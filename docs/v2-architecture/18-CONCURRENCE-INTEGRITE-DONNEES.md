# Concurrence et intégrité des données — Stratégie multi-portail

## Contexte

Cekoya est une plateforme multi-portail (admin régional, client, ambassadeur, Hub central) où **plusieurs acteurs** peuvent interagir simultanément sur les mêmes données :

- Un **admin** modifie un forfait client pendant qu'un **autre admin** met à jour la même fiche
- Un **client** consulte sa consommation pendant qu'un **import CDR** met à jour ses données
- Un **job d'agrégation** lit les CDR pendant qu'un **import Transatel** en écrit de nouveaux
- La **facturation** calcule un montant pendant qu'un **admin** modifie un tarif

Sans stratégie de concurrence, ces scénarios mènent à des **données corrompues**, des **factures fausses** ou des **pertes silencieuses** de modifications.

## Les 3 niveaux de concurrence identifiés

### Niveau 1 — Concurrence utilisateur (humain vs humain)

**Scénario type** : Admin A ouvre la fiche du client X. Admin B ouvre la même fiche. B sauvegarde. A sauvegarde ensuite → la modification de B est écrasée silencieusement ("last write wins").

**Tables concernées** : `clients`, `lines`, `collaborators`, `prospects`, `plans`, `devices`, tout ce qui est éditable par plusieurs utilisateurs.

### Niveau 2 — Concurrence multi-portail (admin vs client)

**Scénario type** : L'admin modifie les options d'une ligne pendant que le client consulte ses lignes sur son portail.

**Tables concernées** : `clients` (coordonnées), `tickets`, `lines` (options), `invoices` (contestation vs verrouillage).

### Niveau 3 — Concurrence technique (jobs vs jobs vs utilisateurs)

**Scénario type** : Import CDR Transatel (toutes les heures) pendant que le job d'agrégation nocturne tourne, pendant qu'un admin consulte les stats du jour.

**Tables concernées** : `calls`, `call_iots`, `call_ucass`, `daily_call_summaries`, `daily_iot_summaries`, `monthly_summaries`, `invoices`.

---

## Stratégie par niveau

### Niveau 1 — Optimistic Locking (verrouillage optimiste)

**Principe** : on ne verrouille pas la donnée en lecture. On vérifie au moment de l'écriture que la donnée n'a pas été modifiée entre-temps. Si elle a changé → on rejette l'écriture et on demande à l'utilisateur de rafraîchir.

**Implémentation Laravel** :

```php
// 1. Ajouter une migration sur les tables éditables
Schema::table('clients', function (Blueprint $table) {
    $table->unsignedBigInteger('version')->default(0);
});

// 2. Trait réutilisable sur les modèles concernés
trait HasOptimisticLock
{
    public function saveWithLock(array $attributes): bool
    {
        $currentVersion = $this->version;

        $updated = static::where('id', $this->id)
            ->where('version', $currentVersion)
            ->update(array_merge($attributes, [
                'version' => $currentVersion + 1,
            ]));

        if ($updated === 0) {
            throw new StaleModelException(
                "Cette donnée a été modifiée par un autre utilisateur. Veuillez rafraîchir la page."
            );
        }

        return true;
    }
}

// 3. Utilisation dans un Service/Action
class UpdateClientAction
{
    public function execute(Client $client, UpdateClientDTO $dto): Client
    {
        // Lance StaleModelException si modifié entre-temps
        $client->saveWithLock($dto->toArray());
        return $client->fresh();
    }
}
```

**Côté Livewire** (expérience utilisateur) :

```php
// Dans le composant Livewire
public int $clientVersion; // Chargé au mount()

public function save()
{
    try {
        $this->updateClientAction->execute($this->client, $this->dto);
        $this->dispatch('notify', message: 'Client mis à jour.');
    } catch (StaleModelException $e) {
        $this->dispatch('notify',
            message: 'Ce client a été modifié par un autre utilisateur. La page va se rafraîchir.',
            type: 'warning'
        );
        // Rafraîchir les données automatiquement
        $this->client->refresh();
        $this->clientVersion = $this->client->version;
    }
}
```

**Tables qui nécessitent l'optimistic lock** :

| Table | Risque de conflit | Justification |
|-------|-------------------|---------------|
| `clients` | Élevé | Modifiable par admin + client (coordonnées) |
| `lines` | Moyen | Options modifiables par admin |
| `collaborators` | Moyen | Modifiable par admin |
| `prospects` | Moyen | Plusieurs commerciaux |
| `plans` / `catalog` | Faible | Peu d'éditeurs |
| `invoices_v2` | **CRITIQUE** | Verrouillage + paiement concurrent |
| `tickets` | Moyen | Admin + client écrivent |

### Niveau 2 — Séparation lecture/écriture par portail

