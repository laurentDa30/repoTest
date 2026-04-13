# Architecture Cekoya V2

## Vue d'ensemble

Cekoya V2 repose sur un **Modular Monolith DDD-lite** déployé en mode **Hub & Spoke multi-régions**. Cette approche offre la simplicité opérationnelle d'un monolithe (un seul déploiement par région, pas de complexité microservices) tout en imposant une discipline architecturale forte via les bounded contexts DDD.

---

## Diagramme d'architecture global

```
┌─────────────────────────────────────────────────────────────────────────┐
│                          INTERNET / CDN (Cloudflare)                    │
└────────────┬────────────────────────────────────────────────────────────┘
             │ WAF, DDoS protection, TLS termination
             │
   ┌─────────▼──────────┐
   │    hub.ceko.fr      │  ← Application Hub Central
   │  ┌───────────────┐  │
   │  │ RegionRegistry│  │  - Registre des régions actives
   │  │ SSO / JWT     │  │  - Émission des tokens JWT
   │  │ CrossDashboard│  │  - Dashboard consolidé cross-régions
   │  │ HealthMonitor │  │  - Monitoring santé des régions
   │  └───────────────┘  │
   │  PostgreSQL (Hub)   │
   │  Redis (Hub)        │
   └────────┬────────────┘
            │  REST API (JWT auth)
            │
    ┌───────┴──────────────────────────────────────┐
    │                                               │
    ▼                                               ▼
┌───────────────────────────────┐   ┌───────────────────────────────┐
│    paris.ceko.fr              │   │    lyon.ceko.fr               │
│  ┌──────────────────────────┐ │   │  ┌──────────────────────────┐ │
│  │ Modular Monolith Laravel │ │   │  │ Modular Monolith Laravel │ │
│  │  ┌────────┐ ┌─────────┐  │ │   │  │  ┌────────┐ ┌─────────┐  │ │
│  │  │  IAM   │ │   CRM   │  │ │   │  │  │  IAM   │ │   CRM   │  │ │
│  │  └────────┘ └─────────┘  │ │   │  │  └────────┘ └─────────┘  │ │
│  │  ┌────────┐ ┌─────────┐  │ │   │  │  ┌────────┐ ┌─────────┐  │ │
│  │  │Catalog │ │ Orders  │  │ │   │  │  │Catalog │ │ Orders  │  │ │
│  │  └────────┘ └─────────┘  │ │   │  │  └────────┘ └─────────┘  │ │
│  │  ┌────────┐ ┌─────────┐  │ │   │  │  ┌────────┐ ┌─────────┐  │ │
│  │  │ Lines  │ │Billing  │  │ │   │  │  │ Lines  │ │Billing  │  │ │
│  │  └────────┘ └─────────┘  │ │   │  │  └────────┘ └─────────┘  │ │
│  │  ┌────────┐ ┌─────────┐  │ │   │  │  ┌────────┐ ┌─────────┐  │ │
│  │  │  CDR   │ │Emissions│  │ │   │  │  │  CDR   │ │Emissions│  │ │
│  │  └────────┘ └─────────┘  │ │   │  │  └────────┘ └─────────┘  │ │
│  └──────────────────────────┘ │   │  └──────────────────────────┘ │
│                               │   │                               │
│  ┌──────────┐ ┌─────────────┐ │   │  ┌──────────┐ ┌─────────────┐ │
│  │PostgreSQL│ │    Redis    │ │   │  │PostgreSQL│ │    Redis    │ │
│  │  (CDR    │ │  (Cache +   │ │   │  │  (CDR    │ │  (Cache +   │ │
│  │  partitionné)│ Horizon)  │ │   │  │  partitionné)│ Horizon)  │ │
│  └──────────┘ └─────────────┘ │   │  └──────────┘ └─────────────┘ │
└───────────────────────────────┘   └───────────────────────────────┘

Portails clients/ambassadeurs :
  client.paris.ceko.fr  → espace client Paris
  amba.paris.ceko.fr    → espace ambassadeur Paris
  client.lyon.ceko.fr   → espace client Lyon
```

