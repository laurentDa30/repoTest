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
// app/Modules/Shared/Traits/EncryptsAttributes.php
trait EncryptsAttributes
{
    // Colonnes chiffrées automatiquement en lecture/écriture
    // Utilise Laravel Crypt (AES-256-CBC)
    // Clé stockée dans KMS, pas dans .env

    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        if (in_array($key, $this->encrypted ?? []) && $value !== null) {
            return decrypt($value);
        }

        return $value;
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->encrypted ?? []) && $value !== null) {
            $value = encrypt($value);
        }

        return parent::setAttribute($key, $value);
    }
}

// Utilisation
class Client extends Model
{
    use EncryptsAttributes;

    protected array $encrypted = ['iban', 'bic', 'siret'];
}
```

#### Gestion des secrets

| Secret | Stockage V1 | Stockage V2 |
|--------|------------|------------|
| Clé APP_KEY | `.env` sur le serveur | Scaleway Secret Manager |
| Clés API fournisseurs | `.env` | Scaleway Secret Manager |
| Credentials BDD centrale | `.env` | Variables d'environnement du container (injectées au runtime) |
| Credentials BDD par tenant | N/A | Scaleway Secret Manager (un secret par région) |
| Tokens Yousign | `.env` | Scaleway Secret Manager |

### D. Audit Trail

```php
// Implémentation via spatie/laravel-activitylog

// Toute modification sur une entité sensible est loguée
activity()
    ->performedOn($client)
    ->causedBy($user)
    ->withProperties([
        'old' => $client->getOriginal(),
        'new' => $client->getAttributes(),
        'ip' => request()->ip(),
        'user_agent' => request()->userAgent(),
    ])
    ->log('updated');

// Consultation possible dans l'admin (menu Logs existant)
// Conservation : 2 ans minimum
// Immutabilité : table d'audit en append-only, pas de DELETE autorisé
```

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
// - Les tokens SSO sont one-time-use, expirés après 60 secondes, signés HMAC
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
