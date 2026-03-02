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
| Perte de données (pas de backup off-site documenté) | Faible | **CRITIQUE** |
| Déploiement cassé sans rollback | Élevée | Élevé |
| Incohérence entre env de dev et prod | Certaine | Moyen |
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

### Architecture d'infrastructure

```
                    ┌─────────────────────┐
                    │   Scaleway DNS /    │
                    │   Cloudflare DNS    │
                    └──────────┬──────────┘
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
    │  Laravel +     │ │ Laravel +    │ │  horizon +   │
    │  Nginx + PHP   │ │ Nginx + PHP  │ │  scheduler   │
    └────────────────┘ └──────────────┘ └──────────────┘
              │                │                 │
              └────────────────┼─────────────────┘
                               │
         ┌─────────────────────┼─────────────────────┐
         │                     │                      │
┌────────▼─────────┐ ┌────────▼─────────┐ ┌──────────▼────────┐
│ Scaleway Managed │ │ Scaleway Managed │ │ Scaleway Object   │
│ MySQL 8          │ │ Redis 7          │ │ Storage (S3)      │
│ (DB-DEV2-S)      │ │ (RED1-MICRO)     │ │ Factures, exports │
│                  │ │ Cache + queues   │ │ backups, CDR arch  │
└──────────────────┘ └──────────────────┘ └───────────────────┘
```

### Estimation de coûts mensuels (Scaleway)

| Ressource | Spec | Coût estimé/mois |
|-----------|------|-------------------|
| 2x App Server (DEV1-S, 2vCPU/2GB) | Containers ou instances | ~30€ |
| 1x Worker (DEV1-S) | Queue processing | ~15€ |
| MySQL Managé (DB-DEV2-S) | 2vCPU/4GB/50GB SSD | ~40€ |
| Redis Managé (RED1-MICRO) | 1GB | ~15€ |
| Load Balancer | 1 LB | ~10€ |
| Object Storage | ~100 Go | ~5€ |
| Backups | Snapshots auto | ~10€ |
| **Total estimé** | | **~125-150€/mois** |

> Comparaison hébergement local : coût électricité + maintenance matérielle + risque de panne probablement supérieur, sans la résilience.

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
      MYSQL_DATABASE: cekoya
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
    ports:
      - "3306:3306"

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  meilisearch:
    image: getmeili/meilisearch:latest
    volumes:
      - meilisearch_data:/meili_data
    ports:
      - "7700:7700"

  mailpit:
    image: axllent/mailpit
    ports:
      - "8025:8025"
      - "1025:1025"

volumes:
  mysql_data:
  meilisearch_data:
```

### Dockerfile de production

```dockerfile
# docker/Dockerfile
FROM php:8.3-fpm-alpine AS base

RUN apk add --no-cache \
    nginx \
    supervisor \
    && docker-php-ext-install pdo_mysql opcache pcntl

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

## 4. CI/CD — GitHub Actions

```yaml
# .github/workflows/ci.yml
name: CI/CD Pipeline

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  tests:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: cekoya_test
          MYSQL_ROOT_PASSWORD: password
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
      redis:
        image: redis:7-alpine
        ports: ['6379:6379']

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_mysql, redis, pcntl
          coverage: xdebug

      - name: Install dependencies
        run: composer install --prefer-dist --no-progress

      - name: Run tests
        run: php artisan test --parallel
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_DATABASE: cekoya_test
          DB_USERNAME: root
          DB_PASSWORD: password

      - name: Run static analysis
        run: ./vendor/bin/phpstan analyse

      - name: Run code style check
        run: ./vendor/bin/pint --test

  deploy-staging:
    needs: tests
    if: github.ref == 'refs/heads/develop'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Build & Push Docker image
        run: |
          docker build -t registry.scw.cloud/cekoya/app:staging -f docker/Dockerfile .
          docker push registry.scw.cloud/cekoya/app:staging
      - name: Deploy to staging
        run: |
          # SSH deploy or Scaleway API call
          echo "Deploy to staging environment"

  deploy-production:
    needs: tests
    if: github.ref == 'refs/heads/main'
    runs-on: ubuntu-latest
    environment: production  # Requires manual approval
    steps:
      - uses: actions/checkout@v4
      - name: Build & Push Docker image
        run: |
          docker build -t registry.scw.cloud/cekoya/app:${{ github.sha }} -f docker/Dockerfile .
          docker push registry.scw.cloud/cekoya/app:${{ github.sha }}
      - name: Deploy to production
        run: |
          echo "Blue-green deploy to production"
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
| Base MySQL | Snapshot Scaleway managé + mysqldump vers S3 | Quotidien + avant chaque déploiement | 30 jours |
| Fichiers (factures, docs) | Déjà sur S3 (Object Storage) = répliqué | Natif | Versioning S3 activé |
| Configuration | Dans Git (sauf secrets) | Chaque commit | Infini |
| Secrets | Scaleway Secret Manager ou fichier `.env` chiffré | Chaque modification | Versionné |

**RTO cible** : < 1 heure (restore depuis snapshot + redéploiement Docker)
**RPO cible** : < 24 heures (perte maximale = données depuis le dernier backup)

## 6. Risques identifiés

| Risque | Mitigation |
|--------|------------|
| Migration cloud ratée = downtime | Phase de cohabitation : app sur local + backup cloud prêt, bascule DNS finale |
| Coûts cloud dérapent | Alertes budget Scaleway, revue mensuelle, instances réservées si stable |
| Compétence Docker insuffisante chez les 2 devs | Docker Compose d'abord (simple), formation progressive |
| Dépendance Scaleway | Architecture Docker = portable vers n'importe quel cloud |

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

### 3. Meilisearch manquant dans l'infra
> Ajouter une instance Meilisearch dédiée ou utiliser le service managé Scaleway si disponible. Budget additionnel : ~10-15€/mois.

### 4. Monitoring à préciser
> La proposition de monitoring est dans le document dédié, mais les agents de collecte (Prometheus/Grafana ou Scaleway Cockpit) doivent être budgétés dans l'infra.

## Verdict
Infrastructure **bien dimensionnée et réaliste**. Le budget de ~150-170€/mois (avec Meilisearch) est excellent pour cette taille de plateforme. Pas de sur-ingénierie.
