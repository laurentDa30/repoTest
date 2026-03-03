# 📋 Livrables Finaux Consolidés

---

## 1. Diagramme d'architecture cible

```
                              ┌──────────────────────────────────┐
                              │         INTERNET / USERS          │
                              └──────────┬───────────────────────┘
                                         │
                              ┌──────────▼──────────────────────┐
                              │   Cloudflare / Scaleway Edge     │
                              │   WAF · DDoS · CDN · SSL        │
                              │   DNS: *.cekoya.fr (wildcard)    │
                              └──────────┬───────────────────────┘
                                         │
                              ┌──────────▼──────────────────────┐
                              │     Scaleway Load Balancer       │
                              │     SSL termination (wildcard)   │
                              │     Health checks                │
                              └───┬──────────┬──────────┬───────┘
                                  │          │          │
                    ┌─────────────▼──┐  ┌───▼────────┐ │
                    │  App Server 1  │  │ App Server 2│ │
                    │  Docker        │  │ Docker      │ │
                    │                │  │             │ │
                    │ ┌────────────┐ │  │ ┌─────────┐ │ │
                    │ │ Nginx      │ │  │ │ Nginx   │ │ │
                    │ │ PHP-FPM 8.3│ │  │ │ PHP-FPM │ │ │
                    │ │ Laravel 12 │ │  │ │ Laravel │ │ │
                    │ │ + stancl/  │ │  │ │ 12      │ │ │
                    │ │   tenancy  │ │  │ │ +tenant │ │ │
                    │ │ Livewire 3 │ │  │ │         │ │ │
                    │ └────────────┘ │  │ └─────────┘ │ │
                    └────────────────┘  └─────────────┘ │
                                                        │
                         ┌──────────────────────────────▼───────┐
                         │           Worker Server               │
                         │           Docker                      │
                         │  ┌─────────────────────────────────┐  │
                         │  │ Laravel Horizon                  │  │
                         │  │ ├── supervisor-default (5 proc)  │  │
                         │  │ ├── supervisor-imports (3 proc)  │  │
                         │  │ ├── supervisor-billing (2 proc)  │  │
                         │  │ └── supervisor-tenancy (2 proc)  │  │
                         │  ├─────────────────────────────────┤  │
                         │  │ Laravel Scheduler (cron)         │  │
                         │  ├─────────────────────────────────┤  │
                         │  │ Laravel Reverb (WebSocket)       │  │
                         │  └─────────────────────────────────┘  │
                         └───────────────────────────────────────┘
                                  │          │           │
               ┌──────────────────┼──────────┼───────────┼───────────┐
               │                  │          │           │           │
      ┌────────▼────────┐ ┌──────▼───┐ ┌──▼────────┐               │
      │ MySQL 8 Managé  │ │ Redis 7  │ │  S3       │               │
      │ (DB-DEV2-M)     │ │ Managé   │ │  Object   │               │
      │                 │ │ Cache    │ │  Storage  │               │
      │ Databases:      │ │ Sessions │ │           │               │
      │ ├ cekoya_central│ │ Queues   │ │ Par région│               │
      │ │  └ tenants    │ │ Rate     │ │ /idf/     │               │
      │ │  └ catalog    │ │ limiting │ │ /paca/    │               │
      │ │  └ regional_  │ │          │ │ /lyon/    │               │
      │ │    summaries  │ │          │ │           │               │
      │ ├ cekoya_idf    │ │ Search:  │ │ Factures  │               │
      │ │  └ calls      │ │ Scout +  │ │ Documents │               │
      │ │  └ invoices   │ │ database │ │ Exports   │               │
      │ │  └ clients    │ │ driver   │ │ CDR arch. │               │
      │ │  └ daily_sum  │ │ (par     │ │ Backups   │               │
      │ ├ cekoya_paca   │ │  tenant) │ │           │               │
      │ └ cekoya_lyon   │ │          │ │           │               │
      └─────────────────┘ └──────────┘ └───────────┘               │
               │                                                     │
               │              VPC PRIVÉ (non exposé internet)         │
               └─────────────────────────────────────────────────────┘

               INTÉGRATIONS EXTERNES (via module Integration)
               ┌─────────┐ ┌─────────┐ ┌──────┐ ┌──────────┐ ┌──────┐ ┌────────┐
               │Transatel│ │  Unyc   │ │ IELO │ │euroFIBER │ │ Wazo │ │Yousign │
               │  API    │ │Import/  │ │ API  │ │  API     │ │Import│ │  API   │
               │  REST   │ │  API    │ │      │ │          │ │      │ │        │
               └─────────┘ └─────────┘ └──────┘ └──────────┘ └──────┘ └────────┘
```

