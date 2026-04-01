# 19 — Guide de migration : Table `calls` — Partitionnement et séparation IoT/UCaaS

## Contexte

| Élément | Valeur actuelle |
|---------|----------------|
| Table | `calls` |
| Rows | **~19M** (10M IoT + 9M hors IoT) |
| Taille | **~8.5 Go** (data + 8 index secondaires) |
| Période | 12 mois glissants (mars 2025 → mars 2026) |
| Archivage | Job M-1 existant → `calls_archive` (~9M rows) |
| Index | 1 PK + 1 UNIQUE + 7 secondaires |
| FK | 4 (line_id, telecom_type_id, call_type_id, cdr_file_id) |

### Objectifs

1. **Partitionner `calls`** par mois → partition pruning sur les requêtes (×12 gain), `DROP PARTITION` instantané pour l'archivage
2. **Séparer IoT et UCaaS** dans des tables dédiées (`call_iots`, `call_ucass`) → les futurs CDR vont dans la bonne table
3. **Partitionner `calls_archive`** → purge mensuelle par `DROP PARTITION` au lieu de `DELETE`

### Décision architecturale : "Couper au présent"

On ne migre PAS les CDR IoT/UCaaS historiques hors de `calls`. Les données existantes restent en place.

**Pourquoi :**

| Critère | Migrer l'historique | Couper au présent ✅ |
|---------|--------------------|--------------------|
| Rows à déplacer | 10M+ | 0 |
| Downtime | 1-2h | 0 (partitionnement découplé) |
| Risque perte de données | Moyen | Nul |
| IDs dans les nouvelles tables | Fragmentés (47, 193, 8750504...) | Séquentiels (1, 2, 3...) |
| Convergence | Immédiate | Naturelle en 12 mois (archivage) |
| Requêtes IoT historiques | 1 table | UNION temporaire 12 mois |

Les anciens CDR IoT dans `calls` vieillissent et partent via l'archivage M-1 normalement. Après 12 mois, `calls` ne contient plus que du mobile/fixe/internet.

---

## Pré-requis

```
□ Backup complet de calls (mysqldump --single-transaction --quick)
□ Backup complet de calls_archive
□ Espace disque vérifié : ≥ 20 Go libres (copie temporaire)
□ Migration Laravel exécutée (calls_new, call_iots, call_ucass créées vides)
□ Code d'import CDR modifié pour router vers call_iots/call_ucass (déployé MAIS pas encore actif)
□ Fenêtre de maintenance planifiée (~1h30)
□ Accès SSH au serveur + terminal screen/tmux
```

---

## Phase A — Partitionnement de `calls` (fenêtre de maintenance)

### A.0 Snapshot de contrôle

```sql
-- Sauvegarder ces chiffres pour vérification post-migration
SELECT
    COUNT(*) AS total_rows,
    MIN(date) AS date_min,
    MAX(date) AS date_max,
    COUNT(DISTINCT line_id) AS distinct_lines,
    SUM(CASE WHEN telecom_type_id IN (/* IOT_TYPE_IDS */) THEN 1 ELSE 0 END) AS iot_rows
FROM calls;

-- Noter : total_rows = _______, date_min = _______, date_max = _______
```

### A.1 Désactiver les imports CDR

```bash
# Commenter ou désactiver le CRON d'import CDR
# Vérifier qu'aucun job d'import n'est en cours
php artisan queue:monitor
```

### A.2 Copie des données (19M rows, ~20-30 min)

```bash
# Se connecter dans un screen (protection contre déconnexion SSH)
screen -S migration-calls
mysql -u root -p [DATABASE_NAME]
```

```sql
-- Copie brute — calls_new n'a que la PK, pas d'index secondaire
-- C'est pourquoi c'est plus rapide qu'un INSERT avec 8 index à maintenir
INSERT INTO calls_new SELECT * FROM calls;
```

**Suivi de progression** (dans un 2e terminal) :

```sql
SHOW PROCESSLIST;
-- Chercher "INSERT INTO calls_new SELECT * FROM calls"
-- La colonne Time indique les secondes écoulées
```

### A.3 Vérification du count

```sql
SELECT
    (SELECT COUNT(*) FROM calls) AS source,
    (SELECT COUNT(*) FROM calls_new) AS copie;
-- ⚠️ Les deux DOIVENT être identiques. Si différence → STOP, investiguer.
```

### A.4 Mise à jour des statistiques

