# 👨‍💻 Architecte Logiciel — Analyse & Recommandations

## 1. Diagnostic de l'existant

### Forces
- Laravel est un choix solide pour ce type d'application métier
- Livewire permet une productivité élevée avec une petite équipe
- L'automatisation Transatel via API montre la capacité d'intégration
- MFA déjà en place

### Faiblesses structurelles identifiées

| Problème | Impact | Sévérité |
|----------|--------|----------|
| **Monolithe non modulaire** | Couplage fort entre domaines, toute modification impacte potentiellement l'ensemble | CRITIQUE |
| **Aucune API interne** | Impossible de découpler les portails client/ambassadeur, pas de réutilisabilité | CRITIQUE |
| **Table `calls` à 12 Go sans `client_id`** | Pas de `client_id` direct → chaque requête CDR par client nécessite un JOIN via `lines` sur 12 Go+. Pas de partitionnement. | CRITIQUE |
| **Table `invoices` = JSON blob** | `doc` (JSON) contient l'intégralité de la facture ; GENERATED STORED extraient les champs résumés. Chaque SELECT charge le JSON complet → mémoire saturée | CRITIQUE |
| **3 portails dans 1 seul repo/app** | Déploiement monobloc, risque de régression croisée | ÉLEVÉ |
| **Pas de Docker pour les services** | Postgres, Redis, mail non conteneurisés → "ça marche sur ma machine", création de tenants (BDD) non reproductible entre devs | MOYEN |
| **Pas de CI/CD** | Pas de filet de sécurité, tests manuels | MOYEN |
| **Hébergement local** | SPOF, pas de scaling, pas de redondance | ÉLEVÉ |
| **Pas de cache applicatif** | Requêtes BDD répétées inutilement | MOYEN |
| **Laravel 10 / Livewire 2** | Versions en fin de support, dette qui s'accumule — **montée vers Laravel 11 + Livewire 4 en cours** | MOYEN |

## 2. Architecture cible : Monolithe Modulaire DDD-lite + Multi-région (Laravel 14)

### Pourquoi PAS de microservices

Avec 2 développeurs, les microservices sont **contre-productifs** :
- Complexité opérationnelle disproportionnée (networking, service discovery, distributed tracing)
- Overhead de déploiement multiplié
- La loi de Conway s'applique : 2 devs = 1 unité de déploiement optimal
- Le monolithe modulaire offre les mêmes bénéfices d'isolation sans la complexité distribuée

### Architecture Multi-région (Hub & Spoke)

**Exigence structurante** : chaque agence régionale dispose de sa propre application et BDD isolée (modèle franchise). Voir `09-ARCHITECTURE-MULTI-REGION.md` pour le détail complet.

```
Hub Central ──── catalogue partagé, monitoring, SSO
    ├── Région Réunion (App + BDD propre)  ← V1 actuelle, première région
    ├── Région Métropole (App + BDD propre)
    └── Région Mayotte (App + BDD propre)
```

**Implémentation** : `stancl/tenancy` v3 avec database-per-tenant. Codebase unique, switch automatique de BDD par sous-domaine. La V1 actuelle devient la première région (zéro migration de données initiale).

### Structure modulaire proposée (DDD-lite)

