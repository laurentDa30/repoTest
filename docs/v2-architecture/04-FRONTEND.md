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
| **Bootstrap** | Framework mature mais générique — l'UI manque probablement de personnalité, surcharge CSS pour override les styles |
| **Livewire 2** | Productif mais la v2 a des limitations (pas de lazy loading natif, pas de `$wire`, performance limitée sur les listes longues) |
| **Pusher** | Service tiers payant — Laravel Reverb le remplace gratuitement |
| **Chart.js** | Correct pour des graphiques simples, mais limité pour des dashboards complexes (finance, CDR analytics) |
| **Pas de design system** | Vraisemblablement des incohérences visuelles entre les 3 portails |

### Points de douleur frontend probables
1. **Lenteur perçue** sur les pages avec beaucoup de données (listes de lignes, CDR, factures)
2. **Incohérence UX** entre admin/client/ambassadeur
3. **Pas de lazy loading** des composants lourds
4. **Pas de recherche globale performante** (Laravel Scout + database driver résoudra côté back)

## 2. Stratégie Frontend — Stack unifiée Livewire 4 + Alpine.js + Tailwind

### Décision : tout Livewire 4 (pas d'Inertia / Vue 3)

**Justification** :
- **Équipe de 2 développeurs backend-first** → une seule stack à maîtriser, pas de courbe d'apprentissage Vue/Inertia
- **Zéro build JS complexe** → Livewire 4 + Alpine.js ne nécessitent pas de pipeline frontend lourd
- **Livewire 4 couvre tous les cas** : lazy loading natif, navigation SPA-like (`wire:navigate`), Single-File Components, Islands, interactivité riche
- **Composants partagés** entre tous les portails → un seul jeu de composants Blade/Livewire
- **Tailwind CSS** remplace Bootstrap : plus léger, plus maintenable, design system intégré
- **Alpine.js** couvre les interactions client-side légères (dropdowns, modals, toggles)

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
        <h2 class="text-xl font-semibold">Tableau de bord - {{ $client->name }}</h2>
    </x-slot>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="col-span-2">
            <livewire:client.consumption-chart :client="$client" />
        </div>

        <div>
            <livewire:client.invoice-summary :invoice="$latestInvoice" />
        </div>

        <div class="col-span-3">
            <livewire:client.lines-list :client="$client" lazy />
        </div>
    </div>
</div>
```

## 3. Design System — Tailwind + Composants partagés

### Pourquoi quitter Bootstrap

| Critère | Bootstrap | Tailwind CSS |
|---------|-----------|-------------|
| Taille du bundle | ~200 Ko (avec JS) | ~10 Ko (purgé) |
| Personnalisation | Override complexe | Configuration native |
| Cohérence | Dépend de la discipline | Forcée par le design system |
| Compatibilité Livewire 4 | OK | Natif (recommandé par Laravel) |
| Composants prêts | Bootstrap UI (générique) | Headless UI + Tailwind UI |

### Bibliothèque de composants (100% Blade/Livewire)

```
resources/
├── css/
│   └── app.css                 # Tailwind base + custom
├── js/
│   └── app.js                  # Alpine.js + Livewire bootstrap
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
│   │   │   ├── consumption-chart.blade.php   # ApexCharts via Alpine
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

### Chart.js → ApexCharts

**Justification** :
- ApexCharts est plus adapté aux dashboards complexes (finance, CDR analytics)
- Meilleure gestion du temps réel (mise à jour de données en live)
- Responsive natif, dark mode, export PNG/SVG/CSV intégrés
- Compatible Livewire 4 (via Alpine.js wrapper)

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
- **Tailwind CSS** peut être utilisé via CDN (dev) ou via le CLI standalone (prod) — aucun npm requis

### Layout type V2 (zéro npm)

```html
<!-- resources/views/layouts/app.blade.php -->
<head>
    {{-- Tailwind via CDN (dev) ou CLI standalone (prod) --}}
    <script src="https://cdn.tailwindcss.com"></script>

    @livewireStyles
</head>
<body>
    {{ $slot }}

    @livewireScripts
    {{-- Alpine.js est déjà injecté par Livewire, rien à ajouter --}}
</body>
```

