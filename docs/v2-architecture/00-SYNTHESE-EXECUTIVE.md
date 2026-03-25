# Cekoya V2 — Synthèse Exécutive

## Contexte

Plateforme télécom B2B de gestion end-to-end : CRM, catalogue multi-fournisseurs (Unyc, Transatel, IELO, euroFIBER, Wazo), facturation, gestion SIM/lignes, portabilités, signatures (Yousign), espace client, espace ambassadeur, module RSE.

## V1 — Situation actuelle

| Élément | Valeur |
|---------|--------|
| Stack | Laravel 10 → **11 (en cours)**, Livewire 2 → **4 (en cours)**, Blade, Bootstrap, Chart.js, MySQL, Pusher → **Reverb (en cours)** |
| Hébergement | Serveur local (on-premise) |
| Auth | Session Laravel + MFA |
| Docker / CI/CD | Aucun |
| Clients | 380 |
| Lignes actives | 6 000 |
| Utilisateurs internes | ~12 |
| CDR/mois | ~1 000 000 (table `calls` ~12 Go) |
| Imports | Quotidien (Unyc, Wazo), horaire (Transatel) — **passage de toModel à toCollection en cours** |
| URLs | prod.cekoya.fr / client.cekoya.fr / amba.cekoya.fr |
| Équipe | 2 développeurs |
| API interne | Aucune |

## Décision structurante

**Migration progressive (Strangler Fig)** — Pas de réécriture big-bang. La V2 se construit module par module en s'appuyant sur la base existante.

## Architecture cible

**Monolithe modulaire Laravel 14 + API-first + Multi-région Hub & Spoke**

> Chaque agence régionale dispose de sa propre application et BDD isolée (modèle franchise). Un Hub central gère le catalogue partagé, le monitoring et l'accès cross-régions via SSO.

## Stack V2 recommandée

| Couche | Technologie | Justification |
|--------|------------|---------------|
| Backend | **Laravel 14** (cible) — via montée progressive 10 → 11 → 12 → 14 | Continuité de compétences, écosystème riche, **fonctionnalités IA natives** |
| PHP | **PHP ≥ 8.3** (requis par Laravel 14) | Pré-requis Laravel 14, typed class constants, `#[Override]`, performance JIT |
| Multi-tenant | stancl/tenancy v3 (database-per-tenant) | Isolation BDD par région, codebase unique |
| Frontend (tous portails) | Livewire 4 + Alpine.js | Stack unifiée, productivité maximale pour 2 devs backend-first |
| Graphiques | **Chart.js v4** (conservé) | Licence MIT gratuite, déjà en V1, pas de passage à ApexCharts |
| Base relationnelle | MySQL 8.0 (1 BDD central + 1 BDD/région) | Isolation pannes, RGPD, scaling indépendant |
| CDR/Time-series | MySQL 8.0 + **tables séparées** (calls, call_iots, call_ucass) + tables d'agrégation | Résout le problème 12 Go sans nouvelle techno |
| Cache / Queue | Redis 7 | Cache, sessions, queues, rate limiting |
| Search | Laravel Scout + database driver | Recherche catalogue, clients, lignes (zéro service tiers) |
| Temps réel | **Laravel Reverb** (remplacement Pusher) — **prêt pour production** | Natif Laravel, zéro coût tiers |
| Signature | Yousign (existant) | Maintien |
| Stockage fichiers | S3-compatible (Scaleway Object Storage) | Factures PDF, documents, exports |
| Monitoring | Laravel Pulse + Sentry + Grafana | Observabilité complète |
| CI/CD | GitLab CI | Déjà sur GitLab |
| Conteneurisation | Docker + Docker Compose (phase ultérieure) | Reproductibilité, pré-requis cloud — **pas prioritaire à ce stade** |
| Hébergement | Scaleway (Paris) — migration ultérieure | Souveraineté FR, RGPD, coût maîtrisé |

## Roadmap condensée

> Construite à partir de l'analyse croisée des documents 01 à 17. Chaque étape référence le document source.

