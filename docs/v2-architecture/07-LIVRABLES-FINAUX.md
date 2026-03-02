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
                         │   DNS: *.cekoya.fr               │
                         └──────────┬───────────────────────┘
                                    │
                         ┌──────────▼──────────────────────┐
                         │     Scaleway Load Balancer       │
                         │     SSL termination              │
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
               │ │ Livewire 3 │ │  │ │ 12      │ │ │
               │ └────────────┘ │  │ └─────────┘ │ │
               └────────────────┘  └─────────────┘ │
                                                    │
                    ┌───────────────────────────────▼───────┐
                    │           Worker Server               │
                    │           Docker                      │
                    │  ┌─────────────────────────────────┐  │
                    │  │ Laravel Horizon                  │  │
                    │  │ ├── supervisor-default (5 proc)  │  │
                    │  │ ├── supervisor-imports (3 proc)  │  │
                    │  │ └── supervisor-billing (2 proc)  │  │
                    │  ├─────────────────────────────────┤  │
                    │  │ Laravel Scheduler (cron)         │  │
                    │  ├─────────────────────────────────┤  │
                    │  │ Laravel Reverb (WebSocket)       │  │
                    │  └─────────────────────────────────┘  │
                    └───────────────────────────────────────┘
                             │          │           │
          ┌──────────────────┼──────────┼───────────┼───────────┐
          │                  │          │           │           │
 ┌────────▼────────┐ ┌──────▼───┐ ┌────▼─────┐ ┌──▼────────┐  │
 │ MySQL 8 Managé  │ │ Redis 7  │ │Meilisearch│ │  S3       │  │
 │                 │ │ Managé   │ │          │ │  Object   │  │
 │ Partitionnement │ │ Cache    │ │ Recherche│ │  Storage  │  │
 │ CDR par mois    │ │ Sessions │ │ clients  │ │           │  │
 │                 │ │ Queues   │ │ lignes   │ │ Factures  │  │
 │ Tables:         │ │ Rate     │ │ catalogue│ │ Documents │  │
 │ - calls (part.) │ │ limiting │ │          │ │ Exports   │  │
 │ - daily_summary │ │          │ │          │ │ CDR arch. │  │
 │ - monthly_sum.  │ │          │ │          │ │ Backups   │  │
 │ - invoices      │ │          │ │          │ │           │  │
 │ - clients, etc. │ │          │ │          │ │           │  │
 └─────────────────┘ └──────────┘ └──────────┘ └───────────┘  │
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

### Routing des portails

```
prod.cekoya.fr   ──► Laravel (Livewire 3)    ──► Middleware: admin auth + MFA
client.cekoya.fr ──► Laravel (Inertia/Vue 3) ──► Middleware: client auth
amba.cekoya.fr   ──► Laravel (Inertia/Vue 3) ──► Middleware: ambassador auth
api.cekoya.fr    ──► Laravel API REST        ──► Middleware: Sanctum + throttle
```

Même application Laravel, **routage par domaine** (Laravel route domain groups).

---

## 2. Stack complète recommandée

### Backend
| Composant | Technologie | Version |
|-----------|------------|---------|
| Framework | Laravel | 12 (LTS) |
| PHP | PHP | 8.3 |
| ORM | Eloquent | Natif Laravel |
| Auth | Laravel Sanctum + Session | Natif |
| Queues | Laravel Horizon + Redis | Natif |
| WebSocket | Laravel Reverb | Natif (remplace Pusher) |
| Scheduler | Laravel Scheduler | Natif |
| Search | Laravel Scout + Meilisearch | Natif |
| Audit | spatie/laravel-activitylog | ^4.0 |
| Monitoring | Laravel Pulse | Natif |
| Tests | Pest PHP | ^3.0 |
| Analyse statique | PHPStan / Larastan | Level 6 |
| Code style | Laravel Pint | Natif |

### Frontend
| Composant | Technologie | Portail |
|-----------|------------|---------|
| Admin UI | Livewire 3 + Alpine.js | prod.cekoya.fr |
| Client UI | Inertia.js + Vue 3 | client.cekoya.fr |
| Ambassador UI | Inertia.js + Vue 3 | amba.cekoya.fr |
| CSS Framework | Tailwind CSS 4 | Tous |
| Charts | ApexCharts | Tous |
| Build tool | Vite | Tous |

### Infrastructure
| Composant | Technologie |
|-----------|------------|
| Cloud | Scaleway Paris |
| Containers | Docker + Docker Compose |
| Reverse proxy | Nginx |
| DB | MySQL 8.0 Managé |
| Cache/Queue | Redis 7 Managé |
| Search | Meilisearch |
| Object Storage | Scaleway S3 |
| CI/CD | GitHub Actions |
| DNS/WAF/CDN | Cloudflare |
| Monitoring | Laravel Pulse + Sentry + Scaleway Cockpit |
| Secrets | Scaleway Secret Manager |

---

## 3. Stratégie de scaling

### Scaling horizontal (si le parc double+)

