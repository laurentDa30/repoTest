# 🤖 Fonctionnalités IA — Cekoya V2

## Plateforme IA d'entreprise

**Infrastructure** : NVIDIA Shark (appliance IA on-premise ou cloud privé)
- Permet l'inférence locale sans envoi de données sensibles vers des services tiers
- Compatible avec les modèles open-source (Llama, Mistral, etc.)
- Souveraineté des données garantie (RGPD, données télécom sensibles)
- GPU dédié pour l'inférence, pas d'impact sur les serveurs applicatifs

**Intégration avec la V2** : le module `IA/` communique avec le NVIDIA Shark via API REST interne. Les données sensibles (CDR, clients) ne quittent jamais l'infrastructure.

```
┌──────────────────────────────────────────────────────┐
│                   CEKOYA V2                           │
│                                                       │
│  ┌──────────┐  ┌──────────┐  ┌──────────────────┐   │
│  │ Module   │  │ Module   │  │ Module IA        │   │
│  │ CDR      │  │ Billing  │  │ (orchestrateur)  │   │
│  └────┬─────┘  └────┬─────┘  └────────┬─────────┘   │
│       │              │                 │              │
│       └──────────────┴─────────────────┘              │
│                      │                                │
│                      ▼                                │
│              ┌───────────────┐                        │
│              │ Queue IA      │                        │
│              │ (Redis)       │                        │
│              └───────┬───────┘                        │
└──────────────────────┼────────────────────────────────┘
                       │ API REST interne
                       ▼
              ┌───────────────────┐
              │   NVIDIA Shark    │
              │                   │
              │ ├─ Anomaly model  │
              │ ├─ NLP model      │
              │ └─ Scoring model  │
              └───────────────────┘
```

---

## Fonctionnalités IA — Priorisées par phase

### 1. Détection d'anomalies CDR (Phase 1 — Quick win)

**Objectif** : Détecter automatiquement les consommations anormales (hors forfait, pics soudains, fraude potentielle).

**Approche** : Statistique simple d'abord (écart-type sur historique), puis modèle ML sur le NVIDIA Shark si nécessaire.

**Données utilisées** :
- `daily_call_summaries` (agrégation quotidienne par ligne)
- `monthly_summaries` (tendances historiques)
- Seuils par forfait (depuis le module `Catalog`)

**Implémentation** :

```php
// app/Modules/IA/Jobs/DetectCDRAnomaliesJob.php
// Exécuté chaque nuit après l'agrégation CDR

class DetectCDRAnomaliesJob implements ShouldQueue
{
    public function handle(AnomalyDetectionService $detector): void
    {
        // 1. Pour chaque ligne active, comparer conso J-1 vs moyenne 30 jours
        // 2. Si écart > 3x écart-type → alerte "anomalie"
        // 3. Si hors forfait > seuil configuré → alerte "dépassement"
        // 4. Notification admin + email client (optionnel)
    }
}
```

**Alertes générées** :
- Consommation data inhabituelle (> 3x la moyenne)
- Pic d'appels internationaux (potentielle fraude)
- Dépassement forfait imminent (prévention)
- Ligne inactive soudainement active (SIM compromise ?)

**Valeur business** : Réduction des litiges clients, détection de fraude précoce, proactivité dans la relation client.

**Complexité** : Faible — basé sur des statistiques simples, pas de ML requis initialement.

---

### 2. Suggestions d'optimisation de forfait (Phase 2)

**Objectif** : Recommander le forfait optimal pour chaque ligne/client en fonction de l'usage réel.

**Approche** : Analyse de l'historique de consommation vs grille tarifaire. Le NVIDIA Shark peut faire tourner un modèle de classification plus fin.