### Phase 0 — Montées de version et fondations (en cours)

Objectif : stabiliser la stack technique avant toute évolution fonctionnelle.

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **0.1** | Laravel 10 → 11 | `15-GUIDE-MONTEE-VERSION` | **En cours** |
| **0.2** | Livewire 2 → 4 (migration syntaxe : `@livewire` → `<livewire:>`, `emit` → `dispatch`, `wire:model` deferred par défaut) | `15-GUIDE-MONTEE-VERSION`, `04-FRONTEND` | **En cours** |
| **0.3** | Pusher → Laravel Reverb | `04-FRONTEND` §4 | **Prêt pour production** (validation serveur requise) |
| **0.4** | Passage imports toModel → toCollection (traitement plus rapide) | — | **En cours** |
| **0.5** | Mise à jour packages dépendants (Spatie, Horizon, etc.) | `15-GUIDE-MONTEE-VERSION` | À vérifier |
| **0.6** | Laravel 11 → 12 → **14** + PHP ≥ 8.3 | `15-GUIDE-MONTEE-VERSION` | À planifier |

> **Point de vigilance Phase 0.6** : La montée vers Laravel 14 nécessite PHP ≥ 8.3. Cela implique potentiellement la mise à jour du serveur, de l'OS, des extensions PHP, et la vérification de compatibilité de tous les packages Composer. Risque modéré à élevé — à valider sur un environnement de test avant production. L'intérêt principal est l'accès aux **fonctionnalités IA natives** de Laravel 14.

### Phase 1 — Évolutions base de données, CDR et facturation

Objectif : résoudre les problèmes de performance critiques, structurer les données pour le multi-type, et préparer la refonte facturation.

#### 1A — CDR et tables de consommation (en cours)

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **1.1** | Ajout `client_id` dans `calls` + backfill via JOIN `lines` + index composite `(client_id, date)` | `01-ARCHITECTE` §3, `08-ANALYSE-BDD` | **En cours** |
| **1.2** | Ajout `client_id` dans `monthly_summaries` + index `(client_id, month)` | `01-ARCHITECTE` §3 | **En cours** |
| **1.3** | Création table `call_iots` (CDR IoT séparés — volume massif M2M) | `01-ARCHITECTE` §2 séparation CDR | **En cours** |
| **1.4** | Création table `call_ucass` (CDR UCaaS/Wazo séparés) | `01-ARCHITECTE` §2 séparation CDR | **En cours** |
| **1.5** | Tables d'agrégation journalière : `daily_call_summaries` + `daily_iot_summaries` + `daily_ucaas_summaries` | `01-ARCHITECTE` §3, `03-BACKEND` | **En cours** |
| **1.6** | Jobs de récupération et traitement pour agrégations (nuit + post-import) | `03-BACKEND` §imports | **En cours** |
| **1.7** | Suppression d'index inutiles sur `calls` (après tests de performance) | — | **En cours** |
| **1.8** | Enrichir `monthly_summaries` : colonnes `total_charge`, `total_price`, émissions carbone | `01-ARCHITECTE` §3, `08-ANALYSE-BDD` | À faire |

#### 1B — Facturation (refonte `invoices`)

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **1.9** | Créer tables `invoices_v2` (en-tête normalisé) + `invoice_lines` (lignes détaillées) — **à côté** de `invoices` existante | `17-REFONTE-FACTURATION`, `08-ANALYSE-BDD` | À faire |
| **1.10** | Script de migration historique : parsing JSON `doc` → insertion dans `invoices_v2` + `invoice_lines` avec vérification checksum (divergence ≤ ±0.01€) | `17-REFONTE-FACTURATION` | À faire |
| **1.11** | Mode dual-write : nouveau code écrit dans les deux systèmes, lit depuis `invoices_v2`. Comparaison automatique à chaque génération | `14-STRATEGIE-TRANSITION` | À faire |
| **1.12** | Génération PDF asynchrone via Queue → stockage S3 (`/invoices/{year}/{month}/{id}.pdf`) | `17-REFONTE-FACTURATION`, `03-BACKEND` | À faire |