---

## Architecture du Modular Monolith (région)

### Couches DDD-lite

```
┌─────────────────────────────────────────────────────────────┐
│                     HTTP Layer (Nginx)                      │
└────────────────────────────┬────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────┐
│              Infrastructure Layer                            │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐ │
│  │ HTTP         │  │  Eloquent    │  │  Jobs / Listeners  │ │
│  │ Controllers  │  │  Repositories│  │  (Events bridge)   │ │
│  │ (Inertia/API)│  │              │  │                    │ │
│  └──────┬───────┘  └──────┬───────┘  └─────────┬──────────┘ │
└─────────┼─────────────────┼───────────────────┼─────────────┘
          │                 │                   │
┌─────────▼─────────────────▼───────────────────▼─────────────┐
│              Application Layer                               │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────────────┐ │
│  │   Commands   │  │   Queries    │  │       DTOs         │ │
│  │   Handlers   │  │   Handlers   │  │                    │ │
│  └──────┬───────┘  └──────┬───────┘  └────────────────────┘ │
└─────────┼─────────────────┼───────────────────────────────────┘
          │                 │
┌─────────▼─────────────────▼───────────────────────────────────┐
│              Domain Layer (PHP pur, zéro framework)           │
│  ┌──────────┐  ┌──────────────┐  ┌──────────┐  ┌──────────┐  │
│  │ Entities │  │ Value Objects│  │  Events  │  │  Repos   │  │
│  │          │  │              │  │ (domain) │  │(interface│  │
│  └──────────┘  └──────────────┘  └──────────┘  └──────────┘  │
└────────────────────────────────────────────────────────────────┘
```

### Règle de dépendance

```
Infrastructure ──→ Application ──→ Domain
                                   (noyau, zéro dépendance externe)
```

Le domaine ne connaît pas Laravel, Eloquent, ou quoi que ce soit du framework. Il s'exprime en PHP pur avec des types stricts. Cela garantit sa testabilité et sa pérennité.

---

## Liste des modules et relations

### Modules de la région

| Module | Responsabilité | Dépend de |
|---|---|---|
| **IAM** | Utilisateurs, rôles, permissions, sessions, JWT | Shared |
| **CRM** | Clients (B2B), Prospects, Agences, Collaborateurs | IAM, Shared |
| **Catalog** | Produits, Services, Packages, Fournisseurs | Shared |
| **Orders** | Devis, Commandes, Portabilité, Provisioning | CRM, Catalog, Lines, Shared |
| **Lines** | Lignes téléphoniques, SIM, Appareils, Services par ligne | CRM, Catalog, Shared |
| **Billing** | Facturation, SEPA, Comptabilité, Programme Ambassadeur | CRM, Lines, Orders, Shared |
| **CDR** | Call Detail Records, Analytics, Agrégations | Lines, Shared |
| **Emissions** | Bilan carbone, Base Carbone ADEME | Lines, CDR, Shared |
| **Communications** | Mailing, Templates, Notifications push/email/SMS | IAM, CRM, Orders, Shared |
| **Operations** | Imports fournisseurs, Logs, Alertes, Monitoring interne | Tous les modules (read) |
| **Shared** | Money, DateRange, UUID, DomainEvent base | Aucun (noyau partagé) |

### Modules du Hub

| Module | Responsabilité |
|---|---|
| **RegionManagement** | CRUD des régions, clés API, statuts |
| **SSO** | Émission JWT cross-région, refresh tokens |
| **CrossRegionDashboard** | Agrégation métriques business toutes régions |
| **HealthMonitoring** | Ping regions, alertes panne, latence API |

### Flux inter-modules (région)

```
[CRM] ──(ClientCreated)──→ [Communications]  (email de bienvenue)
[Orders] ──(OrderValidated)──→ [Lines]        (provisioning ligne)
[Orders] ──(OrderValidated)──→ [Billing]      (génération facture)
[Lines] ──(LineActivated)──→ [CDR]             (début collecte CDR)
[CDR] ──(CDRImported)──→ [Billing]            (facturation à l'usage)
[CDR] ──(CDRImported)──→ [Emissions]          (calcul empreinte carbone)
[Billing] ──(InvoiceGenerated)──→ [Communications] (envoi facture)
```

