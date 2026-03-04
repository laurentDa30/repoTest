<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes (Régions)
|--------------------------------------------------------------------------
|
| Routes accessibles via sous-domaine : idf.cekoya.local, paca.cekoya.local
| Chaque région a sa propre base de données isolée.
|
*/

Route::middleware([
    'web',
    InitializeTenancyBySubdomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        $tenant = tenant();
        return response()->json([
            'region' => $tenant->id,
            'name' => $tenant->name,
            'code' => $tenant->code,
            'message' => "Bienvenue sur la région {$tenant->name}",
        ]);
    });

    Route::get('/status', function () {
        return response()->json([
            'region' => tenant('id'),
            'status' => 'ok',
            'database' => config('database.connections.tenant.database'),
        ]);
    });
});
