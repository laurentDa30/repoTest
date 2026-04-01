# ⚙️ DevOps / Cloud Engineer — Analyse & Recommandations

## 1. Diagnostic de l'existant

### Situation actuelle
- **Hébergement local** : serveur physique ou VM on-premise
- **Aucun conteneur** : pas de Docker
- **Aucun CI/CD** : déploiements manuels (vraisemblablement `git pull` + `composer install` en production)
- **Aucune stratégie de backup automatisée documentée**
- **Aucun monitoring** formalisé
- **Single Point of Failure** complet : un serveur = toute la plateforme

### Risques immédiats

| Risque | Probabilité | Impact |
|--------|-------------|--------|
| Crash serveur = downtime total | Moyenne | **CRITIQUE** — perte de service pour 380 clients |
| Perte de données (Situation non testée en condition..possibilité de perte) | Faible | **CRITIQUE** |
| Taille du serveur limite pouvant causer une impossibilité de réinstaller la BDD | Élevée | Élevé |
| Incohérence entre env de dev et prod | Certaine | Moyen |
| Déploiement cassé sans rollback | Élevée | Élevé |
| Pas de scaling possible | Certaine | Élevé (objectif de doubler le parc) |

## 2. Infrastructure cible

### Choix du cloud provider : Scaleway (Paris)

**Justification** :
| Critère | Scaleway | AWS | OVH Cloud |
|---------|----------|-----|-----------|
| Data sovereignty FR | ✅ DC Paris/Amsterdam | ✅ eu-west-3 Paris | ✅ FR |
| RGPD natif | ✅ Entreprise FR | ⚠️ Cloud Act US | ✅ |
| Coût (comparable) | €€ | €€€ | € |
| Services managés | Bon (K8s, DB, Object Storage, Redis) | Excellent | Limité |
| Rapport qualité/complexité | **Optimal pour petite équipe** | Trop complexe pour 2 devs | Manque de services managés |
| Support FR | ✅ | ⚠️ Anglais principalement | ✅ |

**Alternative acceptable** : OVH Cloud si le budget est très contraint, mais les services managés sont moins matures.

### Architecture d'infrastructure (Multi-région Phase A : serveur unique)

```
                    ┌─────────────────────────┐
                    │    Cloudflare DNS/WAF    │
                    │  *.cekoya.fr → LB        │
                    └──────────┬──────────────┘
                               │
                    ┌──────────▼──────────┐
                    │   Load Balancer     │
                    │   (Scaleway LB)     │
                    │   SSL termination   │
                    └──────────┬──────────┘
                               │
              ┌────────────────┼────────────────┐
              │                │                 │
    ┌─────────▼──────┐ ┌──────▼───────┐ ┌──────▼───────┐
    │  App Server 1  │ │ App Server 2 │ │  Worker      │
    │  (DEV1-Small)  │ │ (DEV1-Small) │ │  (queues)    │
    │  Docker        │ │ Docker       │ │  Docker      │
    │  Laravel 13 +  │ │ Laravel 12 + │ │  horizon +   │
    │  stancl/tenant │ │ stancl/ten.  │ │  scheduler   │
    │  Nginx + PHP   │ │ Nginx + PHP  │ │  + sync jobs │
    └────────────────┘ └──────────────┘ └──────────────┘
              │                │                 │
              └────────────────┼─────────────────┘
                               │
         ┌─────────────────────┼─────────────────────┐
         │                     │                      │
┌────────▼─────────┐ ┌────────▼─────────┐ ┌──────────▼────────┐
│ Scaleway Managed │ │ Scaleway Managed │ │ Scaleway Object   │
│ MySQL 8          │ │ Redis 7          │ │ Storage (S3)      │
│ (DB-DEV2-M)      │ │ (RED1-MICRO)     │ │ Factures, exports │
│                  │ │ Cache + queues   │ │ backups, CDR arch  │
│ Databases:       │ │                  │ │                   │
│ ├ cekoya_central │ │                  │ │ Par région :      │
│ ├ cekoya_idf     │ │                  │ │ ├ /idf/invoices/  │
│ ├ cekoya_paca    │ │                  │ │ ├ /paca/invoices/ │
│ └ cekoya_lyon    │ │                  │ │ └ /lyon/invoices/ │
└──────────────────┘ └──────────────────┘ └───────────────────┘
```

