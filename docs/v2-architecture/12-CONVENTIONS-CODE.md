# Conventions de code — Cekoya V2

Ce document définit les standards de code pour le projet V2. Tout nouveau code doit respecter ces conventions. Le code existant V1 est migré progressivement.

---

## 1. Standards généraux

### PHP
- **Version** : PHP 8.3+
- **Style** : PSR-12 via **Laravel Pint** (configuration par défaut Laravel)
- **Analyse statique** : PHPStan / Larastan **niveau 6** minimum
- **Tests** : Pest PHP (pas PHPUnit directement)
- **Typage** : strict types activé dans chaque fichier (`declare(strict_types=1);`)
- **Return types** : obligatoires sur toutes les méthodes publiques
- **Property promotion** : utiliser la promotion de constructeur PHP 8+

```php
<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Actions;

final class CreateClientAction
{
    public function __construct(
        private readonly ClientRepository $clients,
        private readonly EventDispatcher $events,
    ) {}

    public function execute(CreateClientDTO $dto): Client
    {
        // ...
    }
}
```

### Nommage

| Élément | Convention | Exemple |
|---------|-----------|---------|
| Classes | PascalCase | `CreateClientAction` |
| Méthodes | camelCase | `getActiveLines()` |
| Variables | camelCase | `$totalAmount` |
| Constantes | SCREAMING_SNAKE_CASE | `MAX_CDR_BATCH_SIZE` |
| Tables BDD | snake_case, pluriel | `daily_call_summaries` |
| Colonnes BDD | snake_case | `client_id`, `created_at` |
| Routes API | kebab-case, pluriel | `/api/v1/clients/{client}/lines` |
| Config keys | snake_case | `cdr.import.batch_size` |
| Events | PascalCase, passé | `ClientCreated`, `LineActivated` |
| Jobs | PascalCase, impératif | `ImportTransatelCDRJob`, `DetectLineAnomaliesJob` |
| Actions | PascalCase, verbe + nom | `CreateClientAction`, `ActivateLineAction` |
| DTOs | PascalCase, suffixe DTO | `CreateClientDTO`, `ClientListDTO` |

---

## 2. Architecture des modules (DDD-lite)

### Structure obligatoire

Chaque module suit cette organisation en 3 couches :

```
app/Modules/{ModuleName}/
├── Domain/                    # Logique métier pure (pas de dépendance framework)
│   ├── Models/                # Entités Eloquent avec logique métier embarquée
│   ├── ValueObjects/          # Objets valeur immutables (ex: Money, PhoneNumber)
│   ├── Events/                # Événements domaine
│   ├── Enums/                 # Enums PHP 8.1+
│   └── Contracts/             # Interfaces exposées aux autres modules
│
├── Application/               # Cas d'usage (orchestration)
│   ├── Actions/               # 1 action = 1 cas d'usage
│   ├── Services/              # Services d'orchestration complexes
│   ├── DTOs/                  # Data Transfer Objects (immutables)
│   └── Listeners/             # Réactions aux events d'autres modules
│
├── Infrastructure/            # Implémentation technique (interchangeable)
│   ├── Repositories/          # Eloquent implementations
│   ├── Http/
│   │   ├── Controllers/       # Controllers API (fins, délèguent aux Actions)
│   │   ├── Resources/         # API Resources (jamais de ->toArray() direct)
│   │   └── Requests/          # Form Requests (validation)
│   ├── Livewire/              # Composants Livewire du module
│   ├── Jobs/                  # Queue jobs
│   ├── Mail/                  # Mailables
│   └── Providers/             # ServiceProvider du module
│
└── routes.php                 # Routes du module
```

### Règles d'isolation

1. **Un module ne doit JAMAIS** importer les modèles internes d'un autre module
2. **Communication inter-modules** : par Contrat (interface) ou par Event, jamais par appel direct
3. **Pas de dépendance circulaire** : si A dépend de B, B ne dépend pas de A
4. **Chaque module a son propre ServiceProvider** qui bind les interfaces aux implémentations

```php
// BON : dépendance via interface
public function __construct(
    private readonly ClientRepositoryContract $clients, // Interface du module Client
) {}

// MAUVAIS : dépendance directe vers un modèle d'un autre module
use App\Modules\Client\Domain\Models\Client; // INTERDIT depuis le module Telecom
```

---

## 3. Conventions Laravel

### Controllers
- **Fins** : un controller ne contient pas de logique métier
- Délèguent systématiquement aux Actions ou Services
- Maximum 5 méthodes publiques (CRUD + index)
- Utilisent Form Requests pour la validation
- Utilisent API Resources pour la sortie
- Utilisent Policies pour l'autorisation

```php
// BON
public function store(CreateClientRequest $request): ClientResource
{
    $client = $this->createClient->execute(
        CreateClientDTO::fromRequest($request)
    );
    return new ClientResource($client);
}

// MAUVAIS
public function store(Request $request): JsonResponse
{
    $validated = $request->validate([...]); // Validation dans le controller
    $client = Client::create($validated);   // Logique dans le controller
    return response()->json($client);       // Pas de Resource
}
```

