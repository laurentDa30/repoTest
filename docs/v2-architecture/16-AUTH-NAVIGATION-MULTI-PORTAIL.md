# Authentification & Navigation Multi-Portail

## Vue d'ensemble

Ce document décrit l'architecture d'authentification pour les trois types d'utilisateurs
et leur navigation entre les différents portails (Hub, Tenant, Espace Client).

### Rappel des domaines (cf. doc 09)

```
central.cekoya.fr                → Hub central (super-admin)
{region}.cekoya.fr               → Portail admin régional (employés)
{region}-client.cekoya.fr        → Portail client
{region}-amba.cekoya.fr          → Portail ambassadeur
```

### Matrice d'accès

| Type d'utilisateur | Hub central | Tenant (admin régional) | Espace client | Espace ambassadeur |
|---|---|---|---|---|
| **Hub (super-admin)** | Connexion directe | Navigation via token signé | Navigation via token signé | Navigation via token signé |
| **Employé tenant** | Aucun accès | Connexion directe | Navigation via token signé | Navigation via token signé |
| **Client** | Aucun accès | Aucun accès | Connexion directe | Aucun accès |
| **Ambassadeur** | Aucun accès | Aucun accès | Aucun accès | Connexion directe |

---

## 1. Authentification directe (chaque portail)

Chaque portail a son propre `/login`. L'utilisateur se connecte directement sur son domaine.
Aucune logique cross-domain n'est nécessaire pour la connexion de base.

```
# L'employé de Région Sud se connecte sur :
paca.cekoya.fr/login

# Le client se connecte sur :
paca-client.cekoya.fr/login

# Le super-admin se connecte sur :
central.cekoya.fr/login
```

### Stack d'auth par portail

| Portail | Auth | Session timeout | MFA |
|---|---|---|---|
| Hub central | Laravel Fortify + Session | 15 min inactivité | Obligatoire |
| Admin régional | Laravel Fortify + Session | 30 min inactivité | Obligatoire |
| Client | Laravel Fortify + Session | 60 min inactivité | Recommandé |
| Ambassadeur | Laravel Fortify + Session | 60 min inactivité | Recommandé |

---

## 2. Navigation cross-portail — Token signé RS256

Quand un utilisateur Hub ou Tenant a besoin d'accéder à un autre portail,
on utilise un **JWT signé RS256 à usage unique** (cf. doc 06 — Cybersécurité).

### Pourquoi RS256 et pas un simple token aléatoire ?

- **Asymétrique** : le Hub signe avec sa clé privée, les tenants vérifient avec la clé publique
- **Aucun secret partagé** : les tenants n'ont pas besoin d'appeler le Hub pour vérifier
- **Standard** : JWT est auditable, parseable, et bien outillé

### 2.1 Génération de la paire de clés

```bash
# À exécuter une seule fois — stocker dans Scaleway Secret Manager
openssl genrsa -out sso_private.pem 2048
openssl rsa -in sso_private.pem -pubout -out sso_public.pem
```

Variables `.env` :

```dotenv
# Hub central (.env)
SSO_PRIVATE_KEY=/path/to/sso_private.pem
SSO_PUBLIC_KEY=/path/to/sso_public.pem

# Chaque tenant (.env) — seule la clé publique
SSO_PUBLIC_KEY=/path/to/sso_public.pem
```

### 2.2 Service de génération du token (côté émetteur)

```php
<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;

class SsoTokenService
{
    /**
     * Génère un JWT signé pour naviguer vers un autre portail.
     *
     * @param string $email       Email de l'utilisateur cible
     * @param string $targetRole  Rôle attendu côté cible ('admin', 'client_admin', etc.)
     * @param string $tenantId    Tenant cible (ex: 'paca', 'idf')
     * @param string $portal      Portail cible ('tenant', 'client', 'ambassador')
     */
    public function generate(
        string $email,
        string $targetRole,
        string $tenantId,
        string $portal
    ): string {
        $privateKey = file_get_contents(config('sso.private_key'));
        $jti = Str::uuid()->toString(); // identifiant unique du token

        $payload = [
            'iss'         => 'central.cekoya.fr',       // émetteur
            'sub'         => $email,                      // utilisateur
            'tenant_id'   => $tenantId,                   // tenant cible
            'portal'      => $portal,                     // portail cible
            'target_role' => $targetRole,                 // rôle attendu
            'jti'         => $jti,                        // anti-replay
            'iat'         => time(),                       // émis à
            'exp'         => time() + 60,                  // expire dans 60 secondes
        ];

        // Stocker le jti pour vérification anti-replay
        cache()->put("sso_token:{$jti}", true, now()->addSeconds(90));

        return JWT::encode($payload, $privateKey, 'RS256');
    }
}
```