**Données utilisées** :
- `monthly_summaries` (6 derniers mois de conso par ligne)
- `daily_call_summaries` (patterns d'usage)
- Catalogue des forfaits disponibles (module `Catalog`)
- Prix actuels vs prix théoriques (module `Billing`)

**Implémentation** :

```php
// app/Modules/IA/Services/PlanOptimizationService.php

class PlanOptimizationService
{
    public function suggestOptimalPlan(Line $line): PlanSuggestion
    {
        // 1. Récupérer conso réelle des 6 derniers mois
        // 2. Simuler le coût sur chaque forfait disponible
        // 3. Identifier le forfait le plus économique
        // 4. Calculer l'économie potentielle
        // 5. Retourner la suggestion avec justification
    }
}
```

**UI (Livewire)** :
- Widget dans le dashboard admin : "X lignes pourraient économiser Y€/mois"
- Page détaillée : liste des suggestions par client, avec économies estimées
- Action en 1 clic : proposer le changement au client

**Valeur business** : Augmentation de la satisfaction client (réduction de facture), argument commercial fort, réduction du churn.

**Complexité** : Moyenne — nécessite la grille tarifaire complète et le module Catalog.

---

### 3. Scoring prospect (Phase 2)

**Objectif** : Attribuer un score de probabilité de conversion à chaque prospect.

**Approche** : Modèle de scoring basé sur les caractéristiques du prospect (secteur, taille, localisation, source) et l'historique des conversions. Le NVIDIA Shark fait tourner un modèle de classification binaire (converti / pas converti).

**Données utilisées** :
- Historique prospects (module `Prospect`) : convertis vs perdus
- Caractéristiques : secteur d'activité, nombre de collaborateurs, localisation, source de contact
- Données enrichies : taille du parc télécom estimé, fournisseur actuel (si connu)

**Implémentation** :

```php
// app/Modules/IA/Services/ProspectScoringService.php

class ProspectScoringService
{
    public function score(Prospect $prospect): ProspectScore
    {
        // 1. Extraire les features du prospect
        // 2. Appeler le modèle sur NVIDIA Shark via API
        // 3. Retourner score (0-100) + facteurs contributifs
    }
}
```

**UI (Livewire)** :
- Badge de score sur chaque fiche prospect (🔴 < 30, 🟡 30-70, 🟢 > 70)
- Tri des prospects par score dans la liste
- Indicateurs des facteurs les plus prédictifs

**Valeur business** : Priorisation de l'effort commercial, meilleur taux de conversion, gains de productivité commerciale.

**Complexité** : Moyenne — nécessite un volume de données historiques suffisant (~200+ prospects avec résultat).

---

### 4. Assistant admin IA (Phase 3)

**Objectif** : Chatbot interne accessible depuis le back-office, capable de répondre à des questions sur les données et d'exécuter des actions.

**Approche** : LLM déployé sur NVIDIA Shark + function-calling pour interagir avec l'API interne. Le modèle a accès aux données du tenant courant uniquement.

**Capacités** :
- **Requêtes en langage naturel** : "Quel est le top 10 des clients par consommation ce mois ?" → requête sur `monthly_summaries`
- **Actions guidées** : "Suspend la ligne 06 12 34 56 78" → confirmation → appel API interne
- **Résumés automatiques** : "Résume l'activité de la semaine" → agrégation multi-source
- **Aide contextuelle** : "Comment ajouter une nouvelle région ?" → documentation interne

**Implémentation** :

```php
// app/Modules/IA/Services/AdminAssistantService.php

class AdminAssistantService
{
    // Functions disponibles pour le LLM (function-calling)
    private array $tools = [
        'get_client_info',          // Récupérer infos client
        'get_consumption_summary',  // Résumé conso
        'list_anomalies',           // Anomalies détectées
        'get_invoice_status',       // Statut facture
        'search_lines',             // Rechercher des lignes
        'suspend_line',             // Suspendre une ligne (avec confirmation)
    ];

    public function chat(string $message, User $user): AssistantResponse
    {
        // 1. Envoyer le message + contexte utilisateur au LLM (NVIDIA Shark)
        // 2. Le LLM décide s'il doit appeler une function ou répondre directement
        // 3. Si function-call → exécuter via les Services existants
        // 4. Retourner la réponse formatée
    }
}
```

**UI (Livewire)** :
- Icône chat en bas à droite du back-office (style chatbot)
- Conversation persistante par session
- Historique des conversations consultable
- Actions critiques (suspension, modification) avec confirmation explicite

**Sécurité** :
- Le LLM n'accède qu'aux données du tenant courant (isolation stancl/tenancy)
- Les actions destructives nécessitent une confirmation utilisateur
- Audit trail de toutes les actions exécutées via l'assistant
- Rate limiting sur les requêtes au NVIDIA Shark

**Valeur business** : Productivité des équipes internes, accès rapide aux données sans navigation complexe, réduction du temps de formation.

**Complexité** : Élevée — nécessite le NVIDIA Shark opérationnel, le function-calling, et une couverture API interne suffisante.

---

### 5. Prédiction de churn (Phase 4+)

**Objectif** : Identifier les clients à risque de départ avant qu'ils ne résilient.

**Approche** : Modèle prédictif sur le NVIDIA Shark, entraîné sur l'historique des résiliations et les signaux faibles.

**Signaux pris en compte** :
- Baisse progressive de consommation sur 3+ mois
- Tickets support non résolus / temps de résolution élevé
- Retards de paiement répétés
- Absence de connexion au portail client
- Lignes inactives croissantes
- Devis non convertis
- Changement de contact principal

**Implémentation** :

```php
// app/Modules/IA/Jobs/PredictChurnJob.php
// Exécuté mensuellement

class PredictChurnJob implements ShouldQueue
{
    public function handle(ChurnPredictionService $predictor): void
    {
        // 1. Pour chaque client actif, extraire les features (6 mois d'historique)
        // 2. Envoyer au modèle sur NVIDIA Shark
        // 3. Stocker le score de risque (0-100)
        // 4. Alerter le commercial si score > 70
    }
}
```

**UI (Livewire)** :
- Dashboard dédié : "Clients à risque" avec score et facteurs
- Timeline du risque par client (évolution du score mois par mois)
- Actions recommandées par client (appel, promotion, revue de forfait)

**Valeur business** : Rétention clients proactive, réduction du taux de churn, augmentation de la LTV.

**Complexité** : Élevée — nécessite un volume de données historiques significatif et un modèle entraîné.

---

## Résumé et roadmap IA

| # | Fonctionnalité | Phase | Complexité | Pré-requis | Valeur business |
|---|---------------|-------|------------|------------|-----------------|
| 1 | Détection anomalies CDR | Phase 1 (Q1) | Faible | `daily_call_summaries` | Fraude, litiges |
| 2 | Optimisation forfaits | Phase 2 (Q2) | Moyenne | Catalogue complet, 6 mois de données | Satisfaction, rétention |
| 3 | Scoring prospect | Phase 2 (Q2) | Moyenne | ~200+ prospects historiques, NVIDIA Shark | Conversion commerciale |
| 4 | Assistant admin IA | Phase 3 (Q3) | Élevée | API interne complète, NVIDIA Shark, LLM | Productivité interne |
| 5 | Prédiction churn | Phase 4+ (Q4) | Élevée | 12+ mois d'historique, NVIDIA Shark | Rétention, LTV |

### Architecture du module IA

```
app/Modules/IA/
├── Domain/
│   ├── Models/
│   │   ├── Anomaly.php              # Anomalie détectée
│   │   ├── PlanSuggestion.php       # Suggestion de forfait
│   │   ├── ProspectScore.php        # Score prospect
│   │   └── ChurnRisk.php            # Risque de churn
│   ├── Events/
│   │   ├── AnomalyDetected.php
│   │   ├── ChurnRiskElevated.php
│   │   └── PlanSuggestionGenerated.php
│   └── Contracts/
│       └── AIGateway.php            # Interface vers NVIDIA Shark
│
├── Application/
│   ├── Services/
│   │   ├── AnomalyDetectionService.php
│   │   ├── PlanOptimizationService.php
│   │   ├── ProspectScoringService.php
│   │   ├── AdminAssistantService.php
│   │   └── ChurnPredictionService.php
│   └── DTOs/
│       ├── AnomalyDTO.php
│       ├── PlanSuggestionDTO.php
│       └── ProspectScoreDTO.php
│
├── Infrastructure/
│   ├── Gateways/
│   │   └── NvidiaSharkGateway.php   # Client API NVIDIA Shark
│   ├── Http/Controllers/
│   │   └── AssistantController.php
│   ├── Jobs/
│   │   ├── DetectCDRAnomaliesJob.php
│   │   └── PredictChurnJob.php
│   └── Providers/
│       └── IAServiceProvider.php
│
└── routes.php
```

### Coûts estimés

| Poste | Estimation |
|-------|-----------|
| NVIDIA Shark (achat/leasing) | Variable selon modèle — consulter NVIDIA |
| Consommation électrique | ~50-100€/mois (GPU actif) |
| Maintenance / updates modèles | Inclus dans le temps dev |
| **Total récurrent** | **~50-100€/mois** (hors achat/leasing matériel) |

> **Note** : La fonctionnalité 1 (anomalies CDR) ne nécessite pas le NVIDIA Shark — elle fonctionne avec des statistiques simples côté Laravel. Les fonctionnalités 2 à 5 tirent parti du NVIDIA Shark pour des modèles plus sophistiqués.

---

## Points d'attention

1. **Données suffisantes** : les modèles ML nécessitent un historique. Commencer par la détection d'anomalies (statistique) pendant que les données s'accumulent pour les modèles prédictifs.
2. **RGPD** : les données envoyées au NVIDIA Shark restent on-premise → pas de transfert vers des tiers. Documenter le traitement IA dans le registre RGPD.
3. **Explicabilité** : chaque prédiction doit être accompagnée des facteurs explicatifs (pas de "boîte noire"). Important pour la confiance des équipes commerciales.
4. **Fallback** : si le NVIDIA Shark est indisponible, les fonctionnalités IA sont désactivées gracieusement — le reste de la plateforme continue de fonctionner normalement.
5. **Multi-tenant** : les modèles sont partagés (un seul modèle) mais les données d'entraînement et d'inférence sont strictement isolées par tenant (région).
