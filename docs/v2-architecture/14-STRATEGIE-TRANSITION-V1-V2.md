# Stratégie de Transition V1 → V2 — Analyse comparative

## Contexte

| Élément | V1 actuelle | V2 cible |
|---------|-------------|----------|
| Stack | Laravel 10 → **11 (en cours)**, Livewire 2 → **4 (en cours)**, Blade, Bootstrap, Chart.js, MySQL | Laravel **14**, Livewire 4, **Bootstrap conservé**, **Chart.js conservé**, MySQL 8 multi-BDD |
| Architecture | Monolithe couplé, 3 portails dans 1 app | Monolithe modulaire DDD-lite, API (liaisons clients), multi-région |
| Hébergement | On-premise (serveur local) | Scaleway cloud (ultérieur — Phase 4) |
| Multi-tenancy | Aucun | stancl/tenancy v3 (database-per-tenant, Hub & Spoke) |
| BDD | 1 MySQL avec ~80 tables, `calls` 12 Go, `invoices.doc` JSON blob | 1 BDD centrale + 1 BDD par région, tables séparées (calls, call_iots, call_ucass) |
| Imports | toModel | **toCollection (en cours de migration)** |
| Temps réel | Pusher | **Reverb (prêt pour production)** |
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

Créer un nouveau projet Laravel 14 vierge avec la structure modulaire DDD-lite, installer stancl/tenancy, définir toutes les tables V2, puis migrer les données de la V1 via des scripts ETL.

```
V1 (existante)                    V2 (nouveau projet)
┌──────────────────┐              ┌──────────────────┐
│ Laravel 10       │    ETL       │ Laravel 14       │
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
| **Liberté technologique totale** | Livewire 4, Tailwind, Alpine.js, Reverb, Scout... tout en partant de zéro, zéro coexistence. |

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
│  Phase 0       Phase 1         Phase 2          Phase 3       Phase 4     │
│ ┌────────────┐ ┌────────────┐ ┌──────────────┐ ┌───────────┐ ┌─────────┐ │
│ │ Montées    │ │ BDD + CDR  │ │ Structuration│ │ Multi-rég.│ │ Infra   │ │
│ │ de version │→│+ invoices  │→│+ Redis,CI/CD │→│+ portails │→│+ IA     │ │
│ │ L10→11→14  │ │+ call_iots │ │+ modules DDD │ │+ tenancy  │ │+ Docker │ │
│ │ LW2→4      │ │+ call_ucass│ │+ API clients │ │+ SSO      │ │+ cloud  │ │
│ │ Pusher→Rev.│ │+ agrégat.  │ │+ gateways    │ │+ sécurité │ │+ scaling│ │
│ └────────────┘ └────────────┘ └──────────────┘ └───────────┘ └─────────┘ │
│                                                                           │
│  BDD:          BDD:           BDD:             BDD:          BDD:        │
│  existante     + call_iots    + optimistic     + central     N régions   │
│  inchangée     + call_ucass   lock (version)   + tenant cfg  autonomes   │
│                + invoices_v2  + Redis cache     V1=1er tenant             │
│                + agrégations  + audit trail                               │
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

Créer un nouveau dossier (ex: `app-v2/`) dans le même repo avec un projet Laravel 14 neuf. Ce projet V2 se connecte à la MÊME base de données que la V1 et crée ses nouvelles tables à côté des anciennes.

```
/repo
├── app/           ← V1 (Laravel 10, en production)
│   └── connecté à BDD existante
│
├── app-v2/        ← V2 (Laravel 14, en développement)
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

Le dossier `app/` contient déjà un projet Laravel 14 avec :
- stancl/tenancy v3.9 configuré
- Tenant model avec `HasDatabase`, `HasDomains`
- Routes centrales et tenant séparées
- Seeders pour IDF + PACA (2 régions de démo)
- Migrations centrales (tenants, domains) et tenant (clients, collaborators, lines, sims, devices, telecom_types)
- Config multi-tenant complète (bootstrappers DB, cache, filesystem, queue)

Le dossier `rizom-v2/` contient un template standalone Laravel 14 avec les packages cibles (Livewire 4, Spatie, Snappy PDF, Excel, etc.).

**La base V2 est déjà posée**. La prochaine étape est de connecter ce squelette à la BDD V1 existante.

### Plan d'action concret (aligné sur la roadmap doc 00)

```
MAINTENANT → Phase 0 — Montées de version (en cours)
├── Laravel 10 → 11 (en cours)
├── Livewire 2 → 4 (en cours)
├── Pusher → Reverb (prêt pour production)
├── Imports toModel → toCollection (en cours)
├── Mise à jour packages dépendants
└── Laravel 11 → 14 + PHP ≥ 8.3 (à planifier)

Phase 1 — BDD, CDR et facturation
├── Quick win CDR : client_id sur calls + monthly_summaries (en cours)
├── Tables call_iots + call_ucass (en cours)
├── Agrégations journalières + jobs (en cours)
├── Suppression index inutiles sur calls (en cours)
├── Refonte invoices (invoices_v2 + invoice_lines, dual-write, PDF async)
├── Nettoyage BDD (pricing_zones, tables _bkp, orphelins)
└── Enrichir monthly_summaries (financier + carbone)

Phase 2 — Structuration applicative
├── Redis (cache, sessions, queues)
├── Horizon + worker séparé (Supervisord)
├── Optimistic lock (colonne version) sur tables éditables
├── Cache lock Redis sur jobs critiques
├── Audit trail (spatie/laravel-activitylog)
├── CI/CD GitLab CI
├── API REST v1 (liaisons clients uniquement)
├── Pattern Gateway fournisseurs (Transatel, Unyc, Wazo)
├── Laravel Scout (recherche)
├── Modules DDD-lite (Client, Telecom, Billing, Catalog, CDR, Stock...)
├── Contrats Billable + CollaboratorContract
└── Nettoyage BDD (invoices_v2 → invoices, tables legacy)

Phase 3 — Multi-région, portails et sécurité
├── stancl/tenancy + BDD centrale + V1 = tenant IDF
├── Sync catalogue Hub → régions + SSO
├── Portails client + ambassadeur Livewire 4 + Bootstrap
├── Dashboard Hub central
├── Provisioning automatisé de régions
├── Sécurité : KMS, rétention RGPD, tests anti-fuite cross-tenant
├── Tests E2E + pen test
└── Documentation API

Phase 4 — Infrastructure, IA et scaling (ultérieure)
├── Docker + Docker Compose
├── Migration cloud Scaleway
├── Monitoring (Pulse, Sentry, Grafana)
├── IA (anomalies CDR, optimisation forfaits, scoring, churn)
└── Scaling multi-région (Phase B si >5 régions)
```

---

## Guide d'implémentation Stratégie B — Étapes détaillées

> Ce guide décrit chaque étape concrète de la transformation progressive. Chaque étape est conçue pour être réalisable indépendamment, testable, et réversible. L'ordre est important : les dépendances entre étapes sont explicites.

---

### PHASE 0 — Montées de version et fondations (en cours)

**Objectif** : Stabiliser la stack technique avant toute évolution fonctionnelle. À la fin de Phase 0, la V1 tourne sur Laravel 14, Livewire 4, Reverb. Les utilisateurs ne voient **aucune différence**.

