# 🔐 Expert Cybersécurité — Analyse & Recommandations

## 1. Diagnostic de l'existant

### Surface d'attaque actuelle

| Vecteur | Niveau de risque | Détail |
|---------|-----------------|--------|
| **Serveur local (on-premise)** | CRITIQUE | Pas de WAF, pas de DDoS protection, exposition directe |
| **Chiffrement "dans la même base"** | ÉLEVÉ | Clés probablement dans `.env`, même machine = un seul point de compromission |
| **3 portails, 1 application** | ÉLEVÉ | Une faille sur le portail client impacte l'admin |
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
// Matrice d'authentification par portail
//
// Portail Admin (prod.cekoya.fr)
// ├── Session Laravel + MFA obligatoire
// ├── Session timeout : 30 min d'inactivité
// ├── IP allowlisting optionnel (si accès bureaux uniquement)
// └── Audit log de chaque connexion
//
// Portail Client (client.cekoya.fr)
// ├── Session Laravel + MFA recommandé
// ├── Session timeout : 60 min
// ├── Password policy : min 12 chars, pas de common passwords
// └── Notification email à chaque connexion depuis un nouvel appareil
//
// Portail Ambassadeur (amba.cekoya.fr)
// ├── Session Laravel + MFA recommandé
// └── Accès limité (données financières ambassadeur uniquement)
//
// API interne
// ├── Laravel Sanctum avec token scoping
// ├── Tokens avec expiration (7 jours max)
// └── Rate limiting strict (60 req/min par défaut, ajustable par route)
```

#### Autorisation — RBAC + Policies

```php
// app/Modules/Auth/Models/Permission.php
// Granularité : module.action (ex: clients.view, billing.export, cdr.view)

// Rôles par défaut
// admin         → toutes permissions
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
| Credentials BDD | `.env` | Variables d'environnement du container (injectées au runtime) |
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

## 4. Plan d'action sécurité

| Priorité | Action | Effort | Impact |
|----------|--------|--------|--------|
| 🔴 P0 | Migrer vers le cloud (éliminer l'exposition du serveur local) | Inclus dans migration | CRITIQUE |
| 🔴 P0 | Externaliser les clés de chiffrement (KMS) | 1-2 jours | ÉLEVÉ |
| 🔴 P0 | Mettre en place l'audit trail | 1-2 jours | ÉLEVÉ |
| 🟠 P1 | WAF Cloudflare ou Scaleway Edge | 1 jour | ÉLEVÉ |
| 🟠 P1 | Rate limiting sur toutes les routes API | 1 jour | MOYEN |
| 🟠 P1 | CSP headers + HSTS | 0.5 jour | MOYEN |
| 🟡 P2 | Scan de dépendances dans CI/CD | 0.5 jour | MOYEN |
| 🟡 P2 | Rotation automatique des secrets | 1 jour | MOYEN |
| 🟢 P3 | Pen test externe | Budget externe | ÉLEVÉ |

## 5. Risques résiduels acceptés

| Risque | Justification de l'acceptation |
|--------|-------------------------------|
| Pas de chiffrement de bout en bout des CDR en transit interne | Les CDR transitent dans un VPC privé, chiffrement TLS entre services, le risque est faible |
| Pas de HSM dédié pour les clés | Disproportionné pour cette taille — KMS cloud est suffisant |
| Pas de SOC/SIEM | Budget et taille d'équipe ne le justifient pas — Sentry + audit trail + alertes suffisent |