### 2.3 Vérification du token (côté récepteur)

```php
<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class SsoTokenVerifier
{
    /**
     * Vérifie et consomme un token SSO.
     * Retourne le payload décodé ou null si invalide.
     */
    public function verify(string $token): ?object
    {
        try {
            $publicKey = file_get_contents(config('sso.public_key'));
            $payload = JWT::decode($token, new Key($publicKey, 'RS256'));

            // Vérifier que le token n'a pas déjà été utilisé (anti-replay)
            $cacheKey = "sso_used:{$payload->jti}";

            if (cache()->has($cacheKey)) {
                return null; // token déjà consommé
            }

            // Marquer comme utilisé (garder plus longtemps que l'expiration)
            cache()->put($cacheKey, true, now()->addMinutes(5));

            // Vérifier que le tenant correspond
            if ($payload->tenant_id !== tenant('id')) {
                return null; // token pas pour ce tenant
            }

            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }
}
```

### 2.4 Dépendance Composer

```bash
composer require firebase/php-jwt
```

---

## 3. Scénario Hub → Tenant

Le super-admin, connecté sur `central.cekoya.fr`, veut accéder au portail admin de la région PACA.

### Flux

```
central.cekoya.fr                                paca.cekoya.fr
┌─────────────────┐                              ┌─────────────────┐
│ Super-admin      │                              │ Portail admin    │
│ connecté         │                              │ régional         │
│                  │                              │                  │
│ Clic "PACA"     │──── redirect ───────────────→│ /sso/login       │
│                  │    ?token=eyJhbG...          │                  │
│                  │                              │ 1. Vérifie JWT   │
│                  │                              │ 2. Trouve user   │
│                  │                              │ 3. Auth::login() │
│                  │                              │ 4. Log audit     │
│                  │                              │ 5. → /dashboard  │
└─────────────────┘                              └─────────────────┘
```

### Contrôleur Hub (émetteur)

```php
class HubTenantSwitchController extends Controller
{
    public function __construct(
        private SsoTokenService $ssoToken
    ) {}

    /**
     * Redirige le super-admin vers le portail admin d'un tenant.
     */
    public function switchToTenant(Tenant $tenant)
    {
        $this->authorize('accessTenant', $tenant);

        $admin = auth()->user();
        $domain = $tenant->domains()->where('is_primary', true)->first()->domain;

        $token = $this->ssoToken->generate(
            email: $admin->email,
            targetRole: 'super_admin',
            tenantId: $tenant->id,
            portal: 'tenant'
        );

        // Audit
        activity()
            ->causedBy($admin)
            ->withProperties(['tenant' => $tenant->id, 'portal' => 'tenant'])
            ->log('sso_token_generated');

        return redirect("https://{$domain}/sso/login?token={$token}");
    }

    /**
     * Redirige le super-admin vers le portail client d'un tenant.
     */
    public function switchToClientPortal(Tenant $tenant)
    {
        $this->authorize('accessTenant', $tenant);

        $admin = auth()->user();
        $clientDomain = str_replace('.cekoya.fr', '-client.cekoya.fr',
            $tenant->domains()->where('is_primary', true)->first()->domain
        );

        $token = $this->ssoToken->generate(
            email: $admin->email,
            targetRole: 'super_admin',
            tenantId: $tenant->id,
            portal: 'client'
        );

        return redirect("https://{$clientDomain}/sso/login?token={$token}");
    }
}
```

### Routes Hub (émetteur)

```php
// routes/web.php (central)
Route::middleware(['auth', 'role:super_admin', 'mfa'])->prefix('hub')->group(function () {
    Route::get('/switch/tenant/{tenant}', [HubTenantSwitchController::class, 'switchToTenant'])
        ->name('hub.switch.tenant');

    Route::get('/switch/client/{tenant}', [HubTenantSwitchController::class, 'switchToClientPortal'])
        ->name('hub.switch.client');
});
```

### Contrôleur Tenant (récepteur)

