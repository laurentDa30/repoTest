# Guide de Montée de Version — Laravel 10 → Laravel 11 / Livewire 2 → 3

## Prérequis

| Élément | V1 actuelle | V2 cible |
|---------|-------------|----------|
| PHP | 8.1+ | 8.2+ (recommandé 8.3) |
| Laravel | 10.x | 11.x |
| Livewire | 2.x | 3.x |
| MySQL | 5.7+ | 8.0+ |

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
        "livewire/livewire": "^3.4",
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
            'role'               => \Spatie\Permission\Middlewares\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middlewares\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middlewares\RoleOrPermissionMiddleware::class,
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

## Historique des modifications

| Date | Modification |
|------|-------------|
| 2026-03-09 | Création du guide — Fix spatie/laravel-backup notification email |
| 2026-03-09 | Ajout suppression bengels/laravel-email-exceptions, refactoring disques SFTP Transatel |
| 2026-03-09 | Ajout migration Kernel.php → bootstrap/app.php (étape 5) |
