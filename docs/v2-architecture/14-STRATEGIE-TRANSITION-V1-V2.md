# Stratégie de Transition V1 → V2 — Analyse comparative

## Contexte

| Élément | V1 actuelle | V2 cible |
|---------|-------------|----------|
| Stack | Laravel 10, Livewire 2, Blade, Bootstrap, MySQL | Laravel 12, Livewire 3, Tailwind, MySQL 8 multi-BDD |
| Architecture | Monolithe couplé, 3 portails dans 1 app | Monolithe modulaire DDD-lite, API-first, multi-région |
| Hébergement | On-premise (serveur local) | Scaleway cloud (Docker) |
| Multi-tenancy | Aucun | stancl/tenancy v3 (database-per-tenant, Hub & Spoke) |
| BDD | 1 MySQL avec ~80 tables, `calls` 12 Go, `invoices.doc` JSON blob | 1 BDD centrale + 1 BDD par région |
| Clients | 380 clients, 6 000 lignes, ~12 utilisateurs internes | Multi-région avec isolation par franchise |
| Équipe | 2 développeurs | 2-3 développeurs |

**Question centrale** : Comment passer de la V1 à la V2 tout en gérant la base de données existante (380 clients, 12 Go de CDR, factures en JSON blob) ?

---

## Les 3 stratégies analysées

### Stratégie A — Nouveau projet from scratch + migration de données
### Stratégie B — Même repo, transformation progressive (Strangler Fig)
### Stratégie C — Nouveau dossier V2 dans le même repo, nouvelles tables sur la BDD existante

---

## Stratégie A — Repartir de zéro (nouveau projet)

### Principe

Créer un nouveau projet Laravel 12 vierge avec la structure modulaire DDD-lite, installer stancl/tenancy, définir toutes les tables V2, puis migrer les données de la V1 via des scripts ETL.

```
V1 (existante)                    V2 (nouveau projet)
┌──────────────────┐              ┌──────────────────┐
│ Laravel 10       │    ETL       │ Laravel 12       │
│ 80+ tables       │ ──────────► │ Tables V2        │
│ monolithe        │  (scripts)  │ DDD-lite         │
│ BDD unique       │              │ stancl/tenancy   │
└──────────────────┘              └──────────────────┘
     ↓ éteinte                         ↓ production
```

### Pour

| Avantage | Détail |
|----------|--------|
| **Architecture propre dès le départ** | Aucune dette technique héritée. Structure modulaire parfaite dès le jour 1. |
| **Schéma BDD optimal** | Tables conçues pour la V2 : `invoices` normalisé, CDR séparés (mobile/IoT/UCaaS), agrégations intégrées. Pas de compromis avec l'existant. |
| **Aucun risque de régression V1** | Les deux systèmes sont totalement indépendants. La V1 continue de tourner pendant le développement. |
| **Nommage et conventions idéaux** | Pas besoin de supporter les noms de colonnes historiques, pas de colonnes `_bkp`, pas de `GENERATED STORED`. |
| **Multi-tenant natif** | stancl/tenancy configuré dès le début, pas d'ajustement rétroactif. |
| **Liberté technologique totale** | Livewire 3, Tailwind, Alpine.js, Reverb, Scout... tout en partant de zéro, zéro coexistence. |

### Contre