### Routing des portails (multi-région)

```
central.cekoya.fr            ──► Hub Central (Livewire 3)    ──► MW: super_admin + MFA
{region}.cekoya.fr           ──► Admin régional (Livewire 3) ──► MW: tenant + admin + MFA
{region}-client.cekoya.fr    ──► Portail client (Livewire 3) ──► MW: tenant + client auth
{region}-amba.cekoya.fr      ──► Portail ambass. (Livewire 3)──► MW: tenant + ambassador auth
api.cekoya.fr                ──► Laravel API REST             ──► MW: Sanctum + throttle + tenant
```

Même application Laravel, **routage par domaine** (stancl/tenancy identifie le tenant via le sous-domaine, switch la BDD automatiquement).

---

## 2. Stack complète recommandée

### Backend
| Composant | Technologie | Version |
|-----------|------------|---------|
| Framework | Laravel | 12 (LTS) |
| PHP | PHP | 8.3 |
| ORM | Eloquent | Natif Laravel |
| Multi-tenancy | stancl/tenancy | ^3.0 (database-per-tenant) |
| Auth | Laravel Sanctum + Session | Natif |
| Queues | Laravel Horizon + Redis | Natif |
| WebSocket | Laravel Reverb | Natif (remplace Pusher) |
| Scheduler | Laravel Scheduler | Natif |
| Search | Laravel Scout + database driver | Natif (zéro service tiers) |
| Audit | spatie/laravel-activitylog | ^4.0 |
| Monitoring | Laravel Pulse | Natif |
| Tests | Pest PHP | ^3.0 |
| Analyse statique | PHPStan / Larastan | Level 6 |
| Code style | Laravel Pint | Natif |
| Architecture | Monolithe modulaire DDD-lite | Domain/Application/Infrastructure |

### Frontend (stack unifiée)
| Composant | Technologie | Portail |
|-----------|------------|---------|
| Hub central | Livewire 3 + Alpine.js | central.cekoya.fr |
| Admin régional | Livewire 3 + Alpine.js | {region}.cekoya.fr |
| Client UI | Livewire 3 + Alpine.js | {region}-client.cekoya.fr |
| Ambassador UI | Livewire 3 + Alpine.js | {region}-amba.cekoya.fr |
| CSS Framework | Tailwind CSS 4 | Tous |
| Charts | ApexCharts | Tous |
| Build tool | Vite | Tous |

### Infrastructure
| Composant | Technologie |
|-----------|------------|
| Cloud | Scaleway Paris |
| Containers | Docker + Docker Compose |
| Reverse proxy | Nginx |
| DB | MySQL 8.0 Managé (DB-DEV2-M, multi-BDD) |
| Cache/Queue | Redis 7 Managé |
| Search | Laravel Scout (database driver, via Redis) |
| Object Storage | Scaleway S3 (isolé par région) |
| CI/CD | GitLab CI |
| DNS/WAF/CDN | Cloudflare (wildcard *.cekoya.fr) |
| Monitoring | Laravel Pulse + Sentry + Scaleway Cockpit |
| Secrets | Scaleway Secret Manager (par tenant) |

---

## 3. Stratégie de scaling

### Scaling horizontal — Deux axes : régions + charge

