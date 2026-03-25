# Cekoya V2 — Synthèse Exécutive

## Contexte

Plateforme télécom B2B de gestion end-to-end : CRM, catalogue multi-fournisseurs (Unyc, Transatel, IELO, euroFIBER, Wazo), facturation, gestion SIM/lignes, portabilités, signatures (Yousign), espace client, espace ambassadeur, module RSE.

## V1 — Situation actuelle

| Élément | Valeur |
|---------|--------|
| Stack | Laravel 10, Livewire 2, Blade, Bootstrap, Chart.js, MySQL, Pusher |
| Hébergement | Serveur local (on-premise) |
| Auth | Session Laravel + MFA |
| Docker / CI/CD | Aucun |
| Clients | 380 |
| Lignes actives | 6 000 |
| Utilisateurs internes | ~12 |
| CDR/mois | ~1 000 000 (table `calls` ~12 Go) |
| Imports | Quotidien (Unyc, Wazo), horaire (Transatel) |
| URLs | prod.cekoya.fr / client.cekoya.fr / amba.cekoya.fr |
| Équipe | 2 développeurs |
| API interne | Aucune |

## Décision structurante

**Migration progressive (Strangler Fig)** — Pas de réécriture. La V2 se construit module par module en s'appuyant sur la base existante.

## Architecture cible

**Monolithe modulaire Laravel 13 + API-first + Multi-région Hub & Spoke**

> Chaque agence régionale dispose de sa propre application et BDD isolée (modèle franchise). Un Hub central gère le catalogue partagé, le monitoring et l'accès cross-régions via SSO.

## Stack V2 recommandée

| Couche | Technologie | Justification |
|--------|------------|---------------|
| Backend | Laravel 12 | Continuité de compétences, écosystème riche |
| Multi-tenant | stancl/tenancy v3 (database-per-tenant) | Isolation BDD par région, codebase unique |
| Frontend (tous portails) | Livewire 4 + Alpine.js + Tailwind CSS | Stack unifiée, productivité maximale pour 2 devs backend-first |
| Base relationnelle | MySQL 8.0 (1 BDD central + 1 BDD/région) | Isolation pannes, RGPD, scaling indépendant |
| CDR/Time-series | MySQL 8.0 partitionné + tables d'agrégation | Résout le problème 12 Go sans nouvelle techno |
| Cache / Queue | Redis 7 | Cache, sessions, queues, rate limiting |
| Search | Laravel Scout + database driver | Recherche catalogue, clients, lignes (zéro service tiers) |
| Temps réel | Laravel Reverb (remplacement Pusher) | Natif Laravel, zéro coût tiers |
| Signature | Yousign (existant) | Maintien |
| Stockage fichiers | Sur le serveur Factures PDF, documents, exports | Sécurisation S3-compatible (Scaleway Object Storage) |
| Monitoring | Laravel Pulse + Sentry + Grafana | Observabilité complète |
| CI/CD | GitLab CI | Déjà sur GitLab |
| Conteneurisation | Docker + Docker Compose | Reproductibilité, pré-requis cloud (à réfléchir) |
| Hébergement | Scaleway (Paris) | Souveraineté FR, RGPD, coût maîtrisé |

## Roadmap condensée

| Phase | Durée | Contenu |
|-------|-------|---------|
| **Phase 0** | 1 mois | Docker, CI/CD, tests, Laravel 12, monitoring |
| **Phase 1** | 2-3 mois | API interne, refonte CDR, Redis, **install stancl/tenancy + BDD centrale** |
| **Phase 2** | 2-3 mois | Modularisation, refonte facturation, Livewire 4, **sync catalogue + SSO** |
| **Phase 3** | 2-3 mois | Portails client/amba Livewire 4, migration cloud, **provisioning auto de régions** |
| **Phase 4** | Continu | Nouvelles régions, scaling infra (1 serveur/région si besoin) |

---

##  Analyse du schéma SQL +80 tables

### Problème n°1 : `invoices.doc` est un JSON blob

La colonne `doc` (JSON) contient **l'intégralité** de chaque facture (en-tête + lignes + calculs). Les colonnes `date`, `amount`, `paid`, `locked`, `number` sont des GENERATED STORED extraites du JSON. Chaque SELECT charge le JSON complet en mémoire. **C'est la cause réelle de la lenteur de la table invoices.**

**Solution V2** : Refonte en `invoices` (en-tête normalisé) + `invoice_lines` (lignes détaillées). Migration progressive avec dual-write.

### Problème n°2 : `calls` n'a pas de `client_id`

Les requêtes CDR par client nécessitent un JOIN systématique via `lines`. Avec 12 Go+, c'est coûteux.

**Solution V2** : Ajouter `client_id` dénormalisé + index composite. Quick win réalisable en 1 jour.

### Point positif : `monthly_summaries` existe déjà

L'agrégation mensuelle par ligne est en place. Il manque l'agrégation quotidienne et les colonnes financières.

### Point positif : `cdr_files` assure la traçabilité des imports

Le lien `calls.cdr_file_id` permet l'idempotence et la traçabilité.

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