| Inconvénient | Détail | Sévérité |
|-------------|--------|----------|
| **Migration de données = projet dans le projet** | 80+ tables à transformer, ~12 Go de CDR, invoices JSON à parser, relations polymorphiques à recréer. C'est 3-6 semaines de travail rien que pour l'ETL. | **CRITIQUE** |
| **Période de freeze fonctionnel** | Pendant le développement V2, la V1 ne peut plus évoluer (ou les évolutions doivent être portées dans les deux). Avec 380 clients, un freeze de 6+ mois est risqué commercialement. | **CRITIQUE** |
| **Big-bang à la bascule** | Le jour J, tout doit marcher : facturation, imports CDR, portails clients, intégrations fournisseurs. Si un seul domaine échoue, rollback impossible (les données ont divergé). | **CRITIQUE** |
| **Pas de livraison incrémentale** | Pas de valeur livrée aux utilisateurs pendant des mois. L'équipe travaille "dans le noir". | ÉLEVÉ |
| **2 développeurs = irréaliste** | Réécrire 80+ tables, 20+ modules métier, 3 portails, 5 intégrations fournisseurs, les imports CDR, la facturation... en partant de zéro avec 2 devs, c'est 12-18 mois minimum. | **CRITIQUE** |
| **Perte de connaissance métier** | Le code V1 contient des règles métier implicites (cas limites facturation, gestion des portabilités, calculs carbone). Repartir de zéro = risque de les oublier. | ÉLEVÉ |
| **Double maintenance temporaire** | Bug en prod V1 + développement V2 en parallèle. Avec 2 devs, l'un maintient la V1 pendant que l'autre construit la V2 → division des forces. | ÉLEVÉ |

### Migration des données : le vrai défi

```
Données à migrer :
├── clients (380)                    → Mapping direct, relativement simple
├── collaborators (~2000?)           → Mapping direct
├── lines (6 000)                    → Mapping + nettoyage statuts
├── sims (~6 000)                    → Mapping + validation ICCID/IMSI
├── calls (~12 Go, millions de rows) → ⚠️ LA plus grosse difficulté
│   ├── Restructurer en calls_mobile / calls_iot / calls_ucaas
│   ├── Ajouter client_id (backfill via JOIN lines)
│   ├── Migrer par batch (100K rows/batch) → plusieurs heures
│   └── Risque de données orphelines (line_id supprimé)
├── invoices (JSON blob)             → ⚠️ DEUXIÈME plus grosse difficulté
│   ├── Parser chaque JSON doc
│   ├── Extraire en-tête → invoices_v2
│   ├── Extraire lignes → invoice_lines
│   ├── Vérifier checksums (montants JSON vs colonnes)
│   └── Gérer les cas JSON mal formés ou incomplets
├── monthly_summaries                → Mapping + enrichissement
├── devices, stocks, orders          → Mapping direct
├── portabilities                    → Mapping direct
├── tickets + messages               → Mapping direct
├── prospects + devis (JSON)         → Parser JSON devis
├── partners (ambassadeurs)          → Mapping direct
├── RBAC (users, roles, permissions) → Recréation via seeders V2
├── media (spatie/medialibrary)      → Mapping + migration fichiers physiques
├── tags                             → Mapping
└── transactions                     → Mapping direct

Estimation : 3-6 semaines de développement ETL
             + 2-4 heures de migration le jour J (fenêtre de downtime)
             + 1 semaine de validation post-migration
```

### Verdict : RISQUE TROP ÉLEVÉ pour une équipe de 2

---

## Stratégie B — Même repo, transformation progressive (Strangler Fig)

### Principe

La V1 existante est progressivement transformée module par module. Le nouveau code V2 coexiste avec l'ancien. Les tables sont ajoutées/modifiées incrémentalement. La V1 actuelle devient le premier tenant. C'est **l'approche recommandée dans la doc d'architecture existante** (00-SYNTHESE-EXECUTIVE.md).

```
┌────────────────────────────────────────────────────────────────────────┐
│                          MÊME CODEBASE                                 │
│                                                                        │
│  Phase 0    Phase 1         Phase 2          Phase 3       Phase 4     │
│ ┌────────┐ ┌────────────┐ ┌──────────────┐ ┌───────────┐ ┌─────────┐ │
│ │ V1     │ │V1 + tenant │ │V1/V2 hybride │ │V2 complet │ │V2 + N   │ │
│ │ telle  │→│+ Redis     │→│+ modules DDD │→│+ portails │→│régions  │ │
│ │ quelle │ │+ Docker    │ │+ invoices_v2 │ │+ cloud    │ │         │ │
│ │        │ │+ CI/CD     │ │+ CDR splitté │ │           │ │         │ │
│ └────────┘ └────────────┘ └──────────────┘ └───────────┘ └─────────┘ │
│                                                                        │
│  BDD:        BDD:          BDD:             BDD:          BDD:        │
│  existante   + central     + tables V2      - tables V1   N régions   │
│  inchangée   + tenant cfg  à côté de V1     obsolètes     autonomes   │
│              V1=1er tenant dual-write        supprimées                │
└────────────────────────────────────────────────────────────────────────┘
```