La plupart des conflits multi-portail sont **inexistants** car les portails n'écrivent pas sur les mêmes champs :

| Donnée | Admin écrit | Client écrit | Conflit ? |
|--------|-------------|--------------|-----------|
| Fiche client (commercial) | ✅ Tout | ❌ Lecture seule | Non |
| Coordonnées client | ✅ | ✅ (ses propres coordonnées) | **Oui — optimistic lock** |
| Lignes / forfaits | ✅ Gestion | ❌ Lecture | Non |
| Factures | ✅ Génération + verrouillage | ✅ Contestation (champ séparé) | Non (champs différents) |
| Tickets | ✅ Réponse + statut | ✅ Création + messages | Non (ajout, pas modification) |
| Consommation CDR | ❌ (jobs uniquement) | ❌ Lecture | Non |

**Cas spécial — Contestation de facture** :

Le client peut contester une facture pendant que l'admin la verrouille. Solution :

```php
// La contestation crée un NOUVEAU enregistrement (ticket ou dispute),
// elle ne modifie PAS la facture.
// Le verrouillage (is_locked) est unidirectionnel : admin → locked, irréversible.

class DisputeInvoiceAction
{
    public function execute(Invoice $invoice, string $reason): InvoiceDispute
    {
        // La contestation est un objet séparé, pas une modification de la facture
        return InvoiceDispute::create([
            'invoice_id' => $invoice->id,
            'client_id'  => $invoice->client_id,
            'reason'     => $reason,
            'status'     => 'pending',
        ]);
    }
}
```

### Niveau 3 — Sérialisation des jobs par queue et fenêtre temporelle

**Principe fondamental** : les jobs qui touchent les mêmes données ne doivent jamais tourner en parallèle. On sérialise par **queue dédiée** et par **fenêtre temporelle**.

#### Architecture des queues

```
Queue "imports"      → ImportTransatelJob, ImportUnycJob, ImportWazoJob
                       (sérialisé : un import à la fois par fournisseur)

Queue "aggregation"  → AggregateDailyCallsJob, AggregateDailyIoTJob
                       (ne tourne que APRÈS les imports, sur données J-1)

Queue "billing"      → GenerateInvoiceJob, LockInvoiceJob
                       (ne tourne que sur données agrégées, fin de mois)

Queue "default"      → Notifications, emails, tâches légères
                       (pas de conflit avec les données CDR/factures)
```

#### Fenêtres temporelles

```
00:00 ─────────── 02:00 ─────────── 06:00 ─────────── 23:00
                    │                  │
                    ▼                  ▼
              Agrégation J-1      Fin fenêtre
              (jobs aggregation)  d'agrégation

Imports Transatel : toutes les heures, 24h/24
  → écrivent dans call_iots AVEC la date exacte du CDR
  → l'agrégation ne touche que les CDR de J-1 → pas de collision

Import Unyc : 1x/jour (nuit)
  → sérialisé AVANT l'agrégation dans la queue

Facturation : déclenchée manuellement ou le 1er du mois
  → lit les agrégations (données figées), pas les CDR bruts
```

#### Protection contre les imports concurrents

```php
// Utilisation de Cache Lock (atomic lock Redis) pour empêcher
// deux imports du même fournisseur en parallèle

class ImportTransatelJob implements ShouldQueue
{
    public string $queue = 'imports';

    public function handle(): void
    {
        // Verrou atomique Redis : un seul import Transatel à la fois
        $lock = Cache::lock('import:transatel', 3600); // TTL 1h max

        if (! $lock->get()) {
            // Un import Transatel est déjà en cours → reporter
            $this->release(60); // Réessayer dans 60 secondes
            return;
        }

        try {
            // Traitement de l'import
            $this->processImport();
        } finally {
            $lock->release();
        }
    }
}
```

#### Protection de l'agrégation

```php
class AggregateDailyCallsJob implements ShouldQueue
{
    public string $queue = 'aggregation';

    public function handle(): void
    {
        // L'agrégation ne touche que J-1 (données complètes)
        $date = now()->subDay()->toDateString();

        // Upsert atomique : INSERT ... ON DUPLICATE KEY UPDATE
        // Même si le job tourne 2 fois, le résultat est identique (idempotent)
        DB::statement("
            INSERT INTO daily_call_summaries
                (client_id, line_id, date, telecom_type_id,
                 total_calls, total_data_bytes, total_charge, total_price)
            SELECT
                client_id, line_id, DATE(date), telecom_type_id,
                COUNT(*), SUM(value), SUM(charge), SUM(price)
            FROM calls
            WHERE DATE(date) = ?
            GROUP BY client_id, line_id, DATE(date), telecom_type_id
            ON DUPLICATE KEY UPDATE
                total_calls = VALUES(total_calls),
                total_data_bytes = VALUES(total_data_bytes),
                total_charge = VALUES(total_charge),
                total_price = VALUES(total_price),
                updated_at = NOW()
        ", [$date]);
    }
}
```

