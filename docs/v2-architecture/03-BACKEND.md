# 🖥️ Développeur Backend — Analyse & Recommandations

## 1. Diagnostic de l'existant

### Problèmes identifiés

#### A. Absence d'API interne
C'est le problème structurel n°1. Aujourd'hui :
- Les 3 portails (admin, client, ambassadeur) accèdent directement aux modèles Eloquent
- Pas de couche de service intermédiaire
- Logique métier probablement dispersée dans les contrôleurs et les composants Livewire
- Impossible d'exposer des fonctionnalités à un client mobile ou à un partenaire

#### B. Table `calls` non optimisée (confirmé par schéma)
- 12 Go de CDR, **pas de `client_id`** → tout JOIN par client passe par `lines`
- Index existants (`idx_line_date_price`, `uncharged`) sont utiles mais insuffisants
- La table `monthly_summaries` existe déjà (agrégation par ligne + carbone) mais manque l'agrégation quotidienne et les données financières
- `cdr_files` assure la traçabilité des imports (idempotence possible via `provider_call_id` UNIQUE)

#### C. Table `invoices` = JSON blob (confirmé par schéma)
- La colonne `invoices.doc` (JSON) contient l'intégralité de chaque facture
- Les colonnes `date`, `amount`, `paid`, `locked`, `number` sont des GENERATED STORED extraites du JSON
- Chaque SELECT charge le blob complet → mémoire MySQL saturée
- La table `invoiced` (polymorphique) lie les éléments facturés aux factures
- `invoice_cdrs` contient des CDR compressés en MEDIUMBLOB par facture

#### D. Imports non résilients (partiellement confirmé)
- Imports Transatel toutes les heures, Unyc/Wazo quotidiens
- `cdr_files` trace les fichiers importés (bon point)
- `calls.provider_call_id` UNIQUE empêche les doublons CDR (bon point)
- Sans queue robuste, un import échoué peut passer inaperçu

#### E. Laravel 10 → 12
- Laravel 10 : fin de support sécurité février 2025 (déjà expiré)
- Livewire 2 → 3 : changement majeur (syntaxe, lifecycle, performance)

## 2. Recommandations techniques

### A. Couche API REST interne

```php
// routes/tenant.php — Routes régionales (chargées dans le contexte du tenant)
Route::prefix('api/v1')->middleware(['tenant', 'auth:sanctum', 'throttle:api'])->group(function () {
    Route::apiResource('clients', ClientController::class);
    Route::apiResource('clients.agencies', AgencyController::class)->scoped();
    Route::apiResource('clients.collaborators', CollaboratorController::class)->scoped();
    Route::apiResource('clients.lines', LineController::class)->scoped();
});

// routes/central.php — Routes du Hub central uniquement
Route::prefix('api/v1/central')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::apiResource('regions', RegionController::class);
    Route::apiResource('catalog/plans', CatalogPlanController::class);
    Route::post('regions/{tenant}/sync', SyncCatalogController::class);
    Route::post('regions/{tenant}/sso', SsoController::class);
});
```

```php
// app/Modules/Client/Http/Controllers/ClientController.php
class ClientController extends Controller
{
    public function __construct(
        private readonly ClientService $clientService
    ) {}

    public function index(ClientIndexRequest $request): ClientCollection
    {
        $clients = $this->clientService->list(
            ClientListDTO::fromRequest($request)
        );

        return new ClientCollection($clients);
    }

    public function show(Client $client): ClientResource
    {
        $this->authorize('view', $client);
        return new ClientResource(
            $this->clientService->getWithDetails($client)
        );
    }
}
```

**Principes** :
- **Form Requests** pour toute validation d'entrée
- **API Resources** pour contrôler la sortie (jamais de `->toArray()` direct)
- **DTOs** pour transférer les données entre couches
- **Services** pour la logique métier (jamais dans le contrôleur)
- **Policies** pour l'autorisation (jamais de `if ($user->role === 'admin')` en dur)

### B. Pattern Actions pour les opérations métier