### Actions de nettoyage V1

| Élément V1 | Action V2 |
|------------|-----------|
| `webpack.mix.js` | **Supprimer** |
| `package.json` / `node_modules` | **Supprimer** (si aucune autre dépendance npm nécessaire) |
| `resources/sass/app.scss` | **Remplacer** par Tailwind (CDN dev / CLI standalone prod) |
| `resources/js/app.js` | **Vérifier le contenu** — si c'est juste du bootstrap Laravel, supprimer |

### Seul cas où npm serait réintroduit

Si des composants JS complexes sans CDN sont nécessaires (éditeur rich-text custom, lib de charting sans CDN). Même là, privilégier les CDN ou les packages Livewire dédiés (FilamentPHP, etc.).

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

## 7. Migration Bootstrap → Tailwind

### Stratégie progressive
1. **Phase 0** : Installer Tailwind en parallèle de Bootstrap (coexistence)
2. **Phase 1** : Nouveaux composants en Tailwind uniquement
3. **Phase 2** : Migrer les composants existants page par page
4. **Phase 3** : Supprimer Bootstrap

**Point critique** : ne PAS essayer de tout migrer d'un coup. La coexistence Tailwind + Bootstrap est possible et recommandée.

## 8. Risques identifiés

| Risque | Mitigation |
|--------|------------|
| Coexistence Bootstrap/Tailwind crée de la confusion | Convention stricte : nouveau = Tailwind, ancien = migré progressivement |
| ApexCharts plus lourd que Chart.js | Lazy loading des graphiques via Livewire `lazy` |
| Livewire 4 moins performant que SPA pour interactions très complexes | `wire:navigate` + Islands + Alpine.js comblent l'écart, l'API REST reste dispo pour futur besoin SPA |
| Composants Livewire trop lourds (N+1 queries) | Optimisation backend (eager loading, agrégation), `$this->authorize()` par composant |

---

# 🔍 Revue croisée Backend

## Points validés
- La stack unifiée **tout Livewire 4 + Alpine.js** est le choix le plus pragmatique pour 2 devs backend-first
- Laravel Reverb comme remplacement de Pusher est le bon choix — natif, gratuit, maintenu par Laravel
- ApexCharts est un bon upgrade pour les dashboards complexes
- **Zéro duplication de composants** : un seul jeu de composants Blade partagé par tous les portails

## Points d'attention

### 1. L'API REST reste stratégique
> Même si les portails sont en Livewire, l'API REST `/api/v1/*` est construite en Phase 1. Elle sert aux imports, aux intégrations partenaires, et prépare une future app mobile. L'API est **complémentaire**, pas concurrente de Livewire.

### 2. Les graphiques de CDR seront alimentés par les tables d'agrégation
> Les composants chart côté frontend **ne doivent jamais** requêter la table `calls` directement. Toujours passer par `daily_call_summaries` / `monthly_summaries` (existante, à enrichir). C'est un contrat backend ↔ frontend.

### 3. Hub central : dashboard de synthèse multi-régions
> Le Hub central affiche un dashboard agrégé de toutes les régions (via `regional_summaries`). Chaque carte de région est cliquable → SSO vers l'admin régional. Le même design system Tailwind est utilisé pour le Hub et les régions.

### 4. Navigation SPA-like avec wire:navigate
> Livewire 4 avec `wire:navigate` offre une navigation sans rechargement de page complet, similaire à une SPA. Les assets ne sont pas rechargés, seul le contenu change. Les **Islands** permettent en plus de re-rendre des zones indépendantes sans toucher au reste. Cela résout le principal avantage qu'aurait eu Inertia/Vue.

## Verdict
Stratégie frontend **cohérente et optimale pour 2 devs backend-first**. La stack unifiée Livewire 4 + Alpine.js + Tailwind élimine toute complexité inutile tout en offrant une UX moderne. Le moteur **Blaze** (v4) réduit les mises à jour DOM de 60% par rapport à la v3.