```sql
-- phpMyAdmin affiche une taille incorrecte tant que les stats ne sont pas recalculées
ANALYZE TABLE calls_new;
```

### A.5 Ajout des index (un par un, ~3-8 min chacun)

Les index sont ajoutés APRÈS la copie car construire un index en une passe sur des données existantes est **2-5× plus rapide** que le maintenir pendant 19M INSERT.

```sql
-- UNIQUE KEY — provider_call_id doit inclure date (requis partitionnement)
ALTER TABLE calls_new ADD UNIQUE KEY
    `calls_provider_call_id_unique` (`provider_call_id`, `date`);

-- Index fonctionnels (noms identiques à l'original)
ALTER TABLE calls_new ADD KEY `service_type_fk_3743468` (`telecom_type_id`);
ALTER TABLE calls_new ADD KEY `call_type_fk_3743469` (`call_type_id`);
ALTER TABLE calls_new ADD KEY `calls_cdr_file_id_foreign` (`cdr_file_id`);
ALTER TABLE calls_new ADD KEY `calls_number_index` (`number`, `price`);
ALTER TABLE calls_new ADD KEY `uncharged` (`date`, `line_id`);
ALTER TABLE calls_new ADD KEY `idx_line_date_price` (`line_id`, `date`, `price`);
ALTER TABLE calls_new ADD KEY `calls_client_id_foreign` (`client_id`);
```

### A.6 Vérification du partitionnement

```sql
-- Doit lister toutes les partitions avec leur nombre de rows
SELECT PARTITION_NAME, TABLE_ROWS
FROM information_schema.PARTITIONS
WHERE TABLE_NAME = 'calls_new' AND TABLE_SCHEMA = DATABASE()
ORDER BY PARTITION_NAME;

-- Vérifier le partition pruning (ne doit scanner qu'1 partition)
EXPLAIN SELECT * FROM calls_new WHERE date = '2026-03-15';
-- → La colonne "partitions" doit afficher "p202603" uniquement
```

### A.7 Bascule atomique (< 1 seconde)

```sql
-- ⚠️ Point de non-retour — mais rollback possible via RENAME inverse
RENAME TABLE calls TO calls_old, calls_new TO calls;
```

### A.8 Restaurer AUTO_INCREMENT

```sql
-- MySQL positionne automatiquement le prochain ID à MAX(id) + 1
ALTER TABLE calls MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;
```

### A.9 Réactiver les imports CDR

```bash
# Réactiver le CRON d'import CDR
# Lancer un import de test et vérifier qu'il s'insère correctement
```

### A.10 Vérification post-bascule

```sql
-- Count cohérent
SELECT COUNT(*) FROM calls;

-- Partition pruning fonctionne
EXPLAIN SELECT * FROM calls WHERE date BETWEEN '2026-03-01' AND '2026-03-31';

-- Un INSERT de test fonctionne (puis DELETE)
INSERT INTO calls (label, date, value, is_outgoing, cdr_file_id)
VALUES ('TEST', NOW(), 0, 1, 1);
-- Vérifier que l'ID auto-incrémenté est > MAX ancien
SELECT MAX(id) FROM calls;
DELETE FROM calls WHERE label = 'TEST';
```

### A.11 Conservation et nettoyage

```
calls_old → GARDER 7 jours minimum comme filet de sécurité
             Vérifier chaque jour que l'application fonctionne normalement
             Après 7 jours : DROP TABLE calls_old;
```

### Rollback si problème

```sql
-- Si problème AVANT le RENAME (A.7) :
DROP TABLE calls_new;
-- calls est intacte, aucun impact

-- Si problème APRÈS le RENAME :
RENAME TABLE calls TO calls_failed, calls_old TO calls;
-- Retour à l'état initial en < 1 seconde
-- Investiguer calls_failed pour comprendre le problème
```

---

## Phase B — Partitionnement de `calls_archive` (même maintenance ou séparée)

### B.1 Identifier les mois présents dans l'archive

```sql
SELECT
    DATE_FORMAT(date, '%Y-%m') AS mois,
    COUNT(*) AS rows_count
FROM calls_archive
GROUP BY DATE_FORMAT(date, '%Y-%m')
ORDER BY mois;
```

### B.2 Créer `calls_archive_new` (migration Laravel ou DDL direct)

La structure est identique à `calls_new` mais avec les partitions correspondant aux mois archivés. La migration Laravel doit être adaptée selon les mois retournés en B.1.