```
PHASE A — Serveur unique multi-tenant (1-3 régions)
└── 2 app servers + 1 worker + 1 MySQL (multi-BDD)
└── SUFFISANT pour 380-1000 clients répartis sur 3 régions

PHASE B — Serveur par région (3-10 régions)
└── 2 app servers (partagés) + 1 worker + MySQL par région
└── Chaque région a sa propre BDD sur instance dédiée
└── SUFFISANT pour 1000-3000 clients

PHASE C — Full scaling (10+ régions)
└── App servers par région + workers par région
└── Read replicas MySQL + load balancing par région
└── Passage potentiel à Kubernetes si complexité justifiée
```

### Scaling vertical (immédiat)
- MySQL : upgrade DB-DEV2-M → DB-DEV2-L sans downtime
- Redis : upgrade instance
- App servers : scale-up des containers

### Goulots d'étranglement anticipés et solutions

| Goulot | Seuil | Solution |
|--------|-------|----------|
| CDR queries (par région) | > 5M records actifs | `client_id` dénormalisé + agrégation + partitionnement |
| Import Transatel horaire | > 100K CDR/batch | Batch processing parallélisé |
| Facturation mensuelle | > 1000 factures simultanées | Queue billing dédiée + génération PDF async |
| Recherche clients/lignes | > 50K entités par région | Scout + database driver (index par tenant) |
| Sessions concurrentes | > 100 simultanées (toutes régions) | Redis sessions (déjà prévu) |
| Sync catalogue vers N régions | > 10 régions | Queue tenant-sync + batch sync parallélisé |
| MySQL unique saturé | > 5 BDD régionales actives | Passage Phase B (MySQL par région) |

---

## 4. Stratégie de sécurité

Voir **06-CYBERSECURITE.md** pour le détail complet.

**Résumé** :
1. **Périmètre réseau** : Cloudflare WAF + VPC privé + firewall
2. **Application** : Sanctum, rate limiting, CORS, CSP, Form Requests, Policies
3. **Données** : Chiffrement applicatif (IBAN, SIRET) + chiffrement au repos + KMS externe
4. **Audit** : Logging exhaustif des actions sensibles, conservation 2 ans
5. **CI/CD** : Scan dépendances, SAST, scan secrets
6. **Opérationnel** : MFA, rotation secrets, pen test annuel

---

## 5. Plan CI/CD détaillé

```
┌─────────────────── WORKFLOW CI/CD ───────────────────┐
│                                                       │
│  Push/PR sur develop                                  │
│  │                                                    │
│  ├── 1. Lint (Pint)                    ~30s          │
│  ├── 2. Static Analysis (PHPStan)      ~1min         │
│  ├── 3. Tests unitaires (Pest)         ~2min         │
│  ├── 4. Tests d'intégration (Pest)     ~3min         │
│  ├── 5. Security audit (composer)      ~30s          │
│  ├── 6. Secret scanning (TruffleHog)   ~30s          │
│  │                                                    │
│  └── ✅ Merge autorisé                                │
│                                                       │
│  Merge sur develop                                    │
│  │                                                    │
│  ├── Build Docker image → tag :staging                │
│  ├── Push vers Scaleway Registry                      │
│  ├── Deploy sur staging                               │
│  └── Smoke tests automatiques                         │
│                                                       │
│  Merge sur main (ou tag release)                      │
│  │                                                    │
│  ├── Build Docker image → tag :sha + :latest          │
│  ├── Push vers Scaleway Registry                      │
│  ├── ⏸  Approbation manuelle (environment: production)│
│  ├── Blue-green deploy sur production                 │
│  ├── Smoke tests post-deploy                          │
│  ├── Notification Slack/Discord succès                │
│  └── ✅ Rollback automatique si smoke test échoue     │
│                                                       │
└───────────────────────────────────────────────────────┘
```

### Branches
- `main` → production
- `develop` → staging
- `feature/*` → PR vers develop
- `hotfix/*` → PR vers main (+ cherry-pick develop)

---

## 6. Plan de monitoring & observabilité

