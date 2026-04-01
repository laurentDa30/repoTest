# 🗃️ Analyse détaillée du schéma de base de données V1

## Vue d'ensemble

- **~80 tables** identifiées
- **Packages Spatie** détectés : media-library, tags, laravel-permission
- **Relations polymorphiques** : addresses, errors, invoiced, events, taggables, media, todo_purchases
- **Tables de backup** (`_bkp`) présentes : dette technique à nettoyer

---

## 1. Découvertes critiques

### 🔴 CRITIQUE — Table `invoices` : le JSON blob

```sql
CREATE TABLE `invoices` (
    `id` bigint UNSIGNED NOT NULL,
    `client_id` bigint UNSIGNED DEFAULT NULL,
    `doc` json DEFAULT NULL,  -- ⚠️ L'INTÉGRALITÉ de la facture est ici
    -- Colonnes GENERATED extraites du JSON :
    `date` date GENERATED ALWAYS AS (json_unquote(json_extract(`doc`,'$.date'))) STORED,
    `amount` double(8,2) GENERATED ALWAYS AS (json_unquote(json_extract(`doc`,'$.summary.total'))) STORED,
    `amount_tax` double(8,2) GENERATED ALWAYS AS (json_unquote(json_extract(`doc`,'$.summary.total_tax_included'))) STORED,
    `paid` tinyint(1) GENERATED ALWAYS AS (if((json_extract(`doc`,'$.payment.paid') = true),1,0)) STORED,
    `locked` tinyint(1) GENERATED ALWAYS AS (if((json_extract(`doc`,'$.locked') = true),1,0)) STORED,
    `label` varchar(255) GENERATED ALWAYS AS (json_unquote(json_extract(`doc`,'$.label'))) STORED,
    `number` varchar(255) GENERATED ALWAYS AS (json_unquote(json_extract(`doc`,'$.number'))) STORED,
    ...
);
```

**Problème identifié** :
- La colonne `doc` (JSON) contient **l'intégralité** de la facture : en-tête, lignes de facturation, calculs, métadonnées, infos de paiement
- Chaque `SELECT` charge potentiellement le JSON entier en mémoire, même si on ne veut que la date ou le montant
- Les colonnes GENERATED STORED extraient quelques champs pour les index, mais le blob reste
- La taille de `doc` est proportionnelle au nombre de lignes par client (un client avec 500-1000+ lignes = un JSON de plusieurs centaines de Ko)
- **C'est LA cause du problème de performance de la table `invoices`**

**Impact** :
- Chaque listing de factures charge les JSON complets → mémoire MySQL saturée
- Les `SELECT *` sont catastrophiques sur cette table
- Les backups sont lourds (le JSON est incompressible en MyISAM/InnoDB row format)

### 🔴 CRITIQUE — Table `calls` sans `client_id`

```sql
CREATE TABLE `calls` (
    `id` bigint UNSIGNED NOT NULL,
    `line_id` bigint UNSIGNED DEFAULT NULL,
    -- Pas de client_id !
    ...
);
```

**Problème** : Pour afficher les CDR d'un client, il faut faire :
```sql
SELECT calls.* FROM calls
JOIN lines ON calls.line_id = lines.id
WHERE lines.client_id = ?
```

Avec 1M+ lignes dans `calls`, ce JOIN systématique est coûteux. Un `client_id` dénormalisé permettrait un accès direct.

### 🟡 ATTENTION — Table `devis` : même pattern JSON

```sql
CREATE TABLE `devis` (
    `devis` json DEFAULT NULL,  -- Le contenu du devis est un JSON
    ...
);
```

Même anti-pattern que `invoices`, mais probablement moins volumineux (moins de devis que de factures).

### 🟡 ATTENTION — `invoice_cdrs` avec MEDIUMBLOB

```sql
CREATE TABLE `invoice_cdrs` (
    `compressed_cdr` mediumblob,  -- CDR compressés en binaire
    ...
);
```

**Point positif** : les CDR sont compressés pour les factures — c'est intelligent.
**Point négatif** : un MEDIUMBLOB peut faire jusqu'à 16 Mo. Si on a beaucoup de `invoice_cdrs`, la table enfle vite.

---

## 2. Points positifs découverts

### ✅ `monthly_summaries` existe déjà !

```sql
CREATE TABLE `monthly_summaries` (
    `line_id` bigint UNSIGNED NOT NULL,
    `month` date NOT NULL,
    `sms` int UNSIGNED NOT NULL DEFAULT '0',
    `mms` int UNSIGNED NOT NULL DEFAULT '0',
    `calls` int UNSIGNED NOT NULL DEFAULT '0',
    `calls_duration` bigint UNSIGNED NOT NULL DEFAULT '0',
    `data` bigint UNSIGNED NOT NULL DEFAULT '0',
    `data_xdsl_fttx` bigint UNSIGNED NOT NULL DEFAULT '0',
    `out_of_plan` double(8,2) UNSIGNED NOT NULL DEFAULT '0.00',
    -- + colonnes carbone
    ...
);
```

**Excellente nouvelle** : l'agrégation mensuelle par ligne est déjà en place. Ma recommandation de Phase 1 (tables d'agrégation) est partiellement implémentée.

**Ce qui manque** :
- Agrégation **quotidienne** (pour les dashboards temps quasi-réel)
- Agrégation par **client** (pas seulement par ligne) → `carbon_summaries` couvre partiellement côté carbone
- Colonnes financières dans les résumés (`total_charge`, `total_price`, `out_of_plan_cost`)

### ✅ `cdr_files` pour la traçabilité des imports

```sql
CREATE TABLE `cdr_files` (
    `name` varchar(255),
    `path` varchar(255),
    `provider` varchar(255),
    `processed_at` datetime,
    `status` tinyint NOT NULL DEFAULT '0',
    `counter` int UNSIGNED,
    `duration` int,
    ...
);
```

Chaque fichier CDR importé est tracé. Le lien `calls.cdr_file_id → cdr_files.id` permet de :
- Vérifier si un fichier a déjà été importé (idempotence)
- Tracer l'origine de chaque CDR
- Supprimer par lot en cas de re-import

### ✅ `transactions` pour le suivi des appels API

```sql
CREATE TABLE `transactions` (
    `type` varchar(255),
    `targetable_type` varchar(255),
    `targetable_id` bigint UNSIGNED,
    `status` varchar(255) DEFAULT 'pending',
    `source` varchar(255),
    `payload` json,
    `response` json,
    `started_at` timestamp,
    `finished_at` timestamp,
    ...
);
```

Les appels API fournisseurs sont déjà tracés — c'est un bon début d'audit trail technique.

### ✅ Spatie Laravel Permission déjà en place

Tables `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` — le RBAC est déjà structuré.

