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

### Phase 0 — Montées de version et fondations (en cours)

Objectif : stabiliser la stack technique avant toute évolution fonctionnelle.

| Étape | Détail | Statut |
|-------|--------|--------|
| **0.1** | Laravel 10 → 11 | **En cours** |
| **0.2** | Livewire 2 → 4 | **En cours** |
| **0.3** | Pusher → Laravel Reverb | **Prêt pour production** (validation serveur requise) |
| **0.4** | Passage imports toModel → toCollection | **En cours** |
| **0.5** | Laravel 11 → 12 → **14** + PHP ≥ 8.3 | À planifier |

> **Point de vigilance Phase 0.5** : La montée vers Laravel 14 nécessite PHP ≥ 8.3. Cela implique potentiellement la mise à jour du serveur, de l'OS, des extensions PHP, et la vérification de compatibilité de tous les packages Composer. Risque modéré à élevé — à valider sur un environnement de test avant production. L'intérêt principal est l'accès aux **fonctionnalités IA natives** de Laravel 14.

### Phase 1 — Évolutions base de données et CDR

Objectif : résoudre les problèmes de performance et structurer les données pour le multi-type.

| Étape | Détail | Statut |
|-------|--------|--------|
| **1.1** | Ajout `client_id` dans `calls` + `monthly_summaries` | **En cours** |
| **1.2** | Création table `call_iots` (CDR IoT séparés) | **En cours** |
| **1.3** | Création table `call_ucass` (CDR UCaaS séparés) | **En cours** |
| **1.4** | Tables d'agrégation journalière (IoT + calls) | **En cours** |
| **1.5** | Jobs de récupération et traitement pour agrégations | **En cours** |
| **1.6** | Suppression d'index inutiles sur `calls` (après tests) | **En cours** |
| **1.7** | Enrichir `monthly_summaries` (colonnes financières) | À faire |

### Phase 2 — API, modularisation et facturation

Objectif : structurer le code, découpler les portails, refondre la facturation.

| Étape | Détail | Statut |
|-------|--------|--------|
| **2.1** | API interne REST `/api/v1/*` — **uniquement pour les liaisons clients** (espace client, intégrations partenaires, future app mobile). L'architecture Hub & régions reste classique (Livewire, pas d'API entre eux) | À faire |
| **2.2** | Redis (cache, sessions, queues) | À faire |
| **2.3** | Modularisation DDD-lite (module par module, Strangler Fig) | À faire |
| **2.4** | Refonte facturation (sortie JSON blob → `invoices_v2` + `invoice_lines`) | À faire |
| **2.5** | Install stancl/tenancy + BDD centrale + premier tenant (Réunion = V1) | À faire |
| **2.6** | CI/CD GitLab CI (tests, build, déploiement) | À faire |

### Phase 3 — Multi-région et portails

Objectif : déployer le modèle franchise multi-région et moderniser les portails.

| Étape | Détail | Statut |
|-------|--------|--------|
| **3.1** | Sync catalogue + SSO entre Hub et régions | À faire |
| **3.2** | Portails client/ambassadeur Livewire 4 | À faire |
| **3.3** | Provisioning automatique de nouvelles régions | À faire |

### Phase 4 — Infrastructure et scaling (ultérieure)

Objectif : conteneurisation, migration cloud, scaling.

| Étape | Détail | Statut |
|-------|--------|--------|
| **4.1** | Docker + Docker Compose (dev puis staging/prod) | À faire |
| **4.2** | Migration cloud Scaleway | À faire |
| **4.3** | Monitoring (Laravel Pulse, Sentry, Grafana) | À faire |
| **4.4** | Nouvelles régions, scaling infra (1 serveur/région si besoin) | Continu |

> **Note** : La conteneurisation Docker et la migration cloud ne sont pas prioritaires à ce stade. Elles seront abordées quand les fondations applicatives (versions, BDD, modularisation) seront stabilisées.

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
