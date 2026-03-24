<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DailySummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateDailySummariesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 600;

    public function __construct(
        public readonly ?string $date = null
    ) {}

    public function uniqueId(): string
    {
        return 'aggregate-daily-summaries-' . $this->getDate();
    }

    public function handle(): void
    {
        $date = $this->getDate();

        Log::info("[AggregateDailySummaries] Début agrégation pour {$date}");

        $aggregated = $this->aggregateCalls($date);

        Log::info("[AggregateDailySummaries] {$aggregated} résumés upsertés pour {$date}");
    }

    /**
     * Agrège les CDR de la table `calls` pour la date donnée,
     * groupés par (line_id, client_id, telecom_type_id, date).
     *
     * Utilise upsert pour être idempotent : relancer le job
     * sur la même date écrase les anciens résumés.
     */
    private function aggregateCalls(string $date): int
    {
        $rows = DB::table('calls')
            ->join('lines', 'calls.line_id', '=', 'lines.id')
            ->select([
                'calls.line_id',
                'lines.client_id',
                'lines.telecom_type_id',
                DB::raw("DATE(calls.date) as date"),
                DB::raw("SUM(CASE WHEN calls.type = 'sms' THEN 1 ELSE 0 END) as sms"),
                DB::raw("SUM(CASE WHEN calls.type = 'mms' THEN 1 ELSE 0 END) as mms"),
                DB::raw("SUM(CASE WHEN calls.type = 'voice' THEN 1 ELSE 0 END) as calls"),
                DB::raw("SUM(CASE WHEN calls.type = 'voice' THEN calls.duration ELSE 0 END) as calls_duration"),
                DB::raw("SUM(CASE WHEN calls.type = 'data' THEN calls.volume ELSE 0 END) as data"),
                DB::raw("SUM(CASE WHEN calls.type = 'data_xdsl_fttx' THEN calls.volume ELSE 0 END) as data_xdsl_fttx"),
                DB::raw("SUM(CASE WHEN calls.out_of_plan = 1 THEN calls.price ELSE 0 END) as out_of_plan"),
                DB::raw("SUM(calls.charge) as total_charge"),
                DB::raw("SUM(calls.price) as total_price"),
            ])
            ->whereDate('calls.date', $date)
            ->groupBy('calls.line_id', 'lines.client_id', 'lines.telecom_type_id', DB::raw("DATE(calls.date)"))
            ->get();

        if ($rows->isEmpty()) {
            Log::info("[AggregateDailySummaries] Aucun CDR trouvé pour {$date}");
            return 0;
        }

        $upsertData = $rows->map(fn ($row) => [
            'line_id' => $row->line_id,
            'client_id' => $row->client_id,
            'telecom_type_id' => $row->telecom_type_id,
            'date' => $row->date,
            'sms' => $row->sms,
            'mms' => $row->mms,
            'calls' => $row->calls,
            'calls_duration' => $row->calls_duration,
            'data' => $row->data,
            'data_xdsl_fttx' => $row->data_xdsl_fttx,
            'out_of_plan' => $row->out_of_plan,
            'total_charge' => $row->total_charge,
            'total_price' => $row->total_price,
            // Carbone : calculé séparément ou via un listener
            'carbon_sms' => 0,
            'carbon_mms' => 0,
            'carbon_calls' => 0,
            'carbon_datas_mobile' => 0,
            'carbon_datas_xdsl_fttx' => 0,
            'carbon_devices' => 0,
            'carbon_services' => 0,
            'carbon_total' => 0,
            'updated_at' => now(),
            'created_at' => now(),
        ])->toArray();

        // Upsert par batch de 500 — idempotent sur (line_id, date, telecom_type_id)
        foreach (array_chunk($upsertData, 500) as $chunk) {
            DailySummary::upsert(
                $chunk,
                uniqueBy: ['line_id', 'date', 'telecom_type_id'],
                update: [
                    'client_id',
                    'sms',
                    'mms',
                    'calls',
                    'calls_duration',
                    'data',
                    'data_xdsl_fttx',
                    'out_of_plan',
                    'total_charge',
                    'total_price',
                    'updated_at',
                ]
            );
        }

        return $rows->count();
    }

    private function getDate(): string
    {
        return $this->date ?? Carbon::yesterday()->toDateString();
    }
}