---

## 3. Cartographie des domaines métier (mapping vers modules V2)

### Module **Client** (inclut les collaborateurs — entité pivot et centre de coût)
| Table | Rôle |
|-------|------|
| `clients` | Entité centrale |
| `collaborators` | Employés des clients — entité pivot et **centre de coût** (le client paye les lignes, appareils et prestations de chaque collab.) |
| `client_collaborator` | Pivot collaborateurs/clients avec dates |
| `client_user` | Liaison utilisateurs portail ↔ clients |
| `client_todos` / `client_todo_messages` | Checklist par client |
| `client_preferences` | Préférences de downgrade |
| `client_plan_preferences` | Override de prix par forfait |
| `client_device_sheet` | Prix spécifiques matériels |
| `referents` | Contacts référents par client |
| `groups` | Agences/départements |
| `sub_groups` | Sous-groupes dans les agences |
| `segmentations` | Segmentation client |
| `addresses` | Polymorphique |
| `address_book_entries` | Carnet d'adresses multi-catégories |

> **Le collaborateur est une entité pivot et un centre de coût pour le client** : il appartient au module Client mais est référencé par les modules Telecom (`lines.collaborator_id`), Stock (`device_collaborator`) et Infogérance (`collaborators.id_glpi`). **C'est le client qui paye** pour tout ce que le collaborateur détient (forfaits, CDR, appareils, prestations). Le suivi par collaborateur permet au client de savoir combien lui coûte chaque employé. Les autres modules accèdent aux collaborateurs via le contrat `CollaboratorContract` exposé par le module Client.

### Module **Telecom** (mobile, fixe, internet — hors IoT et UCaaS)
| Table | Rôle |
|-------|------|
| `lines` | Lignes télécom — `collaborator_id` lie la ligne au collaborateur (module Client) |
| `sims` | Cartes SIM classiques (non-IoT) avec ICCID, IMSI, PIN/PUK |
| `line_plan` | Pivot ligne ↔ forfait avec dates et prix |
| `telecom_types` | Types de service (mobile, fixe, internet, IoT, UCaaS) — référentiel partagé |
| `portabilities` | Portabilités effectuées (via Transatel) |
| `portabilities_pending` | Portabilités en attente |
| `mobile_plan_buyings` | Achats forfaits mobile |

> **Lien collaborateur** : `lines.collaborator_id` référence un collaborateur du module Client. Un collaborateur peut détenir plusieurs lignes (mobile + fixe), chacune avec son forfait et ses CDR. Le module Telecom accède au collaborateur via `CollaboratorContract`.

### Module **IoT** (SIMs M2M, capteurs, quotas spécifiques)
| Table | Rôle |
|-------|------|
| `lines` | Lignes IoT (filtrées par `telecom_types.id` = IoT) — même table que Telecom |
| `sims` | SIMs IoT/M2M (même table, filtrées par usage) |
| `calls_iot` | CDR IoT séparés (volume massif, structure légèrement différente) — **NOUVEAU V2** |
| `daily_iot_summaries` | Agrégation quotidienne IoT par SIM/quota — **NOUVEAU V2** |
| `monthly_iot_summaries` | Agrégation mensuelle IoT — **NOUVEAU V2** |

> **Note** : Les lignes et SIMs IoT partagent les mêmes tables que Telecom (`lines`, `sims`) mais sont distinguées par le `telecom_type_id`. Les CDR IoT ont leur propre table car le volume est massivement plus élevé et les patterns de requêtes sont différents. Le module IoT a ses propres vérifications de quota et ses propres dashboards.

### Module **UCaaS** (Wazo — communications unifiées)
| Table | Rôle |
|-------|------|
| `lines` | Postes UCaaS (filtrés par `telecom_types.id` = UCaaS) — même table |
| `calls_ucaas` | CDR Wazo (VoIP, conférence, messaging) — **NOUVEAU V2** |
| `daily_ucaas_summaries` | Agrégation quotidienne UCaaS — **NOUVEAU V2** |
| `monthly_ucaas_summaries` | Agrégation mensuelle UCaaS — **NOUVEAU V2** |

> **Note** : Wazo représente un domaine métier distinct (collaboration, VoIP, télétravail) avec ses propres CDR et dashboards. Les lignes UCaaS sont dans la même table `lines` mais avec un type différent.

### Module **Infogérance** (parc informatique — s'appuie sur le module Client pour les collaborateurs)

Le module Infogérance ne possède pas de tables propres pour le moment. Il s'appuie sur :
- **`collaborators`** (module Client) : via le contrat `CollaboratorContract` — le champ `id_glpi` et `is_register_to_glpi` permettent le lien avec GLPI
- **`device_collaborator`** (module Stock) : pour savoir quel matériel est affecté à quel collaborateur
- **`device_client` / `device_group`** (module Stock) : pour la vue globale du parc client

> **Note** : Le module Infogérance est un module de **lecture et d'orchestration** — il agrège les données des modules Client et Stock pour fournir une vue "parc informatique" par client. À terme, il pourra avoir ses propres tables (contrats d'infogérance, SLA, interventions) et générer des lignes de facture via le contrat `Billable`.

### Module **CDR / Consommations** (stockage et agrégation centralisés)
| Table | Rôle |
|-------|------|
| `calls_mobile` | CDR mobile/fixe/internet détaillés — **RENOMMÉ** (ex-`calls`) |
| `calls_iot` | CDR IoT détaillés (volume massif) — **NOUVEAU V2** |
| `calls_ucaas` | CDR Wazo détaillés — **NOUVEAU V2** |
| `call_types` | Types d'appel (voix, SMS, data, MMS, VoIP, conférence…) |
| `units` | Unités de mesure |
| `cdr_files` | Fichiers CDR importés (tous types, discriminé par `provider`) |
| `daily_call_summaries` | Agrégation quotidienne mobile/fixe/internet — **NOUVEAU V2** |
| `daily_iot_summaries` | Agrégation quotidienne IoT — **NOUVEAU V2** |
| `daily_ucaas_summaries` | Agrégation quotidienne UCaaS — **NOUVEAU V2** |
| `monthly_summaries` | Agrégation mensuelle par ligne (existante, à enrichir) |
| `monthly_iot_summaries` | Agrégation mensuelle IoT — **NOUVEAU V2** |
| `monthly_ucaas_summaries` | Agrégation mensuelle UCaaS — **NOUVEAU V2** |
| `invoice_cdrs` | CDR compressés par facture |

> **Note** : Le module CDR gère le stockage et l'agrégation de TOUS les types de CDR, mais dans des tables séparées. Les modules Telecom, IoT et UCaaS accèdent à leurs CDR respectifs via des contrats (interfaces) exposés par le module CDR. La séparation physique évite que les volumes IoT (potentiellement millions/mois) polluent les requêtes sur les CDR mobile.