> **Statut actuel** : Laravel 10→11 en cours, Livewire 2→4 en cours, Pusher→Reverb prêt pour production, imports toModel→toCollection en cours.
>
> **Note** : Docker, Redis, CI/CD et stancl/tenancy sont reportés en Phase 2 (structuration applicative). La Phase 0 se concentre exclusivement sur les montées de version.

#### Étape 0.1 — Préparer la branche de travail (0,5 jour)

```bash
# Sur le repo GitLab V1
git checkout -b v2/develop
```

- Tout le travail V2 se fait sur `v2/develop`
- La branche `main` reste intacte = V1 en production
- Les hotfixes V1 se font sur `main` et sont cherry-picked dans `v2/develop`
- Convention de commit : `[phase-0] description` pour traçabilité

**Pré-requis** : aucun
**Livrable** : branche prête, équipe alignée sur le workflow

---

#### Étape 0.2 — Laravel 10 → 11 → 12 (2-3 jours)

C'est l'étape la plus technique de Phase 0. La montée se fait en **deux sauts** car Laravel 11 introduit des breaking changes structurels majeurs.

##### Laravel 10 → 11 — Breaking changes majeurs

| Changement | Ce qui disparaît | Ce qui le remplace | Impact sur la V1 |
|------------|-----------------|-------------------|------------------|
| **Kernel HTTP** | `app/Http/Kernel.php` | Tout passe dans `bootstrap/app.php` | Middleware globaux, groupes, aliases à déplacer |
| **RouteServiceProvider** | `app/Providers/RouteServiceProvider.php` | Routing configuré dans `bootstrap/app.php` | Rate limiting, prefix API, route model binding |
| **ExceptionHandler** | `app/Exceptions/Handler.php` | Gestion dans `bootstrap/app.php` via `->withExceptions()` | Handlers custom, reporting, rendering |
| **Config par défaut** | Fichiers config complets dans `config/` | Laravel 11 utilise des valeurs inline (fichiers optionnels) | Garder les fichiers config custom, supprimer ceux inchangés |
| **Casts** | `protected $casts = []` (propriété) | `protected function casts(): array` (méthode) | Tous les models avec `$casts` à migrer |
| **Console Kernel** | `app/Console/Kernel.php` | `routes/console.php` avec `Schedule` façade | Cron jobs, commandes artisan custom |

```php
// AVANT (Laravel 10) — app/Http/Kernel.php
protected $middleware = [
    TrustProxies::class,
    HandleCors::class,
    PreventRequestsDuringMaintenance::class,
    // ...
];

// APRÈS (Laravel 11) — bootstrap/app.php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            // Middleware custom ici
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handlers custom ici
    })
    ->create();
```

##### Laravel 11 → 12 — Moins de breaking changes

- Principalement mise à jour des dépendances
- Vérifier les méthodes deprecated en Laravel 11 (supprimées en 12)
- `php artisan optimize:clear` entre chaque montée

##### Option recommandée : Laravel Shift (~$100)

- Service automatisé qui génère une PR avec tous les changements
- Couvre 80% du travail mécanique (déplacement de fichiers, renommages)
- Les 20% restants = ajustements manuels spécifiques au projet
- **ROI immédiat** : 2-3 jours de travail économisés = 1500-2000€ de temps dev

##### Procédure manuelle (si pas de Shift)

```bash
# Étape 1 : monter vers Laravel 11
composer require laravel/framework:^11.0 --no-update
composer update
# Corriger les erreurs, adapter Kernel, ExceptionHandler, etc.
php artisan optimize:clear
php artisan test   # DOIT passer

# Étape 2 : monter vers Laravel 14
composer require laravel/framework:^12.0 --no-update
composer update
php artisan optimize:clear
php artisan test   # DOIT passer
```

##### Packages à vérifier à chaque montée

| Package V1 | Compatibilité Laravel 14 | Action |
|------------|------------------------|--------|
| `livewire/livewire` | v3+ requis (v2 incompatible) | Migrer en étape 0.3 |
| `spatie/laravel-permission` v7 | Compatible | `composer update` suffit |
| `spatie/laravel-medialibrary` v11 | Compatible | `composer update` suffit |
| `spatie/laravel-tags` v4 | Compatible | `composer update` suffit |
| `laravel/fortify` v1 | Compatible | `composer update` suffit |
| `laravel/sanctum` v4 | Compatible | `composer update` suffit |
| `yajra/laravel-datatables` v12 | Vérifier release notes | Peut nécessiter ajustements |
| `maatwebsite/excel` v3 | Vérifier release notes | Peut nécessiter ajustements |
| `barryvdh/laravel-snappy` v1 | Compatible | `composer update` suffit |
| `digitick/sepa-xml` v3 | Pas de dépendance Laravel | Aucun changement |
| `pusher/pusher-php-server` v7 | Sera remplacé (étape 1.8) | Garder pour l'instant |

**Pré-requis** : étape 0.1
**Livrable** : `php artisan test` passe, application fonctionnelle sur Laravel 14
**Rollback** : `git revert` vers Laravel 10 si blocage critique

---

#### Étape 0.3 — Livewire 2 → 3 (3-5 jours)

C'est **l'étape la plus longue de Phase 0**. Livewire 3 est une réécriture majeure — il n'y a pas de coexistence possible : un composant est v2 ou v3, pas les deux.

##### Changements de syntaxe systématiques

