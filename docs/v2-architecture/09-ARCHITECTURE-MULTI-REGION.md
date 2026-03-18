# Architecture Multi-Région (Hub & Spoke)

## 1. Vision

```
┌─────────────────────────────────────────────────────────────────┐
│                    CENTRAL (HUB)                               │
│                  central.cekoya.fr                             │
│                                                               │
│  ┌─────────┐  ┌───────────┐  ┌──────────┐  ┌────────────┐ │
│  │ Tableau  │  │  Gestion  │  │ Catalogue│  │ Connexion  │ │
│  │ de bord  │  │  régions  │  │  partagé │  │ à une      │ │
│  │ global   │  │           │  │          │  │ région     │ │
│  └─────────┘  └───────────┘  └──────────┘  └────────────┘ │
│                                                               │
│  BDD centrale : catalogue, config, registry, agrégats        │
└──────────┬──────────┬──────────┬──────────┬──────────────────┘
           │          │          │          │
     ┌─────▼──┐ ┌────▼───┐ ┌───▼────┐ ┌───▼────┐
     │ Région │ │ Région │ │ Région │ │ Région │
     │  IDF   │ │  PACA  │ │  Lyon  │ │  ...   │
     │        │ │        │ │        │ │        │
     │ App    │ │ App    │ │ App    │ │ App    │
     │ + BDD  │ │ + BDD  │ │ + BDD  │ │ + BDD  │
     │ propre │ │ propre │ │ propre │ │ propre │
     └────────┘ └────────┘ └────────┘ └────────┘
```

**Principe franchise** : chaque région est **autonome** avec sa propre application et base de données. Si la région PACA tombe, IDF et Lyon continuent de fonctionner normalement. Le Hub central supervise l'ensemble et distribue le catalogue commun.

---

## 2. Choix technique : `stancl/tenancy` (Database per Tenant)

### Pourquoi ce choix

| Critère | Approche retenue | Alternative rejetée |
|---------|-----------------|---------------------|
| Isolation des données | **BDD séparée par région** | `tenant_id` dans chaque table (trop fragile, risque de fuite de données inter-régions) |
| Isolation des pannes | **Containers séparés** (optionnel) OU **même app, BDD différente** | Déploiements totalement séparés (trop de DevOps pour 2 devs) |
| Codebase | **Unique** (un seul repo) | Forks par région (maintenance impossible) |
| Package | **stancl/tenancy v3** | Spatie multitenancy (moins mature pour database-per-tenant) |

### stancl/tenancy en 30 secondes

```php
// config/tenancy.php
'tenant_model' => App\Models\Tenant::class,  // = une Région

// Le package gère automatiquement :
// 1. La détection du tenant (via sous-domaine, header, ou path)
// 2. Le switch de connexion BDD
// 3. Les migrations par tenant
// 4. Le cache, les queues, le filesystem par tenant
```

**Fonctionnement** :
```
Requête → idf.cekoya.fr/clients
         └→ Middleware identifie tenant "idf"
            └→ Switch DB vers cekoya_idf
               └→ Client::all() requête cekoya_idf.clients
                  └→ Réponse isolée 100% IDF
```

---

## 3. Classification des données : Central vs Régional

### 3.1 Données CENTRALES (BDD `cekoya_central`)

Ce sont les **données de référence** partagées entre toutes les régions. Gérées uniquement par le Hub.

```
📁 Catalogue & Tarification
├── plans                          -- Forfaits disponibles
├── plan_rates                     -- Matrice tarifaire
├── options                        -- Options de forfait
├── device_sheets                  -- Fiches matériel catalogue
├── device_sheet_types             -- Types de matériel
├── device_types                   -- Catégories d'appareil
├── service_sheets                 -- Fiches service catalogue
├── service_families               -- Familles de service
├── supplier_sheets                -- Fournisseurs (Unyc, Transatel, IELO…)
├── geographical_zones             -- Zones géographiques
├── geographical_zone_supplier     -- Mapping zones ↔ fournisseurs
├── supplier_zone_countries        -- Mapping pays ↔ zones ↔ fournisseurs
├── countries                      -- Référentiel pays
├── telecom_types                  -- Types de service (mobile, fixe, internet)
├── call_types                     -- Types d'appel
├── units                          -- Unités de mesure
└── base_carbone                   -- Référentiel ADEME

📁 Configuration globale
├── roles                          -- Rôles (super-admin, admin-région, etc.)
├── permissions                    -- Permissions
├── role_has_permissions           -- Matrice RBAC
├── segmentations                  -- Segmentations client (sync vers régions)
├── payment_types                  -- Types de paiement
├── direct_debit_accounts          -- ⚠️ À CONFIRMER EN RÉUNION (IBAN société pour prélèvements)
├── news                           -- Actualités (diffusées vers toutes les régions ou ciblées)
├── news_regions                   -- Pivot news ↔ régions ciblées (NOUVEAU)
├── posts_types                    -- Types de campagnes (Newsletter, Prospection, etc.)
├── templates                      -- Templates d'email de base (les régions peuvent en créer localement)
├── prompts                        -- Prompts IA (si partagés)
└── project_types                  -- Types de projets RSE

📁 Registry & Agrégation
├── tenants                        -- Registre des régions (NOUVEAU)
├── tenant_configs                 -- Config par région (NOUVEAU)
├── regional_summaries             -- Agrégats par région (NOUVEAU)
├── global_users                   -- Super-admins (NOUVEAU)
└── global_user_tenant             -- Accès super-admin ↔ régions (NOUVEAU)
```