### 3 piliers

```
┌────────────────────────────────────────────────────────────┐
│                    OBSERVABILITÉ                            │
│                                                             │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────┐  │
│  │   MÉTRIQUES  │  │    LOGS      │  │    TRACES        │  │
│  │              │  │              │  │                  │  │
│  │ Laravel      │  │ Laravel Log  │  │ Sentry           │  │
│  │ Pulse        │  │ (Monolog)    │  │ (error tracking  │  │
│  │              │  │              │  │  + performance)  │  │
│  │ • Requêtes   │  │ • App logs   │  │                  │  │
│  │ • Slow       │  │ • Queue logs │  │ • Stack traces   │  │
│  │   queries    │  │ • Import     │  │ • Request spans  │  │
│  │ • Queue size │  │   logs       │  │ • User context   │  │
│  │ • Cache hit  │  │ • Auth logs  │  │                  │  │
│  │   rate       │  │ • Audit trail│  │                  │  │
│  │ • Memory     │  │              │  │                  │  │
│  │ • CPU        │  │ Stockage:    │  │                  │  │
│  │              │  │ Scaleway     │  │                  │  │
│  │ Dashboard:   │  │ Cockpit      │  │                  │  │
│  │ Pulse UI     │  │              │  │                  │  │
│  └──────────────┘  └──────────────┘  └──────────────────┘  │
│                                                             │
│  ┌──────────────────────────────────────────────────────┐   │
│  │                    ALERTES                            │   │
│  │                                                      │   │
│  │  Sentry  : erreur 500 → notification immédiate       │   │
│  │  Pulse   : slow query > 5s → notification            │   │
│  │  Custom  : import CDR échoué → notification          │   │
│  │  Custom  : queue size > 1000 → notification          │   │
│  │  Uptime  : healthcheck fail → notification           │   │
│  │                                                      │   │
│  │  Canal : Slack / Discord / Email                     │   │
│  └──────────────────────────────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### Health check endpoint

```php
// routes/web.php
Route::get('/health', function () {
    $checks = [
        'database' => DB::connection()->getPdo() ? 'ok' : 'fail',
        'redis' => Redis::ping() ? 'ok' : 'fail',
        'queue' => Queue::size('default') < 1000 ? 'ok' : 'warning',
        'disk' => disk_free_space('/') > 1_000_000_000 ? 'ok' : 'warning',
        'last_cdr_import' => Cache::get('last_cdr_import_at') > now()->subHours(3)
            ? 'ok' : 'warning',
    ];

    $status = collect($checks)->contains('fail') ? 503 : 200;

    return response()->json($checks, $status);
});
```

---

## 7. Plan de migration progressif (V1 → V2)

```
                    TIMELINE DE MIGRATION