### Module **Catalog**
| Table | Rôle |
|-------|------|
| `plans` | Forfaits |
| `options` | Options de forfait |
| `plan_rates` | Matrice tarifaire (nouveau format normalisé) |
| `pricing_zones` | Zones de tarification (ancien format dénormalisé) |
| `pricing_zone_country` / `pricing_geographical_zone` | Mapping pays ↔ zones |
| `device_sheets` | Fiches matériel (catalogue) |
| `device_sheet_types` / `device_types` | Typologies de matériel |
| `service_sheets` | Fiches service (catalogue) |
| `service_families` | Familles de service |
| `supplier_sheets` | Fiches fournisseur |
| `geographical_zones` | Zones géographiques |
| `geographical_zone_supplier` | Mapping zones ↔ fournisseurs |
| `supplier_zone_countries` | Mapping pays ↔ zones par fournisseur |
| `countries` | Référentiel pays |

### Module **Billing / Finance**
| Table | Rôle |
|-------|------|
| `invoices` | Factures (avec JSON blob problématique) |
| `invoiced` | Pivot polymorphique : éléments facturés |
| `invoice_cdrs` | CDR compressés par facture |
| `direct_debit_accounts` | Comptes de prélèvement SEPA |
| `payment_types` | Types de paiement |

### Module **Stock**
| Table | Rôle |
|-------|------|
| `devices` | Appareils physiques (avec serial, MAC, prix) |
| `device_client` | Affectation appareil ↔ client (avec leasing) |
| `device_collaborator` | Affectation appareil ↔ collaborateur |
| `device_group` | Affectation appareil ↔ agence |
| `device_line` | Liaison appareil ↔ ligne |
| `device_stock` | Appareils en stock (avec réservation) |
| `stocks` | Entrepôts/stocks |
| `states` | États/conditions des appareils |

> **Lien collaborateur** : `device_collaborator` lie un appareil à un collaborateur (module Client). Un collaborateur peut détenir plusieurs appareils (PC, téléphone, tablette). Le module Stock accède au collaborateur via `CollaboratorContract`. Le module Infogérance agrège ces données pour la vue parc IT.

### Module **Ticket** (support, SAV, demandes)
| Table | Rôle |
|-------|------|
| `tickets` | Tickets de suivi (support, SAV, demandes) |
| `tickets_messages` | Messages dans les tickets |
| `tickets_categories` / `tickets_labels` | Catégorisation |
| `ticket_todos` / `ticket_todo_messages` | Tâches dans les tickets |

### Module **Order** (commandes — s'appuie sur le module Ticket)
| Table | Rôle |
|-------|------|
| `orders` | Commandes fournisseur |
| `order_receives` | Réceptions de commande |
| `order_client_status` | Statut de livraison côté client |
| `client_orders` | Commandes client (depuis le portail) |
| `todo_purchases` / `todo_purchase_order` | Achats à effectuer |
| `carts` | Paniers |

> **Note** : Le module Order utilise le système de tickets du module Ticket pour le suivi des commandes. Un ticket peut être lié à une commande, mais tous les tickets ne sont pas des commandes (SAV, demandes diverses).

### Module **Ambassador**
| Table | Rôle |
|-------|------|
| `partner_client` | Liaison ambassadeur ↔ clients apportés |
| `partner_has_partner` | Parrainage multi-niveaux (NV1 → NV2) |
| `partner_payments` | Paiements des commissions |

### Module **Prospect**
| Table | Rôle |
|-------|------|
| `prospects` | Pipeline commercial |
| `devis` | Devis (avec JSON blob) |

### Module **Environment / RSE**
| Table | Rôle |
|-------|------|
| `base_carbone` | Référentiel ADEME |
| `carbon_summaries` | Bilan carbone par client/mois |
| `carbon_reports` | Rapports carbone générés |
| `project_carbon` | Projets de captation |
| `project_types` | Types de projets RSE |
| `client_project_carbon` | Attribution projets ↔ clients |
| `project_stakeholder` / `stakeholders` | Parties prenantes |
| `trees` | Arbres plantés |

### Module **Content / Communication**
| Table | Rôle |
|-------|------|
| `posts` / `posts_types` | Campagnes de mailing |
| `post_sends` | Envois et tracking d'ouverture |
| `templates` | Templates d'email |
| `news` | Actualités |
| `reports` | Rapports généraux |
| `prompts` | Prompts IA (rapport carbone ?) |

### Module **Auth / Système**
| Table | Rôle |
|-------|------|
| `users` | Utilisateurs (admin + clients + ambassadeurs) |
| `roles` / `permissions` | RBAC (Spatie) |
| `model_has_roles` / `model_has_permissions` / `role_has_permissions` | Pivot RBAC |
| `personal_access_tokens` | Tokens API (Sanctum) |
| `password_resets` | Reset mot de passe |
| `user_logs` | Logs d'activité (basique) |
| `page_views` | Analytics internes |
| `batches` / `jobs` / `job_batches` / `failed_jobs` | Queue Laravel |
| `errors` | Erreurs polymorphiques |
| `events` | Événements polymorphiques |
| `alerts` | Alertes (SIM, lignes, appareils) |

---

## 4. Problèmes structurels détaillés

### 4.1 Le pattern JSON blob (`invoices.doc`, `devis.devis`)

**Impact technique** :
```
Volumétrie réelle du JSON par facture :
- Clients légers (~300) : ~20 lignes → JSON doc ≈ 5-20 Ko
- Clients lourds (~80)  : 500-1000+ lignes → JSON doc ≈ 200-500 Ko par facture

Volume total estimé :
  Clients légers : 300 × 12 × ~10 Ko  =   36 Mo/an
  Clients lourds :  80 × 12 × ~350 Ko = ~336 Mo/an
                                  Total ≈  370 Mo/an de JSON pur

  Sur 3 ans ≈ 1.1 Go
  Sur 5 ans ≈ 1.9 Go (sans compter les index et colonnes GENERATED STORED)
  Avec overhead (index + GENERATED + row format) : facilement 3-4 Go sur 5 ans

- Chaque SELECT charge le JSON sauf si on utilise explicitement SELECT sans doc
- Un SELECT * sur la facture d'un client lourd = 200-500 Ko chargés en mémoire par row
```

**Problèmes** :
1. Pas de requête SQL efficace **dans** le contenu des lignes de facture
2. Impossible de faire un reporting sur le détail des factures sans parser le JSON
3. La validation de données est applicative uniquement (pas de contraintes DB)
4. Les colonnes GENERATED sont un workaround, pas une solution — elles consomment de l'espace en double
5. Les index sur les colonnes GENERATED fonctionnent mais MySQL doit maintenir le JSON + les colonnes extraites

