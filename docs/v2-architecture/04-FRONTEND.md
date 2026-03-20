# 🎨 Développeur Frontend — Analyse & Recommandations

## 1. Diagnostic de l'existant

### Stack actuelle
- **Blade** : templates côté serveur
- **Livewire 2** : interactivité sans JavaScript explicite
- **Bootstrap** : framework CSS
- **Chart.js** : graphiques (dashboards, finance)
- **Pusher** : temps réel (notifications, mises à jour live)

### Constats

| Élément | Analyse |
|---------|---------|
| **Bootstrap** | Framework mature, déjà en place — **conservé en V2** (migration vers Tailwind = coût disproportionné pour 2 devs) |
| **Livewire 2** | Productif mais la v2 a des limitations (pas de lazy loading natif, pas de `$wire`, performance limitée sur les listes longues) |
| **Pusher** | Service tiers payant — Laravel Reverb le remplace gratuitement |
| **Chart.js** | Correct pour les besoins actuels — **conservé en V2** (licence MIT, gratuit, Chart.js v4 couvre line/bar/pie/doughnut) |
| **Pas de design system** | Vraisemblablement des incohérences visuelles entre les 3 portails |

### Points de douleur frontend probables
1. **Lenteur perçue** sur les pages avec beaucoup de données (listes de lignes, CDR, factures)
2. **Incohérence UX** entre admin/client/ambassadeur
3. **Pas de lazy loading** des composants lourds
4. **Pas de recherche globale performante** (Laravel Scout + database driver résoudra côté back)

## 2. Stratégie Frontend — Stack unifiée Livewire 4 + Bootstrap + Chart.js

### Décision : tout Livewire 4 (pas d'Inertia / Vue 3)

**Justification** :
- **Équipe de 2 développeurs backend-first** → une seule stack à maîtriser, pas de courbe d'apprentissage Vue/Inertia
- **Zéro build JS complexe** → Livewire 4 ne nécessite pas de pipeline frontend lourd (Alpine.js bundlé nativement)
- **Livewire 4 couvre tous les cas** : lazy loading natif, navigation SPA-like (`wire:navigate`), Single-File Components, Islands, interactivité riche
- **Composants partagés** entre tous les portails → un seul jeu de composants Blade/Livewire
- **Bootstrap conservé** : déjà en place en V1, migrer des centaines de classes vers Tailwind = temps brûlé sans valeur métier
- **Chart.js conservé** : déjà en V1, licence MIT (gratuit sans restriction), Chart.js v4 couvre les besoins (line, bar, pie, doughnut)

### Portail Admin régional ({region}.cekoya.fr) + Hub central (central.cekoya.fr)

```html
<!-- Exemple : composant Livewire 4 pour la liste des lignes -->
<div>
    <!-- Recherche avec debounce -->
    <input wire:model.live.debounce.300ms="search"
           type="text"
           placeholder="Rechercher une ligne, un client...">

    <!-- Filtres -->
    <select wire:model.live="provider">
        <option value="">Tous les fournisseurs</option>
        <option value="transatel">Transatel</option>
        <option value="unyc">Unyc</option>
    </select>

    <!-- Table avec pagination lazy -->
    <table>
        @foreach($lines as $line)
            <tr wire:key="{{ $line->id }}">
                <td>{{ $line->msisdn }}</td>
                <td>{{ $line->client->name }}</td>
                <td>{{ $line->provider }}</td>
                <td>
                    <x-status-badge :status="$line->status" />
                </td>
            </tr>
        @endforeach
    </table>

    {{ $lines->links() }}
</div>
```

### Portails Client et Ambassadeur ({region}-client.cekoya.fr / {region}-amba.cekoya.fr) → Livewire 4

