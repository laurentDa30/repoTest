# Vérifications et détection d'anomalies — V2

Ce document recense les vérifications existantes en V1 qui doivent être intégrées dans la V2, ainsi que les nouvelles vérifications à ajouter.

---

## 1. Anomalies sur les lignes (existant V1 — à migrer)

Ces vérifications existent déjà dans la V1 et doivent être portées dans le module **Telecom** de la V2. Elles alimentent une page d'erreurs dans le back-office admin.

| Code V1 | Description | Module V2 | Sévérité |
|---------|-------------|-----------|----------|
| `$lineClosedWithActivePlan` | Ligne fermée avec un forfait encore actif | Telecom | ÉLEVÉE |
| `$active_sims_on_line` | SIM active sur une ligne (vérification de cohérence) | Telecom | INFO |
| `$lineClosedWithActiveService` | Ligne fermée avec un service encore actif | Telecom | ÉLEVÉE |
| `$lineClosedWithActiveDevice` | Ligne fermée avec un appareil encore affecté | Telecom / Stock | MOYENNE |
| `$lineOpenWithMultiPlans` | Ligne ouverte avec plusieurs forfaits actifs simultanément | Telecom | CRITIQUE |
| `$linesWithoutProvider` | Ligne sans fournisseur assigné | Telecom | ÉLEVÉE |
| `$linesWithoutActivatedAt` | Ligne sans date d'activation | Telecom | MOYENNE |
| `$linesAndSimsNotSameUsage` | Ligne et SIM avec type d'usage incohérent (ex: SIM data sur ligne voix) | Telecom | ÉLEVÉE |
| `$linesActivesWithSimDeactivated` | Ligne active avec SIM désactivée | Telecom | CRITIQUE |
| `$linesWithoutPlanWithCalls` | Ligne sans forfait mais qui génère des CDR (appels/data) | Telecom / CDR | CRITIQUE |

### Implémentation V2

```php
// app/Modules/Telecom/Jobs/DetectLineAnomaliesJob.php
// Exécuté quotidiennement via le scheduler

class DetectLineAnomaliesJob implements ShouldQueue
{
    public function handle(LineAnomalyDetector $detector): void
    {
        $anomalies = collect();

        // Lignes fermées avec forfait actif
        $anomalies = $anomalies->merge(
            $detector->findClosedLinesWithActivePlan()
        );

        // Lignes fermées avec service actif
        $anomalies = $anomalies->merge(
            $detector->findClosedLinesWithActiveService()
        );

        // Lignes fermées avec appareil encore affecté
        $anomalies = $anomalies->merge(
            $detector->findClosedLinesWithActiveDevice()
        );

        // Lignes ouvertes avec plusieurs forfaits actifs
        $anomalies = $anomalies->merge(
            $detector->findOpenLinesWithMultiplePlans()
        );

        // Lignes sans fournisseur
        $anomalies = $anomalies->merge(
            $detector->findLinesWithoutProvider()
        );

        // Lignes sans date d'activation
        $anomalies = $anomalies->merge(
            $detector->findLinesWithoutActivatedAt()
        );

        // Ligne et SIM avec usage incohérent
        $anomalies = $anomalies->merge(
            $detector->findLinesAndSimsUsageMismatch()
        );

        // Lignes actives avec SIM désactivée
        $anomalies = $anomalies->merge(
            $detector->findActiveLinesWithDeactivatedSim()
        );

        // Lignes sans forfait mais avec CDR
        $anomalies = $anomalies->merge(
            $detector->findLinesWithoutPlanWithCalls()
        );

        // Stocker les anomalies dans la table errors (polymorphique)
        // et notifier les admins si nouvelles anomalies critiques
        $detector->storeAndNotify($anomalies);
    }
}
```

### Page Erreurs (Livewire 4)

