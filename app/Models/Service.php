<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['category', 'name', 'description', 'price', 'duration', 'sort_order', 'active'];

    protected $casts = [
        'price' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public static function categories(): array
    {
        return ['Femme', 'Homme', 'Enfant', 'Coloration', 'Soins', 'Barbe'];
    }
}