```php
class SsoLoginController extends Controller
{
    public function __construct(
        private SsoTokenVerifier $verifier
    ) {}

    public function login(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $payload = $this->verifier->verify($request->token);

        if (! $payload) {
            abort(403, 'Token SSO invalide ou expiré.');
        }

        // Trouver l'utilisateur dans la base du tenant
        $user = User::where('email', $payload->sub)->first();

        if (! $user) {
            abort(403, 'Aucun compte trouvé dans cette région.');
        }

        // Vérifier que l'utilisateur a le rôle attendu (ou supérieur)
        if ($payload->target_role === 'super_admin' && ! $user->hasRole('super_admin')) {
            abort(403, 'Permissions insuffisantes.');
        }

        Auth::login($user);

        // Marquer la session comme issue d'un SSO
        session([
            'sso_origin'  => $payload->iss,
            'sso_portal'  => $payload->portal,
            'sso_at'      => now()->toISOString(),
        ]);

        // Audit
        activity()
            ->causedBy($user)
            ->withProperties([
                'origin'  => $payload->iss,
                'portal'  => $payload->portal,
                'ip'      => $request->ip(),
            ])
            ->log('sso_login_success');

        return redirect('/dashboard');
    }
}
```

### Route Tenant (récepteur)

```php
// routes/tenant.php
Route::get('/sso/login', [SsoLoginController::class, 'login'])
    ->middleware(['web'])
    ->name('sso.login');
```

---

## 4. Scénario Tenant → Espace Client

Un employé régional, connecté sur `paca.cekoya.fr`, veut accéder au portail client
pour voir ce que voit un client spécifique.

### Flux

```
paca.cekoya.fr                               paca-client.cekoya.fr
┌─────────────────┐                           ┌─────────────────┐
│ Employé connecté │                           │ Portail client   │
│                  │                           │                  │
│ Clic "Voir      │── redirect ──────────────→│ /sso/login       │
│  espace client" │   ?token=eyJhbG...        │                  │
│                  │                           │ Vérifie JWT      │
│                  │                           │ Auth::login()    │
│                  │                           │ → /dashboard     │
└─────────────────┘                           └─────────────────┘
```

### Contrôleur Tenant (émetteur vers client)

```php
class TenantClientSwitchController extends Controller
{
    public function __construct(
        private SsoTokenService $ssoToken
    ) {}

    /**
     * L'employé accède au portail client de son propre tenant.
     */
    public function switchToClientPortal()
    {
        $employee = auth()->user();

        $clientDomain = str_replace('.cekoya.fr', '-client.cekoya.fr',
            request()->getHost()
        );

        $token = $this->ssoToken->generate(
            email: $employee->email,
            targetRole: $employee->getRoleNames()->first(),
            tenantId: tenant('id'),
            portal: 'client'
        );

        activity()
            ->causedBy($employee)
            ->withProperties(['portal' => 'client'])
            ->log('sso_token_generated_to_client');

        return redirect("https://{$clientDomain}/sso/login?token={$token}");
    }
}
```

> **Note** : Le tenant émet aussi des tokens JWT. Pour cela, chaque tenant
> a accès à la clé privée SSO, **ou** on utilise une clé privée par tenant
> (plus sécurisé, voir section 7).

---

## 5. Scénario Client — Connexion directe

Le client se connecte directement sur son portail. Aucun SSO nécessaire.

```
paca-client.cekoya.fr/login
    → email + mot de passe
    → Auth::login()
    → /dashboard
```

Le client n'a accès qu'à son propre espace. Il ne peut pas naviguer vers le
portail admin ou le Hub.

### Middleware de protection

```php
// S'assurer qu'un client ne peut voir que ses propres données
class EnsureClientOwnership
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Si l'utilisateur vient d'un SSO (employé/admin), il voit tout
        if (session('sso_origin')) {
            return $next($request);
        }

        // Sinon, scope automatique aux données du client
        if ($user->client_id) {
            app()->instance('current_client_id', $user->client_id);
        }

        return $next($request);
    }
}
```

---

## 6. Pré-requis : comptes miroirs dans les tenants

Pour que le SSO fonctionne, l'utilisateur **doit exister** dans la base du tenant cible.

### Super-admin Hub → comptes dans chaque tenant

À la création d'un tenant, créer automatiquement les comptes super-admin :

```php
// App\Listeners\CreateSuperAdminInNewTenant
class CreateSuperAdminInNewTenant
{
    public function handle(TenantCreated $event)
    {
        $tenant = $event->tenant;

        // Récupérer les super-admins depuis la base centrale
        $superAdmins = DB::connection('central')
            ->table('users')
            ->where('role', 'super_admin')
            ->get();

        $tenant->run(function () use ($superAdmins) {
            foreach ($superAdmins as $admin) {
                User::firstOrCreate(
                    ['email' => $admin->email],
                    [
                        'name'     => $admin->name,
                        'password' => $admin->password, // hash déjà hashé
                    ]
                )->assignRole('super_admin');
            }
        });
    }
}
```

