<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoDataSeeder extends Seeder
{
    /**
     * Données de démo pour la région IDF.
     * Illustre : clients → collaborateurs → lignes + appareils (centre de coût).
     */
    public function run(): void
    {
        $idf = Tenant::find('idf');
        if (! $idf) {
            $this->command->error('Région IDF non trouvée. Lancez TenantSeeder d\'abord.');
            return;
        }

        $idf->run(function () {
            // Client 1 : Entreprise Dupont
            $clientId = DB::table('clients')->insertGetId([
                'name' => 'Dupont & Associés',
                'siret' => '12345678901234',
                'email' => 'contact@dupont.fr',
                'phone' => '01 23 45 67 89',
                'status' => 'active',
                'address' => '10 rue de la Paix',
                'city' => 'Paris',
                'zipcode' => '75002',
                'billing_mode' => 'monthly',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Collaborateurs de Dupont (le client paye pour chacun)
            $collab1 = DB::table('collaborators')->insertGetId([
                'client_id' => $clientId,
                'lastname' => 'Martin',
                'firstname' => 'Jean',
                'email' => 'j.martin@dupont.fr',
                'designation' => 'Directeur',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $collab2 = DB::table('collaborators')->insertGetId([
                'client_id' => $clientId,
                'lastname' => 'Bernard',
                'firstname' => 'Sophie',
                'email' => 's.bernard@dupont.fr',
                'designation' => 'Commerciale',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Lignes du collaborateur Martin (coût → client Dupont)
            DB::table('lines')->insert([
                [
                    'client_id' => $clientId,
                    'collaborator_id' => $collab1,
                    'telecom_type_id' => 1, // mobile
                    'number' => '06 12 34 56 78',
                    'label' => 'Mobile direction',
                    'status' => 'active',
                    'provider' => 'transatel',
                    'activation_date' => '2023-01-15',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'client_id' => $clientId,
                    'collaborator_id' => $collab1,
                    'telecom_type_id' => 2, // fixe
                    'number' => '01 98 76 54 32',
                    'label' => 'Fixe bureau direction',
                    'status' => 'active',
                    'provider' => 'unyc',
                    'activation_date' => '2022-06-01',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // Ligne du collaborateur Bernard
            DB::table('lines')->insert([
                'client_id' => $clientId,
                'collaborator_id' => $collab2,
                'telecom_type_id' => 1, // mobile
                'number' => '06 98 76 54 32',
                'label' => 'Mobile commercial',
                'status' => 'active',
                'provider' => 'transatel',
                'activation_date' => '2023-03-01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Appareil affecté à Martin (coût → client Dupont)
            $deviceId = DB::table('devices')->insertGetId([
                'brand' => 'Apple',
                'model' => 'iPhone 15 Pro',
                'serial_number' => 'C39V4KGRQ6',
                'imei' => '353456789012345',
                'type' => 'phone',
                'status' => 'assigned',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('device_collaborator')->insert([
                'device_id' => $deviceId,
                'collaborator_id' => $collab1,
                'assigned_at' => '2023-09-15',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('device_client')->insert([
                'device_id' => $deviceId,
                'client_id' => $clientId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Client 2 : PME du BTP
            $client2Id = DB::table('clients')->insertGetId([
                'name' => 'BTP Constructions SAS',
                'siret' => '98765432109876',
                'email' => 'admin@btp-constructions.fr',
                'status' => 'active',
                'city' => 'Nanterre',
                'zipcode' => '92000',
                'billing_mode' => 'monthly',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('collaborators')->insert([
                'client_id' => $client2Id,
                'lastname' => 'Petit',
                'firstname' => 'Marc',
                'email' => 'm.petit@btp-constructions.fr',
                'designation' => 'Chef de chantier',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->command->info('Données de démo insérées dans la région IDF');
    }
}
