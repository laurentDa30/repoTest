# 17 — Refonte Facturation & CDR : Analyse des risques et exigences

## Contexte

La V1 souffre de deux problèmes structurels majeurs :

1. **Table `calls`** : table unique pour tous les types de consommation (mobile, fixe, internet, IoT), dont le volume est dominé par l'IoT (connexions data massives). Un archivage mensuel (déplacement des CDR les plus anciens) est en place mais ne suffit plus avec la croissance.
2. **Table `invoices`** : colonne `doc` (JSON blob) contenant l'intégralité de la facture — en-tête, lignes, calculs, métadonnées client. Chaque `SELECT` charge potentiellement des centaines de Ko en mémoire.

Ce document formalise les **pour et contre** de chaque axe de refonte, les **risques spécifiques à la facturation**, et les **exigences non négociables** pour garantir la précision et la validité des factures.

---

## 1. Refonte `invoices` → `invoices_v2` + `invoice_lines`

### 1.1 Principe

Remplacer le JSON blob monolithique par deux tables relationnelles :

- **`invoices_v2`** : en-tête de facture (numéro, date, montants pré-calculés, statut, paiement, métadonnées légères)
- **`invoice_lines`** : lignes de facturation détaillées (forfaits, options, matériel, hors-forfait, remises, services), avec lien polymorphique vers l'entité facturée

### 1.2 Pour

#### Intégrité calculatoire vérifiable automatiquement

Aujourd'hui le JSON est une boîte noire. Si un montant est faux dans le blob, il n'est détectable que visuellement. Avec des lignes relationnelles :

```sql
-- Vérification automatisable (cron, post-génération, audit)
SELECT il.invoice_id,
       SUM(il.amount_ttc) AS calculated,
       i.amount_ttc       AS stored
FROM invoice_lines il
JOIN invoices_v2 i ON il.invoice_id = i.id
GROUP BY il.invoice_id
HAVING ABS(calculated - stored) > 0.01;
```

Impossible à faire proprement sur du JSON imbriqué.

#### Auditabilité ligne par ligne

Si un client conteste une facture, on peut requêter exactement quelle ligne pose problème, la relier à l'entité facturée (`billable_type` / `billable_id`), et remonter au CDR source. Aujourd'hui c'est du parsing JSON manuel.

#### Recalcul partiel possible

Un forfait mal tarifé sur 50 lignes ? `UPDATE` ciblé sur 50 lignes dans `invoice_lines` + recalcul du total. Aujourd'hui il faut régénérer le JSON complet.

#### Contraintes DB exploitables

```sql
-- Les décimales sont typées, pas des strings extraites d'un JSON
amount_ht  DECIMAL(10,2) NOT NULL DEFAULT 0
amount_tva DECIMAL(10,2) NOT NULL DEFAULT 0
amount_ttc DECIMAL(10,2) NOT NULL DEFAULT 0

-- CHECK constraint possible (MySQL 8.0.16+)
CHECK (ABS(amount_ttc - (amount_ht + amount_tva)) < 0.02)
```

#### Performance

- `invoices_v2` sans JSON : quelques Mo au lieu de 3-4 Go
- `invoice_lines` : requêtable, indexable, partitionnée par année
- Les dashboards financiers deviennent instantanés (plus de parsing JSON en mémoire)

### 1.3 Contre

#### Le dual-write est la phase la plus dangereuse

Pendant la transition, deux systèmes génèrent des factures. Si le dual-write diverge :

- Une facture correcte dans `invoices` (JSON) mais fausse dans `invoices_v2` — ou l'inverse
- Pire si c'est le nouveau système qui diverge, car il deviendra la source de vérité

**Mitigation obligatoire** :
- Checksum systématique à chaque facture générée : comparaison automatique entre les deux systèmes
- Si divergence → **alerte bloquante**, pas de génération silencieuse
- Le dual-write ne passe en production que si 100% des factures de test (3 mois de données réelles rejouées) sont identiques au centime près

#### Les arrondis TVA — piège classique

Avec le JSON, le calcul était fait une fois et stocké tel quel. Avec des lignes séparées, `SUM(ligne.amount_tva)` peut diverger du calcul `total_ht × taux_tva` à cause des arrondis par ligne.

**Exemple concret** :
```
Ligne 1 : 10.01 € HT × 20% = 2.002 → arrondi 2.00
Ligne 2 : 10.01 € HT × 20% = 2.002 → arrondi 2.00
Ligne 3 : 10.01 € HT × 20% = 2.002 → arrondi 2.00

SUM(amount_tva) = 6.00 €
Mais : 30.03 × 20% = 6.006 → arrondi 6.01 €
→ Écart de 0.01 €
```