```php
// app/Modules/Telecom/Actions/ActivateLineAction.php
class ActivateLineAction
{
    public function __construct(
        private readonly TransatelGateway $transatel,
        private readonly LineRepository $lines,
        private readonly EventDispatcher $events
    ) {}

    public function execute(ActivateLineDTO $dto): Line
    {
        // 1. Validation métier
        $line = $this->lines->findOrFail($dto->lineId);

        if ($line->isActive()) {
            throw new LineAlreadyActiveException($line);
        }

        // 2. Appel fournisseur
        $activation = $this->transatel->activateSim($line->sim->iccid);

        // 3. Mise à jour interne
        $line->markAsActive($activation->activatedAt);
        $this->lines->save($line);

        // 4. Effet de bord via événement
        $this->events->dispatch(new LineActivated($line));

        return $line;
    }
}
```

**Avantage** : chaque action est testable unitairement, réutilisable depuis Livewire, l'API, ou un job.

### C. Connecteurs fournisseurs isolés

```php
// app/Modules/Integration/Contracts/MobileProviderGateway.php
interface MobileProviderGateway
{
    public function activateSim(string $iccid): ActivationResult;
    public function suspendLine(string $msisdn): SuspensionResult;
    public function getConsumption(string $msisdn, CarbonPeriod $period): ConsumptionData;
    public function portNumber(PortabilityRequest $request): PortabilityResult;
}

// app/Modules/Integration/Gateways/TransatelGateway.php
class TransatelGateway implements MobileProviderGateway
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly TransatelConfig $config
    ) {}

    public function activateSim(string $iccid): ActivationResult
    {
        $response = $this->http->post("{$this->config->baseUrl}/sims/{$iccid}/activate", [
            'headers' => ['Authorization' => "Bearer {$this->config->apiKey}"],
        ]);

        return ActivationResult::fromTransatel($response->json());
    }
}

// app/Modules/Integration/Gateways/UnycGateway.php
class UnycGateway implements MobileProviderGateway
{
    // Implémentation spécifique Unyc
}
```

**Pattern Gateway** : chaque fournisseur implémente la même interface. Le code métier ne connaît pas le fournisseur concret → ajout/remplacement d'un fournisseur = 0 impact sur le métier.

### D. Import CDR robuste

```php
// app/Modules/CDR/Jobs/ImportTransatelCDRJob.php
class ImportTransatelCDRJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // retry après 60s

    public function handle(TransatelGateway $gateway, CDRImportService $importer): void
    {
        $rawCDRs = $gateway->getConsumption(
            period: CarbonPeriod::create(now()->subHours(2), now())
        );

        $result = $importer->importBatch($rawCDRs, [
            'provider' => 'transatel',
            'idempotency_key' => $this->generateIdempotencyKey(),
        ]);

        if ($result->hasErrors()) {
            // Log dans la table errors (visible dans le menu Erreurs > CDR)
            CDRImportError::createFromResult($result);
            Notification::send(
                User::admins()->get(),
                new CDRImportFailedNotification($result)
            );
        }
    }

    public function uniqueId(): string
    {
        return 'transatel-cdr-import';
    }
}
```

**Points clés** :
- `ShouldBeUnique` : empêche les imports parallèles
- `$tries = 3` avec backoff : résilience réseau
- Idempotence : pas de doublons si re-run
- Notification en cas d'erreur : les erreurs CDR remontent dans l'UI existante

### E. Queue & Jobs — Laravel Horizon

```php
// config/horizon.php
'environments' => [
    'production' => [
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default', 'notifications'],
            'maxProcesses' => 5,
        ],
        'supervisor-imports' => [
            'connection' => 'redis',
            'queue' => ['imports', 'cdr'],
            'maxProcesses' => 3,
            'timeout' => 600, // 10 min pour les gros imports
        ],
        'supervisor-billing' => [
            'connection' => 'redis',
            'queue' => ['billing', 'invoices'],
            'maxProcesses' => 2,
            'timeout' => 900, // 15 min pour la facturation
        ],
        'supervisor-tenancy' => [
            'connection' => 'redis',
            'queue' => ['tenant-sync', 'tenant-provision'],
            'maxProcesses' => 2,
            'timeout' => 300, // 5 min pour sync catalogue
        ],
    ],
],
```