Mois 0─1          Mois 1─4          Mois 4─7          Mois 7─10
┌──────────┐      ┌──────────┐      ┌──────────┐      ┌──────────┐
│ PHASE 0  │      │ PHASE 1  │      │ PHASE 2  │      │ PHASE 3  │
│ Fondation│─────►│ Coeur    │─────►│ Modules  │─────►│ Portails │
│          │      │ critique │      │ métier   │      │ externes │
└──────────┘      └──────────┘      └──────────┘      └──────────┘
```

### Phase 0 — Fondations (Mois 0-1)

**Objectif** : Poser les bases techniques sans toucher au fonctionnel.

| Tâche | Détail | Durée |
|-------|--------|-------|
| Docker + Docker Compose | Conteneuriser l'app existante V1 | 2-3 jours |
| CI/CD GitLab CI | Pipeline tests + lint + deploy staging | 2-3 jours |
| Tests de base | Tests sur les flux critiques (import, facturation) | 3-5 jours |
| Laravel 10 → 11 → 12 | Via Laravel Shift + ajustements manuels | 2-3 jours |
| Livewire 2 → 3 (début) | Coexistence, migration composants simples | 3-5 jours |
| Redis | Installation, migration sessions + cache | 1-2 jours |
| Monitoring | Laravel Pulse + Sentry | 1 jour |
| Audit trail | spatie/laravel-activitylog | 1 jour |
| **stancl/tenancy** | Install + config, BDD centrale, V1 = premier tenant | 2-3 jours |

**Livrable** : V1 dockerisée, CI/CD fonctionnel, monitoring en place, Laravel 12, **multi-tenancy opérationnel (V1 = tenant initial)**.
**Risque** : Faible — aucune modification fonctionnelle. La V1 tourne comme unique tenant.

### Phase 1 — Coeur critique (Mois 1-4)

**Objectif** : Résoudre les problèmes de performance et poser l'architecture API.

| Tâche | Détail | Durée |
|-------|--------|-------|
| Quick win CDR : `client_id` | Ajouter `client_id` dénormalisé + backfill | 1-2 jours |
| `daily_call_summaries` | Nouvelle table d'agrégation quotidienne + nightly job | 2-3 jours |
| Enrichir `monthly_summaries` | Ajouter `client_id`, `total_charge`, `total_price` | 1 jour |
| Archivage CDR > 12 mois | Export S3 par région + purge | 2-3 jours |
| Refonte `invoices` (sortie JSON blob) | `invoices_v2` + `invoice_lines` + migration JSON→normalisé | 5-7 jours |
| Génération PDF async | Queue billing + stockage S3 (par région) | 2-3 jours |
| API REST interne v1 | Routes tenant + routes centrales (voir 03-BACKEND) | 5-7 jours |
| Module Integration (refacto) | Pattern Gateway pour Transatel, Unyc, Wazo | 5-7 jours |
| Sync catalogue + SSO | Hub → régions via queue tenant-sync | 3-5 jours |
| Laravel Scout | Config database driver par tenant (clients, lignes, catalogue) | 1-2 jours |
| Laravel Reverb | Remplacement Pusher | 1-2 jours |
| Migration cloud staging | Staging Scaleway multi-BDD | 2-3 jours |

**Livrable** : Performance CDR résolue, facturation normalisée, API interne, **sync catalogue Hub ↔ régions**, staging cloud.
**Risque** : Moyen — la migration `invoices.doc` JSON → normalisé est l'opération la plus délicate.

### Phase 2 — Modularisation métier (Mois 4-7)

**Objectif** : Restructurer le code en modules DDD-lite (Domain/Application/Infrastructure).

| Tâche | Détail | Durée |
|-------|--------|-------|
| Module Prospect | Domain/Application/Infrastructure + tests | 3-5 jours |
| Module Client | Domain/Application/Infrastructure + tests | 5-7 jours |
| Module Telecom | Lignes, SIMs, portabilités (Domain-first) | 5-7 jours |
| Module Billing | Facturation normalisée, SEPA, comptabilité | 5-7 jours |
| Module Catalog | Matériels, services, forfaits (centralisé Hub) | 3-5 jours |
| Module Stock | Gestion stock + SIMs | 2-3 jours |
| Nettoyage BDD | Supprimer tables `_bkp`, finaliser `plan_rates` vs `pricing_zones` | 1-2 jours |
| Tailwind CSS migration (début) | Nouveaux composants en Tailwind, coexistence Bootstrap | Continu |
| Livewire 3 migration complète | Tous les composants admin + Hub central | Continu |
| Migration cloud production | Bascule DNS (wildcard), blue-green | 2-3 jours |
| Provisioning 2ème région | Tester le workflow complet d'ajout de région | 2-3 jours |

**Livrable** : Architecture modulaire DDD-lite en production, cloud production actif, **2 régions opérationnelles**.
**Risque** : Moyen — la modularisation peut révéler du couplage caché.

### Phase 3 — Portails externes (Mois 7-10)

**Objectif** : UX de qualité pour les clients et ambassadeurs.

| Tâche | Détail | Durée |
|-------|--------|-------|
| Portail client Livewire 3 | Dashboard, lignes, consommation, factures (scopé par tenant) | 7-10 jours |
| Portail ambassadeur Livewire 3 | Dashboard, paiements, historique (scopé par tenant) | 3-5 jours |
| Dashboard Hub central | Vue synthétique multi-régions + SSO | 3-5 jours |
| Suppression Bootstrap | Migration Tailwind complète | 3-5 jours |
| Module Environnement (refacto) | RSE, émissions, captation | 3-5 jours |
| Module Content (refacto) | Rapports, mailing, templates | 3-5 jours |
| Provisioning auto de régions | CLI artisan + UI admin pour créer une nouvelle région | 3-5 jours |
| Tests E2E | Couverture flux critiques + tests cross-tenant | 3-5 jours |
| Documentation API | OpenAPI/Swagger (routes tenant + routes centrales) | 2-3 jours |
| Pen test | Audit sécurité externe (incluant tests cross-tenant) | 1-2 semaines (externe) |

**Livrable** : V2 complète, tous portails migrés, **provisioning auto de régions**, documentation, sécurité validée.

---

## 8. Analyse des risques majeurs

| # | Risque | Probabilité | Impact | Mitigation | Owner |
|---|--------|-------------|--------|------------|-------|
| R1 | Migration `invoices.doc` JSON → normalisé perd des données | Faible | CRITIQUE | Script de migration avec checksums : comparer totaux JSON vs colonnes pour chaque facture | Backend |
| R2 | L'équipe de 2 devs ne tient pas la cadence | Élevée | ÉLEVÉ | Phases ajustables, report non bloquant, recrutement anticipé si croissance confirmée | SI |
| R3 | Régression fonctionnelle sur la facturation | Moyenne | CRITIQUE | Tests automatisés sur les montants, dual-write pendant transition | Backend |
| R4 | Migration cloud = downtime | Faible | ÉLEVÉ | Blue-green deployment, bascule DNS, rollback instantané | DevOps |
| R5 | Fournisseur change son API pendant la migration | Moyenne | MOYEN | Pattern Gateway isole l'impact, un seul fichier à modifier | Architecte |
| R6 | Données sensibles exposées via la nouvelle API | Faible | CRITIQUE | API Resources contrôlent la sortie, tests de sécurité, pen test | Cybersec |
| R7 | Cohabitation V1/V2 génère des bugs de données | Moyenne | ÉLEVÉ | Migrations rétro-compatibles, tests d'intégration, monitoring actif | Backend + DevOps |
| R8 | Bootstrap + Tailwind coexistence génère des conflits CSS | Moyenne | FAIBLE | Préfixe Tailwind, isolation progressive | Frontend |
| R9 | Fuite de données cross-tenant | Faible | CRITIQUE | stancl/tenancy force l'isolation BDD, tests anti-fuite automatisés, pen test cross-tenant | Cybersec |
| R10 | Sync catalogue désynchronisée entre régions | Moyenne | ÉLEVÉ | Checksums, logs de sync, retry auto, alerte si écart | Backend |
| R11 | MySQL unique saturé par N BDD régionales | Moyenne | MOYEN | Monitoring taille/BDD, passage Phase B si dépassement | DevOps |

---

## 9. Roadmap technique priorisée

```
         ROADMAP V2 CEKOYA (Multi-région + DDD-lite)

