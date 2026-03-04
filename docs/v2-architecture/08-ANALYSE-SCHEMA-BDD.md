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
- La taille de `doc` est proportionnelle au nombre de lignes par client (un client avec 200 lignes = un JSON massif)
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

### Module **Client**
| Table | Rôle |
|-------|------|
| `clients` | Entité centrale |
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

### Module **Telecom** (mobile, fixe, internet — hors IoT et UCaaS)
| Table | Rôle |
|-------|------|
| `lines` | Lignes télécom (mobile, fixe, internet) — filtrées par `telecom_types` |
| `sims` | Cartes SIM classiques (non-IoT) avec ICCID, IMSI, PIN/PUK |
| `line_plan` | Pivot ligne ↔ forfait avec dates et prix |
| `telecom_types` | Types de service (mobile, fixe, internet, IoT, UCaaS) — référentiel partagé |
| `portabilities` | Portabilités effectuées (via Transatel) |
| `portabilities_pending` | Portabilités en attente |
| `mobile_plan_buyings` | Achats forfaits mobile |

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

### Module **Infogérance** (parc informatique clients)
| Table | Rôle |
|-------|------|
| `collaborators` | Contacts gérés dans le cadre de l'infogérance pour les clients |
| `client_collaborator` | Pivot collaborateurs ↔ clients avec dates |
| `device_collaborator` | Affectation appareil ↔ collaborateur |

> **Note** : Les collaborateurs ne sont PAS des utilisateurs de la plateforme. Ce sont des contacts du parc informatique client, avec un lien éventuel vers GLPI (`id_glpi`). Le module Infogérance peut générer des lignes de facture (prestation de service) via le contrat `Billable`.

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
Facture 1 client avec 200 lignes :
- JSON doc ≈ 50-200 Ko par facture
- 380 clients × 12 factures/an = 4 560 factures/an
- Taille estimée après 3 ans : ~2.7 Go de JSON pur (sans index)
- Chaque SELECT charge le JSON sauf si on utilise explicitement SELECT sans doc
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

#### 1.3 Partitionner `calls`

```sql
-- Étape 1 : Recréer la table avec partitionnement
-- ⚠️ Le partitionnement MySQL nécessite que la colonne de partition
-- fasse partie de la PRIMARY KEY et de tous les UNIQUE KEY

-- Problème : calls a une UNIQUE KEY sur provider_call_id qui n'inclut pas date
-- Solution : changer la UNIQUE KEY en un index normal + contrainte applicative
-- OU inclure date dans la UNIQUE KEY

ALTER TABLE calls DROP INDEX calls_provider_call_id_unique;
ALTER TABLE calls ADD UNIQUE KEY `calls_provider_date_unique` (`provider_call_id`, `date`);

-- Puis partitionner par mois sur YEAR(date)*100+MONTH(date)
-- ⚠️ Les FK sur la table calls doivent être supprimées avant le partitionnement
-- MySQL ne supporte pas les FK sur les tables partitionnées

-- Alternative recommandée : ne pas partitionner directement,
-- mais implémenter l'archivage via un job qui déplace les vieux CDR
```

**Point d'attention CRITIQUE** : MySQL ne supporte pas les clés étrangères sur les tables partitionnées. La table `calls` a des FK (`line_id → lines`, `telecom_type_id → telecom_types`, `call_type_id → call_types`, `cdr_file_id → cdr_files`).

**Options** :
1. **Supprimer les FK sur `calls`** → les contraintes deviennent applicatives. Acceptable car `calls` est une table d'ingestion de données externes.
2. **Ne pas partitionner, mais archiver** → un job mensuel déplace les CDR > 12 mois vers une table `calls_archive` (identique mais sans FK) et purge la table principale.
3. **Les deux** → archiver + partitionner la table d'archive.

**Recommandation** : Option 2 (archivage) en Phase 1, Option 3 si la volumétrie l'exige.

### Phase 2 — Refonte de la table `invoices`

#### Schéma cible