### 4.2 Migration de la tarification (en cours)

```
Ancien système (dénormalisé) — en cours de remplacement :
  pricing_zones          → colonnes matrix mobile_to_0..3, fixed_to_0..3, sms_to_0..3, etc.
  pricing_geographical_zone
  pricing_zone_country

Nouveau système (normalisé) — cible :
  plan_rates             → (plan_id, service_type, from_zone, to_zone, unit_price, allowance)
  supplier_zone_countries

Tables backup (sauvegardes de sécurité) :
  plan_rates_bkp
  geographical_zone_supplier_bkp
  supplier_zone_countries_bkp
```

**Constat** : La migration de la tarification vers le nouveau système normalisé (`plan_rates`) est **déjà en cours**. Les tables `_bkp` sont des sauvegardes de sécurité de cette transition. Ce n'est pas une dette technique à résoudre — c'est une migration pilotée par l'équipe.

**Action V2** : Attendre que la migration soit confirmée stable, puis supprimer les tables `pricing_zones` et `_bkp` si l'équipe le valide.

### 4.3 Table `collaborators` = module infogérance

```sql
-- collaborators : entités distinctes des users
CREATE TABLE `collaborators` (
    `id`, `uuid`, `designation`, `lastname`, `firstname`,
    `email`, `phone`, `profil_id`, `is_register_to_glpi`, `id_glpi`, ...
);
```

Les collaborateurs sont des **contacts gérés dans le cadre de l'infogérance** pour les clients. Ils ne sont **pas** des utilisateurs de la plateforme et n'ont pas de compte de connexion. C'est une gestion purement interne du parc informatique client (lien avec GLPI via `id_glpi`).

**En V2** : Les collaborateurs restent dans un module dédié (Infogérance ou Client). Aucun lien `collaborators ↔ users` n'est nécessaire sauf si un besoin d'accès portail client émerge à l'avenir.

### 4.4 La table `users` est multi-rôle

La même table `users` sert pour :
- Les administrateurs Cekoya (~12 personnes)
- Les utilisateurs du portail client (via `client_user`)
- Les ambassadeurs (via `partner_client`, `partner_has_partner`)

C'est acceptable avec Spatie Permission (rôles différents), mais ça implique :
- Un `User` peut être simultanément admin et ambassadeur
- La logique d'autorisation doit être rigoureuse pour éviter les escalades de privilèges

### 4.5 Index sur `calls` : bon mais incomplet

```sql
-- Index existants
KEY `idx_calls_date` (`date`),
KEY `idx_calls_price` (`price`),
KEY `idx_date_price` (`date`,`price`),
KEY `idx_line_date_price` (`line_id`,`date`,`price`),
KEY `calls_number_index` (`number`),
KEY `uncharged` (`price`,`date`,`line_id`),
UNIQUE KEY `calls_provider_call_id_unique` (`provider_call_id`),
```

**Analyse** :
- `idx_line_date_price` est le plus utile (CDR par ligne sur une période)
- `uncharged` (`price`, `date`, `line_id`) sert vraisemblablement à trouver les CDR non tarifés
- `calls_provider_call_id_unique` assure l'unicité (anti-doublon d'import)

**Ce qui manque** : Un index incluant `client_id` (quand on l'ajoutera) + `date` pour les requêtes par client sans JOIN.

---

## 5. Plan de migration de schéma V2

### Phase 1 — Quick wins sans rupture (rétro-compatible V1)

#### 1.1 Ajouter `client_id` sur `calls`

```sql
-- Migration Laravel
Schema::table('calls', function (Blueprint $table) {
    $table->unsignedBigInteger('client_id')->nullable()->after('line_id');
    $table->foreign('client_id')->references('id')->on('clients');
    $table->index(['client_id', 'date'], 'idx_calls_client_date');
});

-- Remplissage (job batch)
UPDATE calls c
JOIN lines l ON c.line_id = l.id
SET c.client_id = l.client_id
WHERE c.client_id IS NULL;
```

**Gain** : Les requêtes CDR par client passent d'un JOIN à un WHERE direct. Impact immédiat sur les dashboards.

**Important — `client_id` et changement de propriétaire d'une ligne** :
- Le `client_id` sur un CDR représente le client **au moment de l'appel** (snapshot historique)
- Si une ligne change de client, les anciens CDR conservent l'ancien `client_id`
- Seuls les **nouveaux CDR** (importés après le changement) reçoivent le nouveau `client_id`
- Le job d'import CDR prend le `client_id` courant de la ligne au moment de l'insertion
- **Pas besoin** de vérification quotidienne de concordance — c'est par conception un instantané
- Le backfill initial utilise le `client_id` actuel de la ligne (acceptable car les transferts de ligne sont rares)

#### 1.2 Ajouter `daily_call_summaries`

```sql
CREATE TABLE `daily_call_summaries` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` bigint UNSIGNED NOT NULL,
    `line_id` bigint UNSIGNED NOT NULL,
    `date` date NOT NULL,
    `telecom_type_id` bigint UNSIGNED DEFAULT NULL,
    `total_calls` int UNSIGNED NOT NULL DEFAULT 0,
    `total_sms` int UNSIGNED NOT NULL DEFAULT 0,
    `total_mms` int UNSIGNED NOT NULL DEFAULT 0,
    `total_data_bytes` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_duration_seconds` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_charge` decimal(15,4) NOT NULL DEFAULT 0,
    `total_price` decimal(15,4) NOT NULL DEFAULT 0,
    `out_of_plan_count` int UNSIGNED NOT NULL DEFAULT 0,
    `out_of_plan_cost` decimal(15,4) NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `daily_unique` (`client_id`, `line_id`, `date`, `telecom_type_id`),
    KEY `idx_client_date` (`client_id`, `date`),
    KEY `idx_line_date` (`line_id`, `date`),
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
    FOREIGN KEY (`line_id`) REFERENCES `lines` (`id`)
) ENGINE=InnoDB;
```

**Estimation** : 6 000 lignes × 365 jours = ~2.2M rows/an ≈ quelques centaines de Mo. Ridicule comparé aux 12 Go de `calls`.

#### 1.2b Ajouter `daily_iot_summaries`

```sql
CREATE TABLE `daily_iot_summaries` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` bigint UNSIGNED NOT NULL,
    `line_id` bigint UNSIGNED NOT NULL,
    `sim_id` bigint UNSIGNED DEFAULT NULL,
    `date` date NOT NULL,
    `total_sessions` int UNSIGNED NOT NULL DEFAULT 0,
    `total_data_up_bytes` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_data_down_bytes` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_data_bytes` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_sms` int UNSIGNED NOT NULL DEFAULT 0,
    `total_charge` decimal(15,4) NOT NULL DEFAULT 0,
    `total_price` decimal(15,4) NOT NULL DEFAULT 0,
    `quota_used_pct` decimal(5,2) DEFAULT NULL,
    `quota_alert_sent` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `daily_iot_unique` (`client_id`, `line_id`, `date`),
    KEY `idx_client_date` (`client_id`, `date`),
    KEY `idx_sim_date` (`sim_id`, `date`),
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
    FOREIGN KEY (`line_id`) REFERENCES `lines` (`id`)
) ENGINE=InnoDB;
```

**Spécificités IoT** : Les colonnes `data_up/down` (upload/download séparés), `quota_used_pct` et `quota_alert_sent` sont propres à l'IoT M2M — elles n'ont pas de sens dans les CDR mobile classiques, d'où la table séparée.

**Rétention** : 18 mois (cf. §8.3).

#### 1.2c Ajouter `daily_ucaas_summaries`

```sql
CREATE TABLE `daily_ucaas_summaries` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` bigint UNSIGNED NOT NULL,
    `line_id` bigint UNSIGNED NOT NULL,
    `date` date NOT NULL,
    `total_calls_in` int UNSIGNED NOT NULL DEFAULT 0,
    `total_calls_out` int UNSIGNED NOT NULL DEFAULT 0,
    `total_duration_in_seconds` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_duration_out_seconds` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_conferences` int UNSIGNED NOT NULL DEFAULT 0,
    `total_conference_minutes` int UNSIGNED NOT NULL DEFAULT 0,
    `total_messages` int UNSIGNED NOT NULL DEFAULT 0,
    `total_voicemails` int UNSIGNED NOT NULL DEFAULT 0,
    `total_charge` decimal(15,4) NOT NULL DEFAULT 0,
    `total_price` decimal(15,4) NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `daily_ucaas_unique` (`client_id`, `line_id`, `date`),
    KEY `idx_client_date` (`client_id`, `date`),
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
    FOREIGN KEY (`line_id`) REFERENCES `lines` (`id`)
) ENGINE=InnoDB;
```