> **Garde-fous facturation** (doc `17-REFONTE-FACTURATION`) :
> - Arrondi **par ligne** (convention comptable française)
> - Immutabilité : `is_locked = 1` → aucune modification (contrainte applicative + BDD)
> - Traçabilité : chaque `invoice_line` liée à sa source (CDR, forfait, matériel)
> - Avoir plutôt que modification : erreur sur facture verrouillée → note de crédit

#### 1C — Nettoyage BDD

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **1.13** | Finaliser migration tarification `pricing_zones` → `plan_rates` (normalisé), supprimer tables `_bkp` une fois confirmé stable | `08-ANALYSE-BDD` | À faire |
| **1.14** | Nettoyage données orphelines (devices sans client, lignes sans client) | `08-ANALYSE-BDD` | À faire |

### Phase 2 — Structuration applicative

Objectif : structurer le code, mettre en place les outils transverses, isoler les intégrations.

#### 2A — Outillage et infrastructure applicative

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **2.1** | Redis (cache, sessions, queues) — remplacement sessions fichier | `03-BACKEND` §cache, `02-DEVOPS` | À faire |
| **2.2** | Laravel Horizon (monitoring queues, supervisors séparés : `default`, `imports`, `billing`, `aggregation`) | `03-BACKEND` §queues, `02-DEVOPS` §worker | À faire |
| **2.3** | Worker séparé via Supervisord (voir détail doc `02-DEVOPS` §2) | `02-DEVOPS` §worker séparé | À faire |
| **2.4** | Concurrence et intégrité des données : optimistic lock sur modèles éditables, cache lock Redis sur jobs critiques, queues sérialisées, idempotence | `18-CONCURRENCE-INTEGRITE` | À faire |
| **2.5** | Audit trail — `spatie/laravel-activitylog` sur modèles sensibles (obligation RGPD télécom/finance) | `06-CYBERSECURITE` §5, `01-ARCHITECTE` | À faire |
| **2.6** | CI/CD GitLab CI (Pint → PHPStan → tests Pest → build → deploy staging auto, prod manuelle) | `02-DEVOPS` §4 | À faire |

#### 2B — API et intégrations

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **2.7** | API REST `/api/v1/*` — **uniquement liaisons clients** (espace client, intégrations partenaires, future app mobile). Hub & régions restent en architecture classique Livewire | `01-ARCHITECTE` §API, `04-FRONTEND` §6 | À faire |
| **2.8** | Pattern Gateway fournisseurs : `TransatelGateway`, `UnycGateway`, `WazoGateway`, etc. derrière interfaces (`MobileProviderGateway`, `UCaaSProviderGateway`) — isolation des connecteurs | `03-BACKEND` §intégrations, `01-ARCHITECTE` §7.4 | À faire |
| **2.9** | Laravel Scout + database driver (recherche clients, lignes, catalogue — scopé par tenant) | `01-ARCHITECTE` §revue, `03-BACKEND` | À faire |

