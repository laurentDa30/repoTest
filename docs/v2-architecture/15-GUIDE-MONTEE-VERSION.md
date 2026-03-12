# Guide de Montée de Version — Laravel 10 → 11 / Livewire 2 → 3 → 4

## Prérequis

| Élément | V1 actuelle | Cible intermédiaire | V2 cible finale |
|---------|-------------|---------------------|-----------------|
| PHP | 8.1+ | 8.2+ | 8.2+ (recommandé 8.4) |
| Laravel | 10.x | 11.x | 12.x |
| Livewire | 2.x | 3.x | **4.x** |
| MySQL | 5.7+ | 8.0+ | 8.0+ |

> **Stratégie** : La migration se fait en deux temps.
> Étapes 1-8 couvrent Laravel 10→11 et Livewire 2→3.
> Étape 9 couvre Livewire 3→4 (migration légère, rétro-compatible).

---

## Étape 1 — Mise à jour du `composer.json`

> **TODO** : Coller ici le `composer.json` fonctionnel de la V2 une fois validé.

### Dépendances principales à mettre à jour

```json
{
        "require": {
        "php": "^8.2",
        "ext-json": "*",
      "barryvdh/laravel-snappy": "^1.0",
        "bugsnag/bugsnag-laravel": "^2.28",
        "coderflex/laravel-ticket": "^2.0",
        "digitick/sepa-xml": "2.2.0",
        "intervention/validation": "^4.0",
        "ixudra/curl": "6.22.1",
        "jenssegers/agent": "2.6.4",
        "laravel/framework": "^11.0",
        "league/flysystem-aws-s3-v3": "^3.0",
        "league/flysystem-ftp": "^3.16",
        "league/flysystem-sftp-v3": "^3.0",
        "maatwebsite/excel": "*",
        "pusher/pusher-php-server": "^7.2",
      "opcodesio/log-viewer": "^3.0",
      "spatie/laravel-backup": "^9.0",
      "zanysoft/laravel-zip": "^3.0",
      "guzzlehttp/guzzle": "^7.8",
        "nunomaduro/termwind": "^2.0",
        "livewire/livewire": "^4.0",
        "laravel/dusk": "^8.0",
        "laravel/sanctum": "^4.0",
        "laravel/tinker": "^2.9",
        "laravel/ui": "^4.5",
        "symfony/var-dumper": "^7.0",
        "symfony/http-client": "^7.0",
        "spatie/laravel-medialibrary": "^11.0",
        "spatie/laravel-permission": "^6.0",
        "spatie/laravel-tags": "^4.7",
        "yajra/laravel-datatables-oracle": "^11.0",
        "barryvdh/laravel-ide-helper": "^3.0",
        "laravel/fortify": "^1.27"
    },
    "require-dev": {
        "barryvdh/laravel-debugbar": "^3.5",
        "barryvdh/laravel-ide-helper": "^3.0",
        "fakerphp/faker": "^1.9.1",
        "laravel/sail": "^1.0.1",
        "mockery/mockery": "^1.4.2",
        "nunomaduro/collision": "^8.0",
        "spatie/laravel-ignition": "^2.4",
        "phpunit/phpunit": "^11.0"
    },
}
```

### Commande de mise à jour

```bash
composer update -W
```

Le flag `-W` (`--with-all-dependencies`) permet de mettre à jour toutes les dépendances en cascade.

---

## Étape 2 — Configuration des variables d'environnement

### Variables à ajouter/vérifier dans le `.env`

```dotenv
# -------------------------------------------------------
# Spatie Laravel Backup — Notification email (OBLIGATOIRE)
# -------------------------------------------------------
# Sans cette variable, l'erreur suivante se produit :
# TypeError: Spatie\Backup\Exceptions\InvalidConfig::invalidEmail():
# Argument #1 ($email) must be of type string, null given
# -------------------------------------------------------
BACKUP_NOTIFICATION_EMAIL=admin@votredomaine.com
```

### Pourquoi c'est nécessaire

Le package `spatie/laravel-backup` v9 exige une adresse email valide pour les notifications.
Si la variable est absente ou `null`, le `package:discover` d'Artisan échoue au `composer update`.