**Spécificités UCaaS** : Appels entrants/sortants séparés, conférences, messagerie, boîtes vocales — métriques propres à la téléphonie unifiée Wazo.

**Rétention** : 18 mois (cf. §8.3).

#### 1.3 Partitionner `calls` et séparer IoT/UCaaS

> **Guide complet → [doc 19 — Guide de migration `calls`](./19-GUIDE-MIGRATION-CALLS.md)**

**Situation réelle** :

| Table | Rows | Taille | Période |
|-------|------|--------|---------|
| `calls` | ~19M (IoT + mobile mélangés) | ~8.5 Go | 12 mois glissants |
| `calls_archive` | ~9M | ~5 Go | Mois antérieurs |

**Décision clé — "Couper au présent"** : on ne migre pas les CDR IoT/UCaaS historiques hors de `calls`. Les données existantes restent en place. Les nouveaux CDR IoT/UCaaS vont dans `call_iots` / `call_ucass` à partir de la date de bascule. Les anciens IoT/UCaaS dans `calls` vieillissent et disparaissent via l'archivage mensuel en 12 mois.

**Pourquoi pas de migration historique** :
- 10M+ rows à déplacer = risque non nul, ~1h de maintenance supplémentaire
- Les IDs migrés seraient fragmentés (47, 193, 8750504...) au lieu de séquentiels
- La convergence naturelle (12 mois) est moins risquée et sans downtime

**Contraintes techniques** :
- MySQL interdit les FK sur tables partitionnées → FK supprimées, contraintes applicatives
- La PK doit inclure la colonne de partition → `PRIMARY KEY (id, date)` au lieu de `(id)`
- La UNIQUE KEY `provider_call_id` doit inclure `date` → `(provider_call_id, date)`

**Partitionnement mensuel** — `PARTITION BY RANGE (YEAR(date) * 100 + MONTH(date))` :
- 1 partition par mois (~1.6M rows/mois)
- `DROP PARTITION` instantané pour l'archivage (vs DELETE lent sur 1.6M rows)
- Partition pruning ×12 sur les requêtes filtrées par mois
- Partitions créées jusqu'en 2029 + `p_future` comme filet de sécurité

**Migration (via MySQL CLI, pas de migration Laravel pour la copie)** :

```sql
-- La table calls_new est créée par la migration Laravel (vide, partitionnée)
-- La copie se fait via MySQL CLI pour éviter les timeouts et avoir la progression

-- 1. Copie brute sans index (plus rapide)
INSERT INTO calls_new SELECT * FROM calls;

-- 2. Vérification
SELECT (SELECT COUNT(*) FROM calls) AS source,
       (SELECT COUNT(*) FROM calls_new) AS copie;

-- 3. Index ajoutés APRÈS la copie (2-5× plus rapide que pendant l'INSERT)
ALTER TABLE calls_new ADD UNIQUE KEY `calls_provider_call_id_unique` (`provider_call_id`, `date`);
ALTER TABLE calls_new ADD KEY `service_type_fk_3743468` (`telecom_type_id`);
ALTER TABLE calls_new ADD KEY `call_type_fk_3743469` (`call_type_id`);
ALTER TABLE calls_new ADD KEY `calls_cdr_file_id_foreign` (`cdr_file_id`);
ALTER TABLE calls_new ADD KEY `calls_number_index` (`number`, `price`);
ALTER TABLE calls_new ADD KEY `uncharged` (`date`, `line_id`);
ALTER TABLE calls_new ADD KEY `idx_line_date_price` (`line_id`, `date`, `price`);
ALTER TABLE calls_new ADD KEY `calls_client_id_foreign` (`client_id`);

-- 4. Bascule atomique (< 1 sec)
RENAME TABLE calls TO calls_old, calls_new TO calls;
ALTER TABLE calls MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;
```

→ **Process complet, rollback, checklist, job d'archivage modifié, cold storage : [doc 19](./19-GUIDE-MIGRATION-CALLS.md)**

### Phase 2 — Refonte de la table `invoices`

> **Guide complet → [doc 20 — Guide de migration `invoices`](./20-GUIDE-MIGRATION-INVOICES.md)**

**Pourquoi on ne peut pas "couper au présent"** (contrairement à `calls`) :
- Les factures ne vieillissent pas — rétention 10 ans, consultées régulièrement (espace client, compta, litiges)
- Le problème (JSON blob 200-500 Ko/facture) concerne **toutes** les factures historiques, pas seulement les nouvelles
- Il faut donc migrer l'historique — mais en arrière-plan, sans downtime

**Tables cibles** :

| Table | Rôle | Partitionnement |
|-------|------|----------------|
| `invoices_v2` | En-tête de facture (~23K rows sur 5 ans) | Non (volume trivial) |
| `invoice_lines` | Lignes détaillées (~840K rows/an) | **Par année** (4M+ rows sur 5 ans) |