Chaque module adopte une organisation en 3 couches :
- **Domain/** : entités avec logique métier embarquée, Value Objects, Events
- **Application/** : Actions/Services applicatifs (cas d'usage), DTOs
- **Infrastructure/** : Eloquent repositories, mail, queue, API clients

#### Organisation des portails dans chaque module

Un module qui expose des fonctionnalités sur plusieurs portails (Hub, Tenant, Client, Ambassadeur) organise ses controllers, requests, resources et composants Livewire **par portail** dans le sous-dossier `Infrastructure/Http/`. Les routes sont également séparées par portail dans `routes/`.

**Principe** : le Domain et l'Application sont **agnostiques du portail** — seule la couche Http sait quel portail elle sert. Un `IotSimService` ou une `CreateQuotaAlertAction` est appelé indifféremment par un controller Tenant ou Client.

```
app/
├── Modules/
│   ├── IoT/                      # Exemple complet d'un module multi-portail
│   │   ├── Domain/               # Métier pur — agnostique du portail
│   │   │   ├── Models/
│   │   │   ├── ValueObjects/
│   │   │   ├── Events/
│   │   │   └── Contracts/
│   │   ├── Application/          # Cas d'usage — agnostique du portail
│   │   │   ├── Actions/
│   │   │   ├── Services/
│   │   │   ├── DTOs/
│   │   │   └── Listeners/
│   │   ├── Infrastructure/
│   │   │   ├── Repositories/
│   │   │   ├── Http/
│   │   │   │   ├── Tenant/              # Admin régional — gestion complète
│   │   │   │   │   ├── Controllers/     #   SimController, QuotaController...
│   │   │   │   │   ├── Requests/        #   CreateSimRequest, UpdateQuotaRequest...
│   │   │   │   │   ├── Resources/       #   SimResource, QuotaResource...
│   │   │   │   │   └── Livewire/        #   SimTable, QuotaDashboard...
│   │   │   │   └── Client/              # Portail client — vue limitée
│   │   │   │       ├── Controllers/     #   ClientIoTDashboardController...
│   │   │   │       ├── Requests/        #   (peu de requests, vues read-only)
│   │   │   │       ├── Resources/       #   ClientSimResource (champs restreints)
│   │   │   │       └── Livewire/        #   ClientIoTOverview...
│   │   │   ├── Jobs/
│   │   │   └── Providers/        # IoTServiceProvider (bindings + chargement routes)
│   │   ├── routes/
│   │   │   ├── tenant.php        # Routes admin IoT (prefix: iot/, name: tenant.iot.*)
│   │   │   └── client.php        # Routes client IoT (prefix: iot/, name: client.iot.*)
│   │   └── resources/views/
│   │       ├── tenant/           # Vues admin
│   │       └── client/           # Vues client
│   │
│   ├── Prospect/                 # Module mono-portail (tenant uniquement)
│   │   ├── Domain/
│   │   │   ├── Models/
│   │   │   ├── ValueObjects/
│   │   │   ├── Events/
│   │   │   └── Contracts/
│   │   ├── Application/
│   │   │   ├── Actions/
│   │   │   ├── Services/
│   │   │   ├── DTOs/
│   │   │   └── Listeners/
│   │   ├── Infrastructure/
│   │   │   ├── Repositories/
│   │   │   ├── Http/
│   │   │   │   └── Tenant/              # Tenant uniquement — pas de sous-dossier Client/
│   │   │   │       ├── Controllers/
│   │   │   │       ├── Resources/
│   │   │   │       └── Requests/
│   │   │   ├── Jobs/
│   │   │   └── Providers/
│   │   └── routes/
│   │       └── tenant.php
│   │
│   ├── Client/             # Clients, agences, référents, préférences, collaborateurs (entité pivot)
│   ├── Telecom/            # Lignes mobile/fixe/internet, SIMs, portabilités
│   ├── UCaaS/              # Wazo : communications unifiées, VoIP, collaboration
│   ├── Infogerance/        # Parc informatique, GLPI (s'appuie sur les collaborateurs du module Client)
│   ├── Catalog/            # Matériels, services, forfaits, fournisseurs
│   ├── Ticket/             # Tickets support, SAV, demandes (messages, catégories, labels, todos)
│   ├── Order/              # Commandes fournisseur/client, suivi, transit (s'appuie sur le module Ticket)
│   ├── Billing/            # Facturation unifiée, comptabilité, SEPA (collecte les lignes de tous les modules)
│   ├── CDR/                # Consommations — stockage et agrégation (partitionné par type : mobile, IoT, UCaaS)
│   ├── Stock/              # Gestion stock, SIMs physiques, appareils
│   ├── Integration/        # Connecteurs fournisseurs (Transatel, Unyc, Wazo, IELO, euroFIBER)
│   ├── Ambassador/         # Programme ambassadeur, paiements (portails : tenant + ambassador)
│   ├── Environment/        # Module RSE, émissions, captation
│   ├── Content/            # Rapports, nouveautés, mailing, templates
│   ├── Auth/               # Authentification, rôles, permissions
│   ├── Finance/            # Dashboard finance, analyse, exports
│   ├── IA/                 # Intelligence artificielle (anomalies CDR, scoring, optimisation forfaits, assistant)
│   └── Central/            # Hub multi-région — exclusivement hub central (catalogue, sync, dashboard global)
│
├── Shared/                 # Code partagé entre modules
│   ├── Traits/
│   ├── ValueObjects/
│   ├── Contracts/          # Interfaces inter-modules
│   │   └── Billable.php    # Contrat de facturation (tout module facturable l'implémente)
│   └── DTOs/
```

#### Matrice portails × modules

Chaque module n'expose des routes que sur les portails où il a des fonctionnalités. Le ServiceProvider de chaque module ne charge que les fichiers de routes pertinents selon le contexte d'exécution.

| Module | Hub Central | Tenant (admin) | Client | Ambassadeur |
|--------|:-----------:|:--------------:|:------:|:-----------:|
| **Central** | ✅ (exclusif) | — | — | — |
| **Client** | — | ✅ | ✅ | — |
| **Telecom** | — | ✅ | ✅ | — |
| **IoT** | — | ✅ | ✅ | — |
| **UCaaS** | — | ✅ | ✅ | — |
| **Infogerance** | — | ✅ | ✅ | — |
| **Billing** | — | ✅ | ✅ | — |
| **CDR** | — | ✅ | ✅ | — |
| **Stock** | — | ✅ | — | — |
| **Catalog** | ✅ (sync) | ✅ | — | — |
| **Ticket** | — | ✅ | ✅ | — |
| **Order** | — | ✅ | ✅ | — |
| **Prospect** | — | ✅ | — | — |
| **Ambassador** | — | ✅ | — | ✅ |
| **Environment** | — | ✅ | ✅ | — |
| **Finance** | ✅ (global) | ✅ | — | — |
| **Content** | — | ✅ | ✅ | ✅ |
| **Auth** | ✅ | ✅ | ✅ | ✅ |
| **IA** | — | ✅ | ✅ | — |
| **Integration** | — | ✅ (interne) | — | — |

#### Chargement conditionnel des routes par portail

Chaque module charge ses routes **uniquement** dans le bon contexte via son ServiceProvider. Le contexte est déterminé par `stancl/tenancy` (central vs tenant) et par le domaine (tenant vs client vs ambassadeur).

```php
// app/Modules/IoT/Infrastructure/Providers/IoTServiceProvider.php

public function boot(): void
{
    // Contexte tenant (admin régional OU portail client/ambassadeur)
    if ($this->app->bound('tenancy') && tenancy()->initialized) {

        // Routes admin régional — domaine : {region}.cekoya.fr
        if ($this->isTenantPortal()) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/tenant.php');
        }

        // Routes client — domaine : {region}-client.cekoya.fr
        if ($this->isClientPortal()) {
            $this->loadRoutesFrom(__DIR__ . '/../../routes/client.php');
        }
    }

    // Le module IoT n'a pas de routes sur le Hub central
    // → pas de chargement en contexte central
}
```

```php
// app/Modules/Central/Infrastructure/Providers/CentralServiceProvider.php

public function boot(): void
{
    // Le module Central ne charge ses routes QUE sur le Hub
    if ($this->isCentralDomain()) {
        $this->loadRoutesFrom(__DIR__ . '/../../routes/central.php');
    }
    // En contexte tenant → le module ne fait rien, il n'est pas chargé
}
```

**Helpers de détection du portail** (trait partagé) :

```php
// app/Shared/Traits/DetectsPortal.php

trait DetectsPortal
{
    protected function isCentralDomain(): bool
    {
        return request()->getHost() === config('tenancy.central_domains')[0];
    }

    protected function isTenantPortal(): bool
    {
        $host = request()->getHost();
        return !$this->isCentralDomain()
            && !str_contains($host, '-client.')
            && !str_contains($host, '-amba.');
    }

    protected function isClientPortal(): bool
    {
        return str_contains(request()->getHost(), '-client.');
    }

    protected function isAmbassadorPortal(): bool
    {
        return str_contains(request()->getHost(), '-amba.');
    }
}
```

#### Convention de nommage des routes

Chaque fichier de routes utilise un préfixe de nom qui identifie le portail, suivi du nom du module :

```php
// app/Modules/IoT/routes/tenant.php
Route::middleware(['web', 'auth', 'tenant'])
    ->prefix('iot')
    ->name('tenant.iot.')
    ->group(function () {
        Route::get('/sims', [SimController::class, 'index'])->name('sims.index');
        Route::get('/quotas', [QuotaController::class, 'index'])->name('quotas.index');
        Route::post('/sims', [SimController::class, 'store'])->name('sims.store');
        // ... gestion complète
    });

// app/Modules/IoT/routes/client.php
Route::middleware(['web', 'auth', 'client', 'client.ownership'])
    ->prefix('iot')
    ->name('client.iot.')
    ->group(function () {
        Route::get('/dashboard', [ClientIoTDashboardController::class, 'index'])->name('dashboard');
        Route::get('/sims', [ClientSimController::class, 'index'])->name('sims.index');
        // ... vue limitée, lecture seule principalement
    });

// app/Modules/Central/routes/central.php
Route::middleware(['web', 'auth', 'central', 'role:super_admin'])
    ->prefix('hub')
    ->name('central.')
    ->group(function () {
        Route::get('/dashboard', [HubDashboardController::class, 'index'])->name('dashboard');
        Route::get('/regions', [RegionController::class, 'index'])->name('regions.index');
        // ...
    });
```

Cela donne des noms de routes lisibles et sans ambiguïté :
- `tenant.iot.sims.index` → admin gère les SIMs IoT
- `client.iot.sims.index` → client voit ses SIMs IoT
- `central.dashboard` → dashboard Hub

> **Note multi-région** : Les modules s'exécutent dans chaque instance régionale. Le module `Central/` ne tourne que sur le Hub et gère la synchronisation catalogue, le registry des régions et le SSO. Son ServiceProvider ne charge rien en contexte tenant.

### Séparation des domaines métier : Telecom vs IoT vs UCaaS vs Infogérance

**Pourquoi séparer ?**

| Aspect | Telecom (mobile/fixe/internet) | IoT | UCaaS (Wazo) | Infogérance |
|--------|-------------------------------|-----|-------------|-------------|
| Volume CDR | Moyen (~500K/mois) | Très élevé (~millions/mois) | Variable | Aucun CDR |
| Type de conso | Voix, SMS, data, MMS | Data quasi-exclusivement | VoIP, conférence, messaging | N/A |
| Quotas | Forfaits classiques | Quotas data très bas, alertes spécifiques | Minutes VoIP, postes | N/A |
| Fournisseur | Transatel, Unyc | Transatel (SIMs M2M) | Wazo | GLPI, interne |
| Facturation | Lignes de facture telecom | Lignes de facture IoT | Lignes de facture UCaaS | Lignes de facture infogérance |

**Ce qui les unit** : la facture client. Un client peut avoir des lignes mobile + des SIMs IoT + des postes Wazo + de l'infogérance, tout sur **une seule facture**.

**La solution** : le contrat `Billable` (interface partagée).

```php
// app/Shared/Contracts/Billable.php
interface Billable
{
    /**
     * Retourne les lignes de facturation pour une période donnée.
     * Chaque module facturable implémente cette interface.
     */
    public function getInvoiceLines(Client $client, CarbonPeriod $period): Collection;
}

