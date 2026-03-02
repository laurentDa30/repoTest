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
| **Table `calls` à 12 Go non partitionnée** | Dégradation progressive des performances, requêtes de reporting lentes | CRITIQUE |
| **Table `invoices` surdimensionnée** | Facturation lente, risque de timeout | ÉLEVÉ |
| **3 portails dans 1 seul repo/app** | Déploiement monobloc, risque de régression croisée | ÉLEVÉ |
| **Pas de Docker** | Pas de reproductibilité env, déploiements manuels risqués | MOYEN |
| **Pas de CI/CD** | Pas de filet de sécurité, tests manuels | MOYEN |
| **Hébergement local** | SPOF, pas de scaling, pas de redondance | ÉLEVÉ |
| **Pas de cache applicatif** | Requêtes BDD répétées inutilement | MOYEN |
| **Laravel 10 / Livewire 2** | Versions en fin de support, dette qui s'accumule | MOYEN |

## 2. Architecture cible : Monolithe Modulaire DDD-lite

### Pourquoi PAS de microservices

Avec 2 développeurs, les microservices sont **contre-productifs** :
- Complexité opérationnelle disproportionnée (networking, service discovery, distributed tracing)
- Overhead de déploiement multiplié
- La loi de Conway s'applique : 2 devs = 1 unité de déploiement optimal
- Le monolithe modulaire offre les mêmes bénéfices d'isolation sans la complexité distribuée

### Structure modulaire proposée

```
app/
├── Modules/
│   ├── Prospect/           # Gestion prospects, devis prospect
│   │   ├── Models/
│   │   ├── Actions/        # Single-responsibility classes
│   │   ├── Services/
│   │   ├── Http/
│   │   │   ├── Controllers/
│   │   │   ├── Resources/  # API Resources
│   │   │   └── Requests/
│   │   ├── Events/
│   │   ├── Listeners/
│   │   ├── Jobs/
│   │   └── routes.php
│   │
│   ├── Client/             # Clients, agences, collaborateurs
│   ├── Telecom/            # Lignes, SIMs, portabilités, appareils
│   ├── Catalog/            # Matériels, services, forfaits, fournisseurs
│   ├── Order/              # Commandes, suivi, transit
│   ├── Billing/            # Facturation, comptabilité, SEPA
│   ├── CDR/                # Consommations (table partitionnée)
│   ├── Stock/              # Gestion stock, SIMs physiques
│   ├── Integration/        # Connecteurs fournisseurs (Transatel, Unyc, Wazo, IELO, euroFIBER)
│   ├── Ambassador/         # Programme ambassadeur, paiements
│   ├── Environment/        # Module RSE, émissions, captation
│   ├── Content/            # Rapports, nouveautés, mailing, templates
│   ├── Auth/               # Authentification, rôles, permissions
│   └── Finance/            # Dashboard finance, analyse, exports
│
├── Shared/                 # Code partagé entre modules
│   ├── Traits/
│   ├── ValueObjects/
│   ├── Contracts/          # Interfaces inter-modules
│   └── DTOs/
```

### Règles d'isolation inter-modules

1. **Communication directe interdite** entre modules — uniquement via :
   - Interfaces (Contracts) définies dans `Shared/`
   - Events/Listeners pour les effets de bord
   - DTOs pour le transfert de données
2. **Chaque module possède ses propres migrations** (préfixées par module)
3. **Pas de `use App\Models\...` croisé** — un module ne peut pas importer directement un Model d'un autre module
4. **API Resources** systématiques — chaque module expose ses données via des Resources JSON

### Pattern API-first

```
┌─────────────────────────────────────────────────────┐
│                   Laravel 12                         │
│                                                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────┐  │
│  │ Livewire │  │ Inertia  │  │   API REST       │  │
│  │ (Admin)  │  │ (Client) │  │ /api/v1/*        │  │
│  └────┬─────┘  └────┬─────┘  └────────┬─────────┘  │
│       │              │                 │             │
│       ▼              ▼                 ▼             │
│  ┌──────────────────────────────────────────────┐   │
│  │         Couche Services / Actions             │   │
│  │         (logique métier partagée)             │   │
│  └──────────────────────────────────────────────┘   │
│       │              │                 │             │
│       ▼              ▼                 ▼             │
│  ┌──────────────────────────────────────────────┐   │
│  │              Repositories / Models            │   │
│  └──────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────┘
```

**Principe clé** : Livewire et Inertia appellent les mêmes Services/Actions. La logique métier n'est jamais dans un contrôleur ou un composant Livewire.

## 3. Stratégie pour la table `calls` (CDR)

### Problème actuel
- 1M enregistrements/mois
- ~12 Go actuellement
- Objectif : doubler → 2M/mois → ~24 Go/an de croissance
- Les requêtes de reporting (agrégation par client, par mois, par forfait) deviennent lentes

### Solution : Partitionnement MySQL 8 + Tables d'agrégation

```sql
-- Partitionnement par mois
ALTER TABLE calls
PARTITION BY RANGE (YEAR(call_date) * 100 + MONTH(call_date)) (
    PARTITION p202501 VALUES LESS THAN (202502),
    PARTITION p202502 VALUES LESS THAN (202503),
    -- ...
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Tables d'agrégation pré-calculées
CREATE TABLE daily_call_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    call_date DATE NOT NULL,
    total_calls INT DEFAULT 0,
    total_duration_seconds INT DEFAULT 0,
    total_data_mb DECIMAL(12,2) DEFAULT 0,
    total_sms INT DEFAULT 0,
    total_cost DECIMAL(10,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_unique (client_id, line_id, call_date),
    KEY idx_client_date (client_id, call_date),
    KEY idx_date (call_date)
) ENGINE=InnoDB;

CREATE TABLE monthly_call_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id INT UNSIGNED NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    month_year CHAR(7) NOT NULL,  -- '2025-01'
    total_calls INT DEFAULT 0,
    total_duration_seconds INT DEFAULT 0,
    total_data_mb DECIMAL(12,2) DEFAULT 0,
    total_sms INT DEFAULT 0,
    total_cost DECIMAL(10,4) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_unique (client_id, line_id, month_year),
    KEY idx_client_month (client_id, month_year)
) ENGINE=InnoDB;
```

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

## 4. Stratégie pour la table `invoices`

Sans voir le schéma exact, les problèmes courants sont :
- Stockage des lignes de facture dans la même table ou via des JSON blobs
- Calculs de totaux faits à la volée au lieu d'être pré-calculés
- PDF générés de manière synchrone

### Recommandations
1. **Séparer clairement** : `invoices` (en-tête) / `invoice_lines` (détail) / `invoice_payments` (règlements)
2. **Totaux pré-calculés** sur `invoices` : `total_ht`, `total_tva`, `total_ttc`
3. **Génération PDF asynchrone** via Laravel Queue → stockage S3
4. **Index composites** sur les colonnes de filtrage fréquentes (client_id + status + date)
5. **Partitionnement par année** si le volume le justifie

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

---

> **Priorisation** :
> - Court terme (0-3 mois) : Dockerisation, CI/CD, partitionnement CDR, Redis, upgrade Laravel 12
> - Moyen terme (3-6 mois) : API interne, modularisation, refonte facturation, Livewire 3
> - Long terme (6-12 mois) : Portails Vue 3, migration cloud, scaling

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
