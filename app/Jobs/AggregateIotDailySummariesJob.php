<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CallTypeEnum;
use App\Enums\TelecomTypeEnum;
use App\Models\DailyIotSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregateIotDailySummariesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 600;

    public function __construct(
        public readonly ?string $date = null,
    ) {}

    public function uniqueId(): string
    {
        return 'aggregate-iot-daily-summaries-' . $this->getDate();
    }

    public function handle(): void
    {
        $date = $this->getDate();

        Log::info("[AggregateIotDailySummaries] Début agrégation IoT pour {$date}");

        $aggregated = $this->aggregateIotCalls($date);

        Log::info("[AggregateIotDailySummaries] {$aggregated} résumés IoT upsertés pour {$date}");
    }

    /**
     * Agrège les CDR IoT de la table `calls` pour la date donnée,
     * groupés par (line_id, client_id, telecom_type_id, date).
     *
     * Filtre uniquement les lignes IoT (telecom_type_id = IOT).
     * Majoritairement data, mais les SIMs IoT peuvent émettre
     * des SMS/appels (surtaxés car hors usage normal).
     *
     * Idempotent : upsert recalcule depuis la source à chaque exécution.
     */
    private function aggregateIotCalls(string $date): int
    {
        $smsTypes = implode(',', [CallTypeEnum::SMS->value, CallTypeEnum::SMS_SPECIAL->value]);
        $voiceTypes = implode(',', [CallTypeEnum::VOICE->value, CallTypeEnum::VOICE_SPECIAL->value, CallTypeEnum::VOICEMAIL->value]);
        $dataType = CallTypeEnum::DATA->value;
        $iotType = TelecomTypeEnum::IOT->value;

        $rows = DB::table('calls')
            ->join('lines', 'calls.line_id', '=', 'lines.id')
            ->select([
                'calls.line_id',
                'lines.client_id',
                'lines.telecom_type_id',
                DB::raw("DATE(calls.date) as date"),
                DB::raw("SUM(CASE WHEN calls.call_type_id IN ({$smsTypes}) THEN 1 ELSE 0 END) as sms"),
                DB::raw("SUM(CASE WHEN calls.call_type_id = " . CallTypeEnum::MMS->value . " THEN 1 ELSE 0 END) as mms"),
                DB::raw("SUM(CASE WHEN calls.call_type_id IN ({$voiceTypes}) THEN 1 ELSE 0 END) as calls"),
                DB::raw("SUM(CASE WHEN calls.call_type_id IN ({$voiceTypes}) THEN calls.duration ELSE 0 END) as calls_duration"),
                DB::raw("SUM(CASE WHEN calls.call_type_id = {$dataType} THEN calls.volume ELSE 0 END) as data"),
                DB::raw("SUM(CASE WHEN calls.out_of_plan = 1 THEN calls.price ELSE 0 END) as out_of_plan"),
                DB::raw("SUM(calls.charge) as total_charge"),
                DB::raw("SUM(calls.price) as total_price"),
            ])
            ->whereDate('calls.date', $date)
            ->where('lines.telecom_type_id', $iotType)
            ->groupBy('calls.line_id', 'lines.client_id', 'lines.telecom_type_id', DB::raw("DATE(calls.date)"))
            ->get();

        if ($rows->isEmpty()) {
            Log::info("[AggregateIotDailySummaries] Aucun CDR IoT trouvé pour {$date}");
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
            'out_of_plan' => $row->out_of_plan,
            'total_charge' => $row->total_charge,
            'total_price' => $row->total_price,
            'carbon_sms' => 0,
            'carbon_mms' => 0,
            'carbon_calls' => 0,
            'carbon_datas_mobile' => 0,
            'carbon_devices' => 0,
            'carbon_total' => 0,
            'updated_at' => now(),
            'created_at' => now(),
        ])->toArray();

        foreach (array_chunk($upsertData, 500) as $chunk) {
            DailyIotSummary::upsert(
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

    private function getDate(): string
    {
        return $this->date ?? Carbon::yesterday()->toDateString();
    }
}