// Chaque module implémente Billable :
// - Telecom\Application\Services\TelecomBillingService implements Billable
// - IoT\Application\Services\IoTBillingService implements Billable
// - UCaaS\Application\Services\UCaaSBillingService implements Billable
// - Infogerance\Application\Services\InfogeranceBillingService implements Billable

// Le module Billing collecte toutes les lignes :
class GenerateInvoiceAction
{
    public function __construct(
        private readonly array $billableServices // Injecté via ServiceProvider
    ) {}

    public function execute(Client $client, CarbonPeriod $period): Invoice
    {
        $invoiceLines = collect();

        foreach ($this->billableServices as $service) {
            $invoiceLines = $invoiceLines->merge(
                $service->getInvoiceLines($client, $period)
            );
        }

        // Trier par type (telecom, IoT, UCaaS, infogérance)
        // Calculer les totaux
        // Générer la facture
    }
}
```

**Avantage** : chaque module gère ses propres données et sa propre logique de calcul. Le module Billing ne connaît pas les détails — il collecte des lignes de facture via l'interface. Ajouter un nouveau domaine facturable = implémenter `Billable`, zéro modification du module Billing.

### Le collaborateur : entité pivot et centre de coût pour le client

Le collaborateur est un **employé du client** (pas un utilisateur de la plateforme). C'est **le client qui paye** pour tout ce que le collaborateur détient :
- **Des lignes** (mobile, fixe, IoT) → lien `lines.collaborator_id` → coût forfait + CDR
- **Des appareils** (téléphone, PC, tablette) → lien `device_collaborator` → coût leasing/achat
- **Un profil d'infogérance** (GLPI, parc IT) → lien `collaborators.id_glpi` → coût prestation

Le collaborateur est donc un **centre de coût** : il permet au client de suivre combien lui coûte chaque employé (somme des lignes, appareils et prestations). C'est pourquoi il vit dans le module **Client** (il appartient au client), pas dans le module Infogérance. Les autres modules accèdent au collaborateur via le contrat exposé par le module Client.

```
Module Client (propriétaire du collaborateur)
│
│   collaborators
│   ├── client_id        → Client propriétaire
│   ├── designation, lastname, firstname, email, phone
│   ├── profil_id        → Profil métier
│   ├── is_register_to_glpi, id_glpi → Lien infogérance
│   └── ...
│
│   Expose : CollaboratorContract (interface)
│
├───────────────────────────────────────────────────┐
│                                                   │
▼                          ▼                        ▼
Module Telecom             Module Stock             Module Infogérance
(lines.collaborator_id)    (device_collaborator)    (GLPI, parc IT)
→ Le client paye les       → Le client paye les     → Le client paye les
  forfaits + CDR du collab.   appareils du collab.     prestations IT du collab.
  (lignes + consommations)    (leasing/achat)          (GLPI, interventions)