Q1 (Mois 0-3)                    Q2 (Mois 3-6)                 Q3 (Mois 6-9)              Q4 (Mois 9-12)
┌──────────────────────────┐ ┌──────────────────────────┐ ┌──────────────────────────┐ ┌──────────────────────────┐
│ 🔴 CRITIQUE              │ │ 🟠 IMPORTANT             │ │ 🟡 VALEUR AJOUTÉE        │ │ 🟢 CONSOLIDATION         │
│                          │ │                          │ │                          │ │                          │
│ ✓ Docker + CI/CD         │ │ ✓ Modules DDD-lite       │ │ ✓ Portail client LW3     │ │ ✓ Documentation complète │
│ ✓ Laravel 12 upgrade     │ │   (Domain/App/Infra)     │ │ ✓ Portail ambass. LW3    │ │ ✓ Pen test (cross-tenant)│
│ ✓ stancl/tenancy setup   │ │ ✓ Refonte invoices       │ │ ✓ Dashboard Hub central  │ │ ✓ Tests E2E              │
│ ✓ Redis (cache+queues)   │ │   (sortie JSON blob)     │ │ ✓ Suppression Bootstrap  │ │ ✓ Provisioning auto      │
│ ✓ Monitoring (Pulse+     │ │ ✓ Livewire 3 complet     │ │ ✓ Module Environnement   │ │   de régions             │
│   Sentry)                │ │ ✓ Sync catalogue + SSO   │ │ ✓ Module Content         │ │ ✓ Optimisation perf      │
│ ✓ Quick win CDR          │ │ ✓ Migration cloud prod   │ │ ✓ API documentation      │ │ ✓ Recrutement dev #3     │
│   (client_id + agrég.)   │ │ ✓ Tailwind (début)       │ │ ✓ Nettoyage BDD (_bkp)   │ │ ✓ KPI review             │
│ ✓ API interne v1         │ │ ✓ Provisioning 2è région │ │                          │ │                          │
│ ✓ Audit trail            │ │                          │ │                          │ │                          │
│ ✓ Pattern Gateway        │ │                          │ │                          │ │                          │
│ ✓ Scout (database drv.)  │ │                          │ │                          │ │                          │
│ ✓ Reverb (bye Pusher)    │ │                          │ │                          │ │                          │
│ ✓ Staging cloud          │ │                          │ │                          │ │                          │
│                          │ │                          │ │                          │ │                          │
│ Équipe: 2 devs           │ │ Équipe: 2 devs           │ │ Équipe: 2-3 devs         │ │ Équipe: 3 devs           │
│ Infra: local + staging   │ │ Infra: cloud prod        │ │ Infra: cloud + 2 régions │ │ Infra: cloud + N régions │
│ Tenant: V1 = 1er tenant  │ │ Tenant: 2 régions        │ │ Budget: ~250€/mois       │ │ Budget: ~300-500€/mois   │
│ Budget: ~165€/mois cloud │ │ Budget: ~200€/mois cloud │ │                          │ │                          │
└──────────────────────────┘ └──────────────────────────┘ └──────────────────────────┘ └──────────────────────────┘
```

---

## Annexe : Checklist de validation pré-production

### Technique
- [ ] Tous les tests passent (> 60% couverture)
- [ ] PHPStan level 6 sans erreur
- [ ] Audit de dépendances clean (composer audit)
- [ ] Scan secrets clean (TruffleHog)
- [ ] Health check endpoint opérationnel
- [ ] Backups automatisés et testés (restore, par tenant)
- [ ] Monitoring + alertes configurés
- [ ] Blue-green deploy testé avec rollback

### Sécurité
- [ ] Audit trail actif sur toutes les entités sensibles
- [ ] MFA actif pour tous les utilisateurs admin + super_admin
- [ ] Rate limiting actif sur toutes les routes API
- [ ] CSP + HSTS headers en place
- [ ] Tests anti-fuite cross-tenant passent
- [ ] SSO tokens validés (one-time-use, expiration 60s)
- [ ] KMS externe configuré (pas de clés dans .env)

### Multi-tenant
- [ ] stancl/tenancy opérationnel (switch BDD par sous-domaine)
- [ ] V1 fonctionne comme premier tenant (zéro régression)
- [ ] Sync catalogue Hub → régions testée et validée
- [ ] Provisioning d'une nouvelle région documenté et testé
- [ ] Backups par BDD régionale automatisés
- [ ] Scout database driver fonctionne par tenant

### Documentation
- [ ] Documentation API (OpenAPI) à jour (routes tenant + centrales)
- [ ] Run book d'incidents documenté
- [ ] Procédure de provisioning de nouvelle région documentée
