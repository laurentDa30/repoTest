# 🔐 Expert Cybersécurité — Analyse & Recommandations

## 1. Diagnostic de l'existant

### Surface d'attaque actuelle

| Vecteur | Niveau de risque | Détail |
|---------|-----------------|--------|
| **Serveur local (on-premise)** | CRITIQUE | Pas de WAF, pas de DDoS protection, exposition directe |
| **Chiffrement "dans la même base"** | ÉLEVÉ | Clés probablement dans `.env`, même machine = un seul point de compromission |
| **3 portails, 1 application** | ÉLEVÉ | Une faille sur le portail client impacte l'admin |
| **Pas d'isolation des données par région** | ÉLEVÉ | Toutes les données dans une seule BDD — pas de cloisonnement en cas de compromission |
| **Pas de CI/CD avec checks sécu** | MOYEN | Pas de scan de dépendances, pas de SAST |
| **Laravel 10 (EOL)** | MOYEN | Plus de patches de sécurité |
| **MFA existant** | ✅ POSITIF | Bon point — à étendre |

### Données critiques identifiées

| Catégorie | Exemples | Classification |
|-----------|----------|---------------|
| **Identité** | Noms, emails, téléphones des collaborateurs clients | Données personnelles (RGPD) |
| **Financier** | IBAN, mandats SEPA, factures | Données sensibles |
| **Télécom** | CDR (qui appelle qui, quand, durée) | Données personnelles sensibles |
| **Authentification** | Mots de passe hashés, tokens MFA | Secrets critiques |
| **Contrats** | Documents signés (Yousign) | Données contractuelles |

## 2. Modèle de sécurité V2

### A. Architecture de sécurité en couches (Defense in Depth)

```
Couche 1 — RÉSEAU
├── Cloudflare (ou Scaleway Edge) : WAF + DDoS + CDN + SSL
├── Firewall : seuls ports 80/443 ouverts
├── Load Balancer : rate limiting L4
└── VPC privé : DB + Redis + workers non exposés à internet

Couche 1b — ISOLATION MULTI-TENANT
├── stancl/tenancy : BDD séparée par région (blast radius limité)
├── Middleware tenant : switch automatique, pas d'accès cross-tenant
├── Credentials BDD par tenant : chaque région a ses propres creds
└── Hub central : accès SSO contrôlé, pas d'accès direct aux BDD régionales

Couche 2 — APPLICATION
├── Laravel Sanctum : auth API avec token scoping
├── Middleware rate limiting : par route, par IP, par user
├── CORS strict : domaines autorisés uniquement
├── CSP headers : Content Security Policy strict
├── Form Requests : validation sur chaque entrée
└── Policy-based authorization : jamais de check inline

Couche 3 — DONNÉES
├── Chiffrement au repos : MySQL TDE ou Scaleway encrypted volumes
├── Chiffrement applicatif : données sensibles (IBAN, etc.) via Laravel Crypt
├── KMS externe : clés de chiffrement séparées de l'app
├── Backups chiffrés : snapshots cryptés sur Object Storage
└── Audit trail : toute action sur données sensibles loguée

Couche 4 — OPÉRATIONNEL
├── MFA obligatoire : tous les utilisateurs internes
├── Rotation des secrets : clés API, tokens, mots de passe
├── Scans de dépendances : automatisés dans CI/CD
├── Pen testing : annuel minimum
└── Incident response plan : procédure documentée
```

### B. Authentification & Autorisation

#### Authentification

```php
// Matrice d'authentification par portail (multi-région)
//
// Hub Central (central.cekoya.fr)
// ├── Session Laravel + MFA obligatoire
// ├── Session timeout : 15 min d'inactivité (accès super-admin)
// ├── IP allowlisting recommandé
// ├── SSO vers les portails régionaux (token signé, one-time-use)
// └── Audit log de chaque connexion + chaque accès cross-région
//
// Portail Admin régional ({region}.cekoya.fr)
// ├── Session Laravel + MFA obligatoire
// ├── Session timeout : 30 min d'inactivité
// ├── IP allowlisting optionnel (si accès bureaux uniquement)
// ├── Données isolées dans la BDD du tenant
// └── Audit log de chaque connexion
//
// Portail Client ({region}-client.cekoya.fr)
// ├── Session Laravel + MFA recommandé
// ├── Session timeout : 60 min
// ├── Password policy : min 12 chars, pas de common passwords
// ├── Scoped au tenant (pas d'accès cross-région possible)
// └── Notification email à chaque connexion depuis un nouvel appareil
//
// Portail Ambassadeur ({region}-amba.cekoya.fr)
// ├── Session Laravel + MFA recommandé
// └── Accès limité (données financières ambassadeur uniquement, scopé au tenant)
//
// API interne
// ├── Laravel Sanctum avec token scoping + tenant scoping
// ├── Tokens avec expiration (7 jours max)
// └── Rate limiting strict (60 req/min par défaut, ajustable par route)
```