```

**Règle** : les modules Telecom, Stock et Infogérance ne manipulent **jamais** directement le modèle `Collaborator`. Ils passent par le contrat `CollaboratorContract` exposé par le module Client.

```php
// app/Modules/Client/Domain/Contracts/CollaboratorContract.php
interface CollaboratorContract
{
    public function findForClient(int $clientId): Collection;
    public function getWithLines(int $collaboratorId): CollaboratorWithLinesDTO;
    public function getWithDevices(int $collaboratorId): CollaboratorWithDevicesDTO;

    /** Coût total d'un collaborateur pour le client (lignes + appareils + prestations) */
    public function getCostForClient(int $collaboratorId): CollaboratorCostDTO;

    /** Coût de tous les collaborateurs d'un client (vue récapitulative) */
    public function getCostSummaryForClient(int $clientId): Collection;
}
```

### Séparation des CDR par type

Le module **CDR** reste centralisé (un seul module gère le stockage et l'agrégation), mais les données sont **partitionnées par type** pour éviter que les volumes IoT polluent les requêtes telecom.

```sql
-- Option A : Tables séparées (recommandé — plus simple, plus performant)
calls_mobile       -- CDR mobile/fixe/internet (Transatel, Unyc)
calls_iot          -- CDR IoT (Transatel M2M) — volume massif
calls_ucaas        -- CDR Wazo (VoIP, conférence)