**L'idempotence** est la clé : si un job d'agrégation tourne 2 fois sur la même date, le résultat est identique grâce à `ON DUPLICATE KEY UPDATE`. Pas de doublons, pas de données corrompues.

---

## Cas critiques et solutions

### Cas 1 : Facture en cours de génération

```
Admin A clique "Générer facture client X" pour mars 2026
Admin B clique "Générer facture client X" pour mars 2026 (2 secondes après)
```

**Solution** : Contrainte d'unicité `UNIQUE(client_id, period, type)` sur `invoices_v2` + verrou Redis.

```php
class GenerateInvoiceAction
{
    public function execute(Client $client, CarbonPeriod $period): Invoice
    {
        $lockKey = "invoice:{$client->id}:{$period->start->format('Y-m')}";
        $lock = Cache::lock($lockKey, 300); // 5 min max

        if (! $lock->get()) {
            throw new InvoiceAlreadyBeingGeneratedException(
                "Une facture est déjà en cours de génération pour ce client et cette période."
            );
        }

        try {
            // Vérifier qu'elle n'existe pas déjà
            $existing = Invoice::where('client_id', $client->id)
                ->where('period', $period->start->format('Y-m'))
                ->first();

            if ($existing) {
                throw new InvoiceAlreadyExistsException();
            }

            return $this->generate($client, $period);
        } finally {
            $lock->release();
        }
    }
}
```

### Cas 2 : Import CDR avec doublons

```
Import Transatel 13h déjà traité, le fichier est re-importé par erreur
```

**Solution** : Contrainte `UNIQUE(provider_call_id)` sur `calls`, `call_iots`, `call_ucass` + upsert.

```php
// L'upsert (updateOrCreate / ON DUPLICATE KEY UPDATE) garantit
// qu'un CDR avec le même provider_call_id n'est jamais dupliqué.
// Si le fichier est re-importé, les données sont mises à jour (pas dupliquées).
```

### Cas 3 : Admin verrouille une facture pendant que le client la consulte

Pas de conflit : le client lit, l'admin écrit. Le verrouillage est **unidirectionnel et irréversible** — pas besoin d'optimistic lock ici, juste une contrainte applicative.

```php
// Une facture verrouillée ne peut plus être modifiée
// SAUF via un avoir (note de crédit) — nouveau document, pas modification
class LockInvoiceAction
{
    public function execute(Invoice $invoice): void
    {
        if ($invoice->is_locked) {
            return; // Déjà verrouillé, idempotent
        }

        $invoice->update(['is_locked' => true, 'locked_at' => now()]);
    }
}
```

---

## Résumé des mécanismes par couche

| Couche | Mécanisme | Portée |
|--------|-----------|--------|
| **BDD** | `UNIQUE KEY` (provider_call_id, client+period) | Empêche les doublons physiquement |
| **BDD** | `ON DUPLICATE KEY UPDATE` (upsert) | Imports et agrégations idempotents |
| **Applicatif** | Optimistic Lock (`version` column) | Modifications concurrentes humaines |
| **Applicatif** | Cache Lock Redis (atomic lock) | Jobs critiques (imports, factures) |
| **Architecture** | Queues séparées + fenêtres temporelles | Sérialisation des jobs dépendants |
| **Architecture** | Séparation lecture/écriture par portail | Réduction naturelle des conflits |
| **Métier** | Immutabilité (factures verrouillées) | Prévention des modifications tardives |
| **Métier** | Idempotence systématique | Tolérance aux doubles exécutions |

---

## Intégration dans la roadmap

Ces mécanismes s'intègrent dans les phases existantes :

| Mécanisme | Phase | Étape |
|-----------|-------|-------|
| UNIQUE KEY sur CDR | **Phase 1A** (déjà en place via `provider_call_id`) | 1.3, 1.4 |
| Upsert / idempotence agrégations | **Phase 1A** | 1.5, 1.6 |
| Contrainte unicité factures | **Phase 1B** | 1.9 |
| Optimistic Lock sur modèles éditables | **Phase 2A** | Nouveau — à intégrer avec Redis |
| Cache Lock Redis pour jobs critiques | **Phase 2A** | 2.1, 2.2 |
| Queues séparées (imports, aggregation, billing) | **Phase 2A** | 2.2, 2.3 |

---

## Ce document ne couvre PAS

- La concurrence **multi-région** (Hub ↔ régions) → voir `09-ARCHITECTURE-MULTI-REGION.md` (sync catalogue par checksums)
- La concurrence **V1/V2 pendant la transition** → voir `14-STRATEGIE-TRANSITION-V1-V2.md` (la stratégie Strangler Fig élimine ce risque : une seule codebase, pas deux apps concurrentes)