### Pour

| Avantage | Détail |
|----------|--------|
| **Zéro migration de données initiale** | La BDD V1 existante DEVIENT la BDD du premier tenant (renommée en `cekoya_idf`). Les données restent en place. Pas d'ETL, pas de big-bang. |
| **Livraison incrémentale** | Chaque phase livre de la valeur : Phase 0 = monitoring + CI/CD, Phase 1 = performance CDR, etc. Les utilisateurs voient des améliorations continues. |
| **Pas de freeze fonctionnel** | La V1 continue de fonctionner normalement à chaque phase. Les bugs V1 sont corrigés en temps réel. Pas de divergence de données. |
| **Rollback facile** | Si un module V2 a un problème, on peut désactiver le feature flag et revenir au code V1 pour ce module. |
| **Validation progressive** | Les données sont validées au fil de l'eau (dual-write invoices, backfill `client_id` sur `calls`, etc.), pas en un seul big-bang. |
| **Équipe de 2 = réaliste** | Chaque phase dure 1-3 mois et produit un résultat concret. L'équipe reste focalisée sur un objectif à la fois. |
| **Connaissance métier préservée** | Le code V1 existant sert de référence. Les règles métier sont migrées une par une, pas réinventées. |
| **Multi-tenant = ajout, pas remplacement** | stancl/tenancy s'installe sur le codebase existant. La V1 devient le premier tenant (IDF). Le code ne change quasiment pas — seule la config change. |

### Contre

| Inconvénient | Détail | Sévérité |
|-------------|--------|----------|
| **Coexistence V1/V2 = complexité** | Pendant la transition, le codebase contient du code V1 (ancien) et V2 (nouveau) qui cohabitent. Risque de confusion sur "quel code utiliser". | MOYEN |
| **Dual-write = logique supplémentaire** | Pour `invoices`, il faut écrire dans l'ancien format JSON ET dans les nouvelles tables `invoices_v2` + `invoice_lines` pendant la phase de transition. | MOYEN |
| **Les tables V1 restent un moment** | Les anciennes tables (avec leur dette) restent en BDD pendant toute la phase de transition. Le schéma est "sale" temporairement. | FAIBLE |
| **Tentant de prendre des raccourcis** | Sous pression, un dev peut "brancher" du code V2 sur une table V1 sans respecter l'isolation DDD → dette technique réinjectée. | MOYEN |
| **Laravel 10 → 12 = effort** | La montée de version Laravel est un pré-requis. Via Laravel Shift, c'est automatisable (2-3 jours), mais il faut tester. | FAIBLE |
| **Livewire 2 → 3 = effort** | La migration des composants Livewire est le plus gros chantier frontend. Coexistence possible mais les syntaxes diffèrent. | MOYEN |

### Comment ça marche concrètement pour la BDD