La page `/admin/errors/lines` affiche toutes les anomalies détectées, filtrables par :
- Type d'anomalie
- Sévérité (critique, élevée, moyenne, info)
- Client
- Date de détection
- Statut (nouvelle, en cours de résolution, résolue, ignorée)

---

## 2. Anomalies sur le matériel (existant V1 — à migrer)

Vérifications existantes sur les appareils/matériels, à porter dans le module **Stock**.

| Vérification | Description | Module V2 |
|-------------|-------------|-----------|
| Appareil affecté à un client inexistant | FK `device_client.client_id` pointe vers un client supprimé/inactif | Stock |
| Appareil affecté à une ligne fermée | `device_line` pointe vers une ligne fermée | Stock / Telecom |
| Appareil en stock mais affecté | Appareil dans `device_stock` ET dans `device_client` simultanément | Stock |
| Appareil sans numéro de série | `devices.serial` est NULL ou vide | Stock |
| Appareil avec état incohérent | État dans `states` ne correspond pas à l'affectation courante | Stock |

### Implémentation V2

```php
// app/Modules/Stock/Jobs/DetectDeviceAnomaliesJob.php
class DetectDeviceAnomaliesJob implements ShouldQueue
{
    public function handle(DeviceAnomalyDetector $detector): void
    {
        // Appareils affectés à des clients inactifs
        // Appareils affectés à des lignes fermées
        // Appareils doublement affectés (stock + client)
        // Appareils sans serial
        // Incohérence état vs affectation
    }
}
```

---

## 3. Vérifications quotidiennes — Forfaits, Quotas et Hors-forfait (existant V1 — à migrer)

Ces vérifications tournent quotidiennement dans la V1 et doivent être portées dans les modules **CDR**, **Telecom**, **IoT** et **UCaaS**.

### 3.1 Dépassements de Data hors-forfait (lignes non-IoT)

Objectif : détecter les lignes mobiles classiques qui dépassent leur enveloppe data.

```php
// app/Modules/CDR/Jobs/DetectDataOverageJob.php
// Exécuté quotidiennement

class DetectDataOverageJob implements ShouldQueue
{
    public function handle(): void
    {
        // 1. Pour chaque ligne active NON-IoT avec un forfait actif :
        //    a. Récupérer l'enveloppe data du forfait (plan.data_allowance)
        //    b. Récupérer la consommation data du mois en cours
        //       (depuis daily_call_summaries ou monthly_summaries)
        //    c. Si conso > enveloppe → alerte "hors-forfait data"
        //    d. Si conso > 80% enveloppe → alerte "dépassement imminent"

        // 2. Stocker les alertes dans la table `alerts`
        // 3. Notification admin si dépassement confirmé
        // 4. Optionnel : notification client (portail client)
    }
}
```

### 3.2 Dépassements de quota SIMs IoT

Objectif : les SIMs IoT ont des quotas spécifiques (souvent très bas). Un dépassement peut indiquer un dysfonctionnement ou un abus.

```php
// app/Modules/IoT/Jobs/DetectIoTQuotaOverageJob.php
// Exécuté quotidiennement

class DetectIoTQuotaOverageJob implements ShouldQueue
{
    public function handle(): void
    {
        // 1. Pour chaque SIM de type IoT avec un quota défini :
        //    a. Récupérer le quota mensuel (plan.iot_data_quota)
        //    b. Récupérer la consommation data du mois
        //    c. Si conso > quota → alerte "dépassement IoT"
        //    d. Calcul du surcoût potentiel

        // 2. Les SIMs IoT n'ont généralement pas de hors-forfait voix/SMS
        //    → si des appels/SMS sont détectés sur une SIM IoT → anomalie
    }
}
```

### 3.3 Vérifications de cohérence Forfaits

