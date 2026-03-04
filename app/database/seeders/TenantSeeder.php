<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    /**
     * Provisionne les régions de démo :
     * - IDF (Île-de-France) : la V1 historique
     * - PACA (Provence-Alpes-Côte d'Azur) : nouvelle région "production"
     */
    public function run(): void
    {
        // Région 1 : IDF (historique V1)
        $idf = Tenant::create([
            'id' => 'idf',
            'name' => 'Île-de-France',
            'code' => 'idf',
        ]);
        $idf->domains()->create(['domain' => 'idf.cekoya.local']);

        // Région 2 : PACA (nouvelle, comme en production)
        $paca = Tenant::create([
            'id' => 'paca',
            'name' => 'Provence-Alpes-Côte d\'Azur',
            'code' => 'paca',
        ]);
        $paca->domains()->create(['domain' => 'paca.cekoya.local']);

        $this->command->info('2 régions créées : IDF + PACA');
    }
}