**Contraintes techniques pour `invoice_lines`** :
- Partitionnée par `YEAR(invoice_date)` → pas de FK MySQL
- `invoice_date` dénormalisé depuis `invoices_v2.date` (requis pour le partitionnement)
- Rempli automatiquement via event Laravel `creating`
- Lien `invoice_id → invoices_v2` garanti par Eloquent (`belongsTo`)

**Séquence de migration (aucune fenêtre de maintenance)** :

```
Phase A — Migrations Laravel      : tables vides créées à côté de invoices   [< 1 sec]
Phase B — Migration historique    : job queue background, checksum par facture [quelques heures]
Phase C — Dual-write              : nouvelles factures écrivent dans les 2    [déploiement normal]
Phase D — Bascule lecture         : V2 devient source de vérité              [déploiement normal]
Phase E — Extinction dual-write   : invoices n'est plus écrite               [déploiement normal]
Phase F — Bascule tables          : RENAME invoices_v2 → invoices            [optionnel, tardif]
```

**Gain attendu** :
- `invoices` (JSON blob) : 3-4 Go → `invoices_v2` : quelques Mo
- Requête facture client lourd : parse JSON 500 Ko en mémoire → SELECT B-tree indexé
- Dashboards financiers et audits : instantanés

**Exigences non négociables** :
- Checksum centime à chaque facture générée (`SUM(lignes) == en-tête ± 0.01€`)
- Immutabilité post-verrouillage (`is_locked = 1` → `InvoiceLockedException`)
- Arrondi par ligne (standard français, documenté et testé)
- Snapshot client dans `meta` JSON léger (SIRET, adresse au moment de la facturation)
- Migration idempotente (clé unique `number_int` → pas de doublon en cas de re-run)

→ **DDL complet, job de migration, dual-write, checklist, rollback : [doc 20](./20-GUIDE-MIGRATION-INVOICES.md)**

#### Rappel — comportement des index sur tables partitionnées

| Aspect | Comportement |
|--------|-------------|
| **Index locaux** | Chaque partition a son propre B-tree — `WHERE invoice_date = '2025-06-15'` ne scanne que `p2025` |
| **UNIQUE KEY** | Doit inclure la colonne de partition → PK `(id, invoice_date)` |
| **Clés étrangères** | Interdites sur tables partitionnées MySQL — contrainte applicative (Eloquent) |
| **INSERT** | Routage automatique vers la bonne partition, transparent pour l'app |
| **SELECT sans colonne de partition** | Scanne toutes les partitions — pas de gain |
| **DROP PARTITION** | Instantané — idéal pour purge annuelle après 10 ans |
| **REORGANIZE PARTITION** | Découpe `p_future` en nouvelle année + nouveau `p_future` |

> **Règle d'or** : ne partitionner que les tables où la volumétrie le justifie ET où les requêtes filtrent sur la colonne de partition.
> - `calls` (8.5 Go, filtre systématique sur `date`) → **partitionnement mensuel**
> - `invoice_lines` (4M+ rows sur 5 ans, requêtes par année fiscale) → **partitionnement annuel**
> - `invoices_v2` (~23K rows sur 5 ans) → **pas de partitionnement**

### Phase 2 bis — Finalisation de la migration tarification (en cours)

La migration vers `plan_rates` (normalisé) est déjà en cours côté équipe. Une fois confirmée stable :

```sql
-- Quand l'équipe valide que plan_rates est la source de vérité :
DROP TABLE IF EXISTS pricing_zones;
DROP TABLE IF EXISTS pricing_geographical_zone;
DROP TABLE IF EXISTS pricing_zone_country;
DROP TABLE IF EXISTS plan_rates_bkp;
DROP TABLE IF EXISTS geographical_zone_supplier_bkp;
DROP TABLE IF EXISTS supplier_zone_countries_bkp;
```

**Pré-requis** : Confirmation par l'équipe que tout le code utilise `plan_rates` et plus `pricing_zones`.

### Phase 3 — Optimisations secondaires

#### 3.1 Enrichir `monthly_summaries`

```sql
ALTER TABLE monthly_summaries
    ADD COLUMN `client_id` bigint UNSIGNED DEFAULT NULL AFTER `line_id`,
    ADD COLUMN `total_charge` decimal(15,4) NOT NULL DEFAULT 0,
    ADD COLUMN `total_price` decimal(15,4) NOT NULL DEFAULT 0,
    ADD INDEX `idx_client_month` (`client_id`, `month`);

-- Remplissage
UPDATE monthly_summaries ms
JOIN lines l ON ms.line_id = l.id
SET ms.client_id = l.client_id;
```

#### 3.2 Créer `monthly_iot_summaries`

```sql
CREATE TABLE `monthly_iot_summaries` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` bigint UNSIGNED NOT NULL,
    `line_id` bigint UNSIGNED NOT NULL,
    `sim_id` bigint UNSIGNED DEFAULT NULL,
    `month` date NOT NULL,
    `total_sessions` int UNSIGNED NOT NULL DEFAULT 0,
    `total_data_up_bytes` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_data_down_bytes` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_data_bytes` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_sms` int UNSIGNED NOT NULL DEFAULT 0,
    `total_charge` decimal(15,4) NOT NULL DEFAULT 0,
    `total_price` decimal(15,4) NOT NULL DEFAULT 0,
    `avg_quota_used_pct` decimal(5,2) DEFAULT NULL,
    `peak_quota_used_pct` decimal(5,2) DEFAULT NULL,
    `days_with_alert` int UNSIGNED NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `monthly_iot_unique` (`client_id`, `line_id`, `month`),
    KEY `idx_client_month` (`client_id`, `month`),
    KEY `idx_sim_month` (`sim_id`, `month`),
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
    FOREIGN KEY (`line_id`) REFERENCES `lines` (`id`)
) ENGINE=InnoDB;
```

**Colonnes spécifiques** : `avg_quota_used_pct` (moyenne mensuelle), `peak_quota_used_pct` (pic), `days_with_alert` — agrégations depuis les daily IoT. Utile pour l'IA détection d'anomalies (doc 10).

**Rétention** : 7 ans (cf. §8.3). Volume négligeable.

**Job d'agrégation** : Exécuté le 1er de chaque mois, agrège les `daily_iot_summaries` du mois précédent.

#### 3.3 Créer `monthly_ucaas_summaries`

