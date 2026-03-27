# 20 — Guide de migration : Table `invoices` — JSON blob → `invoices_v2` + `invoice_lines`

## Contexte

| Élément | Valeur actuelle |
|---------|----------------|
| Table | `invoices` |
| Rows | ~4 560/an (~23 000 sur 5 ans) |
| Problème | Colonne `doc` JSON blob — l'intégralité de la facture est dans 1 colonne |
| Taille blob | 200 Ko à 500 Ko+ par facture (clients avec 500-1000+ lignes) |
| Impact | Chaque SELECT charge le JSON complet en mémoire, même pour lire juste le montant |
| Rétention | 10 ans minimum (Code de commerce L123-22) |

### Pourquoi on ne peut pas "couper au présent"

Contrairement à `calls` où les anciennes données vieillissent et disparaissent, **les factures sont conservées 10 ans et consultées régulièrement** :

- Les clients consultent leurs factures historiques (espace client)
- La comptabilité audite les exercices passés
- Le support traite des litiges sur des factures de N-1 ou N-2
- Les factures sont des documents légaux figés

Ne migrer que les nouvelles factures laisserait toutes les factures historiques dans le JSON blob, sans gain de performance.

### Pourquoi la migration est moins risquée qu'elle n'y paraît

- **Aucune fenêtre de maintenance** : la migration tourne en arrière-plan (queue)
- **Idempotente** : rejouable à l'infini sans risque de doublon (clé unique `invoice_id`)
- **Vérifiée à chaque facture** : checksum centime par centime
- **Pas de bascule brutale** : dual-write progressif, bascule quand 100% validé
- **Rollback immédiat** : la table `invoices` originale n'est jamais modifiée

---

## Architecture cible

```
invoices (JSON blob, V1)          invoices_v2 (en-tête, V2)
┌─────────────────────┐          ┌──────────────────────────┐
│ id                  │    ┌────►│ id                       │
│ client_id           │    │    │ client_id                 │
│ doc (JSON 200-500Ko)│    │    │ number, date, due_date    │
│   ├─ en-tête        │────┘    │ amount_ht/tva/ttc         │
│   ├─ lignes[]       │         │ is_paid, is_locked        │
│   └─ paiement       │         │ meta (snapshot client)    │
└─────────────────────┘         └──────────────┬───────────┘
                                               │ 1:N
                                               ▼
                                 invoice_lines (lignes, V2)
                                ┌──────────────────────────┐
                                │ id, invoice_id           │
                                │ invoice_date (dénorm.)   │
                                │ billable_type/id         │
                                │ type, label, qty         │
                                │ amount_ht/tva/ttc        │
                                └──────────────────────────┘
```

---

## Phase A — Création des tables (migration Laravel, 0 impact)

### A.1 Migration `invoices_v2`

```php
Schema::create('invoices_v2', function (Blueprint $table) {
    $table->id();
    $table->foreignId('client_id')->constrained('clients');
    $table->string('number', 20)->unique();
    $table->unsignedBigInteger('number_int')->unique();
    $table->string('label')->nullable();
    $table->date('date');
    $table->date('due_date')->nullable();
    $table->date('period_start')->nullable();
    $table->date('period_end')->nullable();

    $table->decimal('amount_ht', 10, 2)->default(0);
    $table->decimal('amount_tva', 10, 2)->default(0);
    $table->decimal('amount_ttc', 10, 2)->default(0);

    $table->tinyInteger('status')->unsigned()->default(0);
    $table->boolean('is_paid')->default(false);
    $table->boolean('is_locked')->default(false);
    $table->datetime('paid_at')->nullable();

    $table->string('payment_method', 50)->nullable();
    $table->string('payment_reference', 100)->nullable();

    $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();

    // Snapshot légal client au moment de la facturation + notes de facturation
    // JSON léger (~500 octets) — pas le même problème que le blob actuel
    $table->json('meta')->nullable();

    $table->timestamps();
    $table->softDeletes();

    // Clé unique pour idempotence de la migration historique
    $table->unique('number_int', 'invoices_v2_number_int_unique');
    $table->index(['client_id', 'date'], 'idx_v2_client_date');
    $table->index('status', 'idx_v2_status');
    $table->index(['client_id', 'is_locked', 'is_paid'], 'idx_v2_paid_locked');
});
```

