# Cekoya V2 — Plateforme Télécom Modulaire

## Vue d'ensemble

Cekoya V2 est la refonte complète de la plateforme de gestion télécom cekoya.fr. La V1 est un monolithe Laravel 10/Livewire 2 hébergé localement avec une base MySQL. La V2 est une architecture **Modular Monolith DDD-lite** multi-régions, conteneurisée via Docker, avec une séparation stricte des préoccupations entre domaine métier et infrastructure technique.

## Objectifs de la V2

### Problèmes résolus par rapport à la V1

| Problème V1 | Solution V2 |
|---|---|
| Monolithe non structuré, couplage fort | Modular Monolith avec bounded contexts DDD |
| MySQL avec table CDR de 12 Go non partitionnée | PostgreSQL 16 avec range partitioning par mois |
| Livewire 2, UI réactive limitée | Inertia.js + Vue.js 3, SPA-like sans API séparée |
| Hébergement local, pas de Docker | Docker + Docker Compose par région |
| Architecture mono-site | Hub & Spoke multi-régions (franchise model) |
| Pas de temps réel | Laravel Reverb (WebSockets natifs) |
| Auth basique | JWT short-lived + refresh rotation + MFA |
| Pas de CI/CD formalisé | GitHub Actions, déploiement par région |

### Vision métier

Cekoya est un opérateur télécom revendeur (MVNO-like) proposant :
- **Forfaits mobiles** (via Transatel)
- **Internet** fixe (via IELO, euroFIBER)
- **IoT** (SIM data, Fleet management)
- **Gestion IT** (via partenaires)
- **Téléphonie IP/Cloud** (via Wazo, Unyc)

La V2 supporte un modèle **franchise** : chaque région (ex: `paris.ceko.fr`, `lyon.ceko.fr`) est une instance applicative indépendante avec sa propre base de données, coordonnée par un **Hub central** (`hub.ceko.fr`).

---

## Structure des dossiers

```
v2/
├── README.md                          ← Ce fichier
├── docs/                              ← Documentation architecture
│   ├── ARCHITECTURE.md                ← Architecture globale + diagrammes
│   ├── BOUNDED_CONTEXTS.md            ← DDD Bounded Contexts détaillés
│   ├── MULTI_REGION.md                ← Stratégie multi-régions Hub & Spoke
│   ├── SECURITY.md                    ← Modèle de sécurité complet
│   ├── CDR_STRATEGY.md                ← Stratégie d'optimisation des CDR
│   ├── STACK.md                       ← Stack technique + justifications
│   ├── ROADMAP.md                     ← Roadmap de migration V1 → V2
│   ├── CICD.md                        ← Pipeline CI/CD
│   └── MONITORING.md                  ← Observabilité et alerting
│
├── region/                            ← Application région (une par franchise)
│   ├── README.md                      ← Guide de la structure DDD-lite
│   └── app/
│       └── Modules/                   ← Modules DDD-lite
│           ├── IAM/                   ← Identity & Access Management
│           │   ├── Domain/
│           │   │   ├── Entities/
│           │   │   ├── ValueObjects/
│           │   │   ├── Events/
│           │   │   └── Repositories/
│           │   ├── Application/
│           │   │   └── Commands/
│           │   ├── Infrastructure/
│           │   │   ├── Persistence/
│           │   │   └── Http/Controllers/
│           │   └── IAMServiceProvider.php
│           ├── CRM/                   ← Clients, Prospects, Agences
│           ├── Catalog/               ← Produits, Services, Packages
│           ├── Orders/                ← Devis, Commandes, Portabilité
│           ├── Lines/                 ← Lignes, SIMs, Appareils
│           ├── Billing/               ← Facturation, SEPA, Comptabilité
│           ├── CDR/                   ← Call Detail Records
│           ├── Emissions/             ← Bilan carbone
│           ├── Communications/        ← Mailing, Templates, Notifications
│           ├── Operations/            ← Imports, Erreurs, Logs
│           └── Shared/                ← Value Objects et Events partagés
│
├── hub/                               ← Application Hub central
│   ├── README.md
│   └── app/
│       └── Modules/
│           ├── RegionManagement/      ← Gestion des régions
│           ├── SSO/                   ← Single Sign-On inter-régions
│           ├── CrossRegionDashboard/  ← Tableau de bord consolidé
│           └── HealthMonitoring/      ← Surveillance des régions
│
└── infrastructure/
    └── docker/
        ├── region/                    ← Docker Compose + Dockerfile région
        │   ├── docker-compose.yml
        │   ├── Dockerfile
        │   └── nginx.conf
        └── hub/                       ← Docker Compose Hub
            └── docker-compose.yml
```

---

## Principes d'architecture

### 1. Modular Monolith DDD-lite

Chaque module suit une structure en 3 couches :

```
Module/
  Domain/          ← Entités, Value Objects, Events, Repository Interfaces
                      Aucune dépendance framework. PHP pur.
  Application/     ← Commands, Queries, Handlers, DTOs
                      Orchestre le domaine. Dépend du domaine uniquement.
  Infrastructure/  ← Eloquent, HTTP Controllers, Jobs, Listeners
                      Implémente les interfaces du domaine. Dépend du framework.
```

**Règle d'or** : les dépendances ne vont que vers l'intérieur.  
`Infrastructure → Application → Domain` (jamais l'inverse).

### 2. Hub & Spoke Multi-Régions

```
hub.ceko.fr  ──────────────────────────────────────────
     │                                                  │
     ├──→ paris.ceko.fr   (DB propre, Redis propre)    │
     ├──→ lyon.ceko.fr    (DB propre, Redis propre)    │
     └──→ marseille.ceko.fr (DB propre, Redis propre)  │
```

Chaque région est **autonome** : elle peut fonctionner sans le Hub (panne tolérée). Le Hub ne fait que coordonner (SSO, dashboard consolidé, santé des régions).

### 3. API-First

La communication Hub ↔ Régions se fait exclusivement par API REST authentifiée par JWT. Aucune connexion directe inter-bases de données.

---

## Démarrage rapide (développement local)

```bash
# Cloner le dépôt
git clone git@github.com:cekoya/platform-v2.git
cd platform-v2

# Démarrer une région locale
cd infrastructure/docker/region
cp .env.example .env
# Éditer .env : APP_REGION=dev, DB_DATABASE=cekoya_dev, etc.
docker compose up -d

# Migrations et seed
docker compose exec app php artisan migrate --seed

# Accéder à l'application
open http://localhost:8080

# Démarrer le Hub
cd ../hub
docker compose up -d
open http://localhost:8090
```

---

## Conventions de code

- **PHP 8.3+** — readonly properties, enums, union types, named arguments
- `declare(strict_types=1)` dans tous les fichiers PHP
- **PHPStan niveau 5+** — analyse statique obligatoire en CI
- **Laravel Pint** — formatage automatique (style PSR-12 étendu)
- **Pest PHP** — tests unitaires et d'intégration
- **Commits** — Conventional Commits (`feat:`, `fix:`, `refactor:`, etc.)

---

## Équipe et contacts

| Rôle | Responsabilité |
|---|---|
| Tech Lead | Architecture, revues de code, décisions techniques |
| Backend Dev | Modules Domain + Application + Infrastructure |
| Frontend Dev | Composants Vue.js, Inertia pages |
| DevOps | Docker, CI/CD, déploiement régions |
| QA | Tests E2E, couverture, non-régression |