```sql
CREATE TABLE `calls_archive_new` (
    `id` bigint UNSIGNED NOT NULL,
    `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `date` datetime NOT NULL,
    `value` bigint UNSIGNED NOT NULL,
    `recipient` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `charge` decimal(15,9) DEFAULT NULL,
    `price` decimal(15,9) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    `deleted_at` timestamp NULL DEFAULT NULL,
    `line_id` bigint UNSIGNED DEFAULT NULL,
    `client_id` bigint UNSIGNED DEFAULT NULL,
    `telecom_type_id` bigint UNSIGNED DEFAULT NULL,
    `call_type_id` bigint UNSIGNED DEFAULT NULL,
    `is_outgoing` tinyint(1) NOT NULL DEFAULT 1,
    `provider_call_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `network` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `from` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `to` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `cdr_file_id` bigint UNSIGNED NOT NULL,

    PRIMARY KEY (`id`, `date`)
    -- Index ajoutés après copie
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
PARTITION BY RANGE (YEAR(date) * 100 + MONTH(date)) (
    -- Adapter les partitions selon le résultat de B.1
    -- Exemple si archive commence en mars 2024 :
    PARTITION p202403 VALUES LESS THAN (202404),
    PARTITION p202404 VALUES LESS THAN (202405),
    -- ... un par mois présent ...
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

### B.3 Copier les données (9M rows, ~10-15 min)

```sql
INSERT INTO calls_archive_new SELECT * FROM calls_archive;
```

### B.4 Vérification

```sql
SELECT
    (SELECT COUNT(*) FROM calls_archive) AS source,
    (SELECT COUNT(*) FROM calls_archive_new) AS copie;

ANALYZE TABLE calls_archive_new;
```

### B.5 Ajout des index

```sql
ALTER TABLE calls_archive_new ADD KEY `idx_client_date` (`client_id`, `date`);
ALTER TABLE calls_archive_new ADD KEY `idx_line_date` (`line_id`, `date`);
ALTER TABLE calls_archive_new ADD KEY `idx_provider_date` (`provider_call_id`, `date`);
-- Moins d'index que calls — l'archive est consultée rarement
```

### B.6 Bascule + nettoyage

```sql
RENAME TABLE calls_archive TO calls_archive_old, calls_archive_new TO calls_archive;

-- Après 7 jours :
DROP TABLE calls_archive_old;
```

---

## Phase C — Activation de la séparation IoT/UCaaS (déploiement normal, pas de maintenance)

### C.1 Pré-requis

Les tables `call_iots` et `call_ucass` existent déjà (créées par migration Laravel). Le code d'import CDR est modifié et déployé.

### C.2 Modification du code d'import

Le job d'import route vers la bonne table selon le `telecom_type_id` :

```php
// Avant (tout dans calls)
Call::create($cdrData);

// Après (routage par type)
match ($cdrData['telecom_type_id']) {
    TelecomType::IOT       => CallIot::create($cdrData),
    TelecomType::UCAAS     => CallUcaas::create($cdrData),
    default                => Call::create($cdrData),
};
```

### C.3 Déploiement

Déploiement CI/CD normal. Aucune fenêtre de maintenance nécessaire. À partir de ce moment :
- Nouveaux CDR IoT → `call_iots` (IDs séquentiels à partir de 1)
- Nouveaux CDR UCaaS → `call_ucass` (IDs séquentiels à partir de 1)
- Nouveaux CDR mobile/fixe/internet → `calls` (IDs continuent la séquence)
- Anciens CDR (tout type) restent dans `calls` et `calls_archive`

### C.4 Période de transition (12 mois)

Pendant 12 mois, les requêtes IoT/UCaaS doivent interroger deux sources :

```php
// App\Services\IotCdrService.php
class IotCdrService
{
    // Date de bascule — à ajuster selon le déploiement réel
    private const CUTOFF = '2026-04-01';