### Alternative : valeur par défaut dans `config/backup.php`

Si vous ne voulez pas dépendre du `.env`, modifiez directement `config/backup.php` :

```php
'mail' => [
    'to' => env('BACKUP_NOTIFICATION_EMAIL', 'admin@votredomaine.com'),
],
```

---

## Étape 3 — Suppression de `bengels/laravel-email-exceptions`

Le package `bengels/laravel-email-exceptions` n'est plus maintenu et incompatible avec Laravel 11.

### 3.1 Retirer le package

```bash
composer remove bengels/laravel-email-exceptions
```

### 3.2 Corriger `app/Exceptions/Handler.php`

**Avant** (Laravel 10) :

```php
use Bengels\LaravelEmailExceptions\Exceptions\EmailHandler;

class Handler extends EmailHandler
```

**Après** (Laravel 11) :

```php
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
```

> **Note** : En Laravel 11+, le `Handler.php` est optionnel. La gestion des exceptions
> se fait dans `bootstrap/app.php` via `->withExceptions()`. Si vous n'avez pas de
> logique custom dans le Handler, vous pouvez le supprimer entièrement.

---

## Étape 4 — Refactoring des disques SFTP Transatel (`config/filesystems.php`)

En Laravel 10, les disques Transatel utilisaient une structure imbriquée avec des sous-clés.
En Laravel 11, chaque variante doit devenir un **disque séparé**.

### 4.1 Nouvelle configuration `config/filesystems.php`

```php
'disks' => [

    // ... autres disques ...

    // ─── Transatel CDR ───────────────────────────────────────
    'transatel-cdr-iot' => [
        'driver'   => 'sftp',
        'host'     => env('FTP_TRANSATEL_CDR_HOST'),
        'username' => env('FTP_TRANSATEL_CDR_IOT_LOGIN'),
        'password' => env('FTP_TRANSATEL_CDR_IOT_PASSWORD'),
        'port'     => env('FTP_TRANSATEL_CDR_PORT', 22),
        'root'     => env('FTP_TRANSATEL_CDR_IOT_PATH'),
        'timeout'  => 30,
    ],

    'transatel-cdr-mvno-light' => [
        'driver'   => 'sftp',
        'host'     => env('FTP_TRANSATEL_CDR_HOST'),
        'username' => env('FTP_TRANSATEL_CDR_MVNO_LIGHT_LOGIN'),
        'password' => env('FTP_TRANSATEL_CDR_MVNO_LIGHT_PASSWORD'),
        'port'     => env('FTP_TRANSATEL_CDR_PORT', 22),
        'root'     => env('FTP_TRANSATEL_CDR_MVNO_PATH'),
        'timeout'  => 30,
    ],

    'transatel-cdr-mvno-full' => [
        'driver'   => 'sftp',
        'host'     => env('FTP_TRANSATEL_CDR_HOST'),
        'username' => env('FTP_TRANSATEL_CDR_MVNO_FULL_LOGIN'),
        'password' => env('FTP_TRANSATEL_CDR_MVNO_FULL_PASSWORD'),
        'port'     => env('FTP_TRANSATEL_CDR_PORT', 22),
        'root'     => env('FTP_TRANSATEL_CDR_MVNO_PATH'),
        'timeout'  => 30,
    ],

    // ─── Transatel Manage ────────────────────────────────────
    'transatel-manage-iot' => [
        'driver'   => 'sftp',
        'host'     => env('FTP_TRANSATEL_CDR_HOST'),
        'username' => env('FTP_TRANSATEL_CDR_IOT_LOGIN'),
        'password' => env('FTP_TRANSATEL_CDR_IOT_PASSWORD'),
        'port'     => env('FTP_TRANSATEL_MANAGE_PORT', 22),
        'root'     => env('FTP_TRANSATEL_MANAGE_IOT_PATH'),
        'timeout'  => 30,
    ],

    'transatel-manage-mvno-light' => [
        'driver'   => 'sftp',
        'host'     => env('FTP_TRANSATEL_CDR_HOST'),
        'username' => env('FTP_TRANSATEL_CDR_MVNO_LIGHT_LOGIN'),
        'password' => env('FTP_TRANSATEL_CDR_MVNO_LIGHT_PASSWORD'),
        'port'     => env('FTP_TRANSATEL_MANAGE_PORT', 22),
        'root'     => env('FTP_TRANSATEL_MANAGE_MVNO_PATH'),
        'timeout'  => 30,
    ],

    'transatel-manage-mvno-full' => [
        'driver'   => 'sftp',
        'host'     => env('FTP_TRANSATEL_CDR_HOST'),
        'username' => env('FTP_TRANSATEL_CDR_MVNO_FULL_LOGIN'),
        'password' => env('FTP_TRANSATEL_CDR_MVNO_FULL_PASSWORD'),
        'port'     => env('FTP_TRANSATEL_MANAGE_PORT', 22),
        'root'     => env('FTP_TRANSATEL_MANAGE_MVNO_PATH'),
        'timeout'  => 30,
    ],
],
```