### 3.2 Données RÉGIONALES (BDD `cekoya_{region}`)

Chaque région a **sa propre copie complète** de ces tables. Les données ne se mélangent jamais.

```
📁 Clients & Relations (inclut les collaborateurs — entité pivot)
├── clients                        -- Clients de la région
├── collaborators                  -- Employés des clients (entité pivot : lignes + appareils + GLPI)
├── client_collaborator            -- Pivot collaborateurs ↔ clients
├── client_user                    -- Utilisateurs portail ↔ clients
├── client_todos / client_todo_messages
├── client_preferences
├── client_plan_preferences
├── client_device_sheet
├── client_project_carbon
├── client_orders
├── referents                      -- Contacts référents
├── groups / sub_groups            -- Agences/départements
├── addresses / address_book_entries
└── segmentations                  -- Copie locale (sync automatique depuis central)

📁 Telecom
├── lines                          -- Lignes de la région
├── sims                           -- SIM de la région
├── line_plan                      -- Affectations forfait ↔ ligne
├── portabilities / portabilities_pending
├── mobile_plan_buyings
└── alerts

📁 CDR / Consommations (séparés par type)
├── calls_mobile                   -- CDR mobile/fixe/internet (ex-calls) (RENOMMÉ V2)
├── calls_iot                      -- CDR IoT séparés (volume massif) (NOUVEAU V2)
├── calls_ucaas                    -- CDR Wazo (VoIP, conférence) (NOUVEAU V2)
├── cdr_files                      -- Fichiers CDR importés (tous types)
├── daily_call_summaries           -- Agrégation quotidienne mobile/fixe (NOUVEAU V2)
├── daily_iot_summaries            -- Agrégation quotidienne IoT (NOUVEAU V2)
├── daily_ucaas_summaries          -- Agrégation quotidienne Wazo (NOUVEAU V2)
├── monthly_summaries              -- Agrégation mensuelle (existante, à enrichir)
├── monthly_iot_summaries          -- Agrégation mensuelle IoT (NOUVEAU V2)
├── monthly_ucaas_summaries        -- Agrégation mensuelle UCaaS (NOUVEAU V2)
├── carbon_summaries
└── invoice_cdrs

📁 Infogérance
├── collaborators                  -- Employés des clients (parc informatique)
├── client_collaborator            -- Pivot collaborateurs ↔ clients
└── device_collaborator            -- Affectation appareil ↔ collaborateur

📁 Facturation
├── invoices (→ invoices_v2)       -- Factures de la région
├── invoice_lines                  -- (NOUVEAU V2)
├── invoiced
└── documents

📁 Stock & Matériel
├── devices
├── device_client / device_collaborator / device_group
├── device_line / device_stock
├── stocks
└── states

📁 Tickets & Commandes
├── tickets / tickets_messages / tickets_categories / tickets_labels
├── ticket_todos / ticket_todo_messages
├── orders / order_receives / order_client_status
├── todo_purchases / todo_purchase_order
└── carts

📁 Commercial
├── prospects
├── devis
├── partner_client / partner_has_partner / partner_payments
└── carbon_reports

📁 Utilisateurs régionaux
├── users                          -- Admins régionaux + utilisateurs portail
├── model_has_roles / model_has_permissions
├── personal_access_tokens
├── password_resets
├── user_logs / page_views
└── batches / jobs / job_batches / failed_jobs

📁 Contenu régional
├── posts / post_sends             -- Campagnes de la région
├── templates                      -- Templates locaux (en plus de ceux syncés du central)
├── events
├── errors
├── trees
├── project_carbon / project_stakeholder / stakeholders
├── media
├── tags / taggables
└── transactions
```

### 3.3 Données SYNCHRONISÉES (Central → Régions)

Certaines données de référence sont gérées centralement mais **répliquées** dans chaque BDD régionale pour que les régions fonctionnent de manière autonome (y compris si le Hub est temporairement indisponible).

```
Central → Région (push/sync)
├── plans, plan_rates, options
├── device_sheets, device_sheet_types, device_types
├── service_sheets, service_families
├── supplier_sheets, geographical_zones, supplier_zone_countries
├── countries
├── telecom_types, call_types, units
├── base_carbone
├── roles, permissions, role_has_permissions
├── segmentations                      -- Segmentation client
├── payment_types
├── posts_types                        -- Types de campagnes
├── templates                          -- Templates de base (les régions ajoutent les leurs)
├── news (+ news_regions pour ciblage) -- Actualités ciblées par région
└── project_types

Région → Central (push summaries)
├── regional_summaries (clients, lignes, revenue, CDR counts)
├── alertes critiques
└── métriques de santé (latence, erreurs, espace disque)
```

---

