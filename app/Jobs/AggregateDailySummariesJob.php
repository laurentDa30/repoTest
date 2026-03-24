<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CallTypeEnum;
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
        public readonly ?string $date = null,
        public readonly bool $withXdslFttx = true,
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
        $xdslUpdated = 0;

        if ($this->withXdslFttx) {
            $xdslUpdated = $this->aggregateXdslFttx($date);
        }

        Log::info("[AggregateDailySummaries] {$aggregated} résumés upsertés, {$xdslUpdated} lignes xDSL/FTTX mises à jour pour {$date}");
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
        $smsTypes = implode(',', [CallTypeEnum::SMS->value, CallTypeEnum::SMS_SPECIAL->value]);
        $voiceTypes = implode(',', [CallTypeEnum::VOICE->value, CallTypeEnum::VOICE_SPECIAL->value, CallTypeEnum::VOICEMAIL->value]);
        $dataType = CallTypeEnum::DATA->value;

        $rows = DB::table('calls')
            ->join('lines', 'calls.line_id', '=', 'lines.id')
            ->select([
                'calls.line_id',
                'lines.client_id',
                'lines.telecom_type_id',
                DB::raw("DATE(calls.date) as date"),
                // SMS : normaux + surtaxés
                DB::raw("SUM(CASE WHEN calls.call_type_id IN ({$smsTypes}) THEN 1 ELSE 0 END) as sms"),
                DB::raw("SUM(CASE WHEN calls.call_type_id = " . CallTypeEnum::MMS->value . " THEN 1 ELSE 0 END) as mms"),
                // Appels : voix + surtaxés + messagerie vocale
                DB::raw("SUM(CASE WHEN calls.call_type_id IN ({$voiceTypes}) THEN 1 ELSE 0 END) as calls"),
                DB::raw("SUM(CASE WHEN calls.call_type_id IN ({$voiceTypes}) THEN calls.duration ELSE 0 END) as calls_duration"),
                // Data mobile uniquement (xDSL/FTTX vient de LibreNMS)
                DB::raw("SUM(CASE WHEN calls.call_type_id = {$dataType} THEN calls.volume ELSE 0 END) as data"),
                // Hors forfait
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
            'data_xdsl_fttx' => 0, // rempli par aggregateXdslFttx()
            'out_of_plan' => $row->out_of_plan,
            'total_charge' => $row->total_charge,
            'total_price' => $row->total_price,
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
                    'out_of_plan',
                    'total_charge',
                    'total_price',
                    'updated_at',
                ]
            );
        }

        return $rows->count();
    }

    /**
     * Récupère les données xDSL/FTTX depuis LibreNMS et met à jour
     * les daily_summaries existants pour la date donnée.
     *
     * LibreNMS est la source de vérité pour le trafic fixe —
     * cette donnée n'existe pas dans la table `calls`.
     */
    private function aggregateXdslFttx(string $date): int
    {
        $startDate = Carbon::parse($date);
        $librenmsDatas = (new \App\Services\LibrenmsService('bills', $startDate))->fetchLibrenmsData();

        if (!isset($librenmsDatas) || ($librenmsDatas['status'] ?? null) !== 'ok') {
            Log::warning("[AggregateDailySummaries] LibreNMS indisponible ou erreur pour {$date}");
            return 0;
        }

        $bills = $librenmsDatas['bills'] ?? [];
        $updated = 0;

        foreach ($bills as $bill) {
            $lineId = $bill['line_id'] ?? null;
            $trafTotal = $bill['history']['traf_total'] ?? 0;

            if (!$lineId || $trafTotal <= 0) {
                continue;
            }

            $affected = DailySummary::where('line_id', $lineId)
                ->where('date', $date)
                ->update(['data_xdsl_fttx' => $trafTotal]);

            // Si pas encore de summary pour cette ligne (pas de CDR telecom ce jour),
            // on en crée un avec uniquement la data xDSL/FTTX
            if ($affected === 0) {
                $line = DB::table('lines')
                    ->select('client_id', 'telecom_type_id')
                    ->where('id', $lineId)
                    ->first();

                if ($line) {
                    DailySummary::create([
                        'line_id' => $lineId,
                        'client_id' => $line->client_id,
                        'telecom_type_id' => $line->telecom_type_id,
                        'date' => $date,
                        'data_xdsl_fttx' => $trafTotal,
                    ]);
                }
            }

            $updated++;
        }

        return $updated;
    }

    private function getDate(): string
    {
        return $this->date ?? Carbon::yesterday()->toDateString();
    }
}