```
Phase 0 : La BDD V1 reste IDENTIQUE
├── On installe stancl/tenancy
├── On crée la BDD centrale (cekoya_central) avec : tenants, domains, sync_logs
├── On enregistre la V1 comme premier tenant : id='idf', database='cekoya_v1' (ou rename)
├── Le code V1 tourne exactement comme avant (les requêtes passent par la connexion tenant)
└── AUCUNE table modifiée, AUCUNE donnée touchée

Phase 1 : On AJOUTE des tables à côté
├── ALTER TABLE calls ADD COLUMN client_id (nullable) → backfill via job
├── CREATE TABLE daily_call_summaries (nouvelle, à côté de monthly_summaries)
├── CREATE TABLE invoices_v2 (à côté de invoices, vide au départ)
├── CREATE TABLE invoice_lines (à côté de invoices, vide au départ)
├── Script de migration : parse invoices.doc JSON → insère dans invoices_v2 + invoice_lines
├── Nouveau code V2 : lit/écrit dans invoices_v2 (READ from V2, WRITE to V1 + V2)
├── Ancien code V1 : continue de lire/écrire dans invoices (aucun changement)
└── Les deux systèmes cohabitent, les données sont synchronisées via dual-write

Phase 2 : On ÉTEINT les anciens chemins
├── Tout le code utilise invoices_v2 → on arrête le dual-write
├── RENAME TABLE invoices → invoices_legacy (conservation 6 mois)
├── RENAME TABLE invoices_v2 → invoices
├── Les tables _bkp sont supprimées
├── pricing_zones supprimé (plan_rates est la source de vérité)
└── Le schéma converge vers la cible V2

Phase 3 : Provisioning de nouvelles régions
├── La BDD du premier tenant est maintenant propre (schéma V2)
├── Créer une nouvelle région = CREATE DATABASE + migrations tenant + seed catalogue
├── Les nouvelles régions naissent directement en V2, pas de dette
└── La V1 n'existe plus — tout est V2
```

### Gestion du `calls` 12 Go

```
Étape 1 (Phase 1, jour 1) :
  ALTER TABLE calls ADD COLUMN client_id BIGINT UNSIGNED NULL;
  → Pas de downtime, nullable, la V1 ne le voit pas

Étape 2 (Phase 1, jours 2-3) :
  Job batch en queue (par tranches de 100K) :
  UPDATE calls c JOIN lines l ON c.line_id = l.id
  SET c.client_id = l.client_id
  WHERE c.client_id IS NULL LIMIT 100000;
  → Tourne en arrière-plan, pas d'impact sur la prod

Étape 3 (Phase 1, jour 4) :
  ALTER TABLE calls ADD INDEX idx_calls_client_date (client_id, date);
  → Les requêtes par client passent de JOIN à WHERE direct

Phase 2 : Séparation calls_mobile / calls_iot / calls_ucaas
  → Via vues SQL d'abord (zéro migration de données)
  → Puis tables séparées pour les nouveaux CDR (les anciens restent dans calls)
  → L'archivage des CDR > 12 mois dans S3 réduit la taille active

AUCUN ETL massif, AUCUN big-bang. Tout est incrémental.
```

### Verdict : STRATÉGIE RECOMMANDÉE

---

## Stratégie C — Nouveau dossier V2, mêmes tables sur la BDD existante

### Principe

Créer un nouveau dossier (ex: `app-v2/`) dans le même repo avec un projet Laravel 12 neuf. Ce projet V2 se connecte à la MÊME base de données que la V1 et crée ses nouvelles tables à côté des anciennes.

```
/repo
├── app/           ← V1 (Laravel 10, en production)
│   └── connecté à BDD existante
│
├── app-v2/        ← V2 (Laravel 12, en développement)
│   └── connecté à LA MÊME BDD
│       ├── lit les tables V1 existantes (clients, lines, calls...)
│       ├── crée de nouvelles tables V2 (invoices_v2, daily_call_summaries...)
│       └── ignore les tables V1 obsolètes
│
└── docs/
```

### Pour

| Avantage | Détail |
|----------|--------|
| **Codebase propre dès le départ** | Le code V2 est structuré en modules DDD-lite, sans aucun héritage V1. Nommage, conventions, architecture = parfaits. |
| **Accès direct aux données V1** | Pas besoin d'ETL : le code V2 lit directement les tables V1 (clients, lines, calls) via Eloquent. |
| **Coexistence opérationnelle** | V1 et V2 peuvent tourner en parallèle sur la même BDD. On migre les utilisateurs portail par portail. |
| **Multi-tenant natif** | stancl/tenancy installé directement dans app-v2/. La BDD existante = premier tenant. |
| **Pas de contraintes V1 dans le code** | Le code V2 n'a pas à supporter Livewire 2, Bootstrap, ou les patterns V1. Tout est neuf. |

