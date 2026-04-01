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

        // call_iots : partitionnée mensuellement comme calls
        // Pas de FK (incompatible partitionnement MySQL) → contraintes applicatives Laravel
        //
        // Colonnes spécifiques IoT par rapport à calls :
        // - imei : identifiant unique du module IoT (traçabilité matériel)
        // - rat  : Radio Access Technology (2G/3G/4G selon 3GPP, ex: UTRAN=1, GERAN=2, EUTRAN=6)
        DB::statement("
            CREATE TABLE `call_iots` (
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
                `imei` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `rat` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `number` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `from` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `to` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                `cdr_file_id` bigint UNSIGNED NOT NULL,

                PRIMARY KEY (`id`, `date`),
                UNIQUE KEY `call_iots_provider_call_id_unique` (`provider_call_id`, `date`),
                KEY `idx_iot_client_date` (`client_id`, `date`),
                KEY `idx_iot_line_date` (`line_id`, `date`),
                KEY `idx_iot_telecom_type` (`telecom_type_id`),
                KEY `idx_iot_call_type` (`call_type_id`),
                KEY `idx_iot_cdr_file` (`cdr_file_id`),
                KEY `idx_iot_date` (`date`),
                KEY `idx_iot_imei` (`imei`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            PARTITION BY RANGE (YEAR(date) * 100 + MONTH(date)) (
                {$partitionsSql}
            )
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `call_iots`');
    }
};