```sql
CREATE TABLE `monthly_ucaas_summaries` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` bigint UNSIGNED NOT NULL,
    `line_id` bigint UNSIGNED NOT NULL,
    `month` date NOT NULL,
    `total_calls_in` int UNSIGNED NOT NULL DEFAULT 0,
    `total_calls_out` int UNSIGNED NOT NULL DEFAULT 0,
    `total_duration_in_seconds` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_duration_out_seconds` bigint UNSIGNED NOT NULL DEFAULT 0,
    `total_conferences` int UNSIGNED NOT NULL DEFAULT 0,
    `total_conference_minutes` int UNSIGNED NOT NULL DEFAULT 0,
    `total_messages` int UNSIGNED NOT NULL DEFAULT 0,
    `total_voicemails` int UNSIGNED NOT NULL DEFAULT 0,
    `total_charge` decimal(15,4) NOT NULL DEFAULT 0,
    `total_price` decimal(15,4) NOT NULL DEFAULT 0,
    `active_days` int UNSIGNED NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `monthly_ucaas_unique` (`client_id`, `line_id`, `month`),
    KEY `idx_client_month` (`client_id`, `month`),
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
    FOREIGN KEY (`line_id`) REFERENCES `lines` (`id`)
) ENGINE=InnoDB;
```

**Colonne spécifique** : `active_days` — nombre de jours avec activité dans le mois, utile pour détecter les postes UCaaS inactifs (optimisation coûts licences Wazo).

**Rétention** : 7 ans (cf. §8.3). Volume négligeable.

#### 3.4 Ajouter `collaborator_id` manquant sur `sims`

La table `sims` a `client_id` et `line_id` mais pas de lien direct vers `collaborators`. Le lien passe par `lines.collaborator_id`. Pas critique mais utile pour les requêtes directes.

---

## 6. Diagramme des relations principales

```
                          ┌──────────────┐
                          │    users     │
                          │ (admin,      │
                          │  client,     │
                          │  ambassador) │
                          └──────┬───────┘
                                 │
              ┌──────────────────┼──────────────────┐
              │                  │                   │
     ┌────────▼──────┐  ┌───────▼──────┐  ┌────────▼────────┐
     │  client_user  │  │partner_client│  │model_has_roles  │
     └────────┬──────┘  └───────┬──────┘  └─────────────────┘
              │                 │
     ┌────────▼─────────────────▼──┐
     │          clients            │
     │ (name, company, iban,       │
     │  bic, segmentation,         │
     │  parent_id, wazo_uuid)      │
     └──┬───┬───┬───┬───┬───┬─────┘
        │   │   │   │   │   │
        │   │   │   │   │   └──── addresses (polymorphic)
        │   │   │   │   │
        │   │   │   │   └──── referents
        │   │   │   │
        │   │   │   └──── groups ──── sub_groups
        │   │   │              │
        │   │   │              └──── client_collaborator
        │   │   │
        │   │   └──── invoices (JSON doc ⚠️)
        │   │              │
        │   │              ├──── invoiced (polymorphic)
        │   │              └──── invoice_cdrs (compressed blob)
        │   │
        │   └──── lines ──────────────────────────┐
        │           │                              │
        │           ├──── sims                     │
        │           ├──── line_plan ── plans        │
        │           ├──── services                 │
        │           ├──── device_line ── devices   │
        │           │                    │
        │           │                    ├── device_client
        │           │                    ├── device_stock
        │           │                    └── device_collaborator
        │           │
        │           └──── calls (12 Go+ ⚠️)
        │                   │
        │                   ├── call_types ── units
        │                   ├── telecom_types
        │                   └── cdr_files
        │
        └──── collaborators
```

---

## 7. Résumé des actions prioritaires sur la BDD

| Priorité | Action | Impact | Effort | Risque |
|----------|--------|--------|--------|--------|
| 🔴 P0 | Ajouter `client_id` sur `calls` + backfill | Performance CDR | 1 jour | Faible |
| 🔴 P0 | Créer `daily_call_summaries` + job | Dashboards rapides | 2 jours | Faible |
| 🔴 P0 | Créer `daily_iot_summaries` + job | Dashboards IoT | 1 jour | Faible |
| 🔴 P0 | Créer `daily_ucaas_summaries` + job | Dashboards UCaaS | 1 jour | Faible |
| 🔴 P1 | **Partitionnement `calls`** — migration + séparation IoT/UCaaS | Performance + archivage instantané | ~1h maintenance | Moyen — [doc 19](./19-GUIDE-MIGRATION-CALLS.md) |
| 🔴 P1 | **Partitionnement `calls_archive`** | Purge `DROP PARTITION` | ~30 min maintenance | Faible — [doc 19](./19-GUIDE-MIGRATION-CALLS.md) |
| 🟠 P1 | **Refonte `invoices`** — JSON blob → `invoices_v2` + `invoice_lines` | Performance facturation | Plusieurs semaines (background) | Moyen — [doc 20](./20-GUIDE-MIGRATION-INVOICES.md) |
| 🟡 P2 | Suppression tables `_bkp` (après validation équipe) | Propreté | 0.5 jour | Faible |
| 🟡 P2 | Finaliser migration tarification (déjà en cours) | Maintenabilité | À confirmer | Faible |
| 🟡 P2 | Enrichir `monthly_summaries` (client_id, financier) | Reporting | 1 jour | Faible |
| 🟡 P2 | Créer `monthly_iot_summaries` + job agrégation | Historique IoT long terme | 1 jour | Faible |
| 🟡 P2 | Créer `monthly_ucaas_summaries` + job agrégation | Historique UCaaS long terme | 1 jour | Faible |
| 🟡 P2 | Jobs de purge (daily > 18-24 mois, cold storage archive) | Conformité RGPD + performance | 1 jour | Faible |
| 🟢 P3 | Refonte `devis` (sortie du JSON) | Cohérence | 2-3 jours | Moyen |

---

## 8. Politique de rétention des données

### 8.1 Tables de summaries — structure complète

La séparation des CDR en 3 tables distinctes (`calls`, `call_iots`, `call_ucass`) implique une symétrie identique pour les agrégations :

| Domaine | CDR bruts | Daily (NOUVEAU V2) | Monthly |
|---------|-----------|---------------------|---------|
| **Mobile/Fixe/Internet** | `calls` | `daily_call_summaries` | `monthly_summaries` (existante, à enrichir §3.1) |
| **IoT M2M** | `call_iots` | `daily_iot_summaries` | `monthly_iot_summaries` (NOUVEAU V2) |
| **UCaaS/Wazo** | `call_ucass` | `daily_ucaas_summaries` | `monthly_ucaas_summaries` (NOUVEAU V2) |

**Pourquoi des tables monthly séparées ?** Les métriques agrégées sont structurellement différentes :
- **Mobile** : minutes voix, SMS, MMS, data Mo — structure de `monthly_summaries` existante
- **IoT** : volume data (Mo/Go), nombre de sessions, quotas SIM, alertes dépassement
- **UCaaS** : minutes VoIP, nombre de conférences, messages, postes actifs

