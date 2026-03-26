<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Générer les partitions mensuelles :
        // - De mars 2025 (début des données) à 3 ans d'avance
        // - p_future en filet de sécurité (tout INSERT futur non couvert y atterrit)
        $partitions = [];
        $start = Carbon::parse('2025-03-01');
        $end = Carbon::parse('2029-04-01'); // ~3 ans d'avance

        while ($start < $end) {
            $name = 'p' . $start->format('Ym');
            $bound = (int) $start->copy()->addMonth()->format('Ym');
            $partitions[] = "PARTITION {$name} VALUES LESS THAN ({$bound})";
            $start->addMonth();
        }

        $partitions[] = 'PARTITION p_future VALUES LESS THAN MAXVALUE';
        $partitionsSql = implode(",\n            ", $partitions);

        // calls_new : structure identique à calls, SANS index secondaires
        // Les index seront ajoutés APRÈS l'INSERT SELECT via MySQL CLI
        // (construire un index sur 19M rows existantes est 2-5× plus rapide
        //  que le maintenir pendant 19M INSERT)
        //
        // Différences avec calls :
        // - PK composite (id, date) au lieu de (id) seul → requis pour le partitionnement
        // - Pas de FK (MySQL interdit les FK sur tables partitionnées)
        // - Pas d'AUTO_INCREMENT (on copie les IDs existants, ajouté après RENAME)
        // - Pas d'index secondaires (ajoutés après la copie)
        DB::statement("
            CREATE TABLE `calls_new` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            PARTITION BY RANGE (YEAR(date) * 100 + MONTH(date)) (
                {$partitionsSql}
            )
        ");

        // ┌─────────────────────────────────────────────────────────────┐
        // │ ÉTAPES MANUELLES À FAIRE VIA MYSQL CLI APRÈS CETTE MIGRATION│
        // │                                                             │
        // │ 1. Désactiver les imports CDR                               │
        // │                                                             │
        // │ 2. INSERT INTO calls_new SELECT * FROM calls;               │
        // │    (~19M rows, ~20-30 min)                                  │
        // │                                                             │
        // │ 3. Vérifier :                                               │
        // │    SELECT                                                   │
        // │      (SELECT COUNT(*) FROM calls) AS source,                │
        // │      (SELECT COUNT(*) FROM calls_new) AS copie;             │
        // │                                                             │
        // │ 4. Ajouter les index :                                      │
        // │    ALTER TABLE calls_new ADD UNIQUE KEY                     │
        // │      `calls_provider_call_id_unique`                        │
        // │      (`provider_call_id`, `date`);                          │
        // │                                                             │
        // │    ALTER TABLE calls_new ADD KEY                            │
        // │      `service_type_fk_3743468` (`telecom_type_id`);         │
        // │                                                             │
        // │    ALTER TABLE calls_new ADD KEY                            │
        // │      `call_type_fk_3743469` (`call_type_id`);               │
        // │                                                             │
        // │    ALTER TABLE calls_new ADD KEY                            │
        // │      `calls_cdr_file_id_foreign` (`cdr_file_id`);           │
        // │                                                             │
        // │    ALTER TABLE calls_new ADD KEY                            │
        // │      `calls_number_index` (`number`, `price`);              │
        // │                                                             │
        // │    ALTER TABLE calls_new ADD KEY                            │
        // │      `uncharged` (`date`, `line_id`);                       │
        // │                                                             │
        // │    ALTER TABLE calls_new ADD KEY                            │
        // │      `idx_line_date_price` (`line_id`, `date`, `price`);    │
        // │                                                             │
        // │    ALTER TABLE calls_new ADD KEY                            │
        // │      `calls_client_id_foreign` (`client_id`);               │
        // │                                                             │
        // │ 5. Bascule :                                                │
        // │    RENAME TABLE calls TO calls_old,                         │
        // │                 calls_new TO calls;                          │
        // │                                                             │
        // │ 6. AUTO_INCREMENT :                                         │
        // │    ALTER TABLE calls MODIFY `id`                            │
        // │      bigint UNSIGNED NOT NULL AUTO_INCREMENT;               │
        // │                                                             │
        // │ 7. Réactiver les imports CDR                                │
        // │                                                             │
        // │ 8. Après 7 jours : DROP TABLE calls_old;                    │
        // └─────────────────────────────────────────────────────────────┘
    }

    public function down(): void
    {
        Schema::dropIfExists('calls_new');
    }
};