#### 2C — Modularisation

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **2.10** | Modularisation DDD-lite progressive (Strangler Fig) — module par module : `Client`, `Telecom`, `IoT`, `UCaaS`, `Billing`, `CDR`, `Catalog`, `Stock`, `Integration`, `Ticket`, `Order`, `Ambassador`, `Environment`, `Content`, `Auth`, `Finance`, `IA` | `01-ARCHITECTE` §2, `12-CONVENTIONS-CODE` | À faire |
| **2.11** | Contrat `Billable` inter-modules (facturation unifiée : chaque module facturable implémente l'interface) | `01-ARCHITECTE` §Billable | À faire |
| **2.12** | Contrat `CollaboratorContract` (collaborateur = centre de coût client, pivot entre Telecom/Stock/Infogérance) | `01-ARCHITECTE` §collaborateur | À faire |

### Phase 3 — Multi-région, portails et sécurité

Objectif : déployer le modèle franchise multi-région, moderniser les portails, sécuriser.

#### 3A — Multi-tenancy et régions

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **3.1** | Install stancl/tenancy v3 + BDD centrale + V1 = premier tenant (Réunion, zéro migration visible) | `01-ARCHITECTE` §2, `09-MULTI-REGION` | À faire |
| **3.2** | Sync catalogue Hub → régions (event `PlanUpdated` → job `SyncCatalogToTenants` via queue dédiée `tenant-sync`, checksums) | `09-MULTI-REGION` §sync | À faire |
| **3.3** | SSO inter-régions (Hub ↔ admin régional) | `09-MULTI-REGION` §SSO | À faire |
| **3.4** | Provisioning automatique de nouvelles régions (commande artisan + UI Hub) | `09-MULTI-REGION` §provisioning | À faire |

#### 3B — Portails

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **3.5** | Portail client Livewire 4 (dashboard conso, factures, lignes, tickets — scopé tenant) | `04-FRONTEND` §2-3 | À faire |
| **3.6** | Portail ambassadeur Livewire 4 (pipeline prospects, commissions, support) | `04-FRONTEND` §2 | À faire |
| **3.7** | Dashboard Hub central (stats multi-régions, gestion catalogue, provisioning UI, santé système) | `09-MULTI-REGION`, `01-ARCHITECTE` §Central | À faire |

#### 3C — Sécurité et conformité

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **3.8** | Middleware sécurité par portail (CSP headers distincts, rate limiting Redis, Sanctum token scoping pour API) | `06-CYBERSECURITE` §2-4 | À faire |
| **3.9** | Chiffrement données sensibles (IBAN, données personnelles) — migration vers KMS (Scaleway KMS ou Vault) | `06-CYBERSECURITE` §1 | À faire |
| **3.10** | Politique de rétention CDR (RGPD : durée légale, purge automatique, anonymisation agrégations au-delà de la période) | `06-CYBERSECURITE` §3, `01-ARCHITECTE` §archivage | À faire |
| **3.11** | Tests anti-fuite cross-tenant (vérification isolation BDD stancl/tenancy) | `06-CYBERSECURITE` §6 | À faire |

### Phase 4 — Infrastructure, IA et scaling (ultérieure)

Objectif : conteneurisation, migration cloud, fonctionnalités IA, scaling.

#### 4A — Infrastructure

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **4.1** | Docker + Docker Compose (dev puis staging/prod) | `02-DEVOPS` §3 | À faire |
| **4.2** | Migration cloud Scaleway (staging puis production, blue-green deploy) | `02-DEVOPS` §2 | À faire |
| **4.3** | Monitoring : Laravel Pulse (métriques), Sentry (erreurs), Grafana (infra) | `02-DEVOPS` §revue §4 | À faire |
| **4.4** | Backup automatisé par tenant (snapshot + mysqldump → S3, vérification post-backup) | `02-DEVOPS` §5 | À faire |
| **4.5** | Archivage CDR automatisé (0-12 mois : en ligne, 12-36 mois : export S3, >36 mois : agrégations seules) | `01-ARCHITECTE` §archivage | À faire |

#### 4B — Fonctionnalités IA

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **4.6** | Détection d'anomalies CDR (consommations inhabituelles, fraude) — basé sur `daily_call_summaries` | `10-FONCTIONNALITES-IA` | À faire |
| **4.7** | Optimisation de forfaits (suggestion de plan adapté sur base de 6+ mois d'historique) | `10-FONCTIONNALITES-IA` | À faire |
| **4.8** | Scoring prospect (priorisation commerciale) | `10-FONCTIONNALITES-IA` | À faire |
| **4.9** | Prédiction de churn (12+ mois d'historique requis) | `10-FONCTIONNALITES-IA` | À faire |
| **4.10** | Assistant admin IA (function calling sur API interne) | `10-FONCTIONNALITES-IA` | À faire |

#### 4C — Scaling

| Étape | Détail | Source | Statut |
|-------|--------|--------|--------|
| **4.11** | Scaling Phase B : serveur dédié par région si >5 régions ou >1000 clients/région (~+50-80€/mois/région) | `02-DEVOPS` §2, `09-MULTI-REGION` §9 | À faire |
| **4.12** | Nouvelles régions (Métropole, Mayotte, etc.) | `09-MULTI-REGION` | Continu |

> **Note** : La conteneurisation Docker et la migration cloud ne sont pas prioritaires à ce stade. Elles seront abordées quand les fondations applicatives (versions, BDD, modularisation) seront stabilisées. Les fonctionnalités IA nécessitent que les tables d'agrégation soient en place et alimentées (Phase 1 complète).

---

## Découvertes critiques après analyse du schéma SQL (80+ tables)

### Problème n°1 : `invoices.doc` est un JSON blob

La colonne `doc` (JSON) contient **l'intégralité** de chaque facture (en-tête + lignes + calculs). Les colonnes `date`, `amount`, `paid`, `locked`, `number` sont des GENERATED STORED extraites du JSON. Chaque SELECT charge le JSON complet en mémoire. **C'est la cause réelle de la lenteur de la table invoices.**

**Solution V2** : Refonte en `invoices` (en-tête normalisé) + `invoice_lines` (lignes détaillées). Migration progressive avec dual-write.

### Problème n°2 : `calls` n'a pas de `client_id`

Les requêtes CDR par client nécessitent un JOIN systématique via `lines`. Avec 12 Go+, c'est coûteux.

**Solution V2** : Ajouter `client_id` dénormalisé + index composite. Quick win réalisable en 1 jour. **En cours de développement.**

### Évolution n°3 : séparation CDR par type (en cours)

Nouvelles tables `call_iots` et `call_ucass` pour séparer les CDR IoT et UCaaS de la table `calls` principale. Tables d'agrégation journalière dédiées. Jobs de traitement en cours de développement.

---

> Détails complets dans les documents d'analyse par rôle :
> - `01-ARCHITECTE-LOGICIEL.md` — Architecture modulaire + revue cybersécurité
> - `02-DEVOPS-CLOUD.md` — Infrastructure Scaleway + revue architecte
> - `03-BACKEND.md` — API, patterns, imports + revue DevOps
> - `04-FRONTEND.md` — Livewire 4 / Alpine.js / Tailwind + revue backend
> - `05-RESPONSABLE-SI.md` — Gouvernance, coûts, risques + validation collective
> - `06-CYBERSECURITE.md` — Modèle de sécurité
> - `07-LIVRABLES-FINAUX.md` — Diagrammes, stack, roadmap, plan de migration
> - `08-ANALYSE-SCHEMA-BDD.md` — Analyse détaillée des 80+ tables, problèmes critiques, plan de migration schéma
> - `09-ARCHITECTURE-MULTI-REGION.md` — Hub & Spoke, isolation BDD par région, sync catalogue, SSO, provisioning
> - `10-FONCTIONNALITES-IA.md` — Fonctionnalités IA (anomalies CDR, optimisation forfaits, scoring prospect, assistant admin, churn) + NVIDIA Shark
> - `11-VERIFICATIONS-ANOMALIES.md` — Vérifications existantes V1 à porter (lignes, matériel, forfaits, quotas IoT, hors-forfait data)
> - `12-CONVENTIONS-CODE.md` — Standards et conventions de code V2 (PHP, Laravel, DDD-lite, Git, tests)
> - `13-POINTS-REUNION.md` — Points en attente de décision collective (direct_debit_accounts, numérotation factures, etc.)
> - `14-STRATEGIE-TRANSITION-V1-V2.md` — Stratégie de transition progressive (Strangler Fig)
> - `15-GUIDE-MONTEE-VERSION.md` — Guide de montée de version Laravel/Livewire
> - `16-AUTH-NAVIGATION-MULTI-PORTAIL.md` — Authentification et navigation multi-portail
> - `17-REFONTE-FACTURATION-CDR.md` — Refonte facturation (invoices_v2 + invoice_lines)
> - `18-CONCURRENCE-INTEGRITE-DONNEES.md` — Concurrence et intégrité des données multi-portail (optimistic lock, cache lock, queues, idempotence)