### Employé Tenant → compte dans le portail client du même tenant

Les employés existent déjà dans la base du tenant. Le portail client
partage la même base de données (même tenant, domaine différent),
donc **aucune synchro n'est nécessaire** — l'employé existe déjà dans la table `users`.

La distinction se fait par le **rôle** :
- `admin`, `manager`, `operator` → employés
- `client_admin`, `client_user` → clients

---

## 7. Sécurisation

### 7.1 Protections intégrées au JWT

| Protection | Implémentation |
|---|---|
| **Expiration** | `exp` = 60 secondes maximum |
| **Anti-replay** | `jti` unique + `cache()->put("sso_used:{jti}")` |
| **Scope tenant** | `tenant_id` dans le payload, vérifié côté récepteur |
| **Scope portail** | `portal` dans le payload (tenant, client, ambassador) |
| **Signature** | RS256 — clé privée au Hub, clé publique partout |
| **Audit** | Log à l'émission ET à la consommation du token |

### 7.2 Stratégie de clés

**Option simple (recommandée pour démarrer)** : une seule paire de clés RSA.
Le Hub et les tenants partagent la même clé privée (puisque les tenants
émettent aussi des tokens vers les portails client).

**Option avancée** : une paire par émetteur.

```
Hub    → sso_hub_private.pem    / sso_hub_public.pem
PACA   → sso_paca_private.pem   / sso_paca_public.pem
IDF    → sso_idf_private.pem    / sso_idf_public.pem
```

Chaque récepteur a les clés publiques de tous les émetteurs autorisés.
Plus sécurisé mais plus complexe à gérer.

### 7.3 Rate limiting sur `/sso/login`

```php
// bootstrap/app.php ou route
Route::get('/sso/login', [SsoLoginController::class, 'login'])
    ->middleware(['web', 'throttle:10,1']); // max 10 tentatives/minute
```

### 7.4 Ce que le token NE contient PAS

- Pas de mot de passe
- Pas de données sensibles (IBAN, SIRET, etc.)
- Pas de permissions détaillées (elles viennent de la base locale)

### 7.5 Révocation d'urgence

En cas de compromission de la clé privée :

```bash
# 1. Générer une nouvelle paire
openssl genrsa -out sso_private_v2.pem 2048
openssl rsa -in sso_private_v2.pem -pubout -out sso_public_v2.pem

# 2. Déployer la nouvelle clé publique sur tous les tenants
# 3. Mettre à jour la clé privée sur le Hub
# 4. Tous les anciens tokens deviennent instantanément invalides
```

---

## 8. Interface utilisateur — Boutons de navigation

### 8.1 Dashboard Hub — Sélecteur de tenant

```blade
{{-- resources/views/hub/dashboard.blade.php --}}
<h2>Régions</h2>
<div class="grid grid-cols-3 gap-4">
    @foreach($tenants as $tenant)
        <div class="card">
            <h3>{{ $tenant->name }}</h3>
            <p>{{ $tenant->domains->first()->domain }}</p>

            <div class="flex gap-2 mt-4">
                <a href="{{ route('hub.switch.tenant', $tenant) }}"
                   class="btn btn-primary">
                    Portail Admin
                </a>
                <a href="{{ route('hub.switch.client', $tenant) }}"
                   class="btn btn-secondary">
                    Portail Client
                </a>
            </div>
        </div>
    @endforeach
</div>
```

### 8.2 Dashboard Tenant — Accès portail client

```blade
{{-- resources/views/tenant/dashboard.blade.php --}}
@can('access-client-portal')
    <a href="{{ route('tenant.switch.client') }}" class="btn btn-secondary"
       target="_blank">
        Voir le portail client
    </a>
@endcan
```

### 8.3 Bandeau SSO (côté récepteur)

Quand un utilisateur est connecté via SSO, afficher un bandeau pour le rappeler :

```blade
{{-- resources/views/layouts/app.blade.php --}}
@if(session('sso_origin'))
    <div class="bg-amber-500 text-white text-center text-sm py-1">
        Connecté via {{ session('sso_origin') }}
        — <a href="{{ route('sso.return') }}" class="underline">Retour</a>
    </div>
@endif
```

---

## 9. Bouton "Retour" vers le portail d'origine

