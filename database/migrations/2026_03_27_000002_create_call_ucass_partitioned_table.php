<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $partitions = [];
        $start = Carbon::parse('2025-03-01');
        $end = Carbon::parse('2030-01-01');

        while ($start < $end) {
            $name = 'p' . $start->format('Ym');
            $bound = (int) $start->copy()->addMonth()->format('Ym');
            $partitions[] = "PARTITION {$name} VALUES LESS THAN ({$bound})";
            $start->addMonth();
        }

        $partitions[] = 'PARTITION p_future VALUES LESS THAN MAXVALUE';
        $partitionsSql = implode(",\n            ", $partitions);

        // call_ucass : partitionnée mensuellement comme calls et call_iots
        // Pas de FK (incompatible partitionnement MySQL) → contraintes applicatives Laravel
        //
        // Colonnes spécifiques UCaaS par rapport à calls :
        // - sip_call_id : identifiant SIP Wazo (traçabilité protocole VoIP)
        // - direction   : sens de l'appel (in/out/internal — plus granulaire que is_outgoing pour la VoIP)
        DB::statement("
            CREATE TABLE `call_ucass` (
                `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
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
                `provider_call_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `network` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `sip_call_id` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `direction` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `from` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `to` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `cdr_file_id` bigint UNSIGNED NOT NULL,

                PRIMARY KEY (`id`, `date`),
                UNIQUE KEY `call_ucass_provider_call_id_unique` (`provider_call_id`, `date`),
                KEY `idx_ucaas_client_date` (`client_id`, `date`),
                KEY `idx_ucaas_line_date` (`line_id`, `date`),
                KEY `idx_ucaas_telecom_type` (`telecom_type_id`),
                KEY `idx_ucaas_call_type` (`call_type_id`),
                KEY `idx_ucaas_cdr_file` (`cdr_file_id`),
                KEY `idx_ucaas_date` (`date`),
                KEY `idx_ucaas_sip_call` (`sip_call_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            PARTITION BY RANGE (YEAR(date) * 100 + MONTH(date)) (
                {$partitionsSql}
            )
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `call_ucass`');
    }
};