| Livewire 2 | Livewire 3 | Type de changement |
|------------|------------|-------------------|
| `$this->emit('event')` | `$this->dispatch('event')` | Renommage |
| `$this->emitTo(Comp, 'event')` | `$this->dispatch('event')->to(Comp)` | Chaînage |
| `$this->emitUp('event')` | `$this->dispatch('event')` (bubble par défaut) | Supprimé |
| `protected $listeners = ['event' => 'method']` | `#[On('event')]` sur la méthode | Attribute PHP 8 |
| `wire:model` (eager par défaut) | `wire:model` (defer par défaut en v3) | **Attention** : comportement inversé |
| `wire:model.defer` | `wire:model` (c'est le défaut maintenant) | Simplification |
| `wire:model.lazy` | `wire:model.blur` | Renommage |
| `wire:model.debounce.300ms` | `wire:model.live.debounce.300ms` | `.live` requis pour eager |
| Computed: `getXProperty()` | `#[Computed] public function x()` | Attribute PHP 8 |
| `$this->resetPage()` | `$this->resetPage()` (API similaire) | Quasi identique |
| Pagination: `WithPagination` | `WithPagination` (API raffinée) | Vérifier les vues custom |
| `@livewire('component')` | `<livewire:component />` (tag syntax) | Les deux marchent en v3 |
| Upload: `WithFileUploads` | `WithFileUploads` (amélioré) | Preview et validation améliorées |

##### Le piège principal : `wire:model`

```html
<!-- Livewire 2 : wire:model = mise à jour IMMÉDIATE (eager) -->
<input wire:model="search">  <!-- envoie une requête à chaque frappe -->

<!-- Livewire 3 : wire:model = mise à jour DIFFÉRÉE (defer) par défaut -->
<input wire:model="search">          <!-- ne met à jour qu'au submit -->
<input wire:model.live="search">     <!-- pour retrouver le comportement eager -->
<input wire:model.live.debounce.300ms="search">  <!-- eager + debounce -->
```

**Impact** : Tous les champs de recherche en temps réel, les filtres dynamiques, les toggles interactifs qui utilisaient `wire:model` sans `.defer` doivent ajouter `.live`. Sans ça, l'interface semble "cassée" (rien ne se passe quand on tape).

##### Nouvelles fonctionnalités à exploiter (pas obligatoire en Phase 0)

```html
<!-- Navigation SPA-like (supprime le rechargement de page) -->
<a wire:navigate href="/clients">Clients</a>

<!-- Lazy loading de composants lourds -->
<livewire:dashboard.consumption-chart lazy />

<!-- Teleport (rendre un composant dans un autre endroit du DOM) -->
<x-teleport to="#modal-container">
    <livewire:edit-client-modal />
</x-teleport>
```

##### Stratégie de migration recommandée

```
Ordre de migration des composants :
1. Composants simples (affichage, badges, statuts)     → 1-2h chacun
2. Composants de formulaire (CRUD, modals)              → 2-4h chacun
3. Composants de liste/table (pagination, tri, filtres) → 4-8h chacun
4. Composants complexes (dashboards, charts, temps réel) → 1 jour chacun

Pour chaque composant :
├── Modifier la classe PHP (emit → dispatch, listeners → attributes, etc.)
├── Modifier le template Blade (wire:model → wire:model.live si needed)
├── Tester manuellement dans le navigateur
├── Écrire un test Pest si le composant est critique
└── Commit unitaire par composant migré
```

**Pré-requis** : étape 0.2 (Laravel 14 requis pour Livewire 3+)
**Livrable** : tous les composants Livewire fonctionnent en v3, puis montée rapide en v4 (voir doc 15 étape 9)
**Rollback** : composant par composant (git revert du commit spécifique)

---

#### Étape 0.4 — Autres packages à mettre à jour (1-2 jours)

Après Laravel 14 + Livewire 4, vérifier et mettre à jour les packages restants :

```bash
composer update --with-all-dependencies
php artisan test
```

Packages à ajouter (nouveaux, pas dans la V1) :

| Package | Rôle | Quand l'installer |
|---------|------|-------------------|
| `spatie/laravel-activitylog` | Audit trail (RGPD) | Phase 0 (obligatoire) |
| `laravel/horizon` | Dashboard queues Redis | Phase 0 (avec Redis) |
| `laravel/pulse` | Monitoring applicatif | Phase 0 |
| `laravel/reverb` | WebSocket natif | Phase 1 (remplace Pusher) |
| `laravel/scout` | Recherche | Phase 1 |

**Pré-requis** : étapes 0.2 et 0.3
**Livrable** : `composer outdated` propre, `php artisan test` passe

---

#### Étape 0.5 — Dockeriser l'application (2-3 jours)

```yaml
# docker-compose.yml (simplifié)
services:
  app:
    build: .
    image: cekoya-app
    volumes: ['.:/var/www/html']
    depends_on: [mysql, redis]

  nginx:
    image: nginx:alpine
    ports: ['80:80', '443:443']
    volumes: ['./docker/nginx.conf:/etc/nginx/conf.d/default.conf']

  mysql:
    image: mysql:8.0
    volumes: ['mysql_data:/var/lib/mysql']
    environment:
      MYSQL_DATABASE: cekoya_v1
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}

  redis:
    image: redis:7-alpine
    volumes: ['redis_data:/data']

  worker:
    build: .
    command: php artisan queue:work redis --sleep=3 --tries=3
    depends_on: [mysql, redis]

  scheduler:
    build: .
    command: php artisan schedule:work
    depends_on: [mysql, redis]
```

Points d'attention :
- Le `Dockerfile` doit inclure PHP 8.3 + extensions nécessaires (gd, zip, pdo_mysql, redis, pcntl)
- `wkhtmltopdf` doit être dans l'image (pour barryvdh/laravel-snappy)
- Volume pour les fichiers uploadés (`storage/app/`)
- Variables d'environnement via `.env` (pas hardcodées)

**Pré-requis** : aucun (peut être fait en parallèle des étapes 0.2-0.4)
**Livrable** : `docker compose up` = environnement de dev complet fonctionnel

---

#### Étape 0.6 — Redis pour cache, sessions, queues (1 jour)

```env
# .env — changements
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=redis        # container Docker
REDIS_PORT=6379
REDIS_PASSWORD=null
```

Bénéfices immédiats :
- Sessions : plus rapides, ne polluent plus la BDD
- Cache : réduit la charge MySQL (requêtes répétitives)
- Queues : prépare Laravel Horizon (Phase 0.7)
- Rate limiting : Redis-backed (plus précis)

**Pré-requis** : étape 0.5 (Docker avec Redis)
**Livrable** : sessions et cache fonctionnent via Redis

---

#### Étape 0.7 — CI/CD GitLab (2-3 jours)

```yaml
# .gitlab-ci.yml
stages:
  - quality
  - test
  - build
  - deploy

lint:
  stage: quality
  script:
    - vendor/bin/pint --test
  rules:
    - if: $CI_MERGE_REQUEST_ID

phpstan:
  stage: quality
  script:
    - vendor/bin/phpstan analyse --level=6
  allow_failure: true  # au début, rendre strict progressivement

test:
  stage: test
  services:
    - mysql:8.0
    - redis:7-alpine
  script:
    - php artisan test --parallel
  coverage: '/Cov:.*?(\d+\.\d+)%/'

security:
  stage: quality
  script:
    - composer audit

build:
  stage: build
  script:
    - docker build -t cekoya:$CI_COMMIT_SHA .
  only:
    - v2/develop
    - main

deploy_staging:
  stage: deploy
  script:
    - ./deploy.sh staging $CI_COMMIT_SHA
  only:
    - v2/develop
  when: manual
```

**Pré-requis** : étape 0.5 (Docker)
**Livrable** : chaque push déclenche lint + tests, déploiement staging en un clic

---

#### Étape 0.8 — stancl/tenancy (2-3 jours)

C'est l'étape qui pose les bases du multi-région. Mais en Phase 0, elle ne change **rien au fonctionnement de la V1**.

```bash
composer require stancl/tenancy:^3.9
php artisan tenancy:install
# Publie : config/tenancy.php, migrations, TenancyServiceProvider, routes
```

##### Configuration clé — `config/tenancy.php`

```php
'tenant_model' => App\Models\Tenant::class,

// La V1 existante est identifiée par sous-domaine
'identification_strategy' => 'subdomain',

// Bootstrappers : ce qui change quand on entre dans un tenant
'bootstrappers' => [
    DatabaseTenancyBootstrapper::class,    // Switch de BDD automatique
    CacheTenancyBootstrapper::class,       // Préfixe cache par tenant
    FilesystemTenancyBootstrapper::class,  // Isolation storage par tenant
    QueueTenancyBootstrapper::class,       // Jobs taggés par tenant
],

// Domaines centraux (Hub) — ces URLs ne déclenchent PAS la tenancy
'central_domains' => [
    'central.cekoya.fr',
    'localhost',  // pour le dev
],
```

##### Enregistrer la V1 comme premier tenant

```php
// Seeder ou commande artisan
$tenant = Tenant::create([
    'id' => 'idf',
    'name' => 'Île-de-France',
]);
$tenant->createDomain('idf.cekoya.fr');
// La database de ce tenant = la BDD V1 existante
// Pas de migration de données, pas de copie — c'est la MÊME BDD
```

##### Séparer les routes

```php
// routes/tenant.php — tout le métier V1 (accessible via idf.cekoya.fr)
Route::middleware(['web', 'tenant'])->group(function () {
    // Toutes les routes existantes de la V1 sont déplacées ici
    // Aucun changement fonctionnel
});

// routes/central.php — Hub uniquement (accessible via central.cekoya.fr)
Route::middleware(['web'])->group(function () {
    Route::get('/', fn () => view('central.dashboard'));
    // Pour l'instant, juste un placeholder
});
```

##### Ce qui change pour la V1 : RIEN (ou presque)

- Les requêtes vers `idf.cekoya.fr` sont interceptées par stancl/tenancy
- Le bootstrapper switch la connexion MySQL vers la BDD V1 existante
- Le code métier V1 tourne exactement comme avant
- Seul changement visible : l'URL passe de `app.cekoya.fr` à `idf.cekoya.fr` (redirection 301)

**Pré-requis** : étape 0.2 (Laravel 14)
**Livrable** : tenancy configuré, V1 = tenant IDF, Hub central avec page vide
**Rollback** : désactiver le middleware tenant = retour au comportement V1

---

#### Étape 0.9 — Monitoring et audit trail (1 jour)

```bash
# Monitoring applicatif
composer require laravel/pulse
php artisan vendor:publish --provider="Laravel\Pulse\PulseServiceProvider"
php artisan migrate

# Audit trail (RGPD)
composer require spatie/laravel-activitylog
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
php artisan migrate
```

Pulse donne immédiatement :
- Temps de réponse par route
- Requêtes SQL lentes
- Taille des queues
- Exceptions récentes
- Usage mémoire/CPU

L'audit trail enregistre automatiquement les modifications sur les modèles critiques (clients, lignes, factures) — requis pour la conformité RGPD.

**Pré-requis** : étape 0.2
**Livrable** : dashboard Pulse accessible, audit trail actif sur les modèles sensibles

---

#### Résumé Phase 0 — Checklist de validation

```
□ Laravel 11 installé, php artisan test passe (en cours)
□ Livewire 4 : tous les composants migrés et fonctionnels (en cours)
□ Pusher remplacé par Laravel Reverb (prêt pour prod)
□ Imports toModel → toCollection migrés (en cours)
□ Tous les packages Composer compatibles et à jour
□ Laravel 14 + PHP ≥ 8.3 (à planifier après stabilisation)
□ AUCUN changement fonctionnel métier
□ Les utilisateurs ne voient aucune différence
```

> **Reporté en Phase 2** : Docker (étape 0.5 ci-dessus), Redis (0.6), CI/CD (0.7), stancl/tenancy (0.8), monitoring et audit trail (0.9). Ces étapes restent documentées ici pour référence technique mais ne font plus partie de la Phase 0.

**Durée estimée** : 15-20 jours de dev
**Risque principal** : incompatibilité Livewire 2→3 sur composants complexes
**Mitigation** : migration composant par composant, tests après chaque migration

---

### PHASE 1 — Performance BDD + API + Intégrations (2-3 mois)

**Objectif** : Résoudre les problèmes de performance critiques (CDR, factures), poser la couche API, moderniser les intégrations fournisseurs. Les utilisateurs commencent à voir des améliorations (dashboards plus rapides, recherche).

#### Étape 1.1 — Quick win CDR : ajouter `client_id` sur `calls` (2 jours)

Le gain de performance le plus rapide à obtenir. Transforme les requêtes CDR par client de O(n) JOIN à O(1) WHERE.

```sql
-- Migration Laravel (database/migrations/tenant/)
-- Jour 1 : ajout colonne + foreign key
ALTER TABLE calls ADD COLUMN client_id BIGINT UNSIGNED NULL AFTER line_id;
ALTER TABLE calls ADD CONSTRAINT fk_calls_client FOREIGN KEY (client_id)
    REFERENCES clients(id) ON DELETE SET NULL;

-- Jour 2-3 : backfill en arrière-plan (job Laravel en queue)
-- Par tranches de 100K pour ne pas bloquer la prod
UPDATE calls c
JOIN lines l ON c.line_id = l.id
SET c.client_id = l.client_id
WHERE c.client_id IS NULL
LIMIT 100000;

-- Jour 4 : index une fois le backfill terminé
ALTER TABLE calls ADD INDEX idx_calls_client_date (client_id, date);
```

**Point critique — Historique vs temps réel** :
- Le `client_id` représente le client au moment de l'appel (snapshot historique)
- Si une ligne change de propriétaire, les anciens CDR gardent l'ancien `client_id`
- Seuls les nouveaux CDR importés après le changement prennent le nouveau `client_id`
- Le job d'import doit capturer `client_id` depuis `lines.client_id` au moment de l'insertion

**Pré-requis** : Phase 0 complète
**Livrable** : requêtes CDR par client 10-50x plus rapides
**Rollback** : `ALTER TABLE calls DROP COLUMN client_id` (sans perte de données)

---

#### Étape 1.2 — Table d'agrégation `daily_call_summaries` (2-3 jours)

```sql
CREATE TABLE daily_call_summaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NOT NULL,
    telecom_type_id BIGINT UNSIGNED NOT NULL,  -- mobile, fixe, IoT, UCaaS
    date DATE NOT NULL,

    -- Compteurs
    total_calls INT UNSIGNED DEFAULT 0,
    total_sms INT UNSIGNED DEFAULT 0,
    total_mms INT UNSIGNED DEFAULT 0,
    total_data_bytes BIGINT UNSIGNED DEFAULT 0,
    total_duration_seconds INT UNSIGNED DEFAULT 0,

    -- Financier
    total_charge DECIMAL(15,4) DEFAULT 0,    -- coût fournisseur
    total_price DECIMAL(15,4) DEFAULT 0,     -- prix client
    out_of_plan_count INT UNSIGNED DEFAULT 0,
    out_of_plan_cost DECIMAL(15,4) DEFAULT 0,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY daily_unique (client_id, line_id, date, telecom_type_id),
    KEY idx_client_date (client_id, date),
    KEY idx_line_date (line_id, date),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (line_id) REFERENCES lines(id)
);
```

- **Volume estimé** : 6 000 lignes × 365 jours = ~2.2M rows/an (~200 Mo vs 12 Go pour `calls`)
- **Job nightly** : `AggregateDailyCallsJob` tourne après chaque import CDR
- **Usage** : dashboards de consommation, rapports mensuels, alertes dépassement

---

#### Étape 1.3 — Enrichir `monthly_summaries` existant (1 jour)

```sql
ALTER TABLE monthly_summaries
    ADD COLUMN client_id BIGINT UNSIGNED NULL,
    ADD COLUMN total_charge DECIMAL(15,4) DEFAULT 0,
    ADD COLUMN total_price DECIMAL(15,4) DEFAULT 0,
    ADD COLUMN out_of_plan_total DECIMAL(15,4) DEFAULT 0;

-- Backfill
UPDATE monthly_summaries ms
JOIN lines l ON ms.line_id = l.id
SET ms.client_id = l.client_id;

ALTER TABLE monthly_summaries
    ADD INDEX idx_client_month (client_id, month);
```

---

#### Étape 1.4 — Normaliser les factures (5-7 jours)

C'est **le chantier le plus critique de Phase 1**. Le JSON blob `invoices.doc` est la première cause de problème de performance.

##### Phase A : Créer les nouvelles tables (jour 1)

```sql
CREATE TABLE invoices_v2 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    number VARCHAR(50) NOT NULL,           -- ex: IDF-2026-0042
    label VARCHAR(255) NULL,
    date DATE NOT NULL,
    due_date DATE NULL,
    period_start DATE NULL,
    period_end DATE NULL,

    -- Montants calculés (plus de JSON)
    amount_ht DECIMAL(15,4) NOT NULL DEFAULT 0,
    amount_tva DECIMAL(15,4) NOT NULL DEFAULT 0,
    amount_ttc DECIMAL(15,4) NOT NULL DEFAULT 0,

    -- Statuts (plus de GENERATED STORED)
    is_paid BOOLEAN NOT NULL DEFAULT FALSE,
    is_locked BOOLEAN NOT NULL DEFAULT FALSE,
    paid_at TIMESTAMP NULL,
    payment_method VARCHAR(50) NULL,

    -- Référence document PDF
    document_path VARCHAR(500) NULL,      -- chemin S3

    -- Métadonnées légères (PAS le contenu de la facture)
    meta JSON NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_number (number),
    KEY idx_client_date (client_id, date),
    KEY idx_paid_locked (client_id, is_locked, is_paid),
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE TABLE invoice_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    line_id BIGINT UNSIGNED NULL,         -- ligne télécom concernée (nullable pour frais généraux)

    type ENUM('plan', 'service', 'device', 'option', 'out_of_plan', 'discount', 'other') NOT NULL,
    label VARCHAR(500) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price_ht DECIMAL(15,4) NOT NULL DEFAULT 0,
    amount_ht DECIMAL(15,4) NOT NULL DEFAULT 0,
    tva_rate DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    amount_tva DECIMAL(15,4) NOT NULL DEFAULT 0,
    amount_ttc DECIMAL(15,4) NOT NULL DEFAULT 0,

    sort_order INT UNSIGNED DEFAULT 0,

    FOREIGN KEY (invoice_id) REFERENCES invoices_v2(id) ON DELETE CASCADE,
    FOREIGN KEY (line_id) REFERENCES lines(id) ON DELETE SET NULL,
    KEY idx_invoice (invoice_id),
    KEY idx_line (line_id)
);
```

##### Phase B : Migration des données historiques (jours 2-4)

```php
// Job Laravel en queue — par batch de 100 factures
class MigrateInvoiceJsonJob implements ShouldQueue
{
    public function handle(): void
    {
        Invoice::whereNotNull('doc')
            ->whereNotIn('id', InvoiceV2::pluck('legacy_id'))
            ->chunk(100, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $doc = json_decode($invoice->doc, true);

                    // Vérification JSON valide
                    if (!$doc) {
                        Log::warning("Invoice {$invoice->id}: JSON invalide");
                        continue;
                    }

                    // Créer en-tête dans invoices_v2
                    $v2 = InvoiceV2::create([
                        'legacy_id' => $invoice->id,
                        'client_id' => $invoice->client_id,
                        'number' => $doc['number'] ?? '',
                        'date' => $doc['date'],
                        'amount_ht' => $doc['summary']['total'] ?? 0,
                        'amount_ttc' => $doc['summary']['total_tax_included'] ?? 0,
                        // ...
                    ]);

                    // Créer les lignes dans invoice_lines
                    foreach ($doc['lines'] ?? [] as $i => $line) {
                        InvoiceLine::create([
                            'invoice_id' => $v2->id,
                            'label' => $line['label'],
                            'amount_ht' => $line['amount'],
                            'sort_order' => $i,
                            // ...
                        ]);
                    }

                    // Vérification checksum
                    $jsonTotal = $doc['summary']['total_tax_included'] ?? 0;
                    $calcTotal = $v2->lines()->sum('amount_ttc');
                    if (abs($jsonTotal - $calcTotal) > 0.01) {
                        Log::warning("Invoice {$invoice->id}: checksum mismatch");
                    }
                }
            });
    }
}
```

##### Phase C : Dual-write pendant la transition (jours 5-7)

```php
// Observer ou trait sur le modèle Invoice
// Quand la V1 crée/modifie une facture, écrire aussi dans invoices_v2
class InvoiceDualWriteObserver
{
    public function saved(Invoice $invoice): void
    {
        // Écrire dans le nouveau format en parallèle
        SyncInvoiceToV2Job::dispatch($invoice->id);
    }
}
```

- Le code V1 continue de lire/écrire dans `invoices` (JSON) — aucun changement
- Le nouveau code V2 lit depuis `invoices_v2` + `invoice_lines`
- Un job de réconciliation horaire vérifie la cohérence entre les deux
- En Phase 2, on bascule tout le code vers `invoices_v2` et on supprime le dual-write

**Pré-requis** : étapes 1.1-1.3 (CDR optimisé pour les rapports de facturation)
**Livrable** : factures normalisées, listings 10x plus rapides, mémoire MySQL libérée
**Rollback** : le code V1 continue de fonctionner sur l'ancien format indépendamment

---

#### Étape 1.5 — API REST interne v1 (5-7 jours)

Voir `03-BACKEND.md` pour le détail des routes. Les routes sont définies dans `routes/tenant.php` (métier régional) et `routes/central.php` (Hub).

Points clés :
- Authentification : Laravel Sanctum (tokens API)
- Validation : Form Requests (une classe par endpoint)
- Réponses : API Resources (contrôle de la sortie JSON)
- Autorisations : Policies Laravel + spatie/laravel-permission
- Throttling : par IP et par token (Redis-backed)
- Versioning : préfixe `/api/v1/` dans l'URL

L'API interne est consommée par :
- Les composants Livewire 4 (via appels HTTP internes)
- Le futur portail client (Phase 3)
- Le Hub central pour les agrégations cross-région

---

#### Étape 1.6 — Pattern Gateway fournisseurs (5-7 jours)

```php
// Interface commune — app/Modules/Integration/Domain/Contracts/
interface MobileProviderGateway
{
    public function importCDR(Carbon $date): Collection;
    public function getLineStatus(string $msisdn): LineStatusDTO;
    public function activateLine(ActivateLineDTO $dto): bool;
    public function suspendLine(string $msisdn, string $reason): bool;
}

// Implémentation par fournisseur
class TransatelGateway implements MobileProviderGateway { /* ... */ }
class UnycGateway implements MobileProviderGateway { /* ... */ }
class WazoGateway implements UCaaSProviderGateway { /* ... */ }
```

Le code métier ne connaît que l'interface. Changer de fournisseur = écrire une nouvelle classe Gateway, zéro changement dans le reste du code.

---

#### Étape 1.7 — Laravel Horizon (1-2 jours)

```bash
composer require laravel/horizon
php artisan horizon:install
```

4 superviseurs spécialisés :
- `default` + `notifications` : tâches courantes (5 workers, timeout 60s)
- `imports` + `cdr` : imports CDR lourds (3 workers, timeout 600s)
- `billing` + `invoices` : facturation (2 workers, timeout 900s)
- `tenant-sync` + `tenant-provision` : multi-tenant (2 workers, timeout 300s)

Dashboard Horizon accessible à `/horizon` (protégé par auth super_admin).

---

#### Étape 1.8 — Remplacer Pusher par Laravel Reverb (1-2 jours)

```bash
composer remove pusher/pusher-php-server
composer require laravel/reverb
php artisan reverb:install
```

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=cekoya
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
```

- Serveur WebSocket natif Laravel (zéro service tiers)
- Channels privés isolés par tenant automatiquement
- Events : CDR import terminé, facture générée, alerte dépassement

---

#### Étape 1.9 — Archivage CDR > 12 mois (2-3 jours)

```php
// Job mensuel
class ArchiveOldCDRJob implements ShouldQueue
{
    public function handle(): void
    {
        $cutoff = now()->subMonths(12);

        // Exporter vers S3 en CSV compressé
        Call::where('date', '<', $cutoff)
            ->chunk(50000, function ($calls) {
                $csv = $this->toCsv($calls);
                Storage::disk('s3')->put(
                    "archives/cdr/{$this->tenant}/{$calls->first()->date->format('Y-m')}.csv.gz",
                    gzencode($csv)
                );
            });

        // Supprimer les enregistrements archivés
        Call::where('date', '<', $cutoff)->delete();
    }
}
```

- Garde les 12 derniers mois en ligne (requêtes directes)
- Au-delà : agrégats dans `daily_call_summaries` + `monthly_summaries`
- Archive brute en S3 pour audit/compliance si nécessaire
- La table `calls` passe de ~12 Go à ~2-3 Go en taille active

---

#### Résumé Phase 1 — Checklist de validation

```
□ calls.client_id backfillé à 100%, index créé, requêtes CDR rapides
□ daily_call_summaries alimenté nightly, dashboards réactifs
□ monthly_summaries enrichi avec client_id + données financières
□ invoices_v2 + invoice_lines créés et alimentés (dual-write actif)
□ Réconciliation invoices JSON ↔ invoices_v2 sans erreur
□ API REST v1 opérationnelle (Sanctum, Form Requests, Resources)
□ Gateway pattern implémenté pour au moins Transatel
□ Horizon déployé, 4 superviseurs configurés
□ Pusher remplacé par Reverb
□ Archivage CDR > 12 mois fonctionnel
□ Staging cloud Scaleway opérationnel
```

**Durée estimée** : 8-12 semaines
**Risque principal** : dual-write invoices (données désynchronisées)
**Mitigation** : job de réconciliation horaire + alertes sur écarts

---

### PHASE 2 — Structuration applicative (2-3 mois)

**Objectif** : Structurer le code en modules DDD-lite, mettre en place l'outillage transversal (Redis, CI/CD, Horizon, worker séparé, audit trail), isoler les intégrations, nettoyer le schéma BDD.

#### Étape 2.0 — Concurrence et intégrité des données (2-3 jours)

Avec plusieurs portails (admin, client, ambassadeur) et des jobs en arrière-plan, les accès concurrents aux mêmes données sont inévitables. Voir le document dédié `18-CONCURRENCE-INTEGRITE-DONNEES.md` pour la stratégie complète.

**Migration à effectuer** — ajouter une colonne `version` sur les tables éditables par plusieurs utilisateurs :

```sql
-- Tables nécessitant l'optimistic lock (colonne version)
ALTER TABLE clients ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE collaborators ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE prospects ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE lines ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE invoices_v2 ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE tickets ADD COLUMN version BIGINT UNSIGNED NOT NULL DEFAULT 0;
```

**Tables qui n'en ont PAS besoin** (insertion/upsert uniquement par jobs) :
- `calls`, `call_iots`, `call_ucass` → upsert via `provider_call_id` (UNIQUE KEY)
- `daily_*_summaries`, `monthly_summaries` → upsert idempotent (`ON DUPLICATE KEY UPDATE`)
- `invoice_lines` → immutables une fois générées (liées à la facture)

**Trait Laravel** — implémentation sur les modèles concernés :

```php
trait HasOptimisticLock
{
    public function saveWithLock(array $attributes): bool
    {
        $currentVersion = $this->version;
        $updated = static::where('id', $this->id)
            ->where('version', $currentVersion)
            ->update(array_merge($attributes, ['version' => $currentVersion + 1]));

        if ($updated === 0) {
            throw new StaleModelException(
                "Donnée modifiée par un autre utilisateur. Veuillez rafraîchir."
            );
        }
        return true;
    }
}
```

**Cache lock Redis** — pour les jobs critiques (imports, génération factures) :

```php
// Un seul import Transatel à la fois
$lock = Cache::lock('import:transatel', 3600);
if (! $lock->get()) {
    $this->release(60); // Réessayer dans 60s
    return;
}
```

**Pré-requis** : Redis (étape 0.6 / Phase 2A), `invoices_v2` (Phase 1B)
**Livrable** : aucune perte de données en cas d'édition concurrente, jobs sérialisés

---

#### Étape 2.1 — Extraction des modules DDD-lite (5-7 jours par module critique)

Déplacer le code existant dans la structure modulaire définie dans `12-CONVENTIONS-CODE.md` :

```
app/Modules/
├── Client/        # Clients, collaborateurs, agences, référents
├── Telecom/       # Lignes, SIM, portabilités
├── Billing/       # Factures, SEPA, prélèvements
├── Catalog/       # Plans, tarifs, options, devices (central + sync)
├── CDR/           # Import, agrégation, archivage
├── Stock/         # Dispositifs, inventaire
├── Ticket/        # Support, SAV
├── Prospect/      # Pipeline, devis
├── Ambassador/    # Programme ambassadeur, commissions
├── Integration/   # Gateways fournisseurs, API externes
└── Auth/          # Utilisateurs, rôles, permissions, SSO
```

**Ordre de migration** (par dépendances) :
1. `Auth` (pas de dépendance, utilisé par tous)
2. `Catalog` (pas de dépendance métier, référentiel central)
3. `Client` (dépend de Auth)
4. `Telecom` (dépend de Client, Catalog)
5. `CDR` (dépend de Telecom, Client)
6. `Billing` (dépend de Client, Telecom, CDR, Catalog)
7. `Stock` (dépend de Catalog)
8. `Ticket` (dépend de Client, Telecom)
9. `Prospect` (dépend de Catalog)
10. `Ambassador` (dépend de Client)
11. `Integration` (dépend de Telecom, CDR)

**Règles d'isolation** :
- Un module ne peut PAS importer directement les modèles d'un autre module
- Communication inter-modules via **interfaces** (Contracts) ou **événements domaine**
- Chaque module a son propre `ServiceProvider` qui enregistre ses bindings
- Les migrations restent dans `database/migrations/tenant/` (stancl/tenancy les exécute)

---

#### Étape 2.2 — Améliorations CSS Bootstrap (progressive, 2-3 jours)

> **Décision** : Bootstrap est **conservé en V2** (voir doc `04-FRONTEND`). La migration vers Tailwind a été écartée — coût disproportionné pour 2 devs, zéro valeur métier.

**Améliorations progressives** :
- Créer un fichier `_variables.scss` centralisé (couleurs, typographie Cekoya)
- Nettoyer les overrides CSS orphelins lors des refactos de vues
- Utiliser les composants Bootstrap 5 natifs (accordions, offcanvas, toasts)
- Monter vers Bootstrap 5.x si nécessaire

**Composants Blade réutilisables** (design system Bootstrap) :

```
resources/views/components/
├── ui/
│   ├── button.blade.php         # Boutons Bootstrap (primary, secondary, danger, etc.)
│   ├── modal.blade.php          # Modals avec Alpine.js
│   ├── table.blade.php          # Tables avec pagination
│   ├── status-badge.blade.php   # Badges de statut (actif, suspendu, résilié)
│   ├── card.blade.php           # Cartes conteneur
│   ├── alert.blade.php          # Alertes/notifications
│   └── form/
│       ├── input.blade.php
│       ├── select.blade.php
│       └── textarea.blade.php
├── charts/
│   └── chartjs-wrapper.blade.php  # Wrapper Chart.js v4 (conservé, pas d'ApexCharts)
└── layout/
    ├── app.blade.php            # Layout admin
    ├── client.blade.php         # Layout portail client
    └── navigation.blade.php     # Menu latéral
```

---

#### Étape 2.3 — Nettoyage BDD (1 jour)

```sql
-- Supprimer les tables de backup (dette technique V1)
DROP TABLE IF EXISTS plan_rates_bkp;
DROP TABLE IF EXISTS geographical_zone_supplier_bkp;
DROP TABLE IF EXISTS supplier_zone_countries_bkp;

-- Supprimer l'ancien système de tarification (si plan_rates est validé)
DROP TABLE IF EXISTS pricing_zones;
DROP TABLE IF EXISTS pricing_geographical_zone;
DROP TABLE IF EXISTS pricing_zone_country;

-- Basculer les factures (dual-write terminé)
RENAME TABLE invoices TO invoices_legacy;      -- conservation 6 mois
RENAME TABLE invoices_v2 TO invoices;
```

**Pré-requis** : validation explicite de l'équipe que `plan_rates` est la source de vérité unique

---

#### Étape 2.4 — Déploiement cloud Scaleway (2-3 jours)

Voir `02-DEVOPS-CLOUD.md` pour l'architecture cible. Phase A :
- 1 Load Balancer Scaleway
- 2 serveurs d'application (PHP-FPM + Nginx)
- 1 serveur worker (Horizon + Scheduler)
- MySQL 8 managé (DB-DEV2-M)
- Redis 7 managé
- S3 Object Storage (documents, archives CDR)
- Cloudflare (DNS wildcard `*.cekoya.fr`, WAF, CDN)
- Budget estimé : ~165-200€/mois

---

#### Étape 2.5 — Provisioning 2ème région PACA (2-3 jours)

Premier test grandeur nature du multi-tenant :

```bash
php artisan tenant:create paca "Provence-Alpes-Côte d'Azur" paca.cekoya.fr admin@paca.cekoya.fr
```

Ce que fait la commande en coulisses :
1. INSERT dans `tenants` (BDD centrale)
2. CREATE DATABASE `cekoya_paca`
3. Exécuter toutes les migrations tenant
4. Seeder le catalogue depuis la BDD centrale
5. Créer l'utilisateur admin initial
6. Configurer le sous-domaine `paca.cekoya.fr`

La région PACA naît directement en V2 — aucune dette technique.

---

#### Résumé Phase 2 — Checklist de validation

```
□ Redis opérationnel (cache, sessions, queues)
□ Laravel Horizon déployé, queues séparées (imports, aggregation, billing)
□ Worker séparé via Supervisord
□ Optimistic lock (colonne version) sur tables éditables (voir doc 18)
□ Cache lock Redis sur jobs critiques (imports, factures)
□ Audit trail (spatie/laravel-activitylog) actif sur modèles sensibles
□ CI/CD GitLab CI fonctionnel (lint + tests + build)
□ Au moins 6 modules DDD-lite extraits et fonctionnels
□ Isolation inter-modules respectée (pas d'imports directs croisés)
□ API REST v1 (liaisons clients) opérationnelle
□ Pattern Gateway implémenté (Transatel, Unyc, Wazo)
□ Design system Blade components Bootstrap créé et documenté
□ Tables _bkp et pricing_zones supprimées
□ invoices_v2 renommé en invoices, dual-write stoppé
□ Tests de non-régression passent
```

---

### PHASE 3 — Portails externes + Automatisation (2-3 mois)

**Objectif** : Moderniser les portails client et ambassadeur, créer le dashboard Hub central, automatiser le provisioning de régions, sécuriser l'ensemble.

#### Étape 3.1 — Portail client (7-10 jours)

Routes : `{region}-client.cekoya.fr` ou `client.{region}.cekoya.fr`

Pages :
- Dashboard : résumé consommation, statut factures, activité récente
- Lignes : détail par ligne (consommation, statut, historique)
- Factures : liste, détail, téléchargement PDF, statut paiement
- Collaborateurs : lignes/devices assignés par collaborateur
- Profil : paramètres compte, mot de passe
- Notifications : alertes dépassement, messages admin

Tout en Livewire 4 + Bootstrap + Alpine.js. Le portail consomme l'API v1 interne (construite en Phase 2).

---

#### Étape 3.2 — Portail ambassadeur (3-5 jours)

Routes : `{region}-amba.cekoya.fr`

Pages :
- Dashboard : solde commissions, clients parrainés, CA généré
- Clients : liste des clients apportés par l'ambassadeur
- Paiements : historique des versements, commissions en attente
- Réseau : structure de parrainage (multi-niveau si applicable)

---

#### Étape 3.3 — Dashboard Hub central (3-5 jours)

Routes : `central.cekoya.fr` (super_admin uniquement)

Pages :
- KPIs globaux : total clients/lignes/CA par région
- Cartes régions : statut, KPIs, actions rapides
- Gestion catalogue : CRUD plans/tarifs/devices (source de vérité)
- Monitoring sync : statut synchronisation catalogue par région
- Provisioning : interface pour créer de nouvelles régions
- SSO : clic sur une région → connexion automatique dans le contexte régional

---

#### Étape 3.4 — Provisioning automatisé (3-5 jours)

Automatisation complète via commande artisan et/ou interface Hub :

```
Nouvelle région = 1 commande, 5 minutes :
├── Entrée dans tenants (central)
├── Création BDD cekoya_{region}
├── Exécution migrations tenant
├── Seed catalogue depuis central
├── Création admin initial + setup MFA
├── Configuration DNS (API Cloudflare)
├── Email de bienvenue à l'admin régional
└── Vérification santé (health check)
```

---

#### Étape 3.5 — Tests E2E + Pen test (3-5 jours dev + 1-2 semaines externe)

Tests E2E (Pest + Laravel Dusk) :
- Parcours admin : créer client → ajouter lignes → import CDR → facturer
- Parcours client : login → voir conso → télécharger facture
- Parcours ambassadeur : voir clients → tracker commissions
- Parcours Hub : créer région → sync catalogue → voir KPIs
- **Test critique** : isolation cross-tenant (IDF ne voit pas PACA et vice versa)

Pen test externe :
- Fuite de données cross-tenant (CRITIQUE)
- Bypass authentification/autorisation
- Sécurité API (rate limiting, injection, validation)
- Vulnérabilités upload fichiers (S3)
- Infrastructure (VPC, firewall, ports exposés)

---

#### Étape 3.6 — Documentation API (2-3 jours)

```bash
composer require knuckleswtf/scribe
php artisan scribe:generate
```

Documentation OpenAPI/Swagger générée automatiquement depuis les Form Requests et API Resources.

---

#### Résumé Phase 3 — Checklist de validation

```
□ stancl/tenancy configuré, V1 = tenant IDF, Hub central accessible
□ Sync catalogue Hub → régions fonctionnel
□ SSO inter-régions opérationnel
□ Portail client fonctionnel et accessible
□ Portail ambassadeur fonctionnel et accessible
□ Dashboard Hub central opérationnel
□ Provisioning de région automatisé et testé
□ Sécurité : KMS, rétention RGPD, tests anti-fuite cross-tenant
□ Tests E2E passent sur tous les parcours critiques
□ Pen test externe réalisé, findings corrigés
□ Isolation cross-tenant vérifiée (zéro fuite)
□ Documentation API publiée
□ Au moins 2-3 régions opérationnelles
```

---

### PHASE 4 — Scaling continu (en continu)

**Pas de fin définie** — cette phase accompagne la croissance :

- Provisioning de nouvelles régions (1 par trimestre estimé)
- Monitoring des métriques de scaling (CPU, mémoire, temps de requête)
- Si nécessaire : passage de Phase A (serveur partagé) à Phase B (serveur par région)
- Optimisation continue BDD (slow_query_log, indexes, partitionnement si nécessaire)
- Nouveaux modules : IoT avancé, IA (anomaly detection, optimisation de plan), UCaaS
- Application mobile (consomme l'API v1 construite en Phase 1)

---

## Matrice de risques par phase

| Risque | Phase | Probabilité | Sévérité | Mitigation |
|--------|-------|-------------|----------|------------|
| **Livewire 2→3 casse des composants** | 0 | Élevée | MOYEN | Migration un par un, test après chaque composant, commit unitaire |
| **Package incompatible Laravel 14** | 0 | Moyenne | MOYEN | Vérifier avant montée, chercher alternative si besoin |
| **Backfill client_id bloque la prod** | 1 | Faible | ÉLEVÉ | Batches de 100K, job en queue, pas de lock table |
| **JSON invoices malformé** | 1 | Moyenne | MOYEN | Log des erreurs, traitement au cas par cas, ne bloque pas le batch |
| **Dual-write désynchronisé** | 1 | Moyenne | ÉLEVÉ | Job réconciliation horaire, alertes, checksum |
| **Module mal isolé (imports croisés)** | 2 | Moyenne | MOYEN | PHPStan rules custom, code review checklist |
| **Bootstrap/Tailwind conflits CSS** | 2 | Faible | FAIBLE | Préfixe CSS, migration progressive page par page |
| **Fuite données cross-tenant** | 2-3 | Faible | **CRITIQUE** | Tests automatisés d'isolation, pen test externe, stancl/tenancy force la BDD |
| **Cloud performance différente du local** | 2 | Moyenne | MOYEN | Staging cloud avant production, load testing |
| **Provisioning région échoue** | 3 | Faible | MOYEN | Job transactionnel, rollback auto, cleanup |
| **Équipe débordée (2 devs)** | Toutes | Moyenne | ÉLEVÉ | Phases ajustables, priorité aux quick wins, recrutement 3ème dev |

---

## Dépendances entre étapes

```
PHASE 0 — Montées de version (en cours) :
0.1 Laravel 10→11 ──→ 0.2 Livewire 2→4 ──→ 0.3 Reverb ──→ 0.4 toCollection
                                                              │
                                                              └──→ 0.5 Packages → 0.6 Laravel 14 + PHP ≥8.3

PHASE 1 — BDD, CDR et facturation :
1A CDR :
  1.1 client_id calls ──→ 1.5 daily_summaries ──→ 1.6 Jobs agrégation
  1.3 call_iots (parallèle) ──→ daily_iot_summaries
  1.4 call_ucass (parallèle) ──→ daily_ucaas_summaries
  1.2 client_id monthly_summaries (parallèle)
  1.7 Suppression index (après tests)

1B Facturation :
  1.9 invoices_v2 + invoice_lines ──→ 1.10 Migration JSON ──→ 1.11 Dual-write
  1.12 PDF async (après 1.9)

1C Nettoyage :
  1.13 pricing_zones/_bkp + 1.14 orphelins (parallèle, après validation)

PHASE 2 — Structuration applicative :
  Redis ──→ Horizon ──→ Worker séparé
               │
               └──→ 2.0 Optimistic lock + cache lock (voir doc 18)
  Audit trail (parallèle)
  CI/CD (parallèle)
  API REST (après Phase 1)
  Gateways fournisseurs (parallèle avec API)
  Scout (parallèle)
  Modules DDD (après API) ──→ Contrat Billable + CollaboratorContract

PHASE 3 — Multi-région, portails et sécurité :
  stancl/tenancy (après modules) ──→ Sync catalogue ──→ SSO ──→ Provisioning
  Portails client + ambassadeur + Hub (après tenancy)
  Sécurité (KMS, RGPD, tests cross-tenant) ──→ Pen test

PHASE 4 — Infrastructure, IA et scaling :
  Docker → Cloud Scaleway → Monitoring → Backup par tenant → Archivage CDR
  IA (anomalies, forfaits, scoring, churn, assistant) — après agrégations Phase 1
  Scaling Phase B (si >5 régions)
```

---

### Comment le dossier `app/` du repo s'inscrit dans tout ça

Le dossier `app/` dans ce repo sert de **prototype / POC** pour valider :
- La configuration stancl/tenancy (routes, migrations, seeders)
- La structure des migrations tenant (clients, lines, sims, devices, etc.)
- La mécanique de provisioning de régions (TenantSeeder, DemoDataSeeder)
- Le modèle Hub & Spoke (routes centrales vs tenant)

Ce POC sera **fusionné dans le codebase V1 réel** (sur GitLab) lors de la Phase 0. Le code du POC sert de référence pour la configuration, pas de base de code production.

Le dossier `rizom-v2/` sert de **template de référence** pour les packages cibles (composer.json) et la structure frontend (Livewire 4, Tailwind, etc.).

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

Le dossier `rizom-v2/` dans le repo contient un projet Laravel 14 standalone avec tous les packages cibles :

```
Packages de référence (à porter dans le codebase V1 lors de la Phase 0-1) :
├── barryvdh/laravel-snappy       → Génération PDF (factures)
├── digitick/sepa-xml             → Prélèvements SEPA
├── intervention/validation       → Validation images/documents
├── livewire/livewire             → Livewire 4 (frontend)
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

> **Dernière mise à jour** : 2026-03-09
> **Auteur** : Analyse architecturale assistée par IA
> **Statut** : Guide d'implémentation détaillé — Stratégie B recommandée