Les événements circulent via le bus d'événements Laravel (Illuminate\Events). Aucun appel direct entre modules (sauf via interfaces/contracts définis dans Shared).

---

## Stratégie de base de données

### PostgreSQL par région

Chaque région possède sa propre instance PostgreSQL 16. Pas de base partagée inter-régions. Le Hub a sa propre base distincte.

```
hub_db          ← base du Hub (regions, sso_tokens, health_checks)
paris_db        ← base région Paris (tous les modules)
lyon_db         ← base région Lyon
marseille_db    ← base région Marseille
```

### Schéma par module

Chaque module utilise un **préfixe de table** pour éviter les collisions :

```sql
-- IAM
iam_users, iam_roles, iam_permissions, iam_user_roles, iam_sessions

-- CRM
crm_clients, crm_contacts, crm_prospects, crm_agencies, crm_collaborators

-- Catalog
catalog_products, catalog_services, catalog_packages, catalog_suppliers

-- Orders
orders_quotes, orders_orders, orders_portabilities, orders_provisions

-- Lines
lines_lines, lines_sims, lines_devices, lines_service_assignments

-- Billing
billing_invoices, billing_invoice_lines, billing_sepa_mandates, billing_payments

-- CDR (partitionné)
cdr_records (partitionnée par mois sur call_date)
cdr_aggregations_daily, cdr_aggregations_monthly

-- Emissions
emissions_reports, emissions_line_factors

-- Communications
comm_templates, comm_campaigns, comm_deliveries

-- Operations
ops_imports, ops_import_errors, ops_audit_logs, ops_alerts
```

### Migrations

Les migrations sont organisées par module :

```
database/migrations/
  iam/
    2024_01_01_000001_create_iam_users_table.php
    2024_01_01_000002_create_iam_roles_table.php
  crm/
    2024_01_01_000100_create_crm_clients_table.php
  cdr/
    2024_01_01_000600_create_cdr_records_partitioned_table.php
```

---

## CDR — Partitionnement PostgreSQL

```sql
-- Table mère (range partitioning par mois sur call_date)
CREATE TABLE cdr_records (
    id            BIGSERIAL,
    line_id       UUID          NOT NULL,
    direction     VARCHAR(10)   NOT NULL,  -- 'inbound' | 'outbound'
    from_number   VARCHAR(20)   NOT NULL,
    to_number     VARCHAR(20)   NOT NULL,
    duration_sec  INTEGER       NOT NULL DEFAULT 0,
    cost_ht       NUMERIC(10,4) NOT NULL DEFAULT 0,
    supplier      VARCHAR(30)   NOT NULL,  -- 'transatel' | 'unyc' | 'wazo'
    call_date     DATE          NOT NULL,
    imported_at   TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    raw_data      JSONB,
    PRIMARY KEY (id, call_date)
) PARTITION BY RANGE (call_date);

-- Partitions automatiques (générées par job mensuel)
CREATE TABLE cdr_records_2024_01 PARTITION OF cdr_records
    FOR VALUES FROM ('2024-01-01') TO ('2024-02-01');

CREATE TABLE cdr_records_2024_02 PARTITION OF cdr_records
    FOR VALUES FROM ('2024-02-01') TO ('2024-03-01');

-- Index par partition (créés automatiquement via héritage)
CREATE INDEX ON cdr_records (line_id, call_date);
CREATE INDEX ON cdr_records (supplier, call_date);
```

Évolution vers TimescaleDB (Phase 2) :
```sql
SELECT create_hypertable('cdr_records', 'call_date', chunk_time_interval => INTERVAL '1 month');
SELECT add_compression_policy('cdr_records', INTERVAL '3 months');
SELECT add_retention_policy('cdr_records', INTERVAL '13 months');
```

---

## Temps réel — Laravel Reverb

