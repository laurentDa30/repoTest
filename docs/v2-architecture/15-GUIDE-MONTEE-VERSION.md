# Guide de Montée de Version — Laravel 10 → Laravel 12

## Prérequis

| Élément | V1 actuelle | V2 cible |
|---------|-------------|----------|
| PHP | 8.1+ | 8.2+ (recommandé 8.3) |
| Laravel | 10.x | 12.x |
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

## Étape 3 — Commandes post-installation

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

## Étape 4 — Points de vigilance

### 4.1 Livewire 2 → 3

- Les directives Blade `@livewire` deviennent `<livewire:composant />`
- `wire:model` est désormais "deferred" par défaut (utiliser `wire:model.live` pour le comportement temps réel)
- Les méthodes `emit()` / `emitTo()` sont remplacées par `dispatch()` / `dispatch()->to()`
- Les propriétés `$rules` et `$messages` deviennent des méthodes `rules()` et `messages()`

### 4.2 Laravel 10 → 12

- Vérifier les breaking changes entre Laravel 10→11 puis 11→12
- Les fichiers de config ont été simplifiés (moins de fichiers dans `config/`)
- Le fichier `bootstrap/app.php` a changé de structure
- Les middleware se déclarent différemment (plus de `Kernel.php` en Laravel 11+)

### 4.3 Base de données

- Vérifier la compatibilité MySQL 8.0
- Les migrations doivent être jouées dans l'ordre
- Attention aux `json` columns et à l'encodage `utf8mb4`

---

## Étape 5 — Checklist avant déploiement

- [ ] `.env` contient `BACKUP_NOTIFICATION_EMAIL`
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

---

## Historique des modifications

| Date | Modification |
|------|-------------|
| 2026-03-09 | Création du guide — Fix spatie/laravel-backup notification email |