## 4. Architecture détaillée du Hub Central

### 4.1 Nouvelles tables centrales

```sql
-- Registre des régions (tenants)
CREATE TABLE `tenants` (
    `id` varchar(20) NOT NULL,           -- 'idf', 'paca', 'lyon'
    `name` varchar(100) NOT NULL,         -- 'Île-de-France'
    `domain` varchar(255) NOT NULL,       -- 'idf.cekoya.fr'
    `database` varchar(100) NOT NULL,     -- 'cekoya_idf'
    `db_host` varchar(255) DEFAULT '127.0.0.1',
    `db_port` int DEFAULT 3306,
    `status` enum('active','maintenance','suspended','creating') DEFAULT 'active',
    `settings` json DEFAULT NULL,         -- Config spécifique
    `admin_email` varchar(255) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `tenants_domain_unique` (`domain`)
) ENGINE=InnoDB;

-- Agrégats par région (mis à jour par cron/event)
CREATE TABLE `regional_summaries` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` varchar(20) NOT NULL,
    `date` date NOT NULL,
    `total_clients` int UNSIGNED DEFAULT 0,
    `active_clients` int UNSIGNED DEFAULT 0,
    `total_lines` int UNSIGNED DEFAULT 0,
    `active_lines` int UNSIGNED DEFAULT 0,
    `total_sims` int UNSIGNED DEFAULT 0,
    `total_devices` int UNSIGNED DEFAULT 0,
    `monthly_revenue_ht` decimal(12,2) DEFAULT 0,
    `monthly_revenue_ttc` decimal(12,2) DEFAULT 0,
    `unpaid_invoices_count` int UNSIGNED DEFAULT 0,
    `unpaid_invoices_amount` decimal(12,2) DEFAULT 0,
    `open_tickets_count` int UNSIGNED DEFAULT 0,
    `cdr_count_month` bigint UNSIGNED DEFAULT 0,
    `carbon_total_month` decimal(12,4) DEFAULT 0,
    `db_size_mb` int UNSIGNED DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `summary_unique` (`tenant_id`, `date`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB;

-- Super-admins (accès multi-régions)
CREATE TABLE `global_users` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `email` varchar(255) NOT NULL,
    `password` varchar(255) NOT NULL,
    `two_factor_secret` text DEFAULT NULL,
    `two_factor_recovery_codes` text DEFAULT NULL,
    `remember_token` varchar(255) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `global_users_email_unique` (`email`)
) ENGINE=InnoDB;