```
Situation actuelle (380 clients, 6K lignes)
└── 2 app servers + 1 worker ── SUFFISANT

Doublement (760 clients, 12K lignes)
└── 2-3 app servers + 1-2 workers ── SUFFISANT

x5 (1900 clients, 30K lignes)
└── 3-4 app servers + 2 workers + read replica MySQL
└── Passage potentiel à Kubernetes si complexité justifiée
```

### Scaling vertical (immédiat)
- MySQL : upgrade de l'instance Scaleway (CPU/RAM) sans downtime
- Redis : upgrade instance
- App servers : scale-up des containers

### Goulots d'étranglement anticipés et solutions

| Goulot | Seuil | Solution |
|--------|-------|----------|
| CDR queries | > 5M records actifs | Partitionnement (déjà prévu) |
| Import Transatel horaire | > 100K CDR/batch | Batch processing parallélisé |
| Facturation mensuelle | > 1000 factures simultanées | Queue billing dédiée + génération PDF async |
| Recherche clients/lignes | > 50K entités | Meilisearch (déjà prévu) |
| Sessions concurrentes | > 100 simultanées | Redis sessions (déjà prévu) |

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
| CI/CD GitHub Actions | Pipeline tests + lint + deploy staging | 2-3 jours |
| Tests de base | Tests sur les flux critiques (import, facturation) | 3-5 jours |
| Laravel 10 → 11 → 12 | Via Laravel Shift + ajustements manuels | 2-3 jours |
| Livewire 2 → 3 (début) | Coexistence, migration composants simples | 3-5 jours |
| Redis | Installation, migration sessions + cache | 1-2 jours |
| Monitoring | Laravel Pulse + Sentry | 1 jour |
| Audit trail | spatie/laravel-activitylog | 1 jour |

**Livrable** : V1 dockerisée, CI/CD fonctionnel, monitoring en place, Laravel 12.
**Risque** : Faible — aucune modification fonctionnelle.

### Phase 1 — Coeur critique (Mois 1-4)

**Objectif** : Résoudre les problèmes de performance et poser l'architecture API.

| Tâche | Détail | Durée |
|-------|--------|-------|
| Partitionnement table `calls` | Migration sans downtime, tables d'agrégation | 3-5 jours |
| Job d'agrégation CDR | Nightly job + historique | 2-3 jours |
| Archivage CDR > 12 mois | Export S3 + purge | 2-3 jours |
| Refonte table `invoices` | Séparation headers/lines, totaux pré-calculés | 3-5 jours |
| Génération PDF async | Queue billing + stockage S3 | 2-3 jours |
| API REST interne v1 | Endpoints clients, lignes, catalogue | 5-7 jours |
| Module Integration (refacto) | Pattern Gateway pour Transatel, Unyc, Wazo | 5-7 jours |
| Meilisearch | Indexation clients, lignes, catalogue | 2-3 jours |
| Laravel Reverb | Remplacement Pusher | 1-2 jours |
| Migration cloud staging | Déployer la staging sur Scaleway | 2-3 jours |

**Livrable** : Performance CDR résolue, API interne opérationnelle, staging cloud.
**Risque** : Moyen — le partitionnement CDR est l'opération la plus délicate.

### Phase 2 — Modularisation métier (Mois 4-7)

**Objectif** : Restructurer le code en modules DDD-lite.

| Tâche | Détail | Durée |
|-------|--------|-------|
| Module Prospect | Extraction + tests | 3-5 jours |
| Module Client | Extraction + tests | 5-7 jours |
| Module Telecom | Lignes, SIMs, portabilités | 5-7 jours |
| Module Billing | Facturation, SEPA, comptabilité | 5-7 jours |
| Module Catalog | Matériels, services, forfaits | 3-5 jours |
| Module Stock | Gestion stock + SIMs | 2-3 jours |
| Tailwind CSS migration (début) | Nouveaux composants en Tailwind, coexistence Bootstrap | Continu |
| Livewire 3 migration complète | Tous les composants admin | Continu |
| Migration cloud production | Bascule DNS, blue-green | 2-3 jours |

**Livrable** : Architecture modulaire en production, cloud production actif.
**Risque** : Moyen — la modularisation peut révéler du couplage caché.

### Phase 3 — Portails externes (Mois 7-10)

**Objectif** : UX de qualité pour les clients et ambassadeurs.

| Tâche | Détail | Durée |
|-------|--------|-------|
| Portail client Vue 3 | Dashboard, lignes, consommation, factures | 10-15 jours |
| Portail ambassadeur Vue 3 | Dashboard, paiements, historique | 5-7 jours |
| Suppression Bootstrap | Migration Tailwind complète | 3-5 jours |
| Module Environnement (refacto) | RSE, émissions, captation | 3-5 jours |
| Module Content (refacto) | Rapports, mailing, templates | 3-5 jours |
| Tests E2E | Couverture des flux critiques | 3-5 jours |
| Documentation API | OpenAPI/Swagger pour l'API REST | 2-3 jours |
| Pen test | Audit sécurité externe | 1-2 semaines (externe) |

**Livrable** : V2 complète, tous portails migrés, documentation, sécurité validée.

---

## 8. Analyse des risques majeurs