### Contre

| Inconvénient | Détail | Sévérité |
|-------------|--------|----------|
| **Conflits de migrations** | Les deux projets écrivent dans la même BDD. Si V1 et V2 modifient la même table (ex: `clients`), les migrations peuvent entrer en conflit. Qui a la "propriété" de chaque table ? | **CRITIQUE** |
| **Conflits d'écritures concurrentes** | V1 et V2 écrivent dans les mêmes tables (clients, lines, invoices). Pas de lock applicatif partagé → risque de race conditions, données corrompues. | **CRITIQUE** |
| **Schéma hybride ingérable** | La BDD contient simultanément des tables V1 (nommage V1, colonnes V1), des tables V2 (nommage V2), et des tables partagées. Impossible de savoir "quel est le schéma de référence". | ÉLEVÉ |
| **2 processus PHP, 2 sessions, 2 auth** | L'utilisateur doit basculer entre V1 et V2 (URLs différentes). Pas de session partagée sauf mécanisme SSO custom. | ÉLEVÉ |
| **Double composer.json, double config** | Deux projets Laravel indépendants à maintenir : 2 × `composer update`, 2 × config DB, 2 × .env, 2 × Docker services. Charge de maintenance doublée. | ÉLEVÉ |
| **"Qui gère quoi ?"** | La V1 gère les imports CDR, la facturation, les portails client. La V2 commence à gérer certains modules. Pendant la transition, personne ne sait exactement quel système est responsable de quoi. | ÉLEVÉ |
| **Les modèles V2 doivent mapper les tables V1** | Le code V2 est "propre" mais ses modèles Eloquent doivent `protected $table = 'calls'` avec les noms de colonnes V1. L'abstraction est dans le code, pas dans les données → fausse propreté. | MOYEN |
| **Pas de dual-write structuré** | Si la V2 crée `invoices_v2`, il n'y a pas de mécanisme naturel pour synchroniser avec `invoices` (la V1 ne connaît pas la V2). Il faut un bridge custom. | ÉLEVÉ |
| **Tests d'intégration complexes** | Tester que V1 et V2 fonctionnent correctement ensemble sur la même BDD nécessite un framework de test cross-projet. | MOYEN |

### Le vrai problème : qui est la source de vérité ?

```
Scénario problématique :

1. V1 crée un client (écrit dans `clients` table)
2. V2 modifie le même client (écrit dans `clients` table)
3. V1 reçoit un import CDR (écrit dans `calls` table)
4. V2 agrège les CDR (lit `calls`, écrit dans `daily_call_summaries`)
5. V1 génère une facture (lit `calls` via JOIN, écrit dans `invoices` JSON)
6. V2 génère aussi une facture (lit `calls` via client_id, écrit dans `invoices_v2`)

Questions :
- Quelle facture est la bonne ?
- Si V1 ajoute une colonne à `clients`, V2 le sait-elle ?
- Si V2 supprime une colonne obsolète, V1 crashe-t-elle ?
- Les migrations V1 et V2 sont dans des dossiers différents → comment savoir l'état réel du schéma ?
```

### Quand cette stratégie marcherait

Elle pourrait fonctionner SI :
- La V2 est **read-only** sur les tables V1 (ne modifie jamais les données V1)
- La V1 est l'unique écrivain jusqu'à la bascule complète
- Les tables V2 sont 100% nouvelles (pas de modification des tables existantes)
- La bascule se fait portail par portail (client → V2, puis admin → V2, etc.)

Mais dans ce cas, c'est quasiment la **Stratégie A** avec un accès direct à la BDD V1 au lieu d'un ETL.

### Verdict : COMPLEXITÉ ACCIDENTELLE TROP ÉLEVÉE

---

## Tableau comparatif