-- Chaque table a la même structure de base (colonnes communes)
-- + colonnes spécifiques au type

-- Les tables d'agrégation sont aussi séparées :
daily_call_summaries          -- Agrégation mobile/fixe/internet
daily_iot_summaries           -- Agrégation IoT (par SIM, par quota)
daily_ucaas_summaries         -- Agrégation Wazo (par poste, par type d'appel)

-- Option B : Table unique partitionnée par telecom_type_id
-- (plus simple en code, mais les volumes IoT ralentissent les requêtes mobile)
-- → NON RECOMMANDÉ si le volume IoT est significatif
```

**Pourquoi des tables séparées plutôt qu'une partition ?**
- Les CDR IoT sont **massivement plus nombreux** (capteurs qui envoient des données toutes les minutes)
- Les requêtes admin sur les CDR mobile ne doivent **jamais** scanner les CDR IoT
- Les dashboards IoT et Telecom sont **différents** (pas les mêmes métriques)
- L'archivage peut avoir des **politiques différentes** (IoT archivé plus tôt car moins de valeur unitaire)
- Le `UNIQUE KEY` sur `provider_call_id` est plus performant sur des tables plus petites

### Règles d'isolation inter-modules (DDD-lite)

**Un module :**
- Expose des **contrats** (interfaces dans `Domain/Contracts/`)
- Peut **émettre des événements** (dans `Domain/Events/`)
- Peut exposer des **services officiels** (dans `Application/Services/`)

**Un module ne doit PAS :**
- Importer les modèles internes d'un autre module
- Accéder directement à la base d'un autre domaine
- Créer de dépendances circulaires

**Communication entre modules** (par ordre de préférence) :
1. **Par interface** (recommandé) : dépendance vers un contrat, binding via ServiceProvider, couplage maîtrisé
2. **Par événements** : faible couplage, idéal pour actions secondaires ou async, favorise la scalabilité
3. **Appels directs** : à éviter absolument (couplage fort)

**Isolation stricte = architecture maintenable et extractible.**

Règles complémentaires :
1. **Chaque module possède ses propres migrations** (préfixées par module)
2. **API Resources** systématiques — chaque module expose ses données via des Resources JSON
3. **La logique métier vit dans Domain/** — les entités savent se valider, calculer leurs états, vérifier leurs invariants
4. **L'infrastructure est interchangeable** — le jour où on change de driver (ex: Scout database → Meilisearch), seul `Infrastructure/` est impacté

### Pattern API — Périmètre clarifié

L'API REST `/api/v1/*` n'est **pas** utilisée pour l'architecture interne Hub ↔ régions. Elle est dédiée aux **interactions externes** :

| Consommateur de l'API | Usage |
|----------------------|-------|
| **Portail client** (espace client) | Consultation consos, factures, lignes, tickets |
| **Intégrations partenaires** | Fournisseurs, connecteurs tiers |
| **Future app mobile** | Accès client mobile |

Le **Hub central** et les **portails admin régionaux** restent en **architecture classique Livewire** (rendu serveur, pas d'API REST entre eux). La synchronisation Hub ↔ régions passe par des mécanismes internes (sync BDD via stancl/tenancy, events Laravel).

```
┌─────────────────────────────────────────────────────┐
│                   Laravel 14                         │
│                                                      │
│  ┌──────────────────────┐  ┌──────────────────┐    │
│  │     Livewire 4       │  │   API REST       │    │
│  │ Hub + Admin régional │  │   /api/v1/*      │    │
│  │ + Client + Ambassa.  │  │ (clients, mobile │    │
│  │ (archi classique)    │  │  partenaires)    │    │
│  └──────────┬───────────┘  └────────┬─────────┘    │
│             │                       │               │
│             ▼                       ▼               │
│  ┌──────────────────────────────────────────────┐   │
│  │         Couche Services / Actions             │   │
│  │         (logique métier partagée)             │   │
│  └──────────────────────────────────────────────┘   │
│             │                       │               │
│             ▼                       ▼               │
│  ┌──────────────────────────────────────────────┐   │
│  │              Repositories / Models            │   │
│  └──────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
```

**Principe clé** : Les composants Livewire et l'API REST appellent les mêmes Services/Actions. La logique métier n'est jamais dans un contrôleur ou un composant Livewire.

## 3. Stratégie pour la table `calls` (CDR)

### Problème actuel (confirmé par le schéma SQL)
- 1M enregistrements/mois, ~12 Go actuellement
- **Pas de `client_id`** sur la table `calls` → chaque requête par client fait un JOIN via `lines`
- Index existants : `idx_line_date_price`, `idx_calls_date`, `uncharged` → corrects mais insuffisants sans `client_id`
- La table `monthly_summaries` existe **déjà** (par ligne, avec carbone) mais manque les colonnes financières et l'agrégation quotidienne

### Solution : Quick wins + Agrégation

```sql
-- QUICK WIN #1 : Ajouter client_id dénormalisé (1 jour)
ALTER TABLE calls ADD COLUMN client_id BIGINT UNSIGNED AFTER line_id;
ALTER TABLE calls ADD INDEX idx_calls_client_date (client_id, date);
-- Backfill via job batch :
-- UPDATE calls c JOIN lines l ON c.line_id = l.id SET c.client_id = l.client_id;

-- QUICK WIN #2 : Enrichir monthly_summaries existante
ALTER TABLE monthly_summaries ADD COLUMN client_id BIGINT UNSIGNED AFTER line_id;
ALTER TABLE monthly_summaries ADD COLUMN total_charge DECIMAL(15,4) DEFAULT 0;
ALTER TABLE monthly_summaries ADD COLUMN total_price DECIMAL(15,4) DEFAULT 0;
ALTER TABLE monthly_summaries ADD INDEX idx_client_month (client_id, month);

-- Nouvelle table d'agrégation quotidienne
CREATE TABLE daily_call_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    telecom_type_id BIGINT UNSIGNED DEFAULT NULL,
    total_calls INT UNSIGNED DEFAULT 0,
    total_sms INT UNSIGNED DEFAULT 0,
    total_mms INT UNSIGNED DEFAULT 0,
    total_data_bytes BIGINT UNSIGNED DEFAULT 0,
    total_duration_seconds BIGINT UNSIGNED DEFAULT 0,
    total_charge DECIMAL(15,4) DEFAULT 0,
    total_price DECIMAL(15,4) DEFAULT 0,
    out_of_plan_count INT UNSIGNED DEFAULT 0,
    out_of_plan_cost DECIMAL(15,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY daily_unique (client_id, line_id, date, telecom_type_id),
    KEY idx_client_date (client_id, date),
    KEY idx_line_date (line_id, date)
) ENGINE=InnoDB;
```

> **Note multi-région** : Chaque BDD régionale contient ses propres `calls`, `daily_call_summaries` et `monthly_summaries`. Les CDR ne sont jamais partagés entre régions.

### Politique d'archivage
- **0–12 mois** : données détaillées en ligne (partitions actives)
- **12–36 mois** : données détaillées archivées (export vers stockage objet S3), agrégations conservées en base
- **> 36 mois** : seules les agrégations mensuelles sont conservées

**Gain estimé** : la table active `calls` reste sous 2-3 Go au lieu de croître indéfiniment. Les requêtes de reporting tapent sur les tables d'agrégation (quelques Mo) au lieu de scanner 12 Go+.

### Job d'agrégation

```php
// app/Modules/CDR/Jobs/AggregateDailyCallsJob.php
// Exécuté chaque nuit via scheduler
// 1. Agrège les CDR du jour J-1 dans daily_call_summaries
// 2. Met à jour monthly_call_summaries
// 3. Émet un event CDRAggregated pour déclencher la facturation si besoin
```

## 4. Stratégie pour la table `invoices` (confirmé par schéma)

### Problème réel identifié

La colonne `invoices.doc` (JSON) contient **l'intégralité** de chaque facture. Les colonnes `date`, `amount`, `amount_tax`, `paid`, `locked`, `label`, `number` sont des `GENERATED ALWAYS AS ... STORED` extraites du JSON.

```sql
-- Structure actuelle (problématique)
`doc` json DEFAULT NULL,
`date` date GENERATED ALWAYS AS (json_unquote(json_extract(`doc`,'$.date'))) STORED,
`amount` double(8,2) GENERATED ALWAYS AS (json_unquote(json_extract(`doc`,'$.summary.total'))) STORED,
-- ... etc.
```

**Impact** : chaque `SELECT` (même sur une liste) charge le JSON complet. Un client avec 200 lignes = un JSON de 50-200 Ko par facture.

### Solution : Normalisation progressive

1. **Créer `invoices_v2`** (en-tête normalisé) + **`invoice_lines`** (lignes détaillées) — voir `08-ANALYSE-SCHEMA-BDD.md` pour le schéma exact
2. **Migration par script** : parser le JSON `doc` de chaque facture existante → insérer en-tête + lignes
3. **Dual-write** pendant la transition : la V2 écrit dans les deux tables
4. **Totaux pré-calculés** : `amount_ht`, `amount_tva`, `amount_ttc` en colonnes normales (pas de GENERATED)
5. **Génération PDF asynchrone** via Queue billing → stockage S3
6. **Index composites** : `(client_id, date)`, `(client_id, is_locked, is_paid)`

> **Note multi-région** : Chaque BDD régionale contient ses propres factures. Le Hub central agrège uniquement les totaux dans `regional_summaries`.

## 5. Infrastructure de développement

### Docker : services uniquement, app en natif

L'app PHP tourne en **natif** (Laravel Herd, Valet ou `php artisan serve`). Seuls les **services annexes** sont conteneurisés — c'est plus rapide pour le dev, pas de volume mount lent, hot reload natif.

L'app sera dockerisée intégralement **uniquement pour le staging/production**.

```yaml
# docker-compose.yml — services uniquement
services:
  postgres:
    image: postgres:16
    environment:
      POSTGRES_USER: cekoya
      POSTGRES_PASSWORD: secret
    ports:
      - "5432:5432"
    volumes:
      - pgdata:/var/lib/postgresql/data

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  mailpit:
    image: axllent/mailpit
    ports:
      - "8025:8025"   # UI web
      - "1025:1025"   # SMTP

volumes:
  pgdata:
```

**Pourquoi Docker dès le jour 1 pour les services :**
- **Parité dev** : avec multi-tenant database-per-tenant, chaque dev doit pouvoir créer des BDD dynamiquement. Docker Postgres = une commande.
- **Multi-domaines locaux** : résolution de `hub.cekoya.local`, `reunion.cekoya.local`, `reunion-client.cekoya.local` — un reverse proxy Traefik/Caddy conteneurisé rend ça trivial.
- **Services annexes** : Redis (cache + queue), Mailpit (mail catcher), éventuellement Meilisearch — aucun à installer sur la machine hôte.

### Tenant = Base de données (stancl/tenancy)

Un tenant correspond à **une base de données isolée**. La création d'un tenant est une opération SQL standard, indépendante de Docker.

```
PostgreSQL
├── cekoya_central          # Base centrale (Hub)
│   ├── tenants              # Registry des tenants
│   ├── domains              # Mapping domaine → tenant
│   ├── central_catalog      # Catalogue maître
│   └── users                # Users centraux (SSO)
│
├── cekoya_reunion           # Tenant Réunion (= V1 migrée)
│   ├── clients, prospects, telecom_lines, iot_sims...
│   └── ...                  # Toutes les tables métier
│
├── cekoya_metropole         # Tenant Métropole
│   └── ...                  # Même schéma, données isolées
│
└── cekoya_mayotte           # Tenant Mayotte
    └── ...
```

**Création d'un tenant** — 3 étapes automatisées par stancl/tenancy :

```php
// Fonctionne partout — Docker ou pas, du moment que Postgres est accessible
$tenant = Tenant::create([
    'id' => 'reunion',
    'name' => 'Cekoya Réunion',
]);
// stancl/tenancy déclenche automatiquement :
// 1. CREATE DATABASE cekoya_reunion
// 2. php artisan tenants:migrate --tenants=reunion
// 3. Événements TenantCreated → CreateDatabase → MigrateDatabase

// Ajout du domaine associé
$tenant->domains()->create(['domain' => 'reunion.cekoya.fr']);
```

**Prérequis unique** : l'utilisateur PostgreSQL doit avoir le droit `CREATE DATABASE`. C'est une config Postgres, pas une dépendance Docker.

**Modes de création de tenants :**
| Contexte | Méthode |
|----------|---------|
| Dev local | `php artisan tenant:create reunion` (commande artisan) |
| Dev local | `php artisan db:seed --class=TenantSeeder` (seeder avec données de test) |
| Production | Interface Hub central (UI d'admin réservée super_admin) |
| CI/CD | Seeder automatique dans le pipeline de test |

## 6. Risques identifiés

| Risque | Probabilité | Impact | Mitigation |
|--------|-------------|--------|------------|
| Migration trop ambitieuse pour 2 devs | Élevée | Critique | Strangler Fig strict, un module à la fois |
| Perte de données pendant la migration | Faible | Critique | Migrations réversibles, backups automatisés, dual-write pendant transition |
| Régression fonctionnelle | Moyenne | Élevé | Tests automatisés avant chaque migration de module |
| Résistance au changement utilisateurs | Moyenne | Moyen | Migration progressive, UX similaire initialement |

## 7. Points d'attention long terme

1. **Ne jamais coupler les modules** — c'est le premier réflexe sous pression et c'est ce qui a mené à la V1 actuelle
2. **L'API REST est dédiée aux liaisons clients et intégrations externes** — elle sert l'espace client (portail client), les intégrations partenaires et prépare une future app mobile. **Le Hub central et les régions restent en architecture classique** (Livewire, rendu serveur, pas d'API entre eux). L'API n'est pas utilisée pour la communication Hub ↔ régions qui passe par des mécanismes internes (sync BDD, events)
3. **Le partitionnement CDR doit être automatisé** — création automatique des partitions mensuelles futures via un job planifié
4. **Prévoir un module Integration dédié** — les connecteurs fournisseurs doivent être isolés derrière des interfaces pour pouvoir ajouter/remplacer un fournisseur sans impacter le métier
5. **L'architecture multi-région doit être pensée dès le début** — les données de référence (catalogue, tarifs) sont centrales ; les données opérationnelles (clients, CDR, factures) sont régionales. Tout le code métier doit être agnostique de la région courante (le switch de BDD est transparent via stancl/tenancy)
6. **Finaliser la migration de tarification** — la migration vers `plan_rates` (normalisé) en remplacement de `pricing_zones` (dénormalisé) est déjà en cours. Les tables `_bkp` sont des sauvegardes de sécurité de cette transition et pourront être supprimées une fois la migration confirmée stable

---

> **Priorisation** (alignée sur la roadmap condensée du doc 00) :
> - **Phase 0 (en cours)** : Montées de version (Laravel 10→11→14, Livewire 2→4, Pusher→Reverb, PHP ≥ 8.3), passage imports toModel→toCollection
> - **Phase 1 (en cours / à suivre)** : CDR (`client_id`, `call_iots`, `call_ucass`, agrégations, index) + refonte facturation (`invoices_v2` + `invoice_lines`, dual-write, PDF async) + nettoyage BDD (`_bkp`, `pricing_zones`, orphelins)
> - **Phase 2** : Redis, Horizon, worker séparé, audit trail, CI/CD, API REST (liaisons clients), gateways fournisseurs, Scout, modularisation DDD-lite, contrats `Billable` et `CollaboratorContract`
> - **Phase 3** : stancl/tenancy, sync catalogue + SSO, portails client/amba/Hub Livewire 4, provisioning régions, sécurité (KMS, RGPD rétention, tests anti-fuite)
> - **Phase 4 (ultérieure)** : Docker, migration cloud Scaleway, monitoring, backups par tenant, archivage CDR, IA (anomalies, optimisation forfaits, scoring, churn, assistant), scaling

---

# 🔍 Revue par Expert Cybersécurité

## Points validés
- L'architecture monolithe modulaire réduit la surface d'attaque par rapport aux microservices (moins de communication réseau inter-services)
- L'approche API-first avec API Resources permet un contrôle fin des données exposées
- L'isolation des modules limite le blast radius en cas de compromission

## Points d'attention soulevés

### 1. Données chiffrées "dans la même base"
> **Risque CRITIQUE** : Si les clés de chiffrement sont dans le `.env` du même serveur que la base, un accès serveur = accès aux données déchiffrées.

**Recommandation** :
- Migrer vers un **Key Management Service** (KMS) — Scaleway KMS ou HashiCorp Vault
- Séparer la gestion des clés de l'application
- Implémenter le chiffrement au niveau applicatif (Laravel `Crypt` avec clés tournantes) ET au repos (MySQL TDE ou chiffrement disque)

### 2. API-first = surface d'attaque élargie
- Chaque endpoint API doit être protégé par **rate limiting** (Redis + Laravel throttle)
- **Sanctum** pour l'auth API avec token scoping
- Validation stricte des entrées via **Form Requests** sur chaque endpoint
- Pas de mass assignment — uniquement des DTOs explicites

### 3. Partitionnement CDR et rétention
- Les données CDR sont des **données personnelles** (qui appelle qui, quand, combien de temps)
- Obligation RGPD : définir une **durée de conservation légale** et automatiser la purge
- Les agrégations doivent être **anonymisées** au-delà de la période de rétention

### 4. Multi-portail = multi-vecteur d'attaque
- Le portail client (client.cekoya.fr) expose des données sensibles à des utilisateurs externes
- **Recommandation** : middleware de sécurité spécifique par portail, CSP headers distincts, audit log systématique

### 5. Manque identifié : audit trail
- Aucune mention d'audit trail dans l'existant
- **Obligation** pour une plateforme gérant des données télécom et financières
- Implémenter `spatie/laravel-activitylog` ou équivalent dès la Phase 0

## Verdict
L'architecture proposée est **saine du point de vue sécurité** à condition d'implémenter les recommandations ci-dessus. Le KMS et l'audit trail sont **non négociables**.