```sql
-- Nouvelle structure normalisée
CREATE TABLE `invoices_v2` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `client_id` bigint UNSIGNED NOT NULL,
    `number` varchar(20) NOT NULL,
    `number_int` bigint UNSIGNED NOT NULL,
    `label` varchar(255) DEFAULT NULL,
    `date` date NOT NULL,
    `due_date` date DEFAULT NULL,
    `period_start` date DEFAULT NULL,
    `period_end` date DEFAULT NULL,

    -- Totaux pré-calculés
    `amount_ht` decimal(10,2) NOT NULL DEFAULT 0,
    `amount_tva` decimal(10,2) NOT NULL DEFAULT 0,
    `amount_ttc` decimal(10,2) NOT NULL DEFAULT 0,

    -- Statuts
    `status` tinyint UNSIGNED NOT NULL DEFAULT 0,
    `is_paid` tinyint(1) NOT NULL DEFAULT 0,
    `is_locked` tinyint(1) NOT NULL DEFAULT 0,
    `paid_at` datetime DEFAULT NULL,

    -- Paiement
    `payment_method` varchar(50) DEFAULT NULL,
    `payment_reference` varchar(100) DEFAULT NULL,

    -- Document PDF
    `document_id` bigint UNSIGNED DEFAULT NULL,

    -- Métadonnées (léger, pas le contenu complet)
    `meta` json DEFAULT NULL,

    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    `deleted_at` timestamp NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    UNIQUE KEY `invoices_v2_number_int_unique` (`number_int`),
    KEY `idx_client_date` (`client_id`, `date`),
    KEY `idx_status` (`status`),
    KEY `idx_paid_locked` (`client_id`, `is_locked`, `is_paid`),
    FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`),
    FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `invoice_lines` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `invoice_id` bigint UNSIGNED NOT NULL,
    `line_id` bigint UNSIGNED DEFAULT NULL,
    `service_id` bigint UNSIGNED DEFAULT NULL,
    `device_id` bigint UNSIGNED DEFAULT NULL,
    `type` enum('plan','service','device','option','out_of_plan','adjustment','discount') NOT NULL,
    `label` varchar(500) NOT NULL,
    `description` text DEFAULT NULL,
    `quantity` decimal(10,3) NOT NULL DEFAULT 1,
    `unit_price_ht` decimal(10,4) NOT NULL DEFAULT 0,
    `amount_ht` decimal(10,2) NOT NULL DEFAULT 0,
    `tva_rate` decimal(5,2) NOT NULL DEFAULT 20.00,
    `amount_tva` decimal(10,2) NOT NULL DEFAULT 0,
    `amount_ttc` decimal(10,2) NOT NULL DEFAULT 0,
    `sort_order` int NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,

    PRIMARY KEY (`id`),
    KEY `idx_invoice` (`invoice_id`),
    KEY `idx_line` (`line_id`),
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices_v2` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`line_id`) REFERENCES `lines` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;
```

#### Stratégie de migration — NE PAS modifier la table existante

**Principe fondamental** : On ne touche **jamais** à la structure de la table `invoices` actuelle. La V1 continue de fonctionner exactement comme avant pendant toute la transition. On crée les nouvelles tables **à côté**.

```
Phase A — Création (pas d'impact sur la V1)
   1. Créer invoices_v2 et invoice_lines (vides, à côté de invoices)
   2. La V1 continue de lire/écrire dans invoices normalement

Phase B — Migration des données historiques
   3. Script de migration batch (job queue) :
      a. Pour chaque invoice existante :
         - Parse le JSON doc
         - Insère l'en-tête dans invoices_v2
         - Parse les lignes du JSON et insère dans invoice_lines
         - Recalcule et vérifie les totaux (checksum)
      b. Vérification : comparer count + sum(amount) entre les deux tables

Phase C — Dual-write (transition)
   4. Le nouveau code de facturation V2 écrit dans les deux tables :
      - invoices (JSON blob, pour la V1 qui lit encore)
      - invoices_v2 + invoice_lines (normalisé, pour le nouveau code V2)
   5. Le nouveau code V2 LIT uniquement depuis invoices_v2 + invoice_lines
   6. La V1 continue de lire depuis invoices (aucun changement)

Phase D — Bascule finale (quand V1 éteinte)
   7. Renommer invoices → invoices_legacy (conservation 6 mois par sécurité)
   8. Renommer invoices_v2 → invoices
   9. Arrêter le dual-write
   10. Supprimer invoices_legacy après confirmation
```

**Pourquoi cette approche** :
- La table `invoices` avec son JSON blob est utilisée dans tout le code V1 (enregistrement ET lecture)
- Modifier la structure existante obligerait à modifier le code V1 à **de nombreux endroits**
- En créant de nouvelles tables à côté, le code V1 n'est **jamais** impacté
- Le risque de régression est minimal : la V1 ne change pas, la V2 utilise ses propres tables

**Gain estimé** :
- `invoices_v2` sans JSON : quelques Mo au lieu de Go
- `invoice_lines` : requêtable, indexable, analysable
- Les dashboards financiers deviennent instantanés

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

#### 3.2 Ajouter `collaborator_id` manquant sur `sims`

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
| 🔴 P1 | Archivage CDR > 12 mois | Taille table `calls` | 2 jours | Moyen |
| 🟠 P1 | Refonte `invoices` (sortie du JSON blob) | Performance facturation | 5-7 jours | Élevé (migration données) |
| 🟡 P2 | Suppression tables `_bkp` (après validation équipe) | Propreté | 0.5 jour | Faible |
| 🟡 P2 | Finaliser migration tarification (déjà en cours) | Maintenabilité | À confirmer | Faible (migration pilotée) |
| 🟡 P2 | Enrichir `monthly_summaries` (client_id, financier) | Reporting | 1 jour | Faible |
| 🟢 P3 | Refonte `devis` (sortie du JSON) | Cohérence | 2-3 jours | Moyen |