| Critère | A — From scratch | B — Strangler Fig | C — Nouveau dossier |
|---------|:----------------:|:------------------:|:-------------------:|
| **Migration de données** | ETL massif (3-6 sem.) | Zéro migration initiale | Zéro (accès direct) |
| **Risque big-bang** | CRITIQUE | Aucun (incrémental) | Moyen |
| **Freeze fonctionnel** | 6-12 mois | Aucun | 3-6 mois partiel |
| **Propreté du code** | Parfaite | Bonne (coexistence temporaire) | Bonne (code V2 propre, tables hybrides) |
| **Propreté du schéma BDD** | Parfaite (cible) | Progressive → parfaite | Hybride (V1+V2 mélangées) |
| **Faisabilité 2 devs** | Non (12-18 mois) | Oui (10-12 mois) | Difficile (conflits) |
| **Livraison incrémentale** | Non | Oui (chaque phase livre) | Partielle |
| **Rollback possible** | Non | Oui (par module) | Partiel |
| **Risque régression V1** | Nul | Faible (tests) | Moyen (BDD partagée) |
| **Multi-tenant** | Natif dès le début | Ajouté Phase 0 | Natif dans V2 |
| **Source de vérité BDD** | Claire (V2 seule) | Claire (1 codebase) | Confuse (2 écrivains) |
| **Complexité opérationnelle** | Élevée (2 systèmes) | Faible (1 système) | Élevée (2 apps, 1 BDD) |

---

## Recommandation : Stratégie B (Strangler Fig)

### Pourquoi

1. **C'est la seule qui marche avec 2 développeurs**. Les stratégies A et C nécessitent une charge de travail parallèle (maintenir V1 + construire V2) qui dépasse la capacité de l'équipe.

2. **Zéro migration de données initiale**. La BDD V1 existante DEVIENT la BDD du premier tenant. Pas d'ETL, pas de risque de perte, pas de big-bang.

3. **Les problèmes de performance sont résolus en premier**. Le `client_id` sur `calls`, les tables d'agrégation, la sortie du JSON blob `invoices` — tout cela est livré dès la Phase 1, avant même la modularisation.

4. **Chaque phase livre de la valeur immédiate**. Phase 0 = CI/CD + monitoring. Phase 1 = performance + API. Phase 2 = architecture DDD. Phase 3 = portails modernes. Les utilisateurs voient des améliorations chaque trimestre.

5. **Le multi-tenant est un ajout, pas une réécriture**. stancl/tenancy s'installe sur le codebase existant en Phase 0. La V1 devient le premier tenant. Le code métier ne change quasiment pas — seul le routage et la config changent.

6. **Rollback garanti**. Si un module V2 a un problème, on revient au code V1 pour ce module. Pas de point de non-retour.

### Ce qui existe déjà dans le repo

Le dossier `app/` contient déjà un projet Laravel 12 avec :
- stancl/tenancy v3.9 configuré
- Tenant model avec `HasDatabase`, `HasDomains`
- Routes centrales et tenant séparées
- Seeders pour IDF + PACA (2 régions de démo)
- Migrations centrales (tenants, domains) et tenant (clients, collaborators, lines, sims, devices, telecom_types)
- Config multi-tenant complète (bootstrappers DB, cache, filesystem, queue)

Le dossier `rizom-v2/` contient un template standalone Laravel 12 avec les packages cibles (Livewire 3, Spatie, Snappy PDF, Excel, etc.).

**La base V2 est déjà posée**. La prochaine étape est de connecter ce squelette à la BDD V1 existante.

### Plan d'action concret