Laravel Reverb remplace Pusher/Soketi pour les WebSockets. Il est self-hosted dans le conteneur de l'application région.

### Canaux utilisés

```php
// Notifications opérateur temps réel
'region.{region_id}.operations'      // imports CDR, erreurs, alertes

// Dashboard live
'region.{region_id}.dashboard'        // métriques en temps réel

// Notifications client (portail client)
'client.{client_id}.notifications'   // nouveau devis, facture, etc.

// Hub (cross-régions)
'hub.global.health'                   // statuts des régions
'hub.global.metrics'                  // métriques consolidées
```

### Configuration Reverb

```php
// config/reverb.php (région)
'apps' => [
    [
        'app_id'  => env('REVERB_APP_ID'),
        'key'     => env('REVERB_APP_KEY'),
        'secret'  => env('REVERB_APP_SECRET'),
        'options' => [
            'host'   => '0.0.0.0',
            'port'   => 8080,
            'scheme' => 'https',
            'useTLS' => true,
        ],
    ],
],
```

---

## File d'attente — Redis + Laravel Horizon

### Queues par priorité

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-critical' => [
            'queue'      => ['critical'],
            'processes'  => 3,
            'tries'      => 3,
            'timeout'    => 60,
        ],
        'supervisor-default' => [
            'queue'      => ['default', 'notifications'],
            'processes'  => 5,
            'tries'      => 3,
            'timeout'    => 120,
        ],
        'supervisor-cdr' => [
            'queue'      => ['cdr-import'],
            'processes'  => 2,
            'tries'      => 5,
            'timeout'    => 600,  // imports CDR peuvent être longs
        ],
        'supervisor-reports' => [
            'queue'      => ['reports', 'emissions'],
            'processes'  => 2,
            'tries'      => 3,
            'timeout'    => 300,
        ],
    ],
],
```

### Jobs principaux

| Job | Queue | Déclencheur | Durée estimée |
|---|---|---|---|
| `ImportTransatelCDR` | cdr-import | Toutes les heures (cron) | 1-5 min |
| `ImportUnyxCDR` | cdr-import | Quotidien 06h00 | 5-15 min |
| `ImportWazoCDR` | cdr-import | Quotidien 06h30 | 2-5 min |
| `GenerateMonthlyInvoices` | critical | 1er du mois | 10-60 min |
| `SendInvoiceEmail` | notifications | Après génération facture | <1 min |
| `ComputeEmissionsReport` | emissions | Mensuel | 5-20 min |
| `CreateCDRPartition` | default | Fin de mois (job préventif) | <1 min |
| `AggregateCDRDaily` | reports | Quotidien 02h00 | 2-10 min |
| `PingHubRegionHealth` | default | Toutes les 5 min | <5 sec |

---

## Décisions d'architecture clés

### Pourquoi Modular Monolith et pas Microservices ?

Les microservices apportent une complexité opérationnelle disproportionnée pour une équipe de taille réduite. Le Modular Monolith offre :
- Déploiement simple (un seul artefact par région)
- Transactions ACID entre modules (pas de saga pattern nécessaire)
- Refactoring plus simple (les modules sont dans le même dépôt)
- Passage possible aux microservices module par module si besoin futur (les interfaces sont déjà définies)

### Pourquoi PostgreSQL et pas MySQL ?

- **Range Partitioning** natif et mature (critique pour CDR)
- **JSONB** avec indexation GIN (stockage du `raw_data` CDR sans surcoût)
- **TimescaleDB** disponible uniquement sur PostgreSQL
- **Window functions** avancées pour les analytics CDR
- **pg_partman** pour la gestion automatique des partitions
- Meilleure conformité SQL standard
- Performances supérieures sur les requêtes analytiques complexes

### Pourquoi Inertia.js + Vue.js 3 et pas une SPA séparée ?

Inertia.js permet de garder le routing Laravel (et donc les middlewares, la gestion des sessions côté serveur) tout en utilisant Vue.js 3 pour une UI moderne et réactive. Pas besoin de maintenir deux applications séparées (API + frontend), ce qui simplifie drastiquement le développement et le déploiement.