#### Autorisation — RBAC + Policies

```php
// app/Modules/Auth/Models/Permission.php
// Granularité : module.action (ex: clients.view, billing.export, cdr.view)

// Rôles par défaut (scopés au tenant)
// super_admin   → Hub central, accès toutes régions, provisioning
// admin         → toutes permissions (dans son tenant uniquement)
// manager       → gestion clients + facturation (pas de config système)
// operator      → gestion lignes + SIM + portabilités
// viewer        → lecture seule
// client_admin  → portail client, gestion de ses propres données
// client_user   → portail client, lecture seule
// ambassador    → portail ambassadeur, ses données uniquement

// Mise en oeuvre via Policies Laravel
class LinePolicy
{
    public function view(User $user, Line $line): bool
    {
        // Un client ne voit que ses propres lignes
        if ($user->isClient()) {
            return $user->client_id === $line->client_id;
        }

        return $user->hasPermission('telecom.lines.view');
    }

    public function update(User $user, Line $line): bool
    {
        return $user->hasPermission('telecom.lines.update')
            && ! $line->isLocked(); // Ligne en cours de portabilité = verrouillée
    }
}
```

### C. Protection des données sensibles

#### Chiffrement applicatif

```php
// Utiliser le cast natif Laravel `encrypted` (disponible depuis Laravel 8+)
// Pas besoin de trait custom — Laravel gère le chiffrement AES-256-CBC nativement
// La clé de chiffrement doit être stockée dans KMS, pas dans .env

class Client extends Model
{
    protected function casts(): array
    {
        return [
            'iban'  => 'encrypted',
            'bic'   => 'encrypted',
            'siret' => 'encrypted',
        ];
    }
}

// Les données sont chiffrées en écriture et déchiffrées en lecture automatiquement.
// Pour rechercher sur un champ chiffré, utiliser un hash blind index séparé.
```

#### Gestion des secrets

| Secret | Stockage V1 | Stockage V2 |
|--------|------------|------------|
| Clé APP_KEY | `.env` sur le serveur | Scaleway Secret Manager |
| Clés API fournisseurs | `.env` | Scaleway Secret Manager |
| Credentials BDD centrale | `.env` | Variables d'environnement du container (injectées au runtime) |
| Credentials BDD par tenant | N/A | Scaleway Secret Manager (un secret par région) |
| Tokens Yousign | `.env` | Scaleway Secret Manager |

### D. Audit Trail — Implémentation custom (sans spatie/laravel-activitylog)

#### Décision : Middleware HTTP + Trait Auditable (pas de package externe)

L'audit est implémenté en interne via deux mécanismes complémentaires, sans dépendance à `spatie/laravel-activitylog`. Cela donne un contrôle total sur les données loguées et évite une abstraction supplémentaire.

#### Niveau 1 — Middleware `AuditLog` (actions utilisateur)

Capture **toutes les interactions utilisateur** : connexions, navigation, actions CRUD.

```php
// app/Http/Middleware/AuditLog.php
class AuditLog
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($this->shouldSkip($request)) {
            return $response;
        }

        AuditEntry::create([
            // QUI
            'user_id'       => auth()->id(),
            'user_name'     => auth()->user()?->name,
            'team_id'       => auth()->user()?->current_team_id,
            'ip'            => $request->ip(),
            'user_agent'    => $request->userAgent(),

            // QUOI
            'action'        => $this->resolveAction($request),
            'method'        => $request->method(),
            'url'           => $request->fullUrl(),
            'route_name'    => $request->route()?->getName(),

            // DÉTAILS
            'payload'       => $this->sanitize($request->all()),
            'response_code' => $response->getStatusCode(),

            // CONTEXTE
            'session_id'    => session()->getId(),
            'referer'       => $request->header('referer'),
        ]);

        return $response;
    }

    protected function resolveAction(Request $request): string
    {
        if ($request->routeIs('login') && $request->isMethod('POST')) {
            return 'login';
        }
        if ($request->routeIs('logout')) {
            return 'logout';
        }

        return match ($request->method()) {
            'GET'    => 'visit',
            'POST'   => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default  => 'other',
        };
    }

    protected function sanitize(array $data): array
    {
        $hidden = ['password', 'password_confirmation', 'token', 'secret', '_token'];

        return collect($data)
            ->except($hidden)
            ->map(fn ($value) => is_string($value) && strlen($value) > 500
                ? substr($value, 0, 500) . '...[tronqué]'
                : $value
            )
            ->toArray();
    }

    protected function shouldSkip(Request $request): bool
    {
        return $request->is('livewire/*')
            || $request->is('_debugbar/*')
            || $request->ajax() && $request->isMethod('GET');
    }
}
```

