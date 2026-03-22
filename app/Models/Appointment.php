<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'client_name', 'client_email', 'client_phone',
        'service_id', 'appointment_date', 'appointment_time',
        'stylist', 'status', 'notes',
    ];

    protected $casts = [
        'appointment_date' => 'date',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public static function isSlotAvailable(string $date, string $time, ?string $stylist = null, ?int $excludeId = null): bool
    {
        $query = self::where('appointment_date', $date)
            ->where('appointment_time', $time)
            ->where('status', '!=', 'cancelled');

        if ($stylist) {
            $query->where('stylist', $stylist);
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return !$query->exists();
    }
}