-- Accès super-admin ↔ régions
CREATE TABLE `global_user_tenant` (
    `global_user_id` bigint UNSIGNED NOT NULL,
    `tenant_id` varchar(20) NOT NULL,
    `role` enum('owner','admin','viewer') NOT NULL DEFAULT 'admin',
    PRIMARY KEY (`global_user_id`, `tenant_id`),
    FOREIGN KEY (`global_user_id`) REFERENCES `global_users` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Journal de synchronisation catalogue
CREATE TABLE `sync_logs` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id` varchar(20) NOT NULL,
    `sync_type` varchar(50) NOT NULL,     -- 'catalog', 'permissions', 'config'
    `status` enum('pending','running','completed','failed') NOT NULL,
    `items_synced` int UNSIGNED DEFAULT 0,
    `error_message` text DEFAULT NULL,
    `started_at` timestamp NULL DEFAULT NULL,
    `completed_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_tenant_type` (`tenant_id`, `sync_type`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB;
```

### 4.2 Fonctionnalités du Hub

```
┌─────────────────────────────────────────────────────┐
│                TABLEAU DE BORD GLOBAL                │
│                                                      │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌────────┐ │
│  │ 1 240    │ │ 18 500   │ │ 2.4M€    │ │ 12     │ │
│  │ clients  │ │ lignes   │ │ CA/mois  │ │ tickets│ │
│  │ total    │ │ actives  │ │ global   │ │ ouverts│ │
│  └──────────┘ └──────────┘ └──────────┘ └────────┘ │
│                                                      │
│  ┌─────────────────────────────────────────────────┐ │
│  │ Régions                        Statut    Action │ │
│  │ ● Île-de-France (380 clients)  ✅ OK    [→]    │ │
│  │ ● PACA (210 clients)           ✅ OK    [→]    │ │
│  │ ● Rhône-Alpes (175 clients)    ✅ OK    [→]    │ │
│  │ ● Grand-Est (0 clients)        🔧 Setup [→]   │ │
│  └─────────────────────────────────────────────────┘ │
│                                                      │
│  [Gérer le catalogue]  [Sync régions]  [Créer]      │
└─────────────────────────────────────────────────────┘
```

**Fonctionnalités** :
1. **Dashboard global** — Agrégation en temps réel (via `regional_summaries`)
2. **Gestion des régions** — Créer, suspendre, configurer une région
3. **Catalogue partagé** — CRUD sur les forfaits, matériels, services → synchronisés vers les régions
4. **Connexion directe** — Cliquer sur une région ouvre son app (SSO via token signé)
5. **Monitoring** — Santé de chaque région (latence, taille BDD, erreurs)
6. **Synchronisation** — Déclencher/monitorer la sync du catalogue vers les régions
7. **Gestion des super-admins** — Utilisateurs qui ont accès à plusieurs régions

---

## 5. Mécanismes de synchronisation

### 5.1 Central → Régions (Catalogue sync)

```
Scénario : un admin crée un nouveau forfait dans le catalogue central

1. Admin crée le plan sur central.cekoya.fr
2. Event CatalogItemUpdated dispatché
3. Job SyncCatalogToTenants :
   Pour chaque région active :
     a. Connecte à la BDD régionale
     b. Upsert le plan (INSERT ... ON DUPLICATE KEY UPDATE)
     c. Log dans sync_logs
4. Si une région est indisponible → retry avec backoff
```

```php
// app/Jobs/SyncCatalogToTenants.php
class SyncCatalogToTenants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $table,
        public array $data,
        public string $action = 'upsert'  // upsert | delete
    ) {}

    public function handle(): void
    {
        $tenants = Tenant::where('status', 'active')->get();

        foreach ($tenants as $tenant) {
            try {
                $tenant->run(function () {
                    match ($this->action) {
                        'upsert' => DB::table($this->table)
                            ->upsert($this->data, ['id'], array_keys($this->data)),
                        'delete' => DB::table($this->table)
                            ->whereIn('id', $this->data)
                            ->delete(),
                    };
                });

                SyncLog::create([
                    'tenant_id' => $tenant->id,
                    'sync_type' => 'catalog',
                    'status' => 'completed',
                    'items_synced' => 1,
                ]);
            } catch (\Throwable $e) {
                SyncLog::create([
                    'tenant_id' => $tenant->id,
                    'sync_type' => 'catalog',
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

                // Retry individuel pour cette région
                SyncCatalogToSingleTenant::dispatch($tenant, $this->table, $this->data)
                    ->delay(now()->addMinutes(5));
            }
        }
    }
}
```

### 5.2 Régions → Central (Summary push)

```
Scénario : cron quotidien à 02:00

1. Chaque région calcule ses métriques (clients, lignes, revenue…)
2. POST vers l'API centrale : /api/regions/{id}/summary
3. Le Hub met à jour regional_summaries
4. Le dashboard global est à jour
```

```php
// Exécuté dans chaque instance régionale (scheduler)
// app/Console/Commands/PushRegionalSummary.php
class PushRegionalSummary extends Command
{
    protected $signature = 'region:push-summary';

    public function handle(): void
    {
        $summary = [
            'date'                   => now()->toDateString(),
            'total_clients'          => Client::count(),
            'active_clients'         => Client::where('status', 1)->count(),
            'total_lines'            => Line::count(),
            'active_lines'           => Line::where('status', 'active')->count(),
            'total_sims'             => Sim::count(),
            'total_devices'          => Device::count(),
            'monthly_revenue_ht'     => Invoice::currentMonth()->sum('amount'),
            'unpaid_invoices_count'  => Invoice::where('is_paid', false)->count(),
            'open_tickets_count'     => Ticket::where('status', 'open')->count(),
            'cdr_count_month'        => Call::currentMonth()->count(),
            'db_size_mb'             => $this->getDatabaseSize(),
        ];

        Http::withToken(config('tenancy.central_api_token'))
            ->post(config('tenancy.central_url') . '/api/regions/summary', $summary);
    }
}
```

### 5.3 Diagramme des flux de données

```
                    ┌─────────────────────┐
                    │     HUB CENTRAL     │
                    │                     │
                    │  BDD cekoya_central │
                    │  ┌───────────────┐  │
                    │  │ tenants       │  │
                    │  │ regional_sum  │  │
                    │  │ global_users  │  │
                    │  │ plans ★       │  │  ★ = source de vérité
                    │  │ device_sheets★│  │
                    │  │ supplier_sh ★ │  │
                    │  │ sync_logs     │  │
                    │  └───────────────┘  │
                    └─────┬─────┬─────────┘
                          │     │
              Sync push ↓ │     │ ↑ Summary push
              (catalogue) │     │ (métriques)
                          │     │
          ┌───────────────┴─┐ ┌─┴───────────────┐
          │   RÉGION IDF    │ │   RÉGION PACA    │
          │                 │ │                  │
          │ BDD cekoya_idf  │ │ BDD cekoya_paca │
          │ ┌─────────────┐ │ │ ┌─────────────┐ │
          │ │ clients     │ │ │ │ clients     │ │
          │ │ lines       │ │ │ │ lines       │ │
          │ │ calls       │ │ │ │ calls       │ │
          │ │ invoices    │ │ │ │ invoices    │ │
          │ │ ...         │ │ │ │ ...         │ │
          │ │ plans ◇     │ │ │ │ plans ◇     │ │  ◇ = copie locale
          │ │ device_sh ◇ │ │ │ │ device_sh ◇ │ │
          │ └─────────────┘ │ │ └─────────────┘ │
          └─────────────────┘ └──────────────────┘
```

---

## 6. Authentification cross-régions (SSO)

### Le problème

Un super-admin sur le Hub doit pouvoir "entrer" dans n'importe quelle région sans recréer un compte.

### La solution : SSO par token signé

```
1. Super-admin clique sur "Accéder à IDF" sur le Hub
2. Le Hub génère un JWT signé :
   {
     "sub": "admin@cekoya.fr",
     "tenant": "idf",
     "role": "admin",
     "exp": +5min,
     "iat": now
   }
3. Redirect vers idf.cekoya.fr/auth/sso?token=<JWT>
4. La région IDF vérifie le JWT (clé publique partagée)
5. Trouve ou crée le user local → session Laravel classique
6. Redirect vers le dashboard IDF
```

```php
// Sur le Hub : générer le token SSO
class SsoController extends Controller
{
    public function redirectToTenant(Tenant $tenant)
    {
        $token = JWT::encode([
            'sub'    => auth()->user()->email,
            'name'   => auth()->user()->name,
            'tenant' => $tenant->id,
            'role'   => auth()->user()->roleForTenant($tenant),
            'iat'    => now()->timestamp,
            'exp'    => now()->addMinutes(5)->timestamp,
        ], config('tenancy.sso_private_key'), 'RS256');

        return redirect($tenant->domain . '/auth/sso?token=' . $token);
    }
}

// Sur chaque région : vérifier et connecter
class SsoLoginController extends Controller
{
    public function handle(Request $request)
    {
        $payload = JWT::decode(
            $request->query('token'),
            new Key(config('tenancy.sso_public_key'), 'RS256')
        );

        $user = User::firstOrCreate(
            ['email' => $payload->sub],
            ['name' => $payload->name, 'password' => Str::random(64)]
        );

        // Assigner le rôle si nécessaire
        $user->syncRoles([$payload->role]);

        Auth::login($user);

        return redirect('/dashboard');
    }
}
```

---

## 7. Provisioning d'une nouvelle région

### Processus automatisé

```
Admin Hub clique sur "Créer une région"
│
├── 1. Saisie des infos (nom, domaine, admin email)
│
├── 2. Job CreateTenantDatabase :
│   ├── CREATE DATABASE cekoya_{slug}
│   ├── Exécuter toutes les migrations tenant
│   ├── Seed les données de référence depuis le central
│   └── Créer le premier admin régional
│
├── 3. Job ConfigureTenantDomain :
│   ├── Ajouter le sous-domaine DNS (API Scaleway)
│   ├── Générer le certificat SSL (Let's Encrypt)
│   └── Mettre à jour le reverse proxy
│
├── 4. Notification à l'admin régional :
│   └── Email avec lien de connexion + setup MFA
│
└── 5. Statut → 'active'
```

```php
// app/Actions/CreateTenant.php
class CreateTenant
{
    public function execute(string $id, string $name, string $domain, string $adminEmail): Tenant
    {
        $tenant = Tenant::create([
            'id'          => $id,
            'name'        => $name,
            'domain'      => $domain,
            'database'    => 'cekoya_' . $id,
            'status'      => 'creating',
            'admin_email' => $adminEmail,
        ]);

        // Crée la BDD et exécute les migrations
        $tenant->createDatabase();
        Artisan::call('tenants:migrate', ['--tenants' => [$id]]);

        // Seed le catalogue depuis le central
        $tenant->run(function () {
            $this->syncCatalogFromCentral();
            $this->createInitialAdmin();
        });

        $tenant->update(['status' => 'active']);

        // Notification
        Mail::to($adminEmail)->send(new TenantCreatedMail($tenant));

        return $tenant;
    }

    private function syncCatalogFromCentral(): void
    {
        $tables = [
            'telecom_types', 'call_types', 'units',
            'countries', 'geographical_zones',
            'supplier_sheets', 'supplier_zone_countries',
            'plans', 'plan_rates', 'options',
            'device_sheets', 'device_sheet_types', 'device_types',
            'service_sheets', 'service_families',
            'base_carbone', 'payment_types', 'project_types',
            'roles', 'permissions', 'role_has_permissions',
        ];

        $centralDb = DB::connection('central');

        foreach ($tables as $table) {
            $data = $centralDb->table($table)->get()->toArray();
            if (count($data) > 0) {
                DB::table($table)->insert(
                    array_map(fn($row) => (array) $row, $data)
                );
            }
        }
    }
}
```

---

## 8. Structure des URLs et routing

### Domaines

```
central.cekoya.fr          → Hub central (super-admins)
idf.cekoya.fr              → Région IDF (admin)
idf-client.cekoya.fr       → Portail client IDF
idf-amba.cekoya.fr          → Portail ambassadeur IDF
paca.cekoya.fr             → Région PACA (admin)
paca-client.cekoya.fr      → Portail client PACA
...
```

### Alternative : path-based (plus simple)

```
app.cekoya.fr/central      → Hub
app.cekoya.fr/idf           → Région IDF (admin)
app.cekoya.fr/idf/client    → Portail client IDF
app.cekoya.fr/paca          → Région PACA (admin)
```

**Recommandation** : Commencer par le **subdomain-based** (plus propre, meilleur SEO, isolation claire). `stancl/tenancy` le supporte nativement.

### Configuration Laravel

```php
// routes/tenant.php — Chargées automatiquement pour chaque tenant
Route::middleware(['tenant', 'auth'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::resource('/clients', ClientController::class);
    Route::resource('/lines', LineController::class);
    // ... toutes les routes régionales
});

// routes/central.php — Hub uniquement
Route::domain('central.cekoya.fr')->middleware(['auth:central'])->group(function () {
    Route::get('/dashboard', CentralDashboardController::class);
    Route::resource('/regions', RegionController::class);
    Route::resource('/catalog/plans', CatalogPlanController::class);
    Route::post('/regions/{tenant}/sso', SsoController::class);
});
```

---

## 9. Infrastructure de déploiement

### Option A : Toutes les régions sur le même serveur (Phase initiale)

```
┌─────────────────────────────────────────┐
│           Serveur Scaleway              │
│                                         │
│  ┌─────────────────────────────────┐    │
│  │   Docker Compose                │    │
│  │                                 │    │
│  │  ┌──────────┐  ┌─────────────┐ │    │
│  │  │  Nginx   │  │  App Laravel│ │    │
│  │  │ (reverse │  │  (unique)   │ │    │
│  │  │  proxy)  │→ │             │ │    │
│  │  └──────────┘  └──────┬──────┘ │    │
│  │                       │        │    │
│  │  ┌────────────────────▼──────┐ │    │
│  │  │       MySQL 8.0          │ │    │
│  │  │  ┌──────────────────┐    │ │    │
│  │  │  │ cekoya_central   │    │ │    │
│  │  │  │ cekoya_idf       │    │ │    │
│  │  │  │ cekoya_paca      │    │ │    │
│  │  │  │ cekoya_lyon      │    │ │    │
│  │  │  └──────────────────┘    │ │    │
│  │  └──────────────────────────┘ │    │
│  │                                │    │
│  │  ┌──────────┐                  │    │
│  │  │  Redis   │                  │    │
│  │  └──────────┘                  │    │
│  └─────────────────────────────────┘    │
└─────────────────────────────────────────┘
```

**Avantages** : Simple, coût minimal, même app servie pour tous les domaines.
**Limites** : Pas d'isolation de crash au niveau serveur (mais isolation BDD).

### Option B : Un serveur par région (Phase scale)

```
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│  Serveur Central │  │  Serveur IDF     │  │  Serveur PACA    │
│                  │  │                  │  │                  │
│  central.cekoya  │  │  idf.cekoya      │  │  paca.cekoya     │
│  BDD: central    │  │  BDD: idf        │  │  BDD: paca       │
│  Redis: central  │  │  Redis: idf      │  │  Redis: paca     │
└──────────────────┘  └──────────────────┘  └──────────────────┘
         │                     │                     │
         └─────────────────────┼─────────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │   Load Balancer     │
                    │   (Scaleway LB)     │
                    └─────────────────────┘
```

**Avantages** : Isolation totale des pannes, scaling indépendant par région.
**Coût** : ~50-80€/mois par région supplémentaire.

**Recommandation** : Commencer par l'**Option A**, passer à l'**Option B** quand le nombre de régions ou la charge le justifie. Le code est identique dans les deux cas — seule l'infra change.

---

## 10. Migration de la V1 existante vers ce modèle

### Étape 1 : La V1 actuelle devient la première région

```
V1 actuelle (monolithe)
    → Devient "Région Siège" ou "Région IDF"
    → BDD existante = cekoya_idf (renommée ou alias)
    → Aucune migration de données nécessaire pour la première région !
```

### Étape 2 : Extraire le catalogue vers la BDD centrale

```
1. Créer cekoya_central
2. Copier les tables de référence (plans, device_sheets, etc.) vers central
3. Ajouter le mécanisme de sync
4. La région IDF lit encore ses propres tables catalogue (copie locale)
```

### Étape 3 : Ajouter stancl/tenancy au codebase

```
composer require stancl/tenancy
php artisan tenancy:install

# Configurer :
# - Le tenant model
# - La détection de tenant (subdomain)
# - Les routes tenant vs central
# - Les migrations tenant vs central
```

### Étape 4 : Provisionner la deuxième région

```
php artisan tenant:create paca "PACA" paca.cekoya.fr admin@paca.cekoya.fr
# → Crée la BDD, migrate, seed le catalogue, notifie l'admin
```

---

## 11. Impact sur la roadmap V2

| Phase | Durée révisée | Ajouts multi-région |
|-------|--------------|---------------------|
| **Phase 0** | 1 mois | Inchangée (Docker, CI/CD, Laravel 12) |
| **Phase 1** | 2-3 mois | + Installer stancl/tenancy, séparer routes central/tenant, créer BDD centrale |
| **Phase 2** | 2-3 mois | + Mécanisme de sync catalogue, SSO, dashboard central basique |
| **Phase 3** | 2-3 mois | + Provisioning automatisé, portails par région, monitoring multi-tenant |
| **Phase 4** | Continu | + Scaling infra (Option B), régions supplémentaires |

**Surcoût estimé** : +2-3 semaines sur Phase 1 et Phase 2 pour le setup multi-tenant. Ensuite, chaque nouvelle région se crée en quelques minutes.

---

## 12. Transfert de client entre tenants (régions)

### Le besoin

Un client géré par la région IDF peut être transféré vers la région PACA (réorganisation commerciale, rachat de portefeuille, etc.). Avec l'architecture database-per-tenant, ce n'est **pas un simple `UPDATE`** — c'est une **migration de données entre deux BDD**.

### Ce qui doit être transféré

```
🔴 CRITIQUE (le client ne fonctionne pas sans) :
├── clients (1 row + addresses, referents, groups, sub_groups)
├── collaborators + client_collaborator (N rows)
├── lines + sims + line_plan + portabilities (N rows)
├── devices + device_client + device_collaborator + device_group + device_line
├── client_user → users portail client (accès au portail)
├── client_preferences, client_plan_preferences, client_device_sheet
└── alerts

🟠 IMPORTANT (historique facturation et consommations) :
├── invoices_v2 + invoice_lines (peut être massif : 800+ lignes/facture × 12 mois × N années)
├── invoice_cdrs (MEDIUMBLOB compressé)
├── calls_mobile (⚠️ TRÈS volumineux : 100K-500K+ rows pour un client lourd)
├── daily_call_summaries + monthly_summaries
├── carbon_summaries + carbon_reports
└── devis

🟡 SECONDAIRE (support, commandes) :
├── tickets + tickets_messages + ticket_todos
├── orders + order_receives + client_orders + carts
├── partner_client (liens ambassadeur)
├── posts / post_sends (campagnes ciblées)
├── media (polymorphique → fichiers physiques à copier !)
├── events, errors (polymorphique)
└── transactions
```

### Le problème des IDs auto-increment

C'est le **principal obstacle technique**. Toutes les tables utilisent `bigint AUTO_INCREMENT`.

```
Exemple de conflit :

BDD IDF :                            BDD PACA :
  clients.id = 42 (Acme Corp)         clients.id = 42 (Autre société)
  lines.id = 100 (ligne d'Acme)       lines.id = 100 (ligne d'Autre)
  lines.client_id = 42                lines.client_id = 42

→ Impossible d'insérer le client IDF id=42 dans PACA : l'id existe déjà !
→ Si on attribue un nouvel id (ex: 501), il faut mettre à jour TOUTES les FK :
   lines.client_id, invoices_v2.client_id, tickets.client_id, etc.
→ Pire : les relations polymorphiques (addresses, media, events) stockent l'id
   dans morphable_id → cascade de mises à jour.
```

**Le catalogue partagé (plans, device_sheets, etc.) n'est PAS impacté** : les IDs sont synchronisés depuis le central, identiques dans toutes les régions.

### Stratégie recommandée : UUIDs sur les tables métier

**La solution la plus robuste pour permettre le transfert** est d'utiliser des UUIDs sur les tables qui portent des données client. Cela élimine les conflits d'IDs entre régions.

```
Tables à migrer vers UUID (V2) :

📁 Priorité haute (entités principales)
├── clients.uuid          — EXISTE DÉJÀ ✅ (colonne uuid présente en V1)
├── collaborators.uuid    — EXISTE DÉJÀ ✅
├── lines                 — À ajouter
├── sims                  — À ajouter
├── devices               — À ajouter
├── invoices_v2           — À ajouter (nouvelle table, on choisit dès le départ)
├── tickets               — À ajouter
└── orders                — À ajouter

📁 Priorité basse (tables filles, suivent la PK parente)
├── invoice_lines         — FK vers invoices_v2 (qui aura un UUID)
├── calls_mobile          — FK vers lines (qui aura un UUID)
├── daily_call_summaries  — FK vers lines + clients
└── etc.
```

**Approche hybride recommandée** : Garder `bigint AUTO_INCREMENT` comme PK (performance des JOINs) mais ajouter une colonne `uuid` comme **identifiant métier** unique globalement.

```sql
-- Pattern pour les tables transférables :
ALTER TABLE lines
    ADD COLUMN `uuid` char(36) NOT NULL AFTER `id`,
    ADD UNIQUE KEY `lines_uuid_unique` (`uuid`);

-- Remplissage des existants :
UPDATE lines SET uuid = UUID() WHERE uuid = '';
```

```php
// Trait Laravel pour les modèles transférables
trait HasUuid
{
    protected static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    // Route model binding par UUID (au lieu de l'id)
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
```

### Processus de transfert (Artisan command)

```php
// php artisan tenant:transfer-client {client_uuid} {source_tenant} {target_tenant}

class TransferClientBetweenTenants extends Command
{
    protected $signature = 'tenant:transfer-client
        {client_uuid : UUID du client à transférer}
        {source : Tenant source (ex: idf)}
        {target : Tenant cible (ex: paca)}
        {--dry-run : Simuler sans exécuter}
        {--skip-cdr : Ne pas transférer les CDR historiques (volume massif)}
        {--since= : Date minimale pour les données historiques (CDR, summaries)}';
}
```

**Étapes du transfert** :

```
Phase 1 — Pré-transfert (vérifications)
   1. Vérifier que le client existe dans le tenant source
   2. Vérifier que l'UUID n'existe PAS dans le tenant cible
   3. Estimer le volume (nombre de rows par table, taille CDR)
   4. Demander confirmation à l'opérateur (ou --force)

Phase 2 — Gel du client (éviter les écritures pendant le transfert)
   5. Mettre le client en status "transferring" (bloquer les écritures)
   6. Notifier les utilisateurs portail connectés

Phase 3 — Copie des données (dans l'ordre des dépendances)
   7. Copier clients + tables liées directement (addresses, referents, etc.)
   8. Copier collaborators + client_collaborator
   9. Copier lines + sims + line_plan + devices + pivots
   10. Copier invoices_v2 + invoice_lines
   11. Copier tickets + orders
   12. Copier CDR (optionnel, filtrable par date via --since)
       → Par batch de 10 000 rows pour les tables volumineuses
   13. Copier summaries (daily + monthly)
   14. Copier media (rows + fichiers physiques sur le filesystem)
   15. Copier users portail + client_user

Phase 4 — Vérification
   16. Comparer les counts par table entre source et cible
   17. Vérifier checksums sur les montants (sum invoices, sum CDR)
   18. Vérifier intégrité des FK dans la cible

Phase 5 — Bascule
   19. Supprimer les données du tenant source (soft-delete ou hard-delete)
   20. Activer le client dans le tenant cible (status = active)
   21. Mettre à jour regional_summaries des deux régions
   22. Logger l'opération dans un audit trail central

Phase 6 — Post-transfert
   23. Notifier les admins des deux régions
   24. Conserver un log de mapping (ancien tenant → nouveau) pendant 6 mois
```

### Tableau des volumes estimés pour un client lourd

| Table | Rows estimées | Taille | Temps estimé |
|-------|--------------|--------|-------------|
| clients + pivots | ~50 | < 1 Mo | < 1s |
| collaborators | ~200 | < 1 Mo | < 1s |
| lines + sims | ~500 | < 5 Mo | < 1s |
| invoices_v2 + invoice_lines | ~10K lignes/an × N ans | 10-50 Mo | 5-30s |
| calls_mobile (CDR) | 100K-500K rows | 50-200 Mo | 1-10 min |
| invoice_cdrs (BLOB) | ~60 (5 ans × 12) | 10-100 Mo | 5-30s |
| tickets + messages | ~200 | < 5 Mo | < 1s |
| devices + pivots | ~500 | < 5 Mo | < 1s |
| summaries (daily+monthly) | ~50K | < 10 Mo | < 5s |
| media (DB + fichiers) | Variable | Variable | Variable |
| **Total client lourd** | **~600K rows** | **~200-400 Mo** | **~5-15 min** |

### Cas spécial : transfert sans historique CDR

Pour les clients très lourds en CDR, le flag `--skip-cdr` permet de :
- Transférer le client actif (lignes, forfaits, devices, collaborateurs)
- Transférer les factures (pour la comptabilité)
- **Ne pas transférer** les CDR bruts (`calls_mobile`)
- Les CDR restent consultables dans l'ancienne région (en lecture seule) pendant une période définie
- Les nouveaux CDR (post-transfert) arrivent directement dans la nouvelle région

### Recommandations

| Recommandation | Priorité | Quand |
|---------------|----------|-------|
| Ajouter `uuid` sur les tables métier principales | **P1** | Phase 1 V2 (dès la refonte) |
| Utiliser `uuid` comme route model binding (URLs) | **P1** | Phase 1 V2 |
| Développer la commande `tenant:transfer-client` | **P2** | Phase 3 (quand la 2ème région existe) |
| Relations polymorphiques : utiliser `uuid` dans `morphable_id` | **P2** | Si faisable (changement important) |
| CDR : ajouter `client_uuid` en plus de `client_id` | **P3** | Pour faciliter les requêtes cross-tenant |

> **Note** : Le transfert de client est une opération **rare** (réorganisation commerciale, pas quotidienne). L'investissement dans les UUIDs se justifie aussi pour d'autres raisons : URLs non-prédictibles (sécurité), API publiques stables, interopérabilité.

---

## 13. Risques et mitigations

| Risque | Impact | Mitigation |
|--------|--------|------------|
| Désynchronisation catalogue entre régions | Forfait incorrect facturé | Sync transactionnelle + checksum de vérification |
| Latence SSO entre Hub et régions | Mauvaise UX admin | JWT auto-contenu (pas de callback nécessaire) |
| BDD régionale corrompue | Perte de données clients | Backups indépendants par région |
| Reporting cross-régions lent | Dashboard global laggy | Tables d'agrégation `regional_summaries` pré-calculées |
| Migrations de schéma désynchronisées | Erreurs SQL | Versioning strict des migrations + CI qui teste sur toutes les BDD |
| Complexité excessive pour 2 devs | Ralentissement développement | stancl/tenancy automatise 80% du travail. Le code métier ne change quasiment pas |
| Transfert de client entre régions impossible | Blocage commercial | UUIDs sur tables métier dès la Phase 1 + commande `tenant:transfer-client` en Phase 3 (voir section 12) |