Application sur les routes admin :

```php
Route::group([
    'as' => 'admin.',
    'middleware' => ['auth', 'team.access', 'audit-log'],
    'domain' => config('app.domain_back_office'),
], function () {
    // ...
});
```

#### Niveau 2 — Trait `Auditable` (mutations modèle)

Capture **les changements réels sur les données** — fonctionne aussi hors HTTP (Jobs, Artisan).

```php
// app/Support/Traits/Auditable.php
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => self::audit('created', $model));
        static::updated(fn ($model) => self::audit('updated', $model));
        static::deleted(fn ($model) => self::audit('deleted', $model));
    }

    protected static function audit(string $event, $model): void
    {
        AuditEntry::create([
            'event'   => $event,
            'model'   => $model::class,
            'id'      => $model->getKey(),
            'changes' => $model->getChanges(),
            'user_id' => auth()->id(),
        ]);
    }
}
```

#### Table `audit_entries`

```php
Schema::create('audit_entries', function (Blueprint $table) {
    $table->id();

    // QUI
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('user_name')->nullable();
    $table->unsignedBigInteger('team_id')->nullable();
    $table->string('ip', 45);
    $table->string('user_agent')->nullable();

    // QUOI
    $table->string('action');         // login, logout, visit, create, update, delete
    $table->string('method', 10);
    $table->text('url');
    $table->string('route_name')->nullable();

    // DÉTAILS
    $table->json('payload')->nullable();
    $table->smallInteger('response_code');

    // CONTEXTE
    $table->string('session_id')->nullable();
    $table->text('referer')->nullable();

    $table->timestamp('created_at');

    // Index pour les requêtes fréquentes
    $table->index('user_id');
    $table->index('action');
    $table->index('created_at');
    $table->index('session_id');
});
```

#### Requêtes d'audit courantes

| Question | Requête |
|----------|---------|
| Qui s'est connecté aujourd'hui ? | `AuditEntry::action('login')->today()->get()` |
| Que fait un utilisateur en ce moment ? | `AuditEntry::forUser($id)->today()->latest()->take(20)->get()` |
| Qui a supprimé ce device ? | `AuditEntry::action('delete')->where('url', 'like', '%device%')->get()` |
| Parcours complet d'une session | `AuditEntry::where('session_id', $sid)->orderBy('created_at')->get()` |

#### Purge automatique

```php
// Dans AuditEntry.php
use Prunable;

public function prunable(): Builder
{
    return static::where('created_at', '<', now()->subMonths(3));
}

// Scheduler
Schedule::command('model:prune', ['--model' => AuditEntry::class])->daily();
```

Conservation : **3 mois** pour les logs de navigation, **2 ans** pour les actions sensibles (login, create, update, delete sur entités critiques).
Immutabilité : table en append-only, pas de DELETE autorisé en dehors de la purge automatique.

### E. Sécurité des intégrations fournisseurs

```php
// Chaque appel API fournisseur doit :
// 1. Logger la requête (sans données sensibles) et la réponse (code HTTP)
// 2. Utiliser des timeouts stricts (30s max)
// 3. Valider le certificat SSL du fournisseur
// 4. Ne jamais stocker de credentials fournisseur en cache
// 5. Notifier en cas d'échec répété (circuit breaker)

// Pattern Circuit Breaker pour les APIs fournisseurs
class CircuitBreaker
{
    // Après 5 échecs consécutifs → circuit ouvert (stop les appels)
    // Après 60 secondes → half-open (1 essai)
    // Si succès → circuit fermé (reprise normale)
    // Évite de surcharger un fournisseur en panne et de ralentir l'app
}
```

## 3. CI/CD Security Pipeline