```php
// app/Modules/Telecom/Jobs/DetectPlanAnomaliesJob.php
// Exécuté quotidiennement

class DetectPlanAnomaliesJob implements ShouldQueue
{
    public function handle(): void
    {
        // 1. Forfaits expirés mais encore actifs (line_plan.end_date passée)
        // 2. Forfaits sans tarification définie (plan sans plan_rates)
        // 3. Lignes avec forfait mais sans consommation depuis 30+ jours
        //    (potentielle SIM inactive non détectée)
        // 4. Forfaits avec dates incohérentes (start > end)
    }
}
```

### 3.4 Vérifications UCaaS (Wazo)

```php
// app/Modules/UCaaS/Jobs/DetectUCaaSAnomaliesJob.php
// Exécuté quotidiennement

class DetectUCaaSAnomaliesJob implements ShouldQueue
{
    public function handle(): void
    {
        // 1. Postes UCaaS actifs sans consommation depuis 30+ jours
        //    (poste facturé mais inutilisé)
        // 2. Pics anormaux d'appels sortants (potentielle fraude VoIP)
        // 3. Appels internationaux sur des postes sans autorisation
        // 4. Postes en erreur (pas de ping Wazo depuis 24h)
        // 5. Licences Wazo actives sans poste associé
    }
}
```

---

## 4. Vérifications IA — Phase 2+ (nouveau V2)

Voir `10-FONCTIONNALITES-IA.md` pour le détail. Résumé :

| Vérification | Phase | Source de données |
|-------------|-------|-------------------|
| Détection anomalies CDR (pic soudain, fraude) | Phase 1 | `daily_call_summaries` |
| Suggestions optimisation forfait | Phase 2 | `monthly_summaries` + `plans` |
| Scoring prospect | Phase 2 | `prospects` (historique) |
| Prédiction churn | Phase 4+ | Multi-source (12 mois d'historique) |

---

## 5. Architecture commune : table `alerts`

Toutes les anomalies détectées (lignes, matériel, forfaits, CDR, IoT) doivent être centralisées dans une table `alerts` existante, enrichie si nécessaire.

```sql
-- Structure cible de la table alerts (enrichie V2)
CREATE TABLE `alerts` (
    `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
    `alertable_type` varchar(255) NOT NULL,     -- 'line', 'device', 'sim', 'client'
    `alertable_id` bigint UNSIGNED NOT NULL,
    `type` varchar(100) NOT NULL,               -- 'line_closed_with_active_plan', 'data_overage', etc.
    `severity` enum('critical','high','medium','info') NOT NULL DEFAULT 'medium',
    `title` varchar(255) NOT NULL,
    `description` text DEFAULT NULL,
    `meta` json DEFAULT NULL,                   -- Données contextuelles (ex: conso actuelle, quota, etc.)
    `status` enum('new','acknowledged','resolving','resolved','ignored') NOT NULL DEFAULT 'new',
    `resolved_at` timestamp NULL DEFAULT NULL,
    `resolved_by` bigint UNSIGNED DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_alertable` (`alertable_type`, `alertable_id`),
    KEY `idx_type_status` (`type`, `status`),
    KEY `idx_severity_status` (`severity`, `status`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB;
```

### Scheduler V2

```php
// app/Console/Kernel.php (ou routes/console.php en Laravel 12)

// Vérifications quotidiennes (02:00 après l'agrégation CDR)
Schedule::job(new DetectLineAnomaliesJob)->dailyAt('02:30');       // Module Telecom
Schedule::job(new DetectDeviceAnomaliesJob)->dailyAt('02:45');     // Module Stock
Schedule::job(new DetectPlanAnomaliesJob)->dailyAt('03:00');       // Module Telecom
Schedule::job(new DetectDataOverageJob)->dailyAt('03:15');         // Module CDR (mobile)
Schedule::job(new DetectIoTQuotaOverageJob)->dailyAt('03:30');     // Module IoT
Schedule::job(new DetectUCaaSAnomaliesJob)->dailyAt('03:45');      // Module UCaaS

// Vérifications IA (après les vérifications classiques)
Schedule::job(new DetectCDRAnomaliesJob)->dailyAt('04:00');        // Module IA
```