**Justification** :
- Les portails client/ambassadeur sont des interfaces **publiques** (utilisateurs externes)
- Chaque portail est automatiquement scopé à la région via stancl/tenancy (sous-domaine → BDD)
- Livewire 4 avec `wire:navigate` offre une **navigation SPA-like** sans JavaScript custom
- Les **Islands** (v4) permettent de re-rendre des zones indépendantes → performance optimale sur les dashboards
- Les interactions complexes (configurateur de forfait) sont gérées via **composants Livewire imbriqués + Alpine.js**
- L'API REST `/api/v1/*` reste disponible si besoin futur (app mobile, partenaires)

```html
<!-- resources/views/livewire/client/dashboard.blade.php -->
<div>
    <x-slot name="header">
        <h2>Tableau de bord - {{ $client->name }}</h2>
    </x-slot>

    <div class="row g-4">
        <div class="col-md-8">
            <livewire:client.consumption-chart :client="$client" />
        </div>

        <div class="col-md-4">
            <livewire:client.invoice-summary :invoice="$latestInvoice" />
        </div>

        <div class="col-12">
            <livewire:client.lines-list :client="$client" lazy />
        </div>
    </div>
</div>
```

## 3. Design System — Bootstrap conservé + Composants partagés

### Décision : Bootstrap conservé en V2

**Justification** :
- **Déjà en place** dans toute la V1 — migrer des centaines de classes vers Tailwind est un coût disproportionné
- **Bootstrap 5** est compatible Livewire 4 sans problème
- **L'équipe connaît Bootstrap** — zéro courbe d'apprentissage
- **Focus sur la valeur métier** : le temps économisé sur la migration CSS est investi dans les modules fonctionnels

**Améliorations progressives possibles** :
- Créer un fichier `_variables.scss` centralisé pour personnaliser Bootstrap (couleurs, typographie Cekoya)
- Nettoyer les overrides CSS orphelins lors des refactos de vues
- Utiliser les composants Bootstrap 5 natifs (accordions, offcanvas, toasts) au lieu de solutions custom

### Chart.js conservé en V2

**Justification** :
- **Licence MIT** — gratuit sans restriction, aucun risque de licence future
- **Déjà en place** en V1 — pas de migration à faire
- **Chart.js v4** couvre les besoins : line, bar, pie, doughnut, radar, scatter
- **ApexCharts a changé son modèle de licence** (v5+) : gratuit uniquement pour les organisations < 2M$ CA/an. Pour Cekoya (380+ clients B2B telecom), une licence commerciale serait probablement nécessaire

| Critère | Chart.js v4 | ApexCharts v5 |
|---------|-------------|---------------|
| Licence | MIT (gratuit, illimité) | Community < 2M$ CA, sinon payant/dev |
| Rendering | Canvas (performant sur gros datasets) | SVG (plus lourd sur gros datasets) |
| Types de charts | Line, bar, pie, doughnut, radar, scatter, bubble | Plus riche (treemap, heatmap, candlestick...) |
| Bundle size | ~60 Ko (min+gzip) | ~125 Ko (min+gzip) |
| Déjà en V1 | ✅ Oui | Non |

### Bibliothèque de composants (100% Blade/Livewire)

```
resources/
├── css/
│   └── app.css                 # Bootstrap + custom variables
├── js/
│   └── app.js                  # Chart.js (+ Alpine.js bundlé par Livewire)
│
├── views/
│   ├── components/             # Composants Blade partagés (tous portails)
│   │   ├── ui/
│   │   │   ├── button.blade.php
│   │   │   ├── modal.blade.php
│   │   │   ├── table.blade.php
│   │   │   ├── status-badge.blade.php
│   │   │   └── data-card.blade.php
│   │   ├── charts/
│   │   │   ├── consumption-chart.blade.php   # Chart.js via Alpine
│   │   │   ├── revenue-chart.blade.php
│   │   │   └── line-status-pie.blade.php
│   │   └── layout/
│   │       ├── admin.blade.php
│   │       ├── client.blade.php
│   │       └── ambassador.blade.php
│   │
│   └── livewire/               # Composants Livewire
│       ├── admin/              # Portail admin régional
│       ├── client/             # Portail client
│       ├── ambassador/         # Portail ambassadeur
│       └── hub/                # Hub central
```