**Séparation des queues** :
- `default` + `notifications` : tâches rapides
- `imports` + `cdr` : imports fournisseurs (potentiellement longs)
- `billing` + `invoices` : facturation (critique, ne doit pas être bloquée par les imports)
- `tenant-sync` + `tenant-provision` : synchronisation catalogue central → régions, provisioning nouvelles régions

### F. Cache stratégique avec Redis

```php
// Exemples de caching pertinent

// Dashboard client — données qui changent rarement
Cache::tags(['client', "client:{$id}"])->remember(
    "client:{$id}:dashboard",
    now()->addMinutes(15),
    fn () => $this->clientService->getDashboardData($id)
);

// Catalogue — changements rares
Cache::tags(['catalog'])->remember(
    'catalog:plans:active',
    now()->addHours(6),
    fn () => Plan::active()->with('provider')->get()
);

// Invalidation ciblée quand le catalogue change
Cache::tags(['catalog'])->flush();
```

## 3. Upgrade Path

### Laravel 10 → 12
1. Laravel 10 → 11 d'abord (changements de structure : `bootstrap/app.php`, suppression de certains fichiers)
2. Laravel 11 → 12 (changements mineurs)
3. Utiliser **Laravel Shift** (service automatisé) pour les deux upgrades — économise des jours de travail

### Livewire 2 → 3
- Changement majeur de syntaxe (`$wire`, lifecycle hooks, `#[On]`, etc.)
- Migration composant par composant (les deux versions coexistent via `livewire:livewire` et `livewire:livewire3`)
- Commencer par les composants les plus simples, finir par les plus complexes

## 4. Risques identifiés

| Risque | Mitigation |
|--------|------------|
| Migration Livewire 2 → 3 plus longue que prévu | Coexistence des deux versions, migration progressive |
| Imports fournisseurs cassés pendant migration | Garder les imports existants fonctionnels, migrer vers les nouveaux jobs en parallèle |
| Performance régression pendant le partitionnement CDR | Faire le partitionnement sur une copie, valider les performances, puis switch |
| Perte de données CDR pendant l'archivage | Double-write vers agrégation + archive avant de supprimer les détails |
| Migration `invoices.doc` JSON → normalisé perd des données | Script de migration avec vérification checksums : comparer les totaux JSON vs colonnes normalisées pour chaque facture |
| Sync catalogue désynchronisée entre régions | Checksums de vérification, logs de sync, retry automatique, alerte si écart |
| Migrations de schéma désynchronisées entre BDD régionales | CI teste les migrations sur toutes les BDD. `artisan tenants:migrate` atomique |

---

# 🔍 Revue croisée DevOps

## Points validés
- L'architecture de queues avec 3 supervisors est bien pensée — empêche qu'un import CDR massif bloque la facturation
- L'idempotence des imports est cruciale et bien adressée
- Le pattern Gateway pour les fournisseurs facilite le testing (mock) et le déploiement (feature flags par fournisseur)

## Points d'attention

### 1. Horizon nécessite Redis — validé dans l'infra
> Redis est prévu dans l'infrastructure Scaleway. OK.

### 2. Timeout des jobs
> Les timeouts de 600s (imports) et 900s (billing) doivent être cohérents avec le timeout du worker Docker. S'assurer que le container worker a un `stop_grace_period` supérieur au plus long job (900s + marge).

### 3. Health checks
> Ajouter un endpoint `/health` qui vérifie : DB, Redis, queue size, dernière exécution des imports. Intégrable au load balancer pour retirer automatiquement un serveur défaillant.

### 4. Laravel Shift pour l'upgrade
> Excellent conseil. Le coût (~100$ pour les deux upgrades) est négligeable vs le temps gagné. À budgéter.

## Verdict
Recommandations backend **solides et pragmatiques**. Le pattern Action + Gateway + Queue est exactement ce qu'il faut pour cette taille de projet. Pas de sur-ingénierie.