    public function query(Carbon $from, Carbon $to): Builder
    {
        // Après la transition : tout est dans call_iots
        if ($from >= Carbon::parse(self::CUTOFF)) {
            return CallIot::whereBetween('date', [$from, $to]);
        }

        // Avant la transition : tout est dans calls
        if ($to < Carbon::parse(self::CUTOFF)) {
            return Call::where('telecom_type_id', TelecomType::IOT)
                       ->whereBetween('date', [$from, $to]);
        }

        // Cheval sur les deux périodes — UNION
        $legacy = Call::where('telecom_type_id', TelecomType::IOT)
                      ->where('date', '<', self::CUTOFF)
                      ->where('date', '>=', $from)
                      ->toBase();

        return CallIot::where('date', '>=', self::CUTOFF)
                       ->where('date', '<=', $to)
                       ->toBase()
                       ->union($legacy);
    }
}
```

### C.5 Après 12 mois (cleanup — mars/avril 2027)

- Tous les anciens CDR IoT/UCaaS ont été archivés hors de `calls`
- Supprimer la branche legacy dans `IotCdrService` / `UcaasCdrService`
- `calls` ne contient plus que du mobile/fixe/internet

---

## Phase D — Modification du job d'archivage M-1

### D.1 Avant (job actuel)

```
calls (tout mélangé) → INSERT SELECT → calls_archive → DELETE
```

### D.2 Après (nouveau job)

```
calls (mobile)     → DROP PARTITION → calls_archive (partitionnée)
call_iots (IoT)    → DROP PARTITION → call_iots_archive (partitionnée)
call_ucass (UCaaS)  → DROP PARTITION → call_ucass_archive (partitionnée)
```

### D.3 Process mensuel (CRON le 1er de chaque mois)

```php
class ArchiveMonthlyCdrs implements ShouldQueue
{
    public function handle(): void
    {
        $tables = [
            'calls' => ['archive' => 'calls_archive', 'retention_months' => 12],
            'call_iots' => ['archive' => 'call_iots_archive', 'retention_months' => 12],
            'call_ucass' => ['archive' => 'call_ucass_archive', 'retention_months' => 12],
        ];

        foreach ($tables as $source => $config) {
            $this->archiveTable($source, $config);
        }
    }