```
MAINTENANT → Phase 0 (1 mois)
├── Prendre le codebase V1 existant (sur GitLab)
├── Créer une branche v2/develop
├── Laravel Shift : 10 → 11 → 12
├── Installer stancl/tenancy sur le codebase V1
├── Créer la BDD centrale + enregistrer V1 comme tenant 'idf'
├── Dockeriser
├── CI/CD GitLab CI
├── Redis (cache, sessions, queues)
├── Monitoring (Pulse + Sentry)
└── La V1 tourne en production comme avant, mais est prête pour la V2

Phase 1 (2-3 mois)
├── Quick win CDR : client_id + daily_call_summaries
├── Refonte invoices (dual-write JSON → normalisé)
├── API REST interne (routes tenant + centrales)
├── Pattern Gateway pour les intégrations fournisseurs
├── Sync catalogue Hub → régions
├── Scout (search database driver)
├── Reverb (remplace Pusher)
└── Staging cloud Scaleway

Phase 2 (2-3 mois)
├── Modules DDD-lite (Client, Telecom, Billing, Catalog, Stock, CDR)
├── Livewire 3 migration complète
├── Tailwind CSS (remplacement Bootstrap)
├── Cloud production
├── Provisioning 2ème région (PACA)
└── Nettoyage BDD (tables _bkp, pricing_zones)

Phase 3 (2-3 mois)
├── Portails client + ambassadeur en Livewire 3
├── Dashboard Hub central
├── Provisioning automatisé de régions
├── Tests E2E + pen test
└── Documentation API
```

### Comment le dossier `app/` du repo s'inscrit dans tout ça

Le dossier `app/` dans ce repo sert de **prototype / POC** pour valider :
- La configuration stancl/tenancy (routes, migrations, seeders)
- La structure des migrations tenant (clients, lines, sims, devices, etc.)
- La mécanique de provisioning de régions (TenantSeeder, DemoDataSeeder)
- Le modèle Hub & Spoke (routes centrales vs tenant)

Ce POC sera **fusionné dans le codebase V1 réel** (sur GitLab) lors de la Phase 0. Le code du POC sert de référence pour la configuration, pas de base de code production.

Le dossier `rizom-v2/` sert de **template de référence** pour les packages cibles (composer.json) et la structure frontend (Livewire 3, Tailwind, etc.).

---

## Annexe : Arbre de décision

```
Combien de développeurs ?
├── 5+ devs → Stratégie A viable (réécriture parallèle)
└── 2-3 devs
    ├── Les données V1 sont jetables ?
    │   ├── Oui → Stratégie A (rare en SaaS B2B avec 380 clients)
    │   └── Non
    │       ├── La V1 peut être gelée 6+ mois ?
    │       │   ├── Oui → Stratégie C possible (mais complexité BDD partagée)
    │       │   └── Non → ★ Stratégie B (Strangler Fig) ★
    │       └── Risque big-bang acceptable ?
    │           ├── Oui → Stratégie A ou C
    │           └── Non → ★ Stratégie B (Strangler Fig) ★
```

---

## Annexe : Et le dossier `rizom-v2/` ?

Le dossier `rizom-v2/` dans le repo contient un projet Laravel 12 standalone avec tous les packages cibles :

```
Packages de référence (à porter dans le codebase V1 lors de la Phase 0-1) :
├── barryvdh/laravel-snappy       → Génération PDF (factures)
├── digitick/sepa-xml             → Prélèvements SEPA
├── intervention/validation       → Validation images/documents
├── livewire/livewire             → Livewire 3 (frontend)
├── maatwebsite/excel             → Exports Excel
├── spatie/laravel-activitylog    → Audit trail (OBLIGATOIRE)
├── spatie/laravel-backup         → Backups automatisés
├── spatie/laravel-medialibrary   → Gestion fichiers/documents
├── spatie/laravel-permission     → RBAC (déjà en V1)
├── spatie/laravel-tags           → Tags (déjà en V1)
├── yajra/laravel-datatables      → DataTables (existant V1)
└── laravel/fortify + sanctum     → Auth MFA + API tokens
```

Ce dossier n'est PAS destiné à devenir l'application V2. C'est une **référence technique** pour les dépendances et la configuration cible. Le vrai codebase V2 sera le codebase V1 transformé (Stratégie B).

---

> **Dernière mise à jour** : 2026-03-07
> **Auteur** : Analyse architecturale assistée par IA
> **Statut** : Proposition — en attente de validation équipe