| # | Risque | Probabilité | Impact | Mitigation | Owner |
|---|--------|-------------|--------|------------|-------|
| R1 | Migration CDR cause une perte de données | Faible | CRITIQUE | Backup complet avant, migration réversible, validation ligne par ligne | Backend |
| R2 | L'équipe de 2 devs ne tient pas la cadence | Élevée | ÉLEVÉ | Phases ajustables, report non bloquant, recrutement anticipé si croissance confirmée | SI |
| R3 | Régression fonctionnelle sur la facturation | Moyenne | CRITIQUE | Tests automatisés sur les montants, double-run (V1 et V2 en parallèle) pendant 1 mois | Backend |
| R4 | Migration cloud = downtime | Faible | ÉLEVÉ | Blue-green deployment, bascule DNS, rollback instantané | DevOps |
| R5 | Fournisseur change son API pendant la migration | Moyenne | MOYEN | Pattern Gateway isole l'impact, un seul fichier à modifier | Architecte |
| R6 | Données sensibles exposées via la nouvelle API | Faible | CRITIQUE | API Resources contrôlent la sortie, tests de sécurité, pen test | Cybersec |
| R7 | Cohabitation V1/V2 génère des bugs de données | Moyenne | ÉLEVÉ | Migrations rétro-compatibles, tests d'intégration, monitoring actif | Backend + DevOps |
| R8 | Bootstrap + Tailwind coexistence génère des conflits CSS | Moyenne | FAIBLE | Préfixe Tailwind, isolation progressive | Frontend |

---

## 9. Roadmap technique priorisée

```
         ROADMAP V2 CEKOYA

Q1 (Mois 0-3)                    Q2 (Mois 3-6)                 Q3 (Mois 6-9)              Q4 (Mois 9-12)
┌──────────────────────────┐ ┌──────────────────────────┐ ┌──────────────────────────┐ ┌──────────────────────────┐
│ 🔴 CRITIQUE              │ │ 🟠 IMPORTANT             │ │ 🟡 VALEUR AJOUTÉE        │ │ 🟢 CONSOLIDATION         │
│                          │ │                          │ │                          │ │                          │
│ ✓ Docker + CI/CD         │ │ ✓ Modularisation code    │ │ ✓ Portail client Vue 3   │ │ ✓ Documentation complète │
│ ✓ Laravel 12 upgrade     │ │ ✓ Refonte facturation    │ │ ✓ Portail ambass. Vue 3  │ │ ✓ Pen test externe       │
│ ✓ Redis (cache+queues)   │ │ ✓ Modules DDD-lite       │ │ ✓ Suppression Bootstrap  │ │ ✓ Tests E2E              │
│ ✓ Monitoring (Pulse+     │ │ ✓ Livewire 3 complet     │ │ ✓ Module Environnement   │ │ ✓ Optimisation perf      │
│   Sentry)                │ │ ✓ Migration cloud prod   │ │ ✓ Module Content         │ │ ✓ Recrutement dev #3     │
│ ✓ Partitionnement CDR    │ │ ✓ Tailwind (début)       │ │ ✓ API documentation      │ │ ✓ KPI review             │
│ ✓ Tables agrégation      │ │                          │ │                          │ │                          │
│ ✓ API interne v1         │ │                          │ │                          │ │                          │
│ ✓ Audit trail            │ │                          │ │                          │ │                          │
│ ✓ Pattern Gateway        │ │                          │ │                          │ │                          │
│ ✓ Meilisearch            │ │                          │ │                          │ │                          │
│ ✓ Reverb (bye Pusher)    │ │                          │ │                          │ │                          │
│ ✓ Staging cloud          │ │                          │ │                          │ │                          │
│                          │ │                          │ │                          │ │                          │
│ Équipe: 2 devs           │ │ Équipe: 2 devs           │ │ Équipe: 2-3 devs         │ │ Équipe: 3 devs           │
│ Infra: local + staging   │ │ Infra: cloud prod        │ │ Infra: cloud stable      │ │ Infra: cloud optimisé    │
│ Budget: ~150€/mois cloud │ │ Budget: ~170€/mois cloud │ │ Budget: ~200€/mois cloud │ │ Budget: ~200€/mois cloud │
└──────────────────────────┘ └──────────────────────────┘ └──────────────────────────┘ └──────────────────────────┘
```

---

## Annexe : Checklist de validation pré-production

- [ ] Tous les tests passent (> 60% couverture)
- [ ] PHPStan level 6 sans erreur
- [ ] Audit de dépendances clean (composer audit)
- [ ] Scan secrets clean (TruffleHog)
- [ ] Health check endpoint opérationnel
- [ ] Backups automatisés et testés (restore)
- [ ] Monitoring + alertes configurés
- [ ] Audit trail actif sur toutes les entités sensibles
- [ ] MFA actif pour tous les utilisateurs admin
- [ ] Rate limiting actif sur toutes les routes API
- [ ] CSP + HSTS headers en place
- [ ] Blue-green deploy testé avec rollback
- [ ] Documentation API (OpenAPI) à jour
- [ ] Run book d'incidents documenté
