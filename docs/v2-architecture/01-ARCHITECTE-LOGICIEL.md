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
| **Pas de Docker** | Pas de reproductibilité env, déploiements manuels risqués | MOYEN |
| **Pas de CI/CD** | Pas de filet de sécurité, tests manuels | MOYEN |
| **Hébergement local** | SPOF, pas de scaling, pas de redondance | ÉLEVÉ |
| **Pas de cache applicatif** | Requêtes BDD répétées inutilement | MOYEN |
| **Laravel 10 / Livewire 2** | Versions en fin de support, dette qui s'accumule | MOYEN |

## 2. Architecture cible : Monolithe Modulaire DDD-lite + Multi-région

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
    ├── Région IDF (App + BDD propre)
    ├── Région PACA (App + BDD propre)
    └── Région Lyon (App + BDD propre)
```

**Implémentation** : `stancl/tenancy` v3 avec database-per-tenant. Codebase unique, switch automatique de BDD par sous-domaine. La V1 actuelle devient la première région (zéro migration de données initiale).

### Structure modulaire proposée (DDD-lite)

Chaque module adopte une organisation en 3 couches :
- **Domain/** : entités avec logique métier embarquée, Value Objects, Events
- **Application/** : Actions/Services applicatifs (cas d'usage), DTOs
- **Infrastructure/** : Eloquent repositories, mail, queue, API clients

```
app/
├── Modules/
│   ├── Prospect/           # Gestion prospects, devis prospect
│   │   ├── Domain/
│   │   │   ├── Models/           # Entités avec logique métier
│   │   │   ├── ValueObjects/     # Ex: ProspectStatus, ContactInfo
│   │   │   ├── Events/           # ProspectConverted, DevisAccepted
│   │   │   └── Contracts/        # Interfaces exposées aux autres modules
│   │   ├── Application/
│   │   │   ├── Actions/          # ConvertProspectToClientAction
│   │   │   ├── Services/         # ProspectService (orchestration)
│   │   │   ├── DTOs/             # CreateProspectDTO, ProspectListDTO
│   │   │   └── Listeners/        # Réactions aux events d'autres modules
│   │   ├── Infrastructure/
│   │   │   ├── Repositories/     # EloquentProspectRepository
│   │   │   ├── Http/
│   │   │   │   ├── Controllers/
│   │   │   │   ├── Resources/    # API Resources
│   │   │   │   └── Requests/     # Form Requests (validation)
│   │   │   ├── Jobs/             # Queue jobs
│   │   │   └── Providers/        # ServiceProvider du module (bindings)
│   │   └── routes.php
│   │
│   ├── Client/             # Clients, agences, collaborateurs
│   ├── Telecom/            # Lignes, SIMs, portabilités, appareils
│   ├── Catalog/            # Matériels, services, forfaits, fournisseurs
│   ├── Ticket/             # Tickets support, SAV, demandes (messages, catégories, labels, todos)
│   ├── Order/              # Commandes fournisseur/client, suivi, transit (s'appuie sur le module Ticket)
│   ├── Billing/            # Facturation, comptabilité, SEPA
│   ├── CDR/                # Consommations (table partitionnée)
│   ├── Stock/              # Gestion stock, SIMs physiques
│   ├── Integration/        # Connecteurs fournisseurs (Transatel, Unyc, Wazo, IELO, euroFIBER)
│   ├── Ambassador/         # Programme ambassadeur, paiements
│   ├── Environment/        # Module RSE, émissions, captation
│   ├── Content/            # Rapports, nouveautés, mailing, templates
│   ├── Auth/               # Authentification, rôles, permissions
│   ├── Finance/            # Dashboard finance, analyse, exports
│   ├── IA/                 # Intelligence artificielle (anomalies CDR, scoring, optimisation forfaits, assistant)
│   └── Central/            # Hub multi-région (catalogue, sync, dashboard global)
│
├── Shared/                 # Code partagé entre modules
│   ├── Traits/
│   ├── ValueObjects/
│   ├── Contracts/          # Interfaces inter-modules
│   └── DTOs/
```

> **Note multi-région** : Les modules ci-dessus s'exécutent dans chaque instance régionale. Le module `Central/` ne tourne que sur le Hub et gère la synchronisation catalogue, le registry des régions et le SSO.

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

### Pattern API-first

```
┌─────────────────────────────────────────────────────┐
│                   Laravel 12                         │
│                                                      │
│  ┌──────────────────────┐  ┌──────────────────┐    │
│  │     Livewire 3       │  │   API REST       │    │
│  │ (Admin + Client +    │  │   /api/v1/*      │    │
│  │  Ambassadeur)        │  │   (mobile, etc.) │    │
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

## 5. Risques identifiés

| Risque | Probabilité | Impact | Mitigation |
|--------|-------------|--------|------------|
| Migration trop ambitieuse pour 2 devs | Élevée | Critique | Strangler Fig strict, un module à la fois |
| Perte de données pendant la migration | Faible | Critique | Migrations réversibles, backups automatisés, dual-write pendant transition |
| Régression fonctionnelle | Moyenne | Élevé | Tests automatisés avant chaque migration de module |
| Résistance au changement utilisateurs | Moyenne | Moyen | Migration progressive, UX similaire initialement |

## 6. Points d'attention long terme

1. **Ne jamais coupler les modules** — c'est le premier réflexe sous pression et c'est ce qui a mené à la V1 actuelle
2. **L'API interne est l'investissement le plus structurant** — elle permet de découpler les portails et prépare une future app mobile
3. **Le partitionnement CDR doit être automatisé** — création automatique des partitions mensuelles futures via un job planifié
4. **Prévoir un module Integration dédié** — les connecteurs fournisseurs doivent être isolés derrière des interfaces pour pouvoir ajouter/remplacer un fournisseur sans impacter le métier
5. **L'architecture multi-région doit être pensée dès le début** — les données de référence (catalogue, tarifs) sont centrales ; les données opérationnelles (clients, CDR, factures) sont régionales. Tout le code métier doit être agnostique de la région courante (le switch de BDD est transparent via stancl/tenancy)
6. **Finaliser la migration de tarification** — la migration vers `plan_rates` (normalisé) en remplacement de `pricing_zones` (dénormalisé) est déjà en cours. Les tables `_bkp` sont des sauvegardes de sécurité de cette transition et pourront être supprimées une fois la migration confirmée stable

---

> **Priorisation** :
> - Court terme (0-3 mois) : Dockerisation, CI/CD, quick wins CDR (`client_id` + agrégation), Redis, Laravel 12, **install stancl/tenancy + BDD centrale**
> - Moyen terme (3-6 mois) : API interne, modularisation, refonte facturation (sortie JSON blob), Livewire 3, **sync catalogue + SSO**
> - Long terme (6-12 mois) : Portails client/amba Livewire 3, migration cloud, **provisioning auto de régions**, scaling

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