**Mitigation obligatoire** — choisir UNE convention et la documenter dans le code :

| Convention | Principe | Usage |
|------------|----------|-------|
| **Arrondi par ligne** | Chaque ligne est arrondie individuellement. Le total = somme des arrondis. | Le plus courant en France. Conforme CGI art. 267. |
| **Arrondi sur le total** | On somme les HT bruts, on calcule la TVA sur le total, on arrondit une seule fois. | Plus précis mathématiquement, mais les lignes individuelles n'ont pas de TTC "juste". |

**Recommandation** : arrondi par ligne (standard du marché français). Le total TTC de la facture = `SUM(invoice_lines.amount_ttc)`. Le champ `invoices_v2.amount_ttc` est un **cache dénormalisé** de cette somme, vérifié à chaque génération.

#### La migration historique peut corrompre les données

Parser des années de JSON pour les éclater en lignes relationnelles — si le format JSON a évolué au fil du temps (champ renommé, sous-objet ajouté, type qui change), certaines factures anciennes auront des structures différentes.

**Mitigation obligatoire** :
1. **Avant** de coder la migration : analyser les variantes de structure JSON existantes
   ```sql
   -- Identifier les clés JSON distinctes
   SELECT DISTINCT JSON_KEYS(doc) FROM invoices ORDER BY date;
   -- Comparer les structures par année
   ```
2. La migration doit être **idempotente** et **re-jouable** (clé unique `invoice_id` dans `invoices_v2`)
3. Chaque facture migrée est vérifiée : `SUM(invoice_lines.amount_ttc)` == montant TTC du JSON source
4. **Garder la table `invoices` originale minimum 12 mois** après bascule complète — c'est le filet de sécurité

#### Immutabilité des factures verrouillées

Une facture envoyée au client et comptabilisée est un **document légal figé** (CGI, PCG). Le nouveau schéma doit garantir qu'aucune modification n'est possible après verrouillage.

```php
// InvoiceLine.php — protection applicative
protected static function booted(): void
{
    static::updating(function (InvoiceLine $line) {
        if ($line->invoice->is_locked) {
            throw new InvoiceLockedException(
                "Impossible de modifier la ligne {$line->id} : facture {$line->invoice_id} verrouillée."
            );
        }
    });

    static::deleting(function (InvoiceLine $line) {
        if ($line->invoice->is_locked) {
            throw new InvoiceLockedException(
                "Impossible de supprimer la ligne {$line->id} : facture {$line->invoice_id} verrouillée."
            );
        }
    });
}
```

**Principe légal** : une facture émise ne se modifie jamais. En cas d'erreur, on émet un **avoir** (facture corrective), jamais une modification in-place.

### 1.4 Métadonnées client dans la facture

Les informations client (raison sociale, adresse, SIRET) doivent être **snapshottées au moment de la facturation** (obligation légale — le client peut déménager après).

**Recommandation** : stocker dans le champ `meta` JSON de `invoices_v2`. Ce JSON est **léger** (~500 octets) et ne contient que l'en-tête légal, pas les lignes de facturation. Ce n'est pas le même problème que le blob JSON actuel.

```json
{
  "client_snapshot": {
    "company": "Acme SAS",
    "siret": "123 456 789 00012",
    "address": "12 rue de la Paix, 75002 Paris",
    "vat_number": "FR12345678901"
  },
  "billing_notes": "Facturation trimestrielle"
}
```

---

## 2. Séparation `calls` → `calls_mobile` + `calls_iot` + `calls_ucaas`

### 2.1 Principe

Éclater la table unique `calls` (~12 Go+) en tables spécifiques par domaine métier :

- **`calls_mobile`** : CDR mobile, fixe, internet fixe (ex-`calls` sans IoT ni UCaaS)
- **`calls_iot`** : CDR IoT/M2M (volume massif, majoritairement data)
- **`calls_ucaas`** : CDR UCaaS/Wazo (VoIP, conférence)

Chaque table inclut `client_id` dénormalisé (cf. doc 08, Phase 1).

### 2.2 Pour

#### L'IoT ne pollue plus les requêtes mobile/fixe

L'IoT peut représenter 70-80% du volume total de `calls` pour 5% de la valeur métier des requêtes courantes (dashboards, facturation mobile). Séparer physiquement élimine ce bruit.

#### Le hors-forfait est calculé par type de ligne