### A.2 Migration `invoice_lines` (partitionnée par année)

```php
// invoice_lines est partitionnée par année (MySQL interdit les FK sur tables partitionnées)
DB::statement("
    CREATE TABLE `invoice_lines` (
        `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        `invoice_id` bigint UNSIGNED NOT NULL,
        `invoice_date` date NOT NULL,

        `billable_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `billable_id` bigint UNSIGNED DEFAULT NULL,

        `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
        `label` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
        `description` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
        `quantity` decimal(10,3) NOT NULL DEFAULT 1.000,
        `unit_price_ht` decimal(10,4) NOT NULL DEFAULT 0.0000,
        `amount_ht` decimal(10,2) NOT NULL DEFAULT 0.00,
        `tva_rate` decimal(5,2) NOT NULL DEFAULT 20.00,
        `amount_tva` decimal(10,2) NOT NULL DEFAULT 0.00,
        `amount_ttc` decimal(10,2) NOT NULL DEFAULT 0.00,
        `sort_order` int NOT NULL DEFAULT 0,

        `created_at` timestamp NULL DEFAULT NULL,
        `updated_at` timestamp NULL DEFAULT NULL,

        PRIMARY KEY (`id`, `invoice_date`),
        KEY `idx_invoice` (`invoice_id`),
        KEY `idx_billable` (`billable_type`, `billable_id`),
        KEY `idx_type_date` (`type`, `invoice_date`),
        KEY `idx_invoice_date` (`invoice_id`, `invoice_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    PARTITION BY RANGE (YEAR(invoice_date)) (
        PARTITION p2020 VALUES LESS THAN (2021),
        PARTITION p2021 VALUES LESS THAN (2022),
        PARTITION p2022 VALUES LESS THAN (2023),
        PARTITION p2023 VALUES LESS THAN (2024),
        PARTITION p2024 VALUES LESS THAN (2025),
        PARTITION p2025 VALUES LESS THAN (2026),
        PARTITION p2026 VALUES LESS THAN (2027),
        PARTITION p2027 VALUES LESS THAN (2028),
        PARTITION p2028 VALUES LESS THAN (2029),
        PARTITION p_future VALUES LESS THAN MAXVALUE
    )
");
```

### A.3 Déploiement

```bash
php artisan migrate
# Les deux tables sont créées vides
# La V1 continue de fonctionner exactement comme avant
# Aucun impact sur la production
```

---

## Phase B — Migration historique (background, aucun impact prod)

### B.1 Analyse préalable des variantes JSON

Avant de coder le parseur, analyser les structures JSON existantes. Le format a pu évoluer au fil des années.

```sql
-- Quelles clés existent à la racine du JSON ?
SELECT DISTINCT JSON_KEYS(doc) as keys, COUNT(*) as nb
FROM invoices
GROUP BY JSON_KEYS(doc)
ORDER BY nb DESC;

-- Y a-t-il des factures sans lignes ?
SELECT COUNT(*) FROM invoices
WHERE JSON_LENGTH(JSON_EXTRACT(doc, '$.lines')) = 0
   OR JSON_EXTRACT(doc, '$.lines') IS NULL;

-- Variations des types de lignes présents
SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(line.value, '$.type')) as line_type, COUNT(*) as nb
FROM invoices,
     JSON_TABLE(doc, '$.lines[*]' COLUMNS (value JSON PATH '$')) as line
GROUP BY line_type
ORDER BY nb DESC;

-- Factures les plus anciennes (format potentiellement différent)
SELECT id, date, JSON_KEYS(doc) FROM invoices ORDER BY date ASC LIMIT 10;
```

**Documenter chaque variante trouvée** — le parseur doit les gérer toutes.

### B.2 Job de migration — `MigrateInvoiceToV2`

```php
// app/Jobs/MigrateInvoiceToV2.php

class MigrateInvoiceToV2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(private int $invoiceId) {}

    public function handle(): void
    {
        $invoice = DB::table('invoices')->find($this->invoiceId);

        if (!$invoice) return;

        // Déjà migré ? (idempotence)
        if (DB::table('invoices_v2')->where('number_int', $invoice->number_int)->exists()) {
            return;
        }

        $doc = json_decode($invoice->doc, true);

        // ── 1. Insérer l'en-tête ──────────────────────────────────────
        $invoiceV2Id = DB::table('invoices_v2')->insertGetId([
            'client_id'          => $invoice->client_id,
            'number'             => $doc['number'] ?? $invoice->number,
            'number_int'         => $invoice->number_int,
            'label'              => $doc['label'] ?? null,
            'date'               => $doc['date'] ?? $invoice->date,
            'due_date'           => $doc['due_date'] ?? null,
            'period_start'       => $doc['period']['start'] ?? null,
            'period_end'         => $doc['period']['end'] ?? null,
            'amount_ht'          => $doc['summary']['total'] ?? 0,
            'amount_tva'         => $doc['summary']['total_tax'] ?? 0,
            'amount_ttc'         => $doc['summary']['total_tax_included'] ?? 0,
            'is_paid'            => (bool) ($doc['payment']['paid'] ?? false),
            'is_locked'          => (bool) ($doc['locked'] ?? false),
            'paid_at'            => $doc['payment']['paid_at'] ?? null,
            'payment_method'     => $doc['payment']['method'] ?? null,
            'payment_reference'  => $doc['payment']['reference'] ?? null,
            'meta'               => json_encode([
                'client_snapshot' => $doc['client'] ?? null,
                'migrated_from'   => 'invoices',
                'original_id'     => $invoice->id,
            ]),
            'created_at'         => $invoice->created_at,
            'updated_at'         => $invoice->updated_at,
        ]);

        // ── 2. Insérer les lignes ─────────────────────────────────────
        $lines = $doc['lines'] ?? [];
        $sortOrder = 0;
        $sumHt = 0;
        $sumTva = 0;
        $sumTtc = 0;

        foreach ($lines as $line) {
            $amountHt  = round((float) ($line['amount_ht'] ?? $line['amount'] ?? 0), 2);
            $tvaRate   = (float) ($line['tva_rate'] ?? $line['tax_rate'] ?? 20);
            $amountTva = round($amountHt * $tvaRate / 100, 2);
            $amountTtc = round($amountHt + $amountTva, 2);

            DB::table('invoice_lines')->insert([
                'invoice_id'    => $invoiceV2Id,
                'invoice_date'  => $doc['date'] ?? $invoice->date,
                'billable_type' => $this->resolveBillableType($line),
                'billable_id'   => $line['line_id'] ?? $line['service_id'] ?? $line['device_id'] ?? null,
                'type'          => $line['type'] ?? 'plan',
                'label'         => $line['label'] ?? $line['description'] ?? '',
                'description'   => $line['description'] ?? null,
                'quantity'      => $line['quantity'] ?? 1,
                'unit_price_ht' => $line['unit_price'] ?? $amountHt,
                'amount_ht'     => $amountHt,
                'tva_rate'      => $tvaRate,
                'amount_tva'    => $amountTva,
                'amount_ttc'    => $amountTtc,
                'sort_order'    => $sortOrder++,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);

            $sumHt  += $amountHt;
            $sumTva += $amountTva;
            $sumTtc += $amountTtc;
        }

        // ── 3. Checksum ───────────────────────────────────────────────
        $expectedTtc = (float) ($doc['summary']['total_tax_included'] ?? 0);

        if (abs($sumTtc - $expectedTtc) > 0.02) {
            // Supprimer ce qu'on vient d'insérer (rollback partiel)
            DB::table('invoice_lines')->where('invoice_id', $invoiceV2Id)->delete();
            DB::table('invoices_v2')->where('id', $invoiceV2Id)->delete();

            Log::error("Migration invoice #{$invoice->id} : checksum KO — attendu {$expectedTtc}, calculé {$sumTtc}");
            throw new \RuntimeException("Checksum KO pour invoice #{$invoice->id}");
        }

        Log::debug("Invoice #{$invoice->id} migrée → invoices_v2 #{$invoiceV2Id} ({$sortOrder} lignes, {$sumTtc}€)");
    }

    private function resolveBillableType(array $line): ?string
    {
        if (!empty($line['line_id']))    return 'App\Models\Line';
        if (!empty($line['service_id'])) return 'App\Models\ServiceSheet';
        if (!empty($line['device_id']))  return 'App\Models\Device';
        return null;
    }
}
```

### B.3 Commande de dispatch (batchée)

```php
// app/Console/Commands/DispatchInvoiceMigration.php

class DispatchInvoiceMigration extends Command
{
    protected $signature = 'invoices:migrate-to-v2
                            {--dry-run : Affiche le nombre de factures à migrer sans rien faire}
                            {--limit=0 : Limiter le nombre de factures (0 = toutes)}
                            {--chunk=100 : Taille des batches}';

    public function handle(): int
    {
        $query = DB::table('invoices as i')
            ->leftJoin('invoices_v2 as v2', 'i.number_int', '=', 'v2.number_int')
            ->whereNull('v2.id') // Non encore migré
            ->select('i.id');

        $total = $query->count();
        $this->info("Factures à migrer : {$total}");

        if ($this->option('dry-run')) {
            return 0;
        }

        $limit  = (int) $this->option('limit');
        $chunk  = (int) $this->option('chunk');
        $queued = 0;

        $query->when($limit > 0, fn ($q) => $q->limit($limit))
              ->orderBy('i.date')
              ->chunk($chunk, function ($invoices) use (&$queued) {
                  foreach ($invoices as $invoice) {
                      MigrateInvoiceToV2::dispatch($invoice->id)
                          ->onQueue('migration');
                  }
                  $queued += $invoices->count();
                  $this->info("  Dispatché {$queued} jobs...");
              });

        $this->info("✓ {$queued} jobs dispatchés sur la queue 'migration'");
        $this->info("Suivre : php artisan queue:work --queue=migration");

        return 0;
    }
}
```

### B.4 Lancement de la migration historique

```bash
# Vérification préalable (dry run)
php artisan invoices:migrate-to-v2 --dry-run

# Lancer en arrière-plan (aucun impact sur la prod)
php artisan invoices:migrate-to-v2 --chunk=50

# Worker dédié sur la queue migration (dans un screen/tmux)
screen -S invoice-migration
php artisan queue:work --queue=migration --timeout=120 --tries=3
```

### B.5 Suivi de la progression

```sql
-- Avancement en temps réel
SELECT
    (SELECT COUNT(*) FROM invoices) AS total_source,
    (SELECT COUNT(*) FROM invoices_v2) AS migrees,
    (SELECT COUNT(*) FROM invoices) -
        (SELECT COUNT(*) FROM invoices_v2) AS restantes,
    ROUND(
        (SELECT COUNT(*) FROM invoices_v2) * 100.0 /
        (SELECT COUNT(*) FROM invoices), 1
    ) AS pct_complete;

-- Factures en erreur (dans la table failed_jobs)
SELECT payload->>'$.displayName' as job, exception, failed_at
FROM failed_jobs
WHERE queue = 'migration'
ORDER BY failed_at DESC;

-- Vérification checksums (echantillon)
SELECT
    v2.number,
    v2.amount_ttc AS stored,
    ROUND(SUM(il.amount_ttc), 2) AS calculated,
    ABS(v2.amount_ttc - ROUND(SUM(il.amount_ttc), 2)) AS ecart
FROM invoices_v2 v2
JOIN invoice_lines il ON il.invoice_id = v2.id
GROUP BY v2.id, v2.number, v2.amount_ttc
HAVING ecart > 0.01
LIMIT 20;
-- Doit retourner 0 ligne
```

### B.6 Critère de passage à la phase suivante

```
□ 100% des factures migrées (restantes = 0)
□ 0 ligne dans la requête de vérification checksums
□ 0 failed_jobs sur la queue migration
□ Vérification manuelle : ouvrir 10 factures au hasard dans les 2 systèmes
```

---

## Phase C — Dual-write (nouvelles factures écrivent dans les 2 systèmes)

### C.1 Principe

Le générateur de factures V2 écrit dans `invoices` ET dans `invoices_v2` + `invoice_lines` à chaque nouvelle facture. Si les deux ne sont pas identiques au centime, la génération est **bloquée**.

```php
// app/Services/InvoiceService.php

class InvoiceService
{
    public function generate(Client $client, Carbon $period): Invoice
    {
        return DB::transaction(function () use ($client, $period) {

            // ── Écriture V1 (JSON blob existant) ────────────────────
            $invoiceV1 = $this->generateV1($client, $period);

            // ── Écriture V2 (normalisé) ──────────────────────────────
            $invoiceV2 = $this->generateV2($client, $period, $invoiceV1);

            // ── Checksum cross-systèmes ──────────────────────────────
            $this->assertConsistency($invoiceV1, $invoiceV2);

            return $invoiceV1; // La V1 reste la source de vérité en lecture
        });
    }

    private function assertConsistency(Invoice $v1, InvoiceV2 $v2): void
    {
        $v1Ttc = (float) json_decode($v1->doc)->summary->total_tax_included;
        $v2Ttc = (float) $v2->amount_ttc;

        if (abs($v1Ttc - $v2Ttc) > 0.01) {
            Log::critical("Dual-write DIVERGENCE facture {$v1->number} : V1={$v1Ttc}€, V2={$v2Ttc}€");
            throw new InvoiceDualWriteDivergenceException(
                "Divergence détectée : V1={$v1Ttc}€ vs V2={$v2Ttc}€"
            );
        }
    }
}
```

### C.2 Critère de bascule en lecture

```
□ 0 divergence constatée sur 3 mois de production (dual-write stable)
□ Vérification mensuelle par la comptabilité (échantillon de 20 factures)
□ Tests de non-régression passent à 100%
□ L'espace client a été testé avec invoices_v2 en staging
```

---

## Phase D — Bascule lecture (V2 devient source de vérité)

### D.1 Modifier les lectures

```php
// Avant
Invoice::find($id);          // lit depuis invoices (JSON blob)

// Après
InvoiceV2::with('lines')->find($id);   // lit depuis invoices_v2 + invoice_lines
```

### D.2 Vérification en staging

```bash
# Simuler la bascule en staging avec des données réelles
# Tester :
# - Liste des factures (pagination)
# - Détail d'une facture (en-tête + lignes)
# - Export PDF depuis invoices_v2
# - Espace client (factures historiques)
# - Dashboard financier
```

### D.3 Bascule en production (déploiement normal)

Aucune fenêtre de maintenance. Le code est modifié, déployé. Si problème : rollback git du déploiement.

---

## Phase E — Extinction du dual-write

Uniquement quand la Phase D est stable depuis **1 mois minimum**.

```php
// Supprimer l'écriture dans invoices lors de la génération
// Le code ne touche plus jamais la table invoices (lecture ou écriture)
```

---

## Phase F — Bascule finale des tables (optionnelle, très tardive)

Uniquement si nécessaire (ex: renommer pour cohérence). Pas de pression temporelle.

```sql
-- Conservation obligatoire : minimum 12 mois après Phase E
RENAME TABLE invoices TO invoices_legacy;
RENAME TABLE invoices_v2 TO invoices;

-- invoices_legacy : conservée 12 mois supplémentaires
-- Suppression uniquement après validation comptable et juridique
DROP TABLE invoices_legacy; -- ~12-24 mois après Phase E
```

---

## Exigences non négociables (rappel du doc 17)

| Exigence | Mécanisme |
|----------|-----------|
| **Checksum centime** | `SUM(invoice_lines.amount_ttc) == invoices_v2.amount_ttc ± 0.01€` à chaque génération |
| **Immutabilité** | `is_locked = 1` → toute modification lève `InvoiceLockedException` |
| **Arrondi par ligne** | Convention documentée, testée unitairement |
| **Snapshot client** | Infos client copiées dans `meta` au moment de la génération (adresse, SIRET, TVA) |
| **Pas de modification** | Toute erreur → workflow **avoir** (facture corrective), jamais d'UPDATE in-place |
| **Idempotence migration** | Clé unique `number_int` → un re-run du job ne crée pas de doublon |

---

## Chronologie complète

```
 PHASE A — Création tables (migration Laravel)           0 impact, < 1 sec
 ═══════════════════════════════════════════════════════════════════════════
 □ php artisan migrate
 □ invoices_v2 et invoice_lines créées vides
 □ La V1 continue de fonctionner


 PHASE B — Migration historique (background)             0 impact, ~quelques heures
 ═══════════════════════════════════════════════════════════════════════════
 □ Analyser les variantes JSON existantes
 □ Coder et tester MigrateInvoiceToV2 sur staging
 □ php artisan invoices:migrate-to-v2
 □ Surveiller : 0 failed_jobs, 0 divergence de checksum
 □ Critère : 100% migré + 0 divergence


 PHASE C — Dual-write (déploiement normal)               0 impact
 ═══════════════════════════════════════════════════════════════════════════
 □ Déployer le générateur de factures avec dual-write
 □ Surveiller 3 mois : 0 divergence
 □ Validation comptabilité (échantillon mensuel)


 PHASE D — Bascule lecture (déploiement normal)          0 impact
 ═══════════════════════════════════════════════════════════════════════════
 □ Modifier les lectures vers invoices_v2
 □ Tester en staging avec données réelles
 □ Déployer
 □ Surveiller 1 mois


 PHASE E — Extinction dual-write (déploiement normal)    0 impact
 ═══════════════════════════════════════════════════════════════════════════
 □ Supprimer l'écriture dans invoices
 □ invoices devient read-only de facto


 PHASE F — Bascule tables (optionnelle)                  maintenance < 1 sec
 ═══════════════════════════════════════════════════════════════════════════
 □ RENAME invoices → invoices_legacy
 □ RENAME invoices_v2 → invoices
 □ Conserver invoices_legacy 12-24 mois
```

---

## Checklist de sécurité

### Avant Phase B (migration historique)
```
□ invoices_v2 et invoice_lines créées et vides
□ Analysé les variantes de structure JSON
□ Job MigrateInvoiceToV2 testé sur staging avec données réelles
□ Checksum validé sur au moins 100 factures en staging
□ Queue worker dédiée configurée (queue 'migration')
```

### Pendant Phase B
```
□ 0 failed_jobs sur la queue migration
□ Requête de vérification checksums retourne 0 ligne
□ Aucune dégradation des performances (migration en background)
```

### Avant Phase C (dual-write)
```
□ 100% des factures historiques migrées
□ 0 divergence de checksum
□ Tests de non-régression OK
```

### Pendant Phase C (dual-write)
```
□ Monitoring des divergences (alerte si > 0)
□ Validation comptabilité mensuelle
□ 3 mois sans divergence avant passage Phase D
```

### Rollback
```
□ Phases A/B : aucun rollback nécessaire (tables ajoutées à côté, rien modifié)
□ Phase C : désactiver le dual-write dans le code (revert commit)
□ Phase D : revert commit (lectures rebasculent sur invoices)
□ La table invoices originale n'est jamais modifiée jusqu'à la Phase F
```