**Avantage** : un seul jeu de composants UI Blade partagé entre tous les portails. Zéro duplication de design system.

## 4. Remplacement de Pusher → Laravel Reverb

```php
// Installation
// composer require laravel/reverb
// php artisan reverb:install

// Broadcasting d'un événement CDR
class CDRImportCompleted implements ShouldBroadcast
{
    public function __construct(
        public readonly string $provider,
        public readonly int $recordsImported,
        public readonly int $errorsCount,
    ) {}

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('admin.imports');
    }
}
```

```javascript
// Côté client (Echo + Reverb)
Echo.private('admin.imports')
    .listen('CDRImportCompleted', (event) => {
        // Notification toast en temps réel
        notify({
            title: `Import ${event.provider} terminé`,
            body: `${event.recordsImported} enregistrements importés`,
            type: event.errorsCount > 0 ? 'warning' : 'success'
        });
    });
```

**Gain** : suppression du coût Pusher + latence réduite (self-hosted).

## 5. Décision : Zéro npm / Zéro build pipeline JS

### Contexte

Un fichier `webpack.mix.js` (Laravel Mix) existe en V1. Laravel Mix est obsolète depuis Laravel 9.19+ (remplacé par Vite). En V2, **aucun build pipeline JS n'est nécessaire**.

### Pourquoi

- **Livewire 4 bundle Alpine.js automatiquement** : Alpine est injecté via `@livewireScripts`, pas besoin d'installation séparée
- **Livewire 4 bundle Morph DOM** : manipulation DOM optimisée incluse
- **`wire:navigate`** fournit la navigation SPA-like nativement
- **Bootstrap CSS/JS** peut être chargé via CDN — aucun npm requis
- **Chart.js** peut être chargé via CDN — aucun npm requis

### Layout type V2 (zéro npm)

```html
<!-- resources/views/layouts/app.blade.php -->
<head>
    {{-- Bootstrap CSS via CDN --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/css/bootstrap.min.css" rel="stylesheet">
    {{-- Custom overrides Cekoya --}}
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">

    @livewireStyles
</head>
<body>
    {{ $slot }}

    {{-- Bootstrap JS via CDN --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3/dist/js/bootstrap.bundle.min.js"></script>
    {{-- Chart.js via CDN --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    @livewireScripts
    {{-- Alpine.js est déjà injecté par Livewire, rien à ajouter --}}
</body>
```

### Actions de nettoyage V1

| Élément V1 | Action V2 |
|------------|-----------|
| `webpack.mix.js` | **Supprimer** — Laravel Mix obsolète |
| `package.json` / `node_modules` | **Supprimer** (Bootstrap + Chart.js via CDN) |
| `resources/sass/app.scss` | **Conserver si custom** — compiler avec `sass` CLI standalone si nécessaire, sinon CDN Bootstrap + `app.css` custom |
| `resources/js/app.js` | **Vérifier le contenu** — si c'est juste du bootstrap Laravel, supprimer |

### Seul cas où npm serait réintroduit

Si des composants JS complexes sans CDN sont nécessaires (éditeur rich-text custom, lib sans CDN). Même là, privilégier les CDN ou les packages Livewire dédiés (FilamentPHP, etc.).

---

## 6. Performance Frontend

### Métriques cibles

| Métrique | Cible |
|----------|-------|
| LCP (Largest Contentful Paint) | < 2.5s |
| FID (First Input Delay) | < 100ms |
| CLS (Cumulative Layout Shift) | < 0.1 |
| TTI (Time to Interactive) | < 3s |

### Optimisations