### Models (Eloquent)
- Les modèles vivent dans `Domain/Models/`
- Ils contiennent la logique métier qui concerne l'entité elle-même
- Scopes nommés pour les requêtes fréquentes
- Relations typées avec return types
- Casts explicites via la méthode `casts()`

```php
class Line extends Model
{
    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'status' => LineStatus::class,
        ];
    }

    // Logique métier dans le modèle
    public function isActive(): bool
    {
        return $this->status === LineStatus::Active;
    }

    public function canBeDeactivated(): bool
    {
        return $this->isActive() && ! $this->hasActivePortability();
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', LineStatus::Active);
    }

    // Relations typées
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
```

### DTOs
- Immutables (propriétés `readonly`)
- Factory method `fromRequest()` pour la création depuis un Form Request
- Pas de logique métier

```php
final readonly class CreateClientDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $siret = null,
    ) {}

    public static function fromRequest(CreateClientRequest $request): self
    {
        return new self(
            name: $request->validated('name'),
            email: $request->validated('email'),
            siret: $request->validated('siret'),
        );
    }
}
```

### Form Requests
- Un Form Request par action (pas de réutilisation abusive)
- Règles de validation explicites
- Messages d'erreur en français si nécessaire
- `authorize()` renvoie vers les Policies

### API Resources
- **Obligatoires** pour toute sortie API
- Contrôlent exactement les champs exposés
- Relations conditionnelles (`$this->whenLoaded()`)

---

## 4. Conventions Frontend (Livewire 3 + Tailwind)

### Composants Livewire
- Un composant = une responsabilité
- Propriétés publiques typées
- `#[On('event')]` pour la communication entre composants
- Lazy loading pour les composants lourds (`lazy`)
- `wire:navigate` pour la navigation SPA-like

### Composants Blade
- Composants UI partagés dans `resources/views/components/ui/`
- Préfixe `x-` pour les composants Blade (convention Laravel)
- Pas de logique PHP dans les templates Blade — uniquement affichage

### Tailwind CSS
- Pas de CSS custom sauf cas exceptionnel
- Utiliser les classes utilitaires Tailwind
- Configuration dans `tailwind.config.js` pour les couleurs/fonts du design system
- Préfixer si coexistence Bootstrap : `tw-` prefix dans la config Tailwind

---

## 5. Conventions Git

### Branches
- `main` → production
- `develop` → staging
- `feature/{module}-{description}` → nouvelles fonctionnalités
- `fix/{description}` → corrections de bugs
- `hotfix/{description}` → corrections urgentes (PR vers main)

### Commits
Format : `type(module): description courte`

Types : `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`

```
feat(telecom): add line anomaly detection job
fix(billing): correct TVA calculation for DOM-TOM
refactor(client): extract ClientService from controller
test(cdr): add daily aggregation job tests
docs(architecture): update multi-region sync flow
chore(docker): add bcmath and intl extensions
perf(cdr): add client_id index on calls table
```

### Pull Requests
- Titre clair et concis
- Description avec contexte et impact
- Tests obligatoires pour toute modification de logique métier
- Review obligatoire par le second développeur

---

## 6. Conventions de tests (Pest PHP)

```php
// Nommage : descriptif, en anglais
it('creates a client with valid data', function () {
    $dto = new CreateClientDTO(name: 'Test Corp', email: 'test@corp.fr');
    $client = $this->createClient->execute($dto);
    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->name)->toBe('Test Corp');
});

it('throws when creating a client with duplicate email', function () {
    // ...
})->throws(DuplicateClientException::class);

// Organisation des tests : miroir de la structure des modules
tests/
├── Unit/
│   ├── Modules/
│   │   ├── Client/
│   │   │   ├── Actions/CreateClientActionTest.php
│   │   │   └── Models/ClientTest.php
│   │   ├── Telecom/
│   │   └── CDR/
│   └── Shared/
├── Feature/
│   ├── Api/
│   │   ├── ClientApiTest.php
│   │   └── LineApiTest.php
│   └── Livewire/
└── CrossTenant/          # Tests spécifiques multi-tenant
    ├── IsolationTest.php
    └── SyncCatalogTest.php
```

---

## 7. Sécurité dans le code

- **Jamais** de `$request->all()` → toujours `$request->validated()`
- **Jamais** de `Model::create($request->all())` → DTOs explicites
- **Jamais** de check de rôle inline (`if ($user->role === 'admin')`) → Policies
- **Jamais** de secret en dur dans le code → config/env/Secret Manager
- **Toujours** utiliser les bindings de route Laravel (pas de `$_GET`)
- **Toujours** échapper les sorties Blade (`{{ }}`, jamais `{!! !!}` sauf cas justifié)
- Champs sensibles chiffrés via le cast `encrypted` natif Laravel
