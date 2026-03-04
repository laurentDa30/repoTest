# Cekoya V2 — Mini Démo Multi-Région

## Architecture

```
┌─────────────────────────────────────────────┐
│           CENTRAL (Hub)                     │
│  central.cekoya.local / localhost           │
│                                             │
│  Tables : tenants, domains, users,          │
│           cache, jobs (+ futur: catalog)    │
│                                             │
│  API : /api/regions → liste des régions     │
└──────────┬──────────────┬───────────────────┘
           │              │
    ┌──────▼──────┐ ┌─────▼──────┐
    │  Région IDF │ │ Région PACA│
    │  idf.cekoya │ │ paca.cekoya│
    │  .local     │ │ .local     │
    │             │ │            │
    │  BDD isolée │ │ BDD isolée │
    │  clients,   │ │ (vide,     │
    │  collabs,   │ │  prête à   │
    │  lignes,    │ │  l'emploi) │
    │  devices... │ │            │
    └─────────────┘ └────────────┘
```

## Stack

- **Laravel 12.53** / PHP 8.4
- **stancl/tenancy v3.9** (database-per-tenant)
- SQLite pour la démo (MySQL 8 en production)

## Installation rapide

```bash
cd app/

# 1. Installer les dépendances
composer install

# 2. Copier l'env (déjà fait si cloné)
cp .env.example .env
php artisan key:generate

# 3. Migrations centrales (tenants, domains, users...)
php artisan migrate

# 4. Créer les 2 régions (IDF + PACA)
php artisan db:seed --class=Database\\Seeders\\TenantSeeder

# 5. Migrer les tables dans chaque région
php artisan tenants:migrate

# 6. (Optionnel) Données de démo dans IDF
php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder
```

## Accès local

Ajouter dans `/etc/hosts` :
```
127.0.0.1  central.cekoya.local
127.0.0.1  idf.cekoya.local
127.0.0.1  paca.cekoya.local
```

Puis :
```bash
php artisan serve --host=central.cekoya.local --port=8000
```

- **Central** : http://central.cekoya.local:8000
- **IDF** : http://idf.cekoya.local:8000
- **PACA** : http://paca.cekoya.local:8000

## Tables par région (tenant)

| Table | Module | Description |
|-------|--------|-------------|
| `clients` | Client | Clients de la région |
| `collaborators` | Client | Employés des clients (centre de coût) |
| `telecom_types` | Telecom | Référentiel types (mobile, fixe, IoT...) |
| `lines` | Telecom | Lignes télécom (→ collaborateur → client) |
| `sims` | Telecom | Cartes SIM |
| `devices` | Stock | Appareils (téléphones, PC...) |
| `device_collaborator` | Stock | Pivot appareil ↔ collaborateur |
| `device_client` | Stock | Pivot appareil ↔ client (parc) |

## Données de démo (IDF)

```
Dupont & Associés (Paris 75002)
├── Jean Martin (Directeur)
│   ├── Mobile : 06 12 34 56 78 (Transatel)
│   ├── Fixe : 01 98 76 54 32 (Unyc)
│   └── iPhone 15 Pro (Apple)
└── Sophie Bernard (Commerciale)
    └── Mobile : 06 98 76 54 32 (Transatel)

BTP Constructions SAS (Nanterre 92000)
└── Marc Petit (Chef de chantier)
    └── (pas encore de ligne)
```

> **Rappel** : c'est le **client** qui paye pour tout ce que le collaborateur détient (forfaits, appareils, prestations). Le collaborateur est un centre de coût.

## Ajouter une nouvelle région (comme en production)

```php
// Via tinker ou un artisan command
$tenant = \App\Models\Tenant::create([
    'id' => 'lyon',
    'name' => 'Auvergne-Rhône-Alpes',
    'code' => 'lyon',
]);
$tenant->domains()->create(['domain' => 'lyon.cekoya.local']);
// La BDD est automatiquement créée + migrée (événement TenantCreated)
```
