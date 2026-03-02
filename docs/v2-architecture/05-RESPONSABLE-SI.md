# 📊 Responsable d'Études SI — Alignement Stratégique

## 1. Contexte business

Cekoya est un opérateur télécom B2B en croissance (objectif : doubler le parc de 380 à 760+ clients). La plateforme est l'outil **central** de l'activité — ce n'est pas un outil support, c'est le coeur métier. Toute indisponibilité = perte directe de capacité opérationnelle.

## 2. Analyse des risques projet

### Risque n°1 : La V2 cannibalise la V1 sans la remplacer

| Indicateur | Seuil d'alerte |
|------------|----------------|
| Modules V2 en production | < 2 après 6 mois → projet en dérive |
| Bugs de régression V1 | > 5/mois pendant migration → instabilité |
| Utilisation de la V2 par les équipes | < 50% après mise en prod d'un module → problème d'adoption |

**Mitigation** : Approche Strangler Fig stricte. Chaque module migré doit être **complètement fonctionnel** avant de passer au suivant. Pas de module à moitié migré.

### Risque n°2 : Surcharge de l'équipe de 2 devs

La V2 demande simultanément :
- Maintenance V1 (bugs, évolutions urgentes)
- Développement V2 (migration progressive)
- Montée en compétences (Docker, Vue 3, Tailwind, CI/CD)

**Recommandation** : Allouer un ratio **70% V2 / 30% maintenance V1**. Si la maintenance V1 dépasse 40%, c'est un signal d'alarme — il faut soit recruter, soit réduire le scope V2.

### Risque n°3 : Continuité de service

Pendant la migration, les deux systèmes coexistent. La base de données est partagée. Un déploiement V2 ne doit **jamais** casser la V1.

**Contrainte absolue** : Les migrations de schéma doivent être rétro-compatibles. Pas de suppression de colonne tant que la V1 l'utilise.

## 3. Gouvernance du projet

### Priorisation des modules à migrer

L'ordre de migration doit être guidé par l'**impact business**, pas par la facilité technique.

| Priorité | Module | Justification |
|----------|--------|---------------|
| 🔴 P0 | **CDR / Consommations** | Problème de performance critique (12 Go), impacte tous les dashboards |
| 🔴 P0 | **Infrastructure** (Docker, CI/CD, monitoring) | Pré-requis technique pour tout le reste |
| 🟠 P1 | **Facturation / Finance** | Coeur de la valeur business, table `invoices` problématique |
| 🟠 P1 | **Intégrations fournisseurs** | L'isolation des connecteurs réduit les risques d'incidents en cascade |
| 🟡 P2 | **Catalogue** | Structurant mais moins urgent (fonctionne en V1) |
| 🟡 P2 | **Gestion clients** | Volume de code important mais moins critique |
| 🟢 P3 | **Portail client** (Vue 3) | UX importante mais secondaire par rapport au coeur opérationnel |
| 🟢 P3 | **Portail ambassadeur** | Plus petit périmètre, peut attendre |
| 🟢 P3 | **Module Environnement** | Non critique pour le business telecom |

### Indicateurs de suivi (KPIs)

| KPI | Cible | Fréquence |
|-----|-------|-----------|
| Couverture de tests | > 60% sur les nouveaux modules | Hebdomadaire |
| Temps de réponse moyen | < 500ms (P95 < 2s) | Temps réel |
| Disponibilité plateforme | > 99.5% | Mensuel |
| Taux d'erreur CDR import | < 0.1% | Quotidien |
| Temps de déploiement | < 15 minutes (auto) | Par déploiement |
| Nombre d'incidents | Tendance décroissante | Mensuel |

## 4. Analyse coûts

### Coûts actuels estimés (hébergement local)

| Poste | Estimation |
|-------|-----------|
| Serveur physique (amortissement) | ~50-100€/mois |
| Électricité + réseau | ~30-50€/mois |
| Maintenance matérielle (risque de panne) | Variable |
| Pusher | ~50-100€/mois selon usage |
| Yousign | Variable |
| **Pas de monitoring, pas de backup off-site** | **Risque non chiffré** |
| **Total visible** | **~150-250€/mois** |
| **Coût caché (risque de panne)** | **Non estimé — potentiellement très élevé** |

### Coûts V2 projetés

| Poste | Estimation |
|-------|-----------|
| Infrastructure Scaleway | ~150-170€/mois |
| Pusher → Reverb | 0€ (self-hosted) |
| GitHub Actions CI/CD | 0€ (free tier suffisant) |
| Sentry (monitoring erreurs) | 0€ (free tier) ou 26€/mois (team) |
| Yousign | Inchangé |
| Laravel Shift (upgrade unique) | ~100€ (one-time) |
| **Total récurrent** | **~150-200€/mois** |

