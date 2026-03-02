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

**Migration progressive (Strangler Fig)** — Pas de réécriture big-bang. La V2 se construit module par module en s'appuyant sur la base existante.

## Architecture cible

**Monolithe modulaire Laravel 12 + API-first + domaines isolés**

## Stack V2 recommandée

| Couche | Technologie | Justification |
|--------|------------|---------------|
| Backend | Laravel 12 (LTS) | Continuité de compétences, écosystème riche |
| Frontend admin | Livewire 3 + Alpine.js + Tailwind CSS | Productivité maximale pour 2 devs |
| Frontend client/amba | API REST + Vue 3 (Inertia.js) | UX riche, séparation progressive |
| Base relationnelle | MySQL 8.0 (partitionné) | Maîtrisé, partitioning natif |
| CDR/Time-series | MySQL 8.0 partitionné + tables d'agrégation | Résout le problème 12 Go sans nouvelle techno |
| Cache / Queue | Redis 7 | Cache, sessions, queues, rate limiting |
| Search | Meilisearch | Recherche catalogue, clients, lignes |
| Temps réel | Laravel Reverb (remplacement Pusher) | Natif Laravel, zéro coût tiers |
| Signature | Yousign (existant) | Maintien |
| Stockage fichiers | S3-compatible (Scaleway Object Storage) | Factures PDF, documents, exports |
| Monitoring | Laravel Pulse + Sentry + Grafana | Observabilité complète |
| CI/CD | GitHub Actions | Déjà sur GitHub |
| Conteneurisation | Docker + Docker Compose | Reproductibilité, pré-requis cloud |
| Hébergement | Scaleway (Paris) | Souveraineté FR, RGPD, coût maîtrisé |

## Roadmap condensée

| Phase | Durée | Contenu |
|-------|-------|---------|
| **Phase 0** | 1 mois | Docker, CI/CD, tests, Laravel 12, monitoring |
| **Phase 1** | 2-3 mois | API interne, refonte CDR (partitioning + agrégation), Redis |
| **Phase 2** | 2-3 mois | Modularisation domaines, refonte facturation, Livewire 3 |
| **Phase 3** | 2-3 mois | Portails client/ambassadeur en Vue 3, migration cloud |
| **Phase 4** | Continu | Optimisations, nouvelles intégrations, scaling |

---

## Découvertes critiques après analyse du schéma SQL (80+ tables)

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

### Dette technique : tables `_bkp` et double système de tarification

Les tables `plan_rates_bkp`, `pricing_zones`, `supplier_zone_countries_bkp` indiquent une migration partielle de la tarification. À finaliser et nettoyer.

---

> Détails complets dans les documents d'analyse par rôle :
> - `01-ARCHITECTE-LOGICIEL.md` — Architecture modulaire + revue cybersécurité
> - `02-DEVOPS-CLOUD.md` — Infrastructure Scaleway + revue architecte
> - `03-BACKEND.md` — API, patterns, imports + revue DevOps
> - `04-FRONTEND.md` — Livewire 3 / Vue 3 / Tailwind + revue backend
> - `05-RESPONSABLE-SI.md` — Gouvernance, coûts, risques + validation collective
> - `06-CYBERSECURITE.md` — Modèle de sécurité
> - `07-LIVRABLES-FINAUX.md` — Diagrammes, stack, roadmap, plan de migration
> - `08-ANALYSE-SCHEMA-BDD.md` — Analyse détaillée des 80+ tables, problèmes critiques, plan de migration schéma