```php
class SsoReturnController extends Controller
{
    public function returnToOrigin()
    {
        $origin = session('sso_origin');

        // Déconnecter du portail actuel
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        if ($origin) {
            return redirect("https://{$origin}/dashboard");
        }

        return redirect('/login');
    }
}
```

```php
Route::get('/sso/return', [SsoReturnController::class, 'returnToOrigin'])
    ->middleware('auth')
    ->name('sso.return');
```

> **Note** : Le retour ne nécessite pas de nouveau token. L'utilisateur a toujours
> sa session active sur le portail d'origine (domaines différents = sessions séparées).

---

## 10. Config Laravel — `config/sso.php`

```php
<?php

return [
    'private_key' => env('SSO_PRIVATE_KEY'),
    'public_key'  => env('SSO_PUBLIC_KEY'),

    // Durée de validité du token en secondes
    'token_ttl' => env('SSO_TOKEN_TTL', 60),

    // Émetteur (pour le champ 'iss' du JWT)
    'issuer' => env('SSO_ISSUER', 'central.cekoya.fr'),

    // Portails autorisés à émettre des tokens
    'allowed_issuers' => [
        'central.cekoya.fr',
        // Les domaines tenant sont ajoutés dynamiquement
    ],
];
```

---

## 11. Résumé des flux

```
┌──────────────────────────────────────────────────────────────────┐
│                         HUB CENTRAL                              │
│                    central.cekoya.fr                              │
│                                                                  │
│   Super-admin connecté (Fortify + MFA)                          │
│                                                                  │
│   ┌─────────────┐  ┌─────────────┐  ┌─────────────┐            │
│   │ Région PACA  │  │ Région IDF  │  │ Région Lyon │            │
│   │              │  │             │  │             │            │
│   │ [Admin] ─────┼──┼─ JWT ──────►│  │             │            │
│   │ [Client] ────┼──┼─ JWT ──────►│  │             │            │
│   └─────────────┘  └─────────────┘  └─────────────┘            │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│                      TENANT (paca.cekoya.fr)                     │
│                                                                  │
│   Employé connecté (Fortify + MFA)                              │
│                                                                  │
│   ┌──────────────────┐                                          │
│   │ Portail Client    │                                          │
│   │ paca-client ──────┼── JWT ──────► paca-client.cekoya.fr     │
│   └──────────────────┘                                          │
│                                                                  │
│   ⛔ Pas d'accès au Hub                                         │
│   ⛔ Pas d'accès aux autres tenants                             │
└──────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────┐
│                 CLIENT (paca-client.cekoya.fr)                   │
│                                                                  │
│   Client connecté (Fortify + MFA recommandé)                    │
│                                                                  │
│   ⛔ Pas d'accès au Hub                                         │
│   ⛔ Pas d'accès au portail admin                               │
│   ⛔ Pas d'accès aux autres tenants                             │
│   ✅ Accès uniquement à ses propres données (scope client_id)   │
└──────────────────────────────────────────────────────────────────┘
```

---

## 12. Checklist d'implémentation

- [ ] Générer la paire de clés RSA (`sso_private.pem` / `sso_public.pem`)
- [ ] Stocker les clés dans Scaleway Secret Manager
- [ ] `composer require firebase/php-jwt`
- [ ] Créer `config/sso.php`
- [ ] Créer `App\Services\SsoTokenService` (émetteur)
- [ ] Créer `App\Services\SsoTokenVerifier` (récepteur)
- [ ] Créer `SsoLoginController` (côté tenant et côté client)
- [ ] Créer `HubTenantSwitchController` (côté hub)
- [ ] Créer `TenantClientSwitchController` (côté tenant)
- [ ] Créer `SsoReturnController` (retour vers le portail d'origine)
- [ ] Créer le listener `CreateSuperAdminInNewTenant`
- [ ] Ajouter rate limiting sur `/sso/login` (10 req/min)
- [ ] Ajouter le bandeau SSO dans le layout
- [ ] Configurer les variables `.env` sur chaque environnement
- [ ] Tests : vérifier qu'un token expiré est rejeté
- [ ] Tests : vérifier qu'un token rejoué est rejeté (anti-replay)
- [ ] Tests : vérifier qu'un token pour un autre tenant est rejeté
- [ ] Tests : vérifier qu'un client ne peut pas accéder au portail admin
- [ ] Audit : vérifier que les logs SSO apparaissent dans `activity_log`

---

## Historique des modifications

| Date | Modification |
|------|-------------|
| 2026-03-12 | Création du document — Architecture auth multi-portail avec JWT RS256 |
