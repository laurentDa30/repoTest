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
4. **Pas de recherche globale performante** (Meilisearch résoudra côté back)

## 2. Stratégie Frontend — Deux approches selon le portail

### Portail Admin (prod.cekoya.fr) → Livewire 3 + Alpine.js + Tailwind

**Justification** :
- L'admin est utilisé par ~12 personnes internes → pas besoin d'une SPA
- Livewire 3 apporte tout ce qui manque à la v2 : lazy loading, performances, meilleure DX
- Tailwind remplace Bootstrap : plus léger, plus maintenable, design system intégré
- Alpine.js couvre les interactions client-side légères

```html
<!-- Exemple : composant Livewire 3 pour la liste des lignes -->
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

### Portails Client et Ambassadeur → Inertia.js + Vue 3

**Justification** :
- Les portails client/ambassadeur sont des interfaces **publiques** (utilisateurs externes)
- Besoin de transitions fluides, d'états complexes côté client (panier, configurateur de forfait)
- Inertia.js permet d'utiliser Vue 3 **sans construire une API séparée** dans un premier temps
- Progressive : on peut commencer avec Inertia et migrer vers une API REST pure plus tard

```vue
<!-- resources/js/Pages/Client/Dashboard.vue -->
<script setup>
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'
import ConsumptionChart from '@/Components/ConsumptionChart.vue'
import LinesList from '@/Components/LinesList.vue'
import InvoiceSummary from '@/Components/InvoiceSummary.vue'

const props = defineProps({
    client: Object,
    consumption: Object,
    lines: Array,
    latestInvoice: Object,
})

const totalLines = computed(() => props.lines.length)
const activeLines = computed(() => props.lines.filter(l => l.status === 'active').length)
</script>

<template>
    <Head :title="`Tableau de bord - ${client.name}`" />

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="col-span-2">
            <ConsumptionChart :data="consumption" />
        </div>

        <div>
            <InvoiceSummary :invoice="latestInvoice" />
        </div>

        <div class="col-span-3">
            <LinesList
                :lines="lines"
                :total="totalLines"
                :active="activeLines"
            />
        </div>
    </div>
</template>
```

## 3. Design System — Tailwind + Composants partagés

### Pourquoi quitter Bootstrap

| Critère | Bootstrap | Tailwind CSS |
|---------|-----------|-------------|
| Taille du bundle | ~200 Ko (avec JS) | ~10 Ko (purgé) |
| Personnalisation | Override complexe | Configuration native |
| Cohérence | Dépend de la discipline | Forcée par le design system |
| Compatibilité Livewire 3 | OK | Natif (recommandé par Laravel) |
| Composants prêts | Bootstrap UI (générique) | Headless UI + Tailwind UI |

### Bibliothèque de composants

```
resources/
├── css/
│   └── app.css                 # Tailwind base + custom
├── js/
│   ├── app.js                  # Inertia bootstrap
│   └── Components/             # Composants Vue partagés
│       ├── UI/
│       │   ├── Button.vue
│       │   ├── Modal.vue
│       │   ├── Table.vue
│       │   ├── StatusBadge.vue
│       │   └── DataCard.vue
│       ├── Charts/
│       │   ├── ConsumptionChart.vue  # ApexCharts
│       │   ├── RevenueChart.vue
│       │   └── LineStatusPie.vue
│       └── Layout/
│           ├── ClientLayout.vue
│           └── AmbassadorLayout.vue
│
├── views/
│   └── components/             # Composants Blade (admin Livewire)
│       ├── ui/
│       │   ├── button.blade.php
│       │   ├── modal.blade.php
│       │   ├── table.blade.php
│       │   └── status-badge.blade.php
│       └── layout/
│           └── admin.blade.php
```

### Chart.js → ApexCharts

**Justification** :
- ApexCharts est plus adapté aux dashboards complexes (finance, CDR analytics)
- Meilleure gestion du temps réel (mise à jour de données en live)
- Responsive natif, dark mode, export PNG/SVG/CSV intégrés
- Compatible Vue 3 et Livewire 3

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

## 5. Performance Frontend

### Métriques cibles

| Métrique | Cible |
|----------|-------|
| LCP (Largest Contentful Paint) | < 2.5s |
| FID (First Input Delay) | < 100ms |
| CLS (Cumulative Layout Shift) | < 0.1 |
| TTI (Time to Interactive) | < 3s |

### Optimisations

1. **Livewire 3 lazy loading** : les composants lourds (graphiques, tables longues) chargés à la demande
2. **Vite** pour le bundling (déjà par défaut Laravel 12) — remplacement de Mix si encore utilisé
3. **Images optimisées** : WebP, lazy loading natif
4. **Pagination côté serveur** : jamais charger des milliers de lignes côté client
5. **Prefetch Inertia** : préchargement des pages au survol des liens

```javascript
// Prefetch au hover (Inertia v2)
import { router } from '@inertiajs/vue3'

router.on('before', (event) => {
    // Prefetch automatique activé par défaut dans Inertia v2
})
```

## 6. Migration Bootstrap → Tailwind

### Stratégie progressive
1. **Phase 0** : Installer Tailwind en parallèle de Bootstrap (coexistence)
2. **Phase 1** : Nouveaux composants en Tailwind uniquement
3. **Phase 2** : Migrer les composants existants page par page
4. **Phase 3** : Supprimer Bootstrap

**Point critique** : ne PAS essayer de tout migrer d'un coup. La coexistence Tailwind + Bootstrap est possible et recommandée.

## 7. Risques identifiés

| Risque | Mitigation |
|--------|------------|
| Apprentissage Vue 3 pour l'équipe (backend-first) | Commencer par des composants simples, Inertia réduit la complexité |
| Coexistence Bootstrap/Tailwind créée de la confusion | Convention stricte : nouveau = Tailwind, ancien = migré progressivement |
| ApexCharts plus lourd que Chart.js | Lazy loading des graphiques, import dynamique |
| Deux stacks frontend (Livewire admin + Vue client) | C'est un choix délibéré — les besoins sont différents, pas un accident |

---

# 🔍 Revue croisée Backend

## Points validés
- La séparation Livewire (admin) / Inertia+Vue (portails externes) est **pragmatique** — elle évite de forcer l'apprentissage de Vue pour le back-office tout en offrant une UX riche aux clients
- Laravel Reverb comme remplacement de Pusher est le bon choix — natif, gratuit, maintenu par Laravel
- ApexCharts est un bon upgrade pour les dashboards complexes

## Points d'attention

### 1. Inertia et API ne sont pas exclusifs
> Inertia utilise les contrôleurs Laravel directement. Quand on construira l'API REST (Phase 1), les portails Inertia pourront progressivement basculer vers l'API. Ce n'est **pas** un choix définitif.

### 2. Composants partagés entre Blade et Vue
> Certains composants (StatusBadge, DataCard) existeront en double (Blade + Vue). C'est inévitable avec deux stacks. Maintenir la cohérence visuelle via le design system Tailwind (mêmes classes, mêmes couleurs).

### 3. Les graphiques de CDR seront alimentés par les tables d'agrégation
> Les `ConsumptionChart` côté frontend **ne doivent jamais** requêter la table `calls` directement. Toujours passer par `daily_call_summaries` / `monthly_call_summaries`. C'est un contrat backend ↔ frontend.

## Verdict
Stratégie frontend **cohérente et réaliste pour 2 devs**. Le duo Livewire 3 + Inertia/Vue 3 est le meilleur compromis productivité/UX pour cette situation.
