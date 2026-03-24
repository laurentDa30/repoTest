<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyIotSummary extends Model
{
    use HasFactory;

    protected $table = 'daily_iot_summaries';

    protected $fillable = [
        'line_id',
        'client_id',
        'date',
        'telecom_type_id',
        'data',
        'out_of_plan',
        'total_charge',
        'total_price',
        'carbon_datas_mobile',
        'carbon_devices',
        'carbon_total',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'data' => 'integer',
            'out_of_plan' => 'decimal:2',
            'total_charge' => 'decimal:8',
            'total_price' => 'decimal:8',
            'carbon_datas_mobile' => 'float',
            'carbon_devices' => 'float',
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
            $this->carbon_datas_mobile
            + $this->carbon_devices
        );
    }
}
