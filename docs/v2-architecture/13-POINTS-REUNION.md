# Points a aborder en reunion — V2

Liste des sujets qui necessitent une decision collective avant implementation.

---

## Points en attente de decision

### 1. `direct_debit_accounts` — Central ou Regional ?

**Contexte** : Les `direct_debit_accounts` contiennent les IBAN de la societe Cekoya pour percevoir les prelevements SEPA des clients.

**Question** : Est-ce que chaque region aura son propre compte bancaire pour les prelevements, ou est-ce qu'un seul compte centralisé est utilise pour toutes les regions ?

**Options** :
- **A) Central uniquement** : un seul IBAN Cekoya, les prelevements de toutes les regions passent par le meme compte
- **B) Regional** : chaque agence regionale a son propre compte bancaire (modele franchise)
- **C) Hybride** : compte central par defaut, avec possibilite de comptes regionaux

**Impact** : determine si la table est dans la BDD centrale ou regionale, et si elle doit etre synchronisee.

---

### 2. Numerotation des factures cross-regions

**Contexte** : La numerotation des factures doit etre **unique et sequentielle** (obligation legale francaise). Avec des BDD regionales separees, chaque region a sa propre sequence auto-increment.

**Question** : Quelle strategie de numerotation adopter ?

**Options** :
- **A) Prefixe par region** : `IDF-2026-0001`, `PACA-2026-0001` — sequences independantes, unicite garantie par le prefixe
- **B) Sequence centrale** : un compteur dans la BDD centrale, chaque region demande le prochain numero — unicite globale mais dependance au Hub
- **C) Format composite** : `{annee}{region}{sequence}` ex: `2026-IDF-0001`

**Impact** : doit etre decide avant la refonte de la table `invoices`.

---

### 3. Migration de la tarification `pricing_zones` → `plan_rates`

**Contexte** : La migration vers le systeme normalise `plan_rates` est deja en cours, mais il y a une possibilité de modification.

**Questions** :
- Doit on tout regrouper en une seule tarification ?
- Combien de fournisseur à terme ?

**Options** :
- **A) Une seule tarification "Cekoya"** : Complexe suivant la tarification et ou des modifications d'un fournisseur. Simple côté client, une seule vision et aucune nuance de tarification en fonction du fournisseur, marge plus restreinte
- **B) Une tarification multiple fournisseurs** : Simple, Chaque fournisseur a son propre plan de tarification et on y applique une marge. Complexe côté client, 2 lignes Sérénité sur chaque fournisseur n'aurait pas la même tarification

**Action** : En attente...
---

### 4. Gestion des news par region

**Contexte** : Les news (actualites affichees lors de la facturation et sur l'espace client) sont gerees centralement mais doivent pouvoir etre ciblees par region.

**Questions** :
- Une news est-elle toujours visible par toutes les regions par defaut, avec option de restriction ?
- Ou faut-il explicitement selectionner les regions pour chaque news ?
- Les regions peuvent-elles creer leurs propres news locales (non visibles par les autres regions) ?

**Proposition** : table pivot `news_regions` avec logique "toutes les regions si aucune restriction, sinon seulement celles listees".

---

### 5. Templates d'email — Gestion centrale vs regionale

**Contexte** : Les templates d'email sont utilises dans les campagnes (posts). Le central fournit des templates de base, mais chaque region peut vouloir en creer des specifiques.

**Questions** :
- Un template central modifie est-il automatiquement mis a jour dans toutes les regions ?
- Une region peut-elle modifier un template central pour sa propre utilisation ?
- Comment gerer les conflits lors de la sync ?

**Proposition** : les templates centraux sont synchronises en lecture seule. Les regions peuvent creer des templates locaux. Pas d'override des templates centraux — si une region veut une variante, elle cree un template local.

---

### 6. Portabilite des collaborateurs entre regions

**Contexte** : Si un client est transfere d'une region a une autre (ex: changement d'agence de reference), ses collaborateurs, lignes, historique doivent suivre.

**Questions** :
- Ce cas de figure est-il prevu ? Un client peut-il changer de region ?
- Si oui, quelle strategie de migration de donnees ? (export/import entre BDD regionales)
- Si oui, L'historique des CDR et factures doit-il suivre ?

---

### 6. NVIDIA Shark pour le module IA

**Contexte** : Les fonctionnalites IA (scoring prospect, assistant admin, prediction) seront positionnées sur plusieurs pages en fonction des utilisateurs et fonctions.

**Questions** :
- Faut il passer par le NVIDIA Shark ou bien par API ?
- Y aura t-il un accès à la base ? lien intéressant: https://securite.developpez.com/actu/381099/Comment-nous-avons-pirate-la-plateforme-d-IA-de-McKinsey-En-moins-de-deux-heures-l-agent-avait-obtenu-un-acces-complet-en-lecture-et-en-ecriture-a-l-ensemble-de-la-base-de-donnees-de-production/

---

### 7. Recrutement d'un 3eme developpeur

**Contexte** : Recommandation d'un recrutement a 6-12 mois si la croissance se confirme

**Questions** :
- Profil souhaite : fullstack Laravel ? Frontend specialise ? DevOps ? Sécurité ? 
- Timeline de recrutement vs timeline du projet ?
- Budget ?

---

## Points resolus

| # | Point | Decision | Date |
|---|-------|----------|------|
| - | Stack frontend : Livewire 4 vs Inertia/Vue | Livewire 4 + Alpine.js (unanime) | - |
| - | MySQL vs PostgreSQL | MySQL 8 (majoritaire) | - |
| - | Cloud provider | Scaleway (unanime) | - |
| - | Architecture | Monolithe modulaire DDD-lite (unanime) | - |
| - | SSO | JWT RS256 avec token one-time-use | - |
