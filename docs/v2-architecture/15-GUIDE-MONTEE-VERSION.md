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
        "laravel/framework": "^12.0",
        "livewire/livewire": "^3.0",
        "spatie/laravel-backup": "^9.0",
        "stancl/tenancy": "^3.0"
    }
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

## Étape 5 — Commandes post-installation

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

## Étape 6 — Points de vigilance

### 6.1 Livewire 2 → 3

- Les directives Blade `@livewire` deviennent `<livewire:composant />`
- `wire:model` est désormais "deferred" par défaut (utiliser `wire:model.live` pour le comportement temps réel)
- Les méthodes `emit()` / `emitTo()` sont remplacées par `dispatch()` / `dispatch()->to()`
- Les propriétés `$rules` et `$messages` deviennent des méthodes `rules()` et `messages()`

### 6.2 Laravel 10 → 11

- Les fichiers de config ont été simplifiés (moins de fichiers dans `config/`)
- Le fichier `bootstrap/app.php` a changé de structure
- Les middleware se déclarent différemment (plus de `Kernel.php` en Laravel 11+)
- Le `Handler.php` d'exceptions est remplacé par `->withExceptions()` dans `bootstrap/app.php`

### 6.3 Base de données

- Vérifier la compatibilité MySQL 8.0
- Les migrations doivent être jouées dans l'ordre
- Attention aux `json` columns et à l'encodage `utf8mb4`

---

## Étape 7 — Checklist avant déploiement

- [ ] `.env` contient `BACKUP_NOTIFICATION_EMAIL`
- [ ] `.env` contient toutes les variables `FTP_TRANSATEL_*`
- [ ] `bengels/laravel-email-exceptions` a été retiré du `composer.json`
- [ ] `app/Exceptions/Handler.php` étend `ExceptionHandler` (pas `EmailHandler`)
- [ ] Les appels `Storage::disk('transatel-cdr')[...]` ont été remplacés par les nouveaux noms de disques
- [ ] `config/filesystems.php` contient les 6 disques Transatel séparés
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