### 4.2 Variables `.env` à ajouter

```dotenv
# ─── Transatel SFTP ───────────────────────────────────────
FTP_TRANSATEL_CDR_HOST=sftp.transatel.com
FTP_TRANSATEL_CDR_PORT=22
FTP_TRANSATEL_MANAGE_PORT=22

# CDR IoT
FTP_TRANSATEL_CDR_IOT_LOGIN=
FTP_TRANSATEL_CDR_IOT_PASSWORD=
FTP_TRANSATEL_CDR_IOT_PATH=

# CDR MVNO Light
FTP_TRANSATEL_CDR_MVNO_LIGHT_LOGIN=
FTP_TRANSATEL_CDR_MVNO_LIGHT_PASSWORD=
FTP_TRANSATEL_CDR_MVNO_PATH=

# CDR MVNO Full
FTP_TRANSATEL_CDR_MVNO_FULL_LOGIN=
FTP_TRANSATEL_CDR_MVNO_FULL_PASSWORD=

# Manage
FTP_TRANSATEL_MANAGE_IOT_PATH=
FTP_TRANSATEL_MANAGE_MVNO_PATH=
```

### 4.3 Mise à jour des appels dans le code

Rechercher/remplacer les anciens appels imbriqués :

| Ancien appel (Laravel 10) | Nouveau appel (Laravel 11) |
|---|---|
| `Storage::disk('transatel-cdr')['iot']` | `Storage::disk('transatel-cdr-iot')` |
| `Storage::disk('transatel-cdr')['mvno']['light']` | `Storage::disk('transatel-cdr-mvno-light')` |
| `Storage::disk('transatel-cdr')['mvno']['full']` | `Storage::disk('transatel-cdr-mvno-full')` |
| `Storage::disk('transatel-manage')['iot']` | `Storage::disk('transatel-manage-iot')` |
| `Storage::disk('transatel-manage')['mvno']['light']` | `Storage::disk('transatel-manage-mvno-light')` |
| `Storage::disk('transatel-manage')['mvno']['full']` | `Storage::disk('transatel-manage-mvno-full')` |

---

## Étape 5 — Migration du `Kernel.php` vers `bootstrap/app.php`

En Laravel 11, le fichier `app/Http/Kernel.php` **n'existe plus**. Toute la configuration des middleware se fait dans `bootstrap/app.php`.

### 5.1 Supprimer `app/Http/Kernel.php`

Le fichier suivant doit être supprimé :

```
app/Http/Kernel.php
```

### 5.2 Créer/modifier `bootstrap/app.php`