> **Scaling Phase B** (quand le nombre de régions le justifie) : un serveur dédié par région. Voir `09-ARCHITECTURE-MULTI-REGION.md` section 9 pour le détail.

### Estimation de coûts mensuels (Scaleway) — Multi-région

| Ressource | Spec | Coût estimé/mois |
|-----------|------|-------------------|
| 2x App Server (DEV1-S, 2vCPU/2GB) | Containers ou instances | ~30€ |
| 1x Worker (DEV1-S) | Queue processing + sync jobs | ~15€ |
| MySQL Managé (**DB-DEV2-M**) | 4vCPU/8GB/100GB SSD (multi-BDD) | ~70€ |
| Redis Managé (RED1-MICRO) | 1GB | ~15€ |
| Load Balancer | 1 LB (wildcard *.cekoya.fr) | ~10€ |
| Object Storage | ~200 Go (par région isolée) | ~10€ |
| Backups | Snapshots auto (par BDD régionale) | ~15€ |
| **Total estimé (Phase A : 1 serveur)** | | **~165-200€/mois** |
| **Par région supplémentaire (Phase B)** | Serveur dédié + BDD | **+50-80€/mois** |

> Le surcoût multi-région est modéré en Phase A (toutes les BDD sur le même MySQL managé). En Phase B (serveur par région), chaque agence coûte ~50-80€/mois supplémentaire — comparable à un abonnement SaaS classique.

## 3. Conteneurisation

### Docker Compose (développement)

```yaml
# docker-compose.yml
services:
  app:
    build:
      context: .
      dockerfile: docker/Dockerfile
    volumes:
      - .:/var/www/html
    depends_on:
      - mysql
      - redis
    ports:
      - "8000:80"
    environment:
      - APP_ENV=local

  mysql:
    image: mysql:8.0
    volumes:
      - mysql_data:/var/lib/mysql
    environment:
      MYSQL_DATABASE: cekoya_central
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
    ports:
      - "3306:3306"

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  mailpit:
    image: axllent/mailpit
    ports:
      - "8025:8025"
      - "1025:1025"

volumes:
  mysql_data:
```

### Dockerfile de production

```dockerfile
# docker/Dockerfile
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
    nginx \
    supervisor \
    icu-dev \
    libzip-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql opcache pcntl bcmath intl zip gd

COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

FROM base AS composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

FROM base AS production
WORKDIR /var/www/html
COPY --from=composer /var/www/html/vendor ./vendor
COPY . .
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan event:cache

EXPOSE 80
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
```

## 4. CI/CD — GitLab CI

```yaml
# .gitlab-ci.yml
stages:
  - test
  - build
  - deploy

variables:
  MYSQL_DATABASE: cekoya_test
  MYSQL_ROOT_PASSWORD: password

# --- Tests ---
tests:
  stage: test
  image: php:8.3-cli
  services:
    - mysql:8.0
    - redis:7-alpine
  before_script:
    - apt-get update && apt-get install -y git unzip libzip-dev
    - docker-php-ext-install pdo_mysql pcntl zip
    - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    - composer install --prefer-dist --no-progress
  script:
    - php artisan test --parallel
    - ./vendor/bin/phpstan analyse
    - ./vendor/bin/pint --test
  only:
    - main
    - develop
    - merge_requests

# --- Build & Push Docker ---
build-staging:
  stage: build
  image: docker:latest
  services:
    - docker:dind
  script:
    - docker build -t registry.scw.cloud/cekoya/app:staging -f docker/Dockerfile .
    - docker push registry.scw.cloud/cekoya/app:staging
  only:
    - develop

build-production:
  stage: build
  image: docker:latest
  services:
    - docker:dind
  script:
    - docker build -t registry.scw.cloud/cekoya/app:$CI_COMMIT_SHA -f docker/Dockerfile .
    - docker push registry.scw.cloud/cekoya/app:$CI_COMMIT_SHA
  only:
    - main

# --- Deploy ---
deploy-staging:
  stage: deploy
  script:
    - echo "Deploy to staging environment"
    # SSH deploy ou appel API Scaleway
  only:
    - develop

deploy-production:
  stage: deploy
  script:
    - echo "Blue-green deploy to production"
  when: manual  # Approbation manuelle requise
  only:
    - main
```