Un forfait mobile n'a rien à voir avec un quota IoT. Le moteur de facturation mobile tape dans `calls_mobile`, l'IoT dans `calls_iot`. Pas de filtre `WHERE telecom_type_id = X` à oublier — la table elle-même est le filtre.

#### Archivage et rétention différenciés

- **Mobile/fixe** : rétention 12 mois (détail) + agrégé au-delà (obligation contractuelle)
- **IoT** : rétention 3-6 mois (détail) + agrégé au-delà (les clients IoT veulent des dashboards, pas des CDR unitaires)
- **UCaaS** : rétention selon contrat Wazo

#### Index plus petits = requêtes plus rapides

Chaque table a ses propres index B-tree, plus compacts, qui tiennent mieux dans le buffer pool InnoDB.

### 2.3 Contre

#### Les requêtes cross-type deviennent des UNION

Si un dashboard affiche la "conso totale d'un client toutes lignes confondues" :

```sql
SELECT 'mobile' AS source, date, SUM(price) FROM calls_mobile WHERE client_id = ? GROUP BY date
UNION ALL
SELECT 'iot',    date, SUM(price) FROM calls_iot    WHERE client_id = ? GROUP BY date
UNION ALL
SELECT 'ucaas',  date, SUM(price) FROM calls_ucaas  WHERE client_id = ? GROUP BY date
```

**Mitigation** : couche d'abstraction applicative.

```php
// Le code métier ne sait jamais dans quelle table il tape
interface CdrRepositoryContract
{
    public function forClient(int $clientId): self;
    public function between(Carbon $start, Carbon $end): self;
    public function get(): Collection;
    public function summarize(): CdrSummary;
}

// L'implémentation route vers la bonne table
// ou agrège depuis plusieurs tables si nécessaire
```

Le code métier (facturation, dashboards, alertes) ne doit **jamais** référencer directement `calls_mobile` ou `calls_iot`.

#### Un CDR manquant = une facture fausse

Si l'import CDR route mal un enregistrement (un CDR IoT atterrit dans `calls_mobile`, ou pire, nulle part), la facturation sera fausse. Le routage à l'import devient **critique**.

**Mitigations obligatoires** :