**Conclusion coûts** : Le passage au cloud est **iso-coût ou légèrement moins cher** que l'hébergement local, avec un niveau de résilience et de service **incomparablement supérieur**. Le vrai gain est dans l'élimination du risque de panne matérielle.

### Coût de développement

Avec 2 devs sur 10-12 mois de migration progressive, le coût principal est le temps-homme. Aucun investissement logiciel significatif (stack open-source).

## 5. Stratégie de recrutement

Si le parc double comme prévu :
- **Court terme (0-6 mois)** : les 2 devs suffisent si le ratio 70/30 est respecté
- **Moyen terme (6-12 mois)** : recruter un **3ème développeur fullstack Laravel** pour absorber la croissance
- **Long terme (12+ mois)** : évaluer le besoin d'un profil DevOps à mi-temps (freelance ou prestation) si la complexité infra augmente

## 6. Plan de continuité des données

### La base de données MySQL est partagée entre V1 et V2

C'est **voulu** et c'est la clé de la migration progressive :

```
Phase V1 seule :
  V1 ──────► MySQL

Phase cohabitation :
  V1 ──────► MySQL ◄────── V2 (nouveaux modules)

Phase V2 complète :
               MySQL ◄────── V2
```

**Règles de cohabitation** :
1. Nouvelles tables → conventions V2 (préfixées par module si nécessaire)
2. Tables existantes → modifications additives uniquement (ajout de colonnes, pas de suppression)
3. Les anciennes colonnes ne sont supprimées qu'une fois la V1 complètement éteinte
4. Les migrations V2 sont réversibles (`down()` fonctionnel)

## 7. Conformité réglementaire

### RGPD
- **Données personnelles identifiées** : noms, téléphones, emails, adresses, IBAN (SEPA), historique d'appels (CDR)
- **Base légale** : exécution du contrat (B2B)
- **Durée de conservation** :
  - CDR détaillés : 12 mois opérationnel, puis agrégé/anonymisé
  - Données de facturation : 10 ans (obligation comptable)
  - Données clients : durée du contrat + 3 ans (prescription)
- **Action requise** : implémenter une commande artisan de purge automatique conforme à ces durées

### ARCEP
- Si Cekoya est déclaré comme opérateur auprès de l'ARCEP, des obligations de conservation et de mise à disposition des données CDR peuvent s'appliquer
- **Action requise** : vérifier le statut déclaratif de Cekoya auprès de l'ARCEP et identifier les obligations spécifiques

---

# 🔍 Validation finale collective

## Consensus de l'équipe

| Point | Consensus |
|-------|-----------|
| Architecture monolithe modulaire | ✅ Unanime — microservices seraient suicidaires avec 2 devs |
| Laravel 12 + Livewire 3 | ✅ Unanime — continuité, productivité |
| Vue 3 / Inertia pour portails | ✅ Majoritaire — désaccord mineur du backend (préfère tout-Livewire), arbitrage : Inertia apporte la flexibilité future sans complexité API immédiate |
| MySQL 8 (pas PostgreSQL) | ✅ Majoritaire — cybersécurité préfère PG (meilleures options de chiffrement), arbitrage : le coût de migration + apprentissage ne justifie pas le gain |
| Scaleway | ✅ Unanime — meilleur rapport service/coût/souveraineté |
| Migration progressive | ✅ Unanime — big-bang = mort du projet |

## Désaccords documentés

### Désaccord 1 : MySQL vs PostgreSQL
- **Pour PostgreSQL** (cybersécurité) : meilleur Row Level Security natif, extensions pgcrypto, JSONB pour données semi-structurées, TimescaleDB pour CDR
- **Pour MySQL** (architecte, backend, DevOps) : maîtrisé par l'équipe, partitionnement natif suffisant, pas de migration à risque, écosystème Laravel optimisé pour MySQL
- **Arbitrage** : MySQL 8 — le risque de migration de base de données est disproportionné par rapport au gain pour cette volumétrie

### Désaccord 2 : Tout Livewire vs Livewire + Vue
- **Tout Livewire** (backend) : une seule stack à maintenir, pas de build JS, équipe backend-first
- **Livewire admin + Vue portails** (frontend, architecte) : UX client doit être supérieure, Vue permet des interactions complexes (configurateur forfait), prépare l'éventuelle app mobile
- **Arbitrage** : dual-stack — les portails externes justifient Vue, l'admin reste Livewire. Le surcoût de maintenance est acceptable car les composants partagent le design system Tailwind.
