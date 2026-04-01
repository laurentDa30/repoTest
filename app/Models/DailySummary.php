<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailySummary extends Model
{
    use HasFactory;

    protected $table = 'daily_summaries';

    protected $fillable = [
        'line_id',
        'client_id',
        'date',
        'telecom_type_id',
        'sms',
        'mms',
        'calls',
        'calls_duration',
        'data',
        'data_xdsl_fttx',
        'out_of_plan',
        'total_charge',
        'total_price',
        'carbon_sms',
        'carbon_mms',
        'carbon_calls',
        'carbon_datas_mobile',
        'carbon_datas_xdsl_fttx',
        'carbon_devices',
        'carbon_services',
        'carbon_total',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'sms' => 'integer',
            'mms' => 'integer',
            'calls' => 'integer',
            'calls_duration' => 'integer',
            'data' => 'integer',
            'data_xdsl_fttx' => 'integer',
            'out_of_plan' => 'decimal:2',
            'total_charge' => 'decimal:8',
            'total_price' => 'decimal:8',
            'carbon_sms' => 'decimal:8',
            'carbon_mms' => 'decimal:8',
            'carbon_calls' => 'decimal:8',
            'carbon_datas_mobile' => 'float',
            'carbon_datas_xdsl_fttx' => 'float',
            'carbon_devices' => 'float',
            'carbon_services' => 'float',
            'carbon_total' => 'float',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function telecomType(): BelongsTo
    {
        return $this->belongsTo(TelecomType::class);
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->where('date', $date);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeForLine(Builder $query, int $lineId): Builder
    {
        return $query->where('line_id', $lineId);
    }

    public function scopeForPeriod(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeForTelecomType(Builder $query, int $telecomTypeId): Builder
    {
        return $query->where('telecom_type_id', $telecomTypeId);
    }

    // ──────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────

    public function hasOutOfPlan(): bool
    {
        return $this->out_of_plan > 0;
    }

    public function totalCarbon(): float
    {
        return $this->carbon_total ?? (
            $this->carbon_sms
            + $this->carbon_mms
            + $this->carbon_calls
            + $this->carbon_datas_mobile
            + $this->carbon_datas_xdsl_fttx
            + $this->carbon_devices
            + $this->carbon_services
        );
    }
}