### Stratégie de déploiement

**Blue-Green** (pas de rolling update complexe pour 2 serveurs) :
1. Build la nouvelle image Docker
2. Déployer sur le serveur "green" (inactif)
3. Smoke tests automatiques
4. Switch du load balancer vers "green"
5. L'ancien "blue" devient le fallback (rollback instantané)

## 5. Backup & Disaster Recovery

| Élément | Stratégie | Fréquence | Rétention |
|---------|-----------|-----------|-----------|
| Base MySQL (chaque BDD régionale) | Snapshot Scaleway managé + mysqldump vers S3 (par région) | Quotidien + avant chaque déploiement | 30 jours |
| Fichiers (factures, docs) | Déjà sur S3 (Object Storage) = répliqué | Natif | Versioning S3 activé |
| Configuration | Dans Git (sauf secrets) | Chaque commit | Infini |
| Secrets | Scaleway Secret Manager ou fichier `.env` chiffré | Chaque modification | Versionné |

**RTO cible** : < 1 heure (restore depuis snapshot + redéploiement Docker)
**RPO cible** : < 24 heures (perte maximale = données depuis le dernier backup)

## 6. Risques identifiés

| Risque | Mitigation |
|--------|------------|
| Migration cloud ratée = downtime | Phase de cohabitation : app sur local + backup cloud prêt, bascule DNS finale |
| Coûts cloud dérapent avec multi-régions | Alertes budget Scaleway, revue mensuelle, Phase A (serveur unique) maîtrise les coûts |
| Compétence Docker insuffisante chez les 2 devs | Docker Compose d'abord (simple), formation progressive |
| Dépendance Scaleway | Architecture Docker = portable vers n'importe quel cloud |
| MySQL saturé avec N BDD régionales | Monitoring taille par BDD, passage Phase B (serveur par région) si dépassement |
| Backup régional échoue silencieusement | Script de vérification automatique post-backup par tenant, alerte si manquant |

---

# 🔍 Revue par Architecte Logiciel

## Points validés
- Le choix Scaleway est **cohérent** avec la taille de l'équipe et les contraintes RGPD
- L'estimation de coûts (~150€/mois) est réaliste et très compétitive vs hébergement local
- La stratégie Blue-Green est adaptée au volume (2 serveurs, pas besoin de rolling update K8s)
- Docker Compose pour le dev + images de production = bon équilibre

## Points d'attention

### 1. Pas besoin de Kubernetes
> Confirmé. Avec 2 devs et cette volumétrie, Docker Compose en prod (via Docker Swarm ou simplement des containers managés Scaleway) est **suffisant**. K8s serait de l'over-engineering.

### 2. Le worker doit être séparé
> Validé. Le worker (Laravel Horizon pour les queues + scheduler) **ne doit pas** tourner sur les mêmes instances que l'app web. Les imports Transatel horaires et l'agrégation CDR nocturne ne doivent pas impacter les performances web.

**Détail du fonctionnement du worker séparé :**

