<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central Routes (Hub)
|--------------------------------------------------------------------------
|
| Routes du hub central : gestion des régions, catalogue, monitoring.
| Accessibles via central.cekoya.local ou localhost.
|
*/

Route::get('/', function () {
    return view('welcome');
});

// API de gestion des régions (central uniquement)
Route::prefix('api/regions')->group(function () {
    Route::get('/', function () {
        $tenants = Tenant::with('domains')->get();
        return response()->json([
            'regions' => $tenants->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'domains' => $t->domains->pluck('domain'),
            ]),
        ]);
    });
});