Forcer ces 3 profils dans une seule table `monthly_summaries` ajouterait des dizaines de colonnes nullable. La séparation est plus propre et cohérente avec la logique CDR.

### 8.2 Chaîne de dépendance et recalculabilité

```
CDR bruts ──────► Daily summaries ──────► Monthly summaries
(source)          (recalculable             (recalculable
                   depuis CDR)               depuis daily)
```

- Les **daily** sont recalculables depuis les CDR bruts tant qu'ils existent
- Les **monthly** sont recalculables depuis les daily
- Une fois les CDR purgés, les daily deviennent la source de vérité
- Une fois les daily purgés, les monthly deviennent la **seule trace historique**

Cette chaîne dicte la politique de rétention : on conserve plus longtemps ce qui est en bout de chaîne.

### 8.3 Durées de rétention par type de table

#### CDR bruts (détail appel par appel)

| Table | En ligne | Archive | Total | Justification |
|-------|----------|---------|-------|---------------|
| `calls` | **12 mois** | **+ 4 ans** (archive) | **5 ans** | Obligation légale télécom (L34-1 CPCE : 1 an facturation, 5 ans contentieux). Table déjà 12 Go — au-delà de 12 mois, archivage nécessaire |
| `call_iots` | **6 mois** | **+ 2,5 ans** (archive) | **3 ans** | Volume potentiellement massif (millions/mois). Moins de contentieux IoT, mais audit quotas B2B nécessaire |
| `call_ucass` | **12 mois** | **+ 2 ans** (archive) | **3 ans** | CDR VoIP — même valeur probante que mobile |

#### Agrégations quotidiennes

| Table | Rétention | Justification |
|-------|-----------|---------------|
| `daily_call_summaries` | **24 mois** | Dashboards, détection d'anomalies IA (doc 10), comparaison N vs N-1 |
| `daily_iot_summaries` | **18 mois** | Idem, volume par ligne moins granulaire |
| `daily_ucaas_summaries` | **18 mois** | Idem IoT |

> **Note** : Au-delà de la rétention, les daily sont purgés car les monthly prennent le relais pour l'historique long.

#### Agrégations mensuelles

| Table | Rétention | Justification |
|-------|-----------|---------------|
| `monthly_summaries` | **Illimitée** (ou 10 ans) | Volume négligeable (1 ligne/mois/ligne télécom). Source unique pour l'IA optimisation forfait (doc 10 §1.2), rapports annuels, historique client |
| `monthly_iot_summaries` | **7 ans** | Idem — quelques centaines de lignes/mois, coût de stockage quasi nul |
| `monthly_ucaas_summaries` | **7 ans** | Idem |

#### Factures

| Table | Rétention | Justification |
|-------|-----------|---------------|
| `invoices_v2` + `invoice_lines` | **10 ans minimum** | Obligation comptable (Code de commerce L123-22). Aucune purge automatique |

### 8.4 Stratégie technique d'archivage des CDR

**Recommandation : Partitionnement MySQL par mois** (préféré à une table d'archive séparée)

```sql
-- Exemple pour calls — même logique applicable à call_iots et call_ucass
ALTER TABLE calls PARTITION BY RANGE (YEAR(start_date) * 100 + MONTH(start_date)) (
    PARTITION p202501 VALUES LESS THAN (202502),
    PARTITION p202502 VALUES LESS THAN (202503),
    PARTITION p202503 VALUES LESS THAN (202504),
    -- ... partitions mensuelles
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

**Avantages du partitionnement vs table d'archive :**

| Critère | Partitionnement | Table archive séparée |
|---------|-----------------|----------------------|
| Transparence | Requêtes inchangées, MySQL optimise automatiquement | Nécessite UNION ou logique applicative |
| Purge | `ALTER TABLE DROP PARTITION` = instantané | `DELETE` = lent + fragmentation |
| Maintenance | Aucun job à maintenir | Job mensuel INSERT + DELETE |
| Requêtes cross-période | Transparentes | Complexes (UNION ALL) |

**Job de gestion des partitions** (à planifier mensuellement) :
```sql
-- 1. Créer la partition du mois suivant (à exécuter le 25 de chaque mois)
ALTER TABLE calls REORGANIZE PARTITION p_future INTO (
    PARTITION p202604 VALUES LESS THAN (202605),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- 2. Purger les partitions au-delà de la rétention (CDR > 5 ans)
ALTER TABLE calls DROP PARTITION p202101;  -- instantané, pas de DELETE row-by-row
```

**Job de purge des daily summaries** (mensuel, le 1er de chaque mois) :
```php
// App\Jobs\PurgeStaleDailySummaries
DB::table('daily_call_summaries')->where('date', '<', now()->subMonths(24))->delete();
DB::table('daily_iot_summaries')->where('date', '<', now()->subMonths(18))->delete();
DB::table('daily_ucaas_summaries')->where('date', '<', now()->subMonths(18))->delete();
```

### 8.5 Schéma visuel de rétention

```
                        │ En ligne       │ Archive / froid   │ Purgé
────────────────────────┼────────────────┼───────────────────┼──────
CDR calls               │◄── 12 mois ──►│◄──── + 4 ans ────►│ purge
CDR call_iots           │◄── 6 mois  ──►│◄──── + 2,5 ans ──►│ purge
CDR call_ucass          │◄── 12 mois ──►│◄──── + 2 ans ────►│ purge
                        │                │                    │
Daily call summaries    │◄──── 24 mois ────────────────────►│ purge
Daily IoT/UCaaS summ.   │◄──── 18 mois ────────────────────►│ purge
                        │                │                    │
Monthly summaries       │◄──────────── illimité / 7-10 ans ─────────►│
                        │  (coût négligeable)                │
                        │                │                    │
Invoices                │◄──────────── 10 ans minimum (loi) ────────►│
```

### 8.6 Références légales

| Texte | Obligation | Tables concernées |
|-------|-----------|-------------------|
| **L34-1 CPCE** (Code des postes et communications électroniques) | Conservation des données de trafic 1 an pour facturation | `calls`, `call_iots`, `call_ucass` |
| **L34-1-1 CPCE** | Conservation jusqu'à 5 ans sur réquisition judiciaire | CDR archivés |
| **Code de commerce L123-22** | Conservation pièces comptables 10 ans | `invoices_v2`, `invoice_lines` |
| **RGPD Art. 5(1)(e)** | Limitation de la conservation au strict nécessaire | Justifie la purge des daily et CDR bruts |

> **Note RGPD** : Les CDR contiennent des données personnelles (numéros appelés). La purge automatique après la durée légale n'est pas seulement une optimisation technique — c'est une **obligation réglementaire**.