    private function archiveTable(string $source, array $config): void
    {
        $cutoff = now()->subMonths($config['retention_months'])->startOfMonth();
        $partition = 'p' . $cutoff->format('Ym');

        // 1. Vérifier que la partition existe
        $exists = DB::selectOne("
            SELECT COUNT(*) as cnt FROM information_schema.PARTITIONS
            WHERE TABLE_NAME = ? AND TABLE_SCHEMA = DATABASE() AND PARTITION_NAME = ?
        ", [$source, $partition]);

        if (!$exists->cnt) {
            Log::info("Archivage {$source}: partition {$partition} inexistante, skip");
            return;
        }

        // 2. Compter
        $count = DB::table($source)
            ->where('date', '>=', $cutoff)
            ->where('date', '<', $cutoff->copy()->addMonth())
            ->count();

        // 3. Copier vers archive
        DB::statement("
            INSERT INTO {$config['archive']}
            SELECT * FROM {$source}
            WHERE date >= ? AND date < ?
        ", [$cutoff, $cutoff->copy()->addMonth()]);

        // 4. Vérifier
        $archived = DB::table($config['archive'])
            ->where('date', '>=', $cutoff)
            ->where('date', '<', $cutoff->copy()->addMonth())
            ->count();

        if ($count !== $archived) {
            Log::error("Archivage {$source}: MISMATCH count={$count}, archived={$archived}");
            throw new \RuntimeException("Archivage {$source}: count mismatch");
        }

        // 5. DROP PARTITION (instantané)
        DB::statement("ALTER TABLE {$source} DROP PARTITION {$partition}");

        // 6. Créer la partition du mois suivant
        $next = now()->addMonth()->startOfMonth();
        $nextPartition = 'p' . $next->format('Ym');
        $nextBound = (int) $next->copy()->addMonth()->format('Ym');

        DB::statement("
            ALTER TABLE {$source} REORGANIZE PARTITION p_future INTO (
                PARTITION {$nextPartition} VALUES LESS THAN ({$nextBound}),
                PARTITION p_future VALUES LESS THAN MAXVALUE
            )
        ");

        Log::info("Archivage {$source}: {$archived} rows → {$config['archive']}, partition {$partition} supprimée");
    }
}
```

---

## Phase E — Cold storage (M-24, ultérieur)

Le cold storage intervient sur les `calls_archive` quand les données ont plus de 24 mois.

### E.1 Export

```sql
-- Exemple pour le mois de mars 2024
SELECT * FROM calls_archive
WHERE date >= '2024-03-01' AND date < '2024-04-01'
INTO OUTFILE '/tmp/2024-03-calls.csv'
FIELDS TERMINATED BY ',' ENCLOSED BY '"'
LINES TERMINATED BY '\n';
```

```bash
gzip /tmp/2024-03-calls.csv
sha256sum /tmp/2024-03-calls.csv.gz > /tmp/2024-03-calls.sha256
```

### E.2 Manifeste

```json
{
  "table": "calls_archive",
  "period": "2024-03",
  "rows_count": 1623000,
  "sha256": "a1b2c3d4...",
  "exported_at": "2026-04-01T02:00:00Z",
  "retention_until": "2029-03-31",
  "columns": ["id","label","date","value","recipient","charge","price","created_at","updated_at","deleted_at","line_id","client_id","telecom_type_id","call_type_id","is_outgoing","provider_call_id","network","number","from","to","cdr_file_id"]
}
```

### E.3 Purge

```sql
-- Après vérification du fichier compressé
ALTER TABLE calls_archive DROP PARTITION p202403;
```

### E.4 Réimport si nécessaire (contentieux, réquisition judiciaire)

```sql
-- Créer une table temporaire
CREATE TEMPORARY TABLE calls_reimport LIKE calls_archive;

LOAD DATA INFILE '/archives/cdr/2024-03-calls.csv'
INTO TABLE calls_reimport
FIELDS TERMINATED BY ',' ENCLOSED BY '"'
LINES TERMINATED BY '\n';

-- Requêter les données nécessaires, puis DROP TEMPORARY TABLE
```

---

## Chronologie complète

```
 ÉTAPE 1 — PRÉPARATION (semaines avant)
 ═══════════════════════════════════════
 □ Migrations Laravel déployées (calls_new, call_iots, call_ucass vides)
 □ Code d'import CDR modifié (routage par type) — PAS encore activé
 □ Backups effectués
 □ Fenêtre de maintenance communiquée

 ÉTAPE 2 — MAINTENANCE CALLS (~1h)
 ═══════════════════════════════════════
 □ Désactiver imports CDR
 □ Phase A : copie → vérif → index → RENAME → AUTO_INCREMENT
 □ Réactiver imports CDR
 □ Vérification post-bascule

 ÉTAPE 3 — MAINTENANCE ARCHIVE (~30 min, même session ou séparée)
 ═══════════════════════════════════════
 □ Phase B : copie → vérif → index → RENAME

 ÉTAPE 4 — DÉPLOIEMENT SÉPARATION IoT/UCaaS (CI/CD normal, 0 downtime)
 ═══════════════════════════════════════
 □ Phase C : activation du routage d'import
 □ Vérifier que les nouveaux CDR arrivent dans les bonnes tables

 ÉTAPE 5 — MODIFICATION JOB ARCHIVAGE (déploiement normal)
 ═══════════════════════════════════════
 □ Phase D : nouveau job avec DROP PARTITION

 ÉTAPE 6 — NETTOYAGE (J+7)
 ═══════════════════════════════════════
 □ DROP TABLE calls_old
 □ DROP TABLE calls_archive_old

 ÉTAPE 7 — CLEANUP TRANSITION (mars/avril 2027)
 ═══════════════════════════════════════
 □ Supprimer branches legacy dans IotCdrService / UcaasCdrService
 □ Plus aucun IoT/UCaaS dans calls

 ÉTAPE 8 — COLD STORAGE (ultérieur, à planifier)
 ═══════════════════════════════════════
 □ Phase E : export CSV.gz + purge partitions > 24 mois
```

---

## Checklist de sécurité

### Avant migration
```
□ Backup mysqldump calls + calls_archive
□ Espace disque ≥ 20 Go libres
□ CRON import CDR désactivé
□ Job archivage M-1 désactivé
□ Snapshot de contrôle (counts, dates min/max)
□ Session screen/tmux active
```

### Pendant migration
```
□ Vérification count après INSERT SELECT
□ ANALYZE TABLE après copie
□ Vérification partition pruning (EXPLAIN)
□ RENAME atomique (pas de DROP + CREATE)
□ AUTO_INCREMENT repositionné
```

### Après migration
```
□ Test import CDR (1 fichier réel)
□ Test lecture dashboards
□ EXPLAIN vérifie le partition pruning
□ calls_old et calls_archive_old conservées 7 jours
□ Monitoring espace disque
```

### Rollback
```
□ Avant RENAME : DROP TABLE calls_new (calls intacte)
□ Après RENAME : RENAME TABLE calls TO calls_failed, calls_old TO calls
□ En cas de doute : NE PAS supprimer calls_old, appeler l'équipe
```