Le "worker séparé" désigne un **processus (ou serveur) dédié** qui exécute les tâches en arrière-plan, physiquement séparé du processus qui sert les requêtes HTTP aux utilisateurs.

**Concrètement, comment ça fonctionne :**

```
Utilisateur → Nginx → PHP-FPM (App Web)       ← sert les pages, Livewire, etc.
                          │
                          │ dispatch(job)
                          ▼
                       Redis (queue)
                          │
                          │ poll
                          ▼
              PHP artisan queue:work (Worker)    ← processus séparé, dédié aux jobs
              PHP artisan schedule:run           ← cron Laravel (scheduler)
```

1. **L'app web** (Nginx + PHP-FPM) reçoit les requêtes utilisateur et dispatche les tâches lourdes dans une **queue Redis** (par exemple : `ImportTransatelJob::dispatch()`)
2. **Le worker** est un processus `php artisan queue:work` (ou Laravel Horizon) qui **tourne en boucle** et consomme les jobs de la queue Redis. Il n'a pas de Nginx, pas de port HTTP — il ne sert personne. Il traite les jobs.
3. **Le scheduler** (`php artisan schedule:run` lancé par cron toutes les minutes) planifie les jobs récurrents (imports horaires Transatel, agrégation CDR nocturne, etc.)

**Pourquoi les séparer ?**

| Scénario | Sans séparation | Avec séparation |
|----------|----------------|-----------------|
| Import Transatel horaire (traitement de 50K CDR) | PHP-FPM monopolise les workers, les pages admin ralentissent | Le worker traite les CDR sur son propre CPU/RAM, l'app web reste réactive |
| Agrégation CDR nocturne (scan de millions de lignes) | La mémoire PHP-FPM explose, risque de crash du serveur web | Le worker consomme sa propre mémoire, le serveur web dort tranquille |
| Pic de connexions utilisateurs le matin | Les jobs en attente bloquent les workers PHP-FPM | Les queues attendent patiemment dans Redis, le web a toutes ses ressources |

**Comment mettre en place concrètement (sans Docker) :**

Sur le **même serveur physique**, il suffit de lancer les processus séparément :

```bash
# Processus 1 : l'app web (déjà en place)
# Nginx + PHP-FPM, qui sert les requêtes HTTP

# Processus 2 : le worker (à ajouter)
# Supervisord maintient le processus en vie
# /etc/supervisor/conf.d/cekoya-worker.conf
[program:cekoya-worker]
command=php /var/www/cekoya/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/cekoya-worker.log

# Processus 3 : le scheduler (à ajouter dans crontab)
* * * * * cd /var/www/cekoya && php artisan schedule:run >> /dev/null 2>&1
```

La séparation est **logique** (processus distincts), pas forcément physique (serveurs distincts). Sur un seul serveur, Supervisord gère le worker comme un service système. L'avantage : si le worker plante (OOM sur un gros import), l'app web continue de tourner normalement.

À terme (Phase 4 — cloud), le worker pourrait tourner sur un **serveur dédié** pour une isolation totale des ressources.

### 3. Recherche — Laravel Scout avec database driver
> Pas besoin de service tiers (Meilisearch, Elasticsearch). Laravel Scout avec le database driver utilise MySQL directement (FULLTEXT ou LIKE). Zéro coût additionnel, zéro service à maintenir. Si la volumétrie l'exige un jour (> 100K entités par tenant), on pourra passer à Meilisearch — le changement est transparent grâce à l'abstraction Scout.

### 4. Monitoring à préciser
> La proposition de monitoring est dans le document dédié, mais les agents de collecte (Prometheus/Grafana ou Scaleway Cockpit) doivent être budgétés dans l'infra.

## Verdict
Infrastructure **bien dimensionnée et réaliste**. Le budget de ~200€/mois en Phase A multi-région est excellent pour cette taille de plateforme. Le passage en Phase B (~+50-80€/région) reste maîtrisé et justifié par l'isolation des pannes (modèle franchise).