| Vérification | Mécanisme | Fréquence |
|--------------|-----------|-----------|
| **Compteur de réconciliation** | `cdr_files.counter` (CDR dans le fichier source) vs `COUNT(*)` dans la table cible | À chaque import |
| **Doublon** | `provider_call_id` UNIQUE empêche les doublons | Structurel (contrainte DB) |
| **Manquants** | Job de réconciliation : CDR importés par fichier vs nombre attendu | Quotidien |
| **Routage** | Le type est déterminé par le `telecom_type_id` de la ligne, pas par heuristique | Structurel (code d'import) |

⚠️ Le `provider_call_id` unique empêche les doublons mais **ne détecte pas les manquants**. Le compteur de réconciliation est indispensable.

#### Le hors-forfait cross-type : existe-t-il ?

**Question bloquante** : existe-t-il des forfaits convergents (mobile + IoT dans un même forfait) où le calcul du hors-forfait doit agréger des CDR de deux tables différentes ?

- **Si non** (chaque forfait est mono-type) → la séparation est sans risque pour la facturation
- **Si oui** → le moteur de facturation doit être capable d'agréger cross-table, ce qui complexifie significativement le calcul

**Action** : vérifier dans `plans` / `line_plan` si des forfaits couvrent plusieurs `telecom_type_id`.

#### L'archivage mensuel × 3 tables = complexité opérationnelle

Aujourd'hui : 1 job d'archivage sur 1 table.
Demain : 3 jobs sur 3 tables, potentiellement avec des rétentions différentes.

Si l'un échoue silencieusement, les CDR archivés pourraient manquer à une facturation corrective (avoir).

**Mitigation** : job d'archivage unique orchestrant les 3 tables, avec vérification post-archivage :

```php
// ArchiveCdrsJob.php
foreach (['calls_mobile', 'calls_iot', 'calls_ucaas'] as $table) {
    $archived = $this->archiveOlderThan($table, $retention[$table]);
    $verified = $this->verifyArchive($table, $archived);

    if (!$verified) {
        alert("Archivage {$table} : incohérence détectée, rollback effectué");
        $this->rollbackArchive($table, $archived);
    }
}
```

---

## 3. Exigences non négociables pour la facturation

Quelle que soit l'architecture retenue, ces exigences sont **bloquantes** — aucune mise en production sans leur implémentation.

### 3.1 Checksums à la génération

Chaque facture générée est vérifiée automatiquement :

```
SUM(invoice_lines.amount_ht)  == invoices_v2.amount_ht   ± 0.01 €
SUM(invoice_lines.amount_tva) == invoices_v2.amount_tva   ± 0.01 €
SUM(invoice_lines.amount_ttc) == invoices_v2.amount_ttc   ± 0.01 €
```

Si divergence → la facture n'est **pas émise** et une alerte est levée.

### 3.2 Immutabilité post-verrouillage

- Une facture `is_locked = 1` ne peut être modifiée ni dans `invoices_v2` ni dans `invoice_lines`
- Protection applicative (events Laravel `updating` / `deleting`)
- Audit log sur toute **tentative** de modification (même bloquée)
- En cas d'erreur sur une facture émise → workflow d'**avoir** (facture corrective), jamais de modification in-place

### 3.3 Convention d'arrondi documentée

- **Choix recommandé** : arrondi par ligne (standard français)
- Documenté dans le code (constante ou config)
- Le total de la facture = `SUM(lignes)`, pas un calcul indépendant
- Tests unitaires couvrant les cas d'arrondis limites (0.005 €)

### 3.4 Réconciliation CDR ↔ Facture

- Aucun CDR ne doit être facturé deux fois (`provider_call_id` UNIQUE)
- Aucun CDR ne doit être oublié (compteurs de réconciliation par `cdr_file`)
- Traçabilité : chaque `invoice_line` de type hors-forfait doit pouvoir être reliée aux CDR sources

### 3.5 Traçabilité ligne → source

Chaque ligne de facture doit être justifiable :

| Type de ligne | Source traçable |
|---------------|----------------|
| Forfait | `line_plan` (pivot ligne ↔ forfait avec dates et prix) |
| Option | `options` + `line_plan` |
| Matériel | `device_client` (avec prix leasing/achat) |
| Service | `service_sheets` + contrat |
| Hors-forfait | CDR source dans `calls_mobile` / `calls_iot` / `calls_ucaas` |
| Remise | Règle commerciale documentée (client_plan_preferences ou autre) |

### 3.6 Avoir plutôt que modification

Workflow obligatoire :

```
Facture émise → erreur détectée → facture verrouillée (intouchable)
                                 → avoir émis (montant négatif, référence la facture d'origine)
                                 → nouvelle facture corrective si nécessaire
```

---

## 4. Stratégie de migration — phases et garde-fous

### Phase A — Création (aucun impact V1)

1. Créer `invoices_v2` et `invoice_lines` (vides, à côté de `invoices`)
2. Créer `calls_mobile`, `calls_iot`, `calls_ucaas` (vides, à côté de `calls`)
3. La V1 continue de fonctionner exactement comme avant

### Phase B — Migration des données historiques

4. **Invoices** : job batch qui parse chaque JSON `doc`, insère l'en-tête dans `invoices_v2`, éclate les lignes dans `invoice_lines`, vérifie le checksum
5. **Calls** : job batch qui déplace les CDR existants vers la table cible selon le `telecom_type_id` de la ligne associée, avec compteur de réconciliation
6. Vérification complète : `COUNT(*)` et `SUM(amount)` entre anciennes et nouvelles tables

### Phase C — Dual-write (transition)

7. Le nouveau code écrit dans les **deux** systèmes (ancien + nouveau)
8. Comparaison automatique à chaque écriture — divergence = alerte bloquante
9. Le nouveau code **lit** depuis le nouveau système uniquement
10. La V1 continue de lire l'ancien système — aucun changement

### Phase D — Bascule finale

11. Extinction du dual-write
12. Renommage : `invoices` → `invoices_legacy`, `invoices_v2` → `invoices`
13. Conservation des tables legacy minimum 12 mois
14. Suppression après validation comptable

### Garde-fous par phase

| Phase | Garde-fou | Critère de passage |
|-------|-----------|-------------------|
| B → C | Migration historique complète | 100% des factures migrées, 0 divergence de checksum |
| C (dual-write) | Comparaison systématique | 0 divergence sur 3 mois de production |
| C → D | Validation métier | L'équipe comptable valide un échantillon de factures |
| D → suppression legacy | Durée de sécurité | 12 mois sans incident |

---

## 5. Question ouverte à trancher

> **Existe-t-il des forfaits convergents (mobile + IoT) ?**
>
> Si oui, le moteur de facturation doit agréger des CDR cross-table pour le calcul du hors-forfait. Cela impacte directement la faisabilité de la séparation physique des tables `calls`.
>
> → **À vérifier dans `plans` / `line_plan` / `telecom_types` avant de lancer la Phase A côté CDR.**