1. **Livewire 4 lazy loading + Islands** : les composants lourds (graphiques, tables longues) chargés à la demande, Islands pour re-render indépendant
2. **Vite** pour le bundling (déjà par défaut Laravel 12) — remplacement de Mix si encore utilisé
3. **Images optimisées** : WebP, lazy loading natif
4. **Pagination côté serveur** : jamais charger des milliers de lignes côté client
5. **`wire:navigate`** : navigation SPA-like native Livewire 4, préchargement au survol des liens

```html
<!-- Navigation SPA-like sans JavaScript custom -->
<a wire:navigate href="{{ route('client.lines.index') }}">
    Mes lignes
</a>

<!-- Composant chargé en lazy (placeholder affiché pendant le chargement) -->
<livewire:client.consumption-chart :client="$client" lazy />
```

## 7. Risques identifiés

| Risque | Mitigation |
|--------|------------|
| Bootstrap 5 non mis à jour | Suivre les releases Bootstrap 5.x, mettre à jour le CDN régulièrement |
| Chart.js limité pour dashboards très complexes (heatmaps, treemaps) | Suffisant pour les besoins actuels. Réévaluer si besoin futur — Chart.js plugins ou alternative évaluée au cas par cas |
| Livewire 4 moins performant que SPA pour interactions très complexes | `wire:navigate` + Islands + Alpine.js comblent l'écart, l'API REST reste dispo pour futur besoin SPA |
| Composants Livewire trop lourds (N+1 queries) | Optimisation backend (eager loading, agrégation), `$this->authorize()` par composant |

---

# 🔍 Revue croisée Backend

## Points validés
- La stack unifiée **tout Livewire 4 + Bootstrap + Chart.js** est le choix le plus pragmatique pour 2 devs backend-first
- Laravel Reverb comme remplacement de Pusher est le bon choix — natif, gratuit, maintenu par Laravel
- **Bootstrap conservé** — migration vers Tailwind = coût disproportionné pour l'équipe
- **Chart.js conservé** — licence MIT gratuite, déjà en place, ApexCharts v5 a un risque de licence payante (CA > 2M$)
- **Zéro duplication de composants** : un seul jeu de composants Blade partagé par tous les portails

## Points d'attention

### 1. L'API REST reste stratégique
> Même si les portails sont en Livewire, l'API REST `/api/v1/*` est construite en Phase 1. Elle sert aux imports, aux intégrations partenaires, et prépare une future app mobile. L'API est **complémentaire**, pas concurrente de Livewire.

### 2. Les graphiques de CDR seront alimentés par les tables d'agrégation
> Les composants chart côté frontend **ne doivent jamais** requêter la table `calls` directement. Toujours passer par `daily_call_summaries` / `monthly_summaries` (existante, à enrichir). C'est un contrat backend ↔ frontend.

### 3. Hub central : dashboard de synthèse multi-régions
> Le Hub central affiche un dashboard agrégé de toutes les régions (via `regional_summaries`). Chaque carte de région est cliquable → SSO vers l'admin régional. Le même design system Bootstrap est utilisé pour le Hub et les régions.

### 4. Navigation SPA-like avec wire:navigate
> Livewire 4 avec `wire:navigate` offre une navigation sans rechargement de page complet, similaire à une SPA. Les assets ne sont pas rechargés, seul le contenu change. Les **Islands** permettent en plus de re-rendre des zones indépendantes sans toucher au reste. Cela résout le principal avantage qu'aurait eu Inertia/Vue.

## Verdict
Stratégie frontend **cohérente et optimale pour 2 devs backend-first**. La stack unifiée Livewire 4 + Bootstrap + Chart.js conserve l'existant tout en profitant des avancées de Livewire 4 (lazy loading, Islands, `wire:navigate`, Alpine.js bundlé). Zéro migration CSS, zéro risque de licence. Le moteur **Blaze** (v4) réduit les mises à jour DOM de 60% par rapport à la v3.