Remplacer le contenu de `bootstrap/app.php` par :

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // ─── Middleware globaux (ex-$middleware) ──────────────
        $middleware->use([
            // \App\Http\Middleware\TrustHosts::class,
            \App\Http\Middleware\TrustProxies::class,
            \Illuminate\Http\Middleware\HandleCors::class,
            \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
            \App\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);

        // ─── Groupe "web" ────────────────────────────────────
        $middleware->group('web', [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\SetLocale::class,
        ]);

        // ─── Groupe "api" ────────────────────────────────────
        $middleware->group('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:60,1',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        // ─── Alias de middleware (ex-$routeMiddleware) ───────
        $middleware->alias([
            'auth'               => \App\Http\Middleware\Authenticate::class,
            'auth.basic'         => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
            // 'gate'            => \App\Http\Middleware\AuthGates::class,
            'cache.headers'      => \Illuminate\Http\Middleware\SetCacheHeaders::class,
            'can'                => \Illuminate\Auth\Middleware\Authorize::class,
            'guest'              => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'password.confirm'   => \Illuminate\Auth\Middleware\RequirePassword::class,
            'signed'             => \Illuminate\Routing\Middleware\ValidateSignature::class,
            'throttle'           => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'verified'           => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'admin'              => \App\Http\Middleware\IsAdmin::class,
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'pageviews'          => \App\Http\Middleware\LogPageViews::class,
            'librenms.api'       => \App\Http\Middleware\LibrenmsApi::class,
            'team.access'        => \App\Http\Middleware\TeamAccessToRizom::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->create();
```

### 5.3 Correspondance Kernel.php → bootstrap/app.php

| Laravel 10 (`Kernel.php`) | Laravel 11 (`bootstrap/app.php`) |
|---|---|
| `protected $middleware = [...]` | `$middleware->use([...])` |
| `protected $middlewareGroups = ['web' => [...]]` | `$middleware->group('web', [...])` |
| `protected $middlewareGroups = ['api' => [...]]` | `$middleware->group('api', [...])` |
| `protected $routeMiddleware = [...]` | `$middleware->alias([...])` |

### 5.3.1 Livewire
Modifier les emit(), dispachEvent.. en dispatch les emitTo, emitUp sont pareil 
Les transferts de donnée, doivent être faite au mieux en dispatch('nom', donnee: $donnee). Si on fait dispatch('nom', ['donnee' => $donnee]) dans le JS on récupère un tableau avec un index en plus event.detail.[0].donnee

Modifier les noms de fichier dans les configs, BROADCASTING CACHE FILESYSTEM - ATTENTION .ENV

### 5.4 Fichiers à supprimer après migration

Ces fichiers hérités de Laravel 10 ne sont plus utilisés en Laravel 11 :

- `app/Http/Kernel.php` — remplacé par `bootstrap/app.php`
- `app/Console/Kernel.php` — remplacé par `routes/console.php`
- `app/Exceptions/Handler.php` — remplacé par `->withExceptions()` (voir Étape 3)

---

## Étape 6 — Commandes post-installation

```bash
# Vider les caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Relancer les découvertes de packages
php artisan package:discover

# Publier les configs mises à jour (si nécessaire)
php artisan vendor:publish --tag=backup-config
```

---

## Étape 7 — Points de vigilance

### 7.1 Livewire 2 → 3

- Les directives Blade `@livewire` deviennent `<livewire:composant />`
- `wire:model` est désormais "deferred" par défaut (utiliser `wire:model.live` pour le comportement temps réel)
- Les méthodes `emit()` / `emitTo()` sont remplacées par `dispatch()` / `dispatch()->to()`
- Les propriétés `$rules` et `$messages` deviennent des méthodes `rules()` et `messages()`

### 7.2 Laravel 10 → 11

- Les fichiers de config ont été simplifiés (moins de fichiers dans `config/`)
- Le fichier `bootstrap/app.php` a changé de structure
- Les middleware se déclarent différemment (plus de `Kernel.php` en Laravel 11+)
- Le `Handler.php` d'exceptions est remplacé par `->withExceptions()` dans `bootstrap/app.php`

### 7.3 Base de données

- Vérifier la compatibilité MySQL 8.0
- Les migrations doivent être jouées dans l'ordre
- Attention aux `json` columns et à l'encodage `utf8mb4`

---

## Étape 8 — Checklist avant déploiement

- [ ] `.env` contient `BACKUP_NOTIFICATION_EMAIL`
- [ ] `.env` contient toutes les variables `FTP_TRANSATEL_*`
- [ ] `bengels/laravel-email-exceptions` a été retiré du `composer.json`
- [ ] `app/Exceptions/Handler.php` étend `ExceptionHandler` (pas `EmailHandler`)
- [ ] Les appels `Storage::disk('transatel-cdr')[...]` ont été remplacés par les nouveaux noms de disques
- [ ] `config/filesystems.php` contient les 6 disques Transatel séparés
- [ ] `app/Http/Kernel.php` supprimé, middleware migrés dans `bootstrap/app.php`
- [ ] `app/Console/Kernel.php` supprimé, scheduler migré dans `routes/console.php`
- [ ] `composer update -W` se termine sans erreur
- [ ] `php artisan package:discover` fonctionne
- [ ] `php artisan migrate --pretend` ne montre pas d'erreurs
- [ ] Les tests passent (`php artisan test`)
- [ ] Les assets sont compilés (`npm run build`)
- [ ] Les crons/scheduled tasks sont mis à jour

---

## Erreurs connues et solutions

### Erreur : `InvalidConfig::invalidEmail() — Argument #1 must be of type string, null given`

**Cause** : Variable `BACKUP_NOTIFICATION_EMAIL` manquante dans `.env`
**Solution** : Ajouter `BACKUP_NOTIFICATION_EMAIL=votre@email.com` dans `.env`
ou mettre une valeur par défaut dans `config/backup.php`

### Erreur : `Class 'Bengels\LaravelEmailExceptions\Exceptions\EmailHandler' not found`

**Cause** : Le package `bengels/laravel-email-exceptions` a été retiré mais `app/Exceptions/Handler.php` l'utilise encore
**Solution** : Remplacer `use Bengels\LaravelEmailExceptions\Exceptions\EmailHandler` par `use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler` (voir Étape 3)

### Erreur : Disques SFTP Transatel inaccessibles

**Cause** : L'ancienne config imbriquée `Storage::disk('transatel-cdr')['iot']` n'est plus valide
**Solution** : Utiliser les nouveaux noms de disques plats : `Storage::disk('transatel-cdr-iot')` (voir Étape 4)

---

## Étape 9 — Livewire 3 → 4

> **Prérequis** : Étapes 1-8 terminées (Laravel 11+, Livewire 3 fonctionnel).
> Livewire 4 est sorti en janvier 2026. La migration depuis la v3 est **légère** —
> la plupart des composants existants fonctionnent sans modification.

### 9.1 Mise à jour Composer

```bash
composer require livewire/livewire:^4.0
php artisan optimize:clear
```

### 9.2 Breaking changes à corriger

#### 9.2.1 `wire:model` — Event bubbling supprimé

En v3, `wire:model` sur un conteneur capturait les événements `input`/`change` des éléments enfants.
En v4, `wire:model` n'écoute que les événements directement émis sur l'élément lui-même.

```blade
{{-- Si vous utilisiez wire:model sur un conteneur parent --}}

{{-- V3 : fonctionnait (event bubbling) --}}
<div wire:model="selected">
    <input type="radio" value="a" />
    <input type="radio" value="b" />
</div>

{{-- V4 : ajouter .deep pour restaurer le comportement --}}
<div wire:model.deep="selected">
    <input type="radio" value="a" />
    <input type="radio" value="b" />
</div>
```

> **Note** : Les usages standards (`wire:model` sur `<input>`, `<select>`, `<textarea>`)
> ne sont **pas affectés**.

#### 9.2.2 `wire:model` — Modifiers `.blur` / `.change`

En v3, les modifiers `.blur` et `.change` contrôlaient uniquement l'envoi réseau —
la valeur côté client se mettait à jour immédiatement.

En v4, ces modifiers contrôlent **aussi** la synchro côté client.

```blade
{{-- V3 : la valeur s'affiche en temps réel, envoi au blur --}}
<input wire:model.blur="name" />

{{-- V4 : la valeur ne se met à jour qu'au blur aussi côté client --}}
{{-- Pour retrouver le comportement V3, ajouter .live : --}}
<input wire:model.live.blur="name" />
```

#### 9.2.3 `wire:scroll` renommé

```blade
{{-- V3 --}}
<div wire:scroll>...</div>

{{-- V4 --}}
<div wire:navigate:scroll>...</div>
```

#### 9.2.4 Tags de composants — fermeture obligatoire

En v4, les composants Livewire **doivent** être correctement fermés (support des slots).

```blade
{{-- V3 : fonctionnait même sans fermeture --}}
<livewire:search-bar />

{{-- V4 : toujours OK avec auto-fermeture --}}
<livewire:search-bar />

{{-- V4 : si contenu (slot), fermeture obligatoire --}}
<livewire:card>
    <p>Contenu du slot</p>
</livewire:card>
```

#### 9.2.5 JavaScript Hooks → Interceptors

```javascript
// V3
Livewire.hook('commit', ({ component, commit, respond }) => { ... })
Livewire.hook('request', ({ uri, options }) => { ... })

// V4
Livewire.interceptMessage(({ component, message }) => { ... })
Livewire.interceptRequest(({ uri, options }) => { ... })
```

### 9.3 Routing — `Route::livewire()` (recommandé en v4)

Pour les full-page components, v4 recommande `Route::livewire()` :

```php
// V3
Route::get('/dashboard', DashboardComponent::class);

// V4 (recommandé, obligatoire pour les Single-File Components)
Route::livewire('/dashboard', DashboardComponent::class);
```

> Les anciennes routes continuent de fonctionner pour les composants classiques (class-based).

### 9.4 Nouvelles fonctionnalités disponibles (optionnel)

Ces fonctionnalités sont **opt-in** — pas besoin de les adopter immédiatement :

| Fonctionnalité | Description | Priorité |
|---|---|---|
| **Single-File Components** | PHP + Blade + JS dans un seul fichier `.blade.php` | Nouveaux composants |
| **Islands** | Zones isolées qui se re-rendent indépendamment | Performance listes |
| **Parallel Requests** | `wire:model.live` en requêtes parallèles | Auto (rien à faire) |
| **Slots natifs** | Livewire components acceptent des `<slot>` comme Blade | Refacto UI |
| **`wire:ref`** | Cibler un composant enfant directement | Communication parent→enfant |
| **`wire:transition`** | Animations déclaratives à l'entrée/sortie du DOM | UX |
| **PHP 8.4 Property Hooks** | `set => max(1, $value)` remplace les hooks `updating` | Simplification code |

### 9.5 Outil de migration automatique

[Laravel Shift](https://laravelshift.com/upgrade-livewire-3-to-livewire-4) propose un Livewire 4.x Shift
qui automatise les changements (renommages, config, modifiers).

### 9.6 Checklist Livewire 3 → 4

- [ ] `composer require livewire/livewire:^4.0` sans erreur
- [ ] `php artisan optimize:clear` exécuté
- [ ] Rechercher `wire:model.blur` et `wire:model.change` → ajouter `.live` si comportement temps réel attendu
- [ ] Rechercher `wire:model` sur des éléments conteneurs (non-input) → ajouter `.deep` si nécessaire
- [ ] Rechercher `wire:scroll` → remplacer par `wire:navigate:scroll`
- [ ] Vérifier que tous les tags `<livewire:...>` sont correctement fermés
- [ ] Rechercher `Livewire.hook(` dans le JS → migrer vers `interceptMessage` / `interceptRequest`
- [ ] Mettre à jour `config/livewire.php` (layout namespace `layouts::`)
- [ ] Tests manuels sur les composants critiques (formulaires, modales, tableaux)
- [ ] `php artisan test` passe

---

## Historique des modifications

| Date | Modification |
|------|-------------|
| 2026-03-09 | Création du guide — Fix spatie/laravel-backup notification email |
| 2026-03-09 | Ajout suppression bengels/laravel-email-exceptions, refactoring disques SFTP Transatel |
| 2026-03-09 | Ajout migration Kernel.php → bootstrap/app.php (étape 5) |
| 2026-03-12 | Ajout étape 9 — Migration Livewire 3 → 4 (breaking changes, nouvelles features, checklist) |