```yaml
# Étapes de sécurité intégrées au CI/CD

# 1. Scan des dépendances (CVE connues)
- name: Security audit
  run: composer audit

# 2. Analyse statique (SAST)
- name: Static analysis
  run: ./vendor/bin/phpstan analyse --level=6

# 3. Scan secrets accidentels
- name: Secret scanning
  uses: trufflesecurity/trufflehog@v3
  with:
    path: .

# 4. Vérification des en-têtes de sécurité
- name: Security headers check
  run: |
    # Vérifier CSP, HSTS, X-Frame-Options, etc.
    curl -sI https://staging.cekoya.fr | grep -E "^(Content-Security|Strict-Transport|X-Frame|X-Content-Type)"
```

### F. Sécurité multi-tenant (stancl/tenancy)

```php
// Risques spécifiques au multi-tenancy et mitigations

// 1. FUITE DE DONNÉES CROSS-TENANT
// Risque : une requête oublie le scope tenant → données d'une autre région exposées
// Mitigation :
// - stancl/tenancy force le switch de BDD au niveau middleware (pas de scope oubliable)
// - Tests automatisés : vérifier qu'aucune requête ne tape sur la BDD centrale par erreur
// - Middleware tenant obligatoire sur TOUTES les routes régionales (pas d'opt-in)

// 2. ESCALADE DE PRIVILÈGES VIA LE HUB
// Risque : un admin régional accède au Hub central ou à une autre région
// Mitigation :
// - Le Hub central a sa propre table users (pas partagée avec les tenants)
// - Les tokens SSO sont one-time-use, expirés après 5 minutes, signés JWT RS256
// - L'audit trail du Hub logge chaque accès cross-région avec IP + user_agent

// 3. INJECTION VIA LE NOM DE TENANT
// Risque : un sous-domaine malformé manipule le switch de BDD
// Mitigation :
// - Whitelist de tenants (table `tenants` dans la BDD centrale)
// - Pas de tenant dynamique basé sur l'input utilisateur
// - Validation regex sur le sous-domaine avant le switch

// 4. SYNC CATALOGUE EMPOISONNÉE
// Risque : le Hub pousse des données corrompues vers les régions
// Mitigation :
// - Checksums sur chaque payload de sync
// - La région valide le schema avant d'appliquer
// - Rollback automatique si la validation échoue
```

## 4. Plan d'action sécurité

| Priorité | Action | Effort | Impact |
|----------|--------|--------|--------|
| 🔴 P0 | Migrer vers le cloud (éliminer l'exposition du serveur local) | Inclus dans migration | CRITIQUE |
| 🔴 P0 | Externaliser les clés de chiffrement (KMS) | 1-2 jours | ÉLEVÉ |
| 🔴 P0 | Mettre en place l'audit trail | 1-2 jours | ÉLEVÉ |
| 🔴 P0 | Isolation multi-tenant (stancl/tenancy + BDD séparées) | Inclus dans migration | CRITIQUE |
| 🟠 P1 | WAF Cloudflare ou Scaleway Edge | 1 jour | ÉLEVÉ |
| 🟠 P1 | Rate limiting sur toutes les routes API | 1 jour | MOYEN |
| 🟠 P1 | CSP headers + HSTS | 0.5 jour | MOYEN |
| 🟠 P1 | Tests anti-fuite cross-tenant automatisés | 2 jours | ÉLEVÉ |
| 🟡 P2 | Scan de dépendances dans CI/CD | 0.5 jour | MOYEN |
| 🟡 P2 | Rotation automatique des secrets (par tenant) | 1-2 jours | MOYEN |
| 🟢 P3 | Pen test externe (incluant tests cross-tenant) | Budget externe | ÉLEVÉ |

## 5. Risques résiduels acceptés

| Risque | Justification de l'acceptation |
|--------|-------------------------------|
| Pas de chiffrement de bout en bout des CDR en transit interne | Les CDR transitent dans un VPC privé, chiffrement TLS entre services, le risque est faible |
| Pas de HSM dédié pour les clés | Disproportionné pour cette taille — KMS cloud est suffisant |
| Pas de SOC/SIEM | Budget et taille d'équipe ne le justifient pas — Sentry + audit trail + alertes suffisent |
| Toutes les BDD régionales sur le même serveur MySQL (Phase A) | Acceptable en Phase A car le MySQL managé Scaleway isole les databases. En Phase B, chaque région aura son propre serveur |
| Pas de chiffrement inter-BDD pour la sync catalogue | La sync se fait en interne via jobs Laravel (pas de transit réseau externe), le VPC protège |
