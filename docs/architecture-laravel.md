# SIGPRO Laravel — Architecture proposée et parcours utilisateur

> S'appuie sur l'inventaire [analyse-django.md](analyse-django.md).
> Décisions validées le 2026-10-21 : voir §10.

---

## 1. Principes directeurs

1. **Le travail vient à l'utilisateur** : chaque personne ouvre l'application sur sa liste « À traiter », au lieu de chercher dans un menu organisé par niveau hiérarchique.
2. **L'activité est le pivot** : une seule fiche réunit budget, TDR, décaissements, localités et rapports.
3. **Un seul moteur de workflow** : TDR, soumission du PTBA et rapports suivent le même mécanisme (états, transitions, historique, notifications).
4. **La sécurité est côté serveur** : policies Laravel sur chaque action, changements d'état uniquement en POST, périmètre limité à la direction de l'utilisateur.
5. **L'exercice courant s'applique partout** : il est porté par un middleware et filtre toutes les requêtes concernées.

---

## 2. Stack technique

| Couche | Choix proposé | Pourquoi |
|---|---|---|
| Framework | **Laravel 12** (PHP 8.3) | Version supportée par le kit officiel Livewire |
| Interface | **Livewire 3 + Flux UI + Tailwind** | Écrans riches (tableaux arborescents, modales, filtres) sans maintenir une API ni un front JS séparé. Proche du modèle Django « rendu serveur » |
| Base | **PostgreSQL + PostGIS** (conservés) | Cartographie, reprise des données existantes |
| Rôles et droits | `spatie/laravel-permission` | Rôles, permissions, contrôles dans les policies |
| Machine à états | `spatie/laravel-model-states` | États typés, transitions déclarées et testables |
| Historique | `spatie/laravel-activitylog` + table de transitions métier | Audit et timeline visible |
| Fichiers | `spatie/laravel-medialibrary` | Versions des TDR et des rapports, conversions |
| Excel | `maatwebsite/excel` | Import du PTBA avec prévisualisation, exports |
| Temps réel | **Laravel Reverb** (remplace Channels + Soketi) | Badges et notifications en direct, natif Laravel |
| Carte | Leaflet + `clickbar/laravel-magellan` (PostGIS) | Intègre le module PHP séparé |
| Édition en ligne | Collabora Online (**service `collabora` du `docker-compose.yaml`**) via un contrôleur WOPI **avec jeton signé** | Conserve la fonctionnalité, corrige l'absence d'authentification |
| Files d'attente | Redis + Horizon | Imports, notifications, e-mails |
| Tests | Pest | Couverture des transitions et des policies en priorité |

**Décision** : Livewire 3 + Flux.

---

## 3. Modèle de données

### 3.1 Référentiels et accès

```
users            id, name, email, phone, direction_id, is_active, last_login_at
directions       id, code (DSPA, DAF…), name, parent_id?          ← 25 directions seedées
exercices        id, year, label, starts_on, ends_on, budget_total, status (ouvert|clos), is_current
periodes         id, exercice_id, type (mois|trimestre|semestre|annee), number, starts_on, ends_on
roles / permissions                                                ← spatie
```

### 3.2 Cadre de résultats et PTBA

Les deux volets ont des hiérarchies différentes au-dessus de l'activité. On les garde explicites, puis on les rejoint sur une **table `activities` unique**.

```
VOLET PROJET                                VOLET PROGRAMME
project_components        (code, label)     result_domains        (label, description)
project_subcomponents     (sigle, label)    result_subdomains     (label, expected_result)
project_indicators        (type ILD|HORS_ILD, programme_indicators  (label, baseline, target, unit)
                           code, label,     programme_components  (label, subdomain_id)
                           baseline, target) programme_actions     (code DDR, label)
            \                                          /
             └──────────────► activities ◄────────────┘
```

```
activities
  id, volet (projet|programme), code, label, description
  parent_type / parent_id        ← morph : project_indicator OU programme_action
  parent_activity_id             ← sous-activités / « tâches programme »
  exercice_id, direction_id (responsable), focal_user_id
  category_id, unit_id, ugp_id, funding_source
  starts_on, ends_on
  execution_status (a_venir|en_cours|terminee|suspendue)
  ddp_ref_nature, ddp_ref_action, ddp_ref_activite, ddp_ref_ligne, ppm_ref
  localisation_label

activity_dependencies   activity_id, depends_on_id
activity_schedule       activity_id, periode_id (mois prévus d'exécution)   ← chronogramme
budget_lines            activity_id, exercice_id, unit, quantity, unit_cost, frequency, duration,
                        amount  (colonne générée = quantité × coût × fréquence × durée)
```

Référentiels simples : `expense_categories`, `units`, `ugps`, `funding_sources`.

### 3.3 Exécution financière

```
engagements     id, activity_id, amount, engaged_on, reference, user_id
drfs            id, exercice_id, number, date, amount, label, status   (demande de remboursement de fonds)
payments        id, activity_id, engagement_id?, drf_id?, amount, paid_on, user_id
```

Les agrégats (planifié, engagé, payé, reste à payer, reste à planifier) sont calculés **en SQL** par une vue `activity_financials`, puis cumulés vers les niveaux supérieurs. Cela supprime les N+1 actuels.

### 3.4 Workflows

```
tdrs              id, activity_id, circuit_id, title, state, current_step_id, returned_from_step_id,
                  submitted_at, approved_at, closed_at,
                  coordination_granted (bool), injunction (bool),
                  lessons, risks, recommendations, author_id
                  ← fichiers via medialibrary : collection « tdr » (versionnée) + « tdr_final »
workflow_circuits      id, subject (tdr|ptba|report), volet (projet|programme), name, version, is_active
workflow_steps         id, circuit_id, position, label, role_id, scope (direction|global),
                       gives_opinion (bool)   ← étapes modifiables par l'admin (voir §5)
workflow_transitions   id, subject_type, subject_id, from_state, to_state, action, step_id,
                       user_id, comment, created_at        ← commun à TDR, PTBA et rapports
ptba_submissions  id, exercice_id, direction_id, volet, state, …   ← soumission du PTBA par direction
reports           id, type, periode_id, volet, direction_id?, title, state, author_id
report_audiences  report_id, role_id? / direction_id?
report_obligations type, volet, direction_id, periode_id, due_on   ← « rapports attendus »
```

### 3.5 Cartographie et parc automobile

```
drenas            id, name, geom (multipolygon)
localites         id, drena_id, sous_prefecture, name, geom (point)
activity_localite activity_id, localite_id

vehicles          id, brand, model, year, plate, chassis, status (disponible|en_sortie|en_entretien|hors_service), mileage
vehicle_trips     id, vehicle_id, type (mission|course|deplacement), purpose, destination,
                  manager_name, driver_name, departed_at, returned_at, km_out, km_in, state
vehicle_incidents id, trip_id, type (panne_seche|crevaison|piece_retiree|autre), note
vehicle_maintenances id, vehicle_id, type, done_on, mileage, cost, next_due_km?, next_due_on?
```

---

## 4. Rôles et périmètres

| Rôle (nouveau nom) | Ancien rôle Django | Périmètre | Peut |
|---|---|---|---|
| `point_focal` | PointFocal | Sa direction | Saisir les activités et le budget, déposer et corriger les TDR, finaliser une activité, déposer les rapports |
| `directeur_central` | DirecteurLocal | Sa direction | Valider ou renvoyer les TDR et le PTBA de sa direction, consolider les rapports de sa direction |
| `coordination` | Directeur / « Coordination » | Toutes les directions | Comité technique : valider ou renvoyer, avis « accordé » / « injonction », suivi financier, engagements et DRF |
| `directeur_executif` | DirecteurExe | Tout | Validation finale, tableaux de bord consolidés |
| `gestionnaire_parc` | *(nouveau)* | Parc auto | Véhicules, sorties, entretiens |
| `lecteur` | *(nouveau)* | Tout, en lecture | Bailleurs, auditeurs |
| `admin` | is_staff | Tout | Utilisateurs, référentiels, exercices, imports |

- Un utilisateur peut avoir plusieurs rôles (par exemple directeur central et point focal dans une petite direction).
- Le périmètre « sa direction » est appliqué par un **scope global** et les **policies**, pas seulement dans le menu.

---

## 5. Machine à états des TDR

Un seul modèle `Tdr` pour les deux volets. Les **étapes de validation diffèrent selon le volet et vont évoluer** : elles ne sont donc pas codées en dur mais stockées en base (`workflow_circuits` / `workflow_steps`) et modifiables par l'admin. Le schéma ci-dessous montre un exemple à 3 étapes.

```
                 soumettre                valider                 valider                  valider
 ┌──────────┐ ───────────► ┌──────────────┐ ──────► ┌──────────────┐ ──────► ┌────────────────┐ ──────► ┌──────────┐
 │BROUILLON │              │ EN VALIDATION│         │ EN VALIDATION│         │ EN VALIDATION  │         │ APPROUVÉ │
 │          │              │ Dir. centrale│         │ Coordination │         │ Dir. exécutive │         │          │
 └──────────┘ ◄─────────── └──────────────┘         └──────────────┘         └────────────────┘         └────┬─────┘
      ▲      renvoyer (commentaire obligatoire, depuis n'importe quelle étape)                               │ démarrer
      │                                                                                                      ▼
 ┌──────────┐                                                                                          ┌──────────┐  finaliser   ┌─────────┐
 │À CORRIGER│ ── resoumettre (nouvelle version du fichier) ──► reprend à l'étape qui a renvoyé            │EN COURS  │ ───────────► │ CLÔTURÉ │
 └──────────┘                                                                                          └──────────┘  (TDR final, └─────────┘
                                                                                                                      leçons, risques,
 ABANDONNÉ ◄── abandonner (point focal, avant approbation)                                                            recommandations)
```

### États

| État | Code | Correspondance Django |
|---|---|---|
| Brouillon | `draft` | 0 sans commentaire |
| À corriger | `changes_requested` | 0 avec commentaire |
| En validation | `in_review` + `current_step` | 10 / 20 / 30 / 40 |
| Approuvé | `approved` | dernière étape validée |
| En cours d'exécution | `in_progress` | *(nouveau, relié à `execution_status`)* |
| Clôturé | `closed` | 100 |
| Abandonné | `abandoned` | suppression |

### Étapes de validation (paramétrables)

- **Un circuit par volet** (et par type d'objet : TDR, PTBA, rapport). Chaque étape porte un libellé, un rôle valideur, un périmètre (sa direction ou global) et l'indication « donne un avis accordé / injonction ».
- **Écran admin « Circuits de validation »** : ajouter, retirer, réordonner ou renommer les étapes sans toucher au code.
- **Versionnement** : modifier un circuit en crée une nouvelle version. Un TDR garde la version en vigueur au moment de sa première soumission, ce qui évite de casser les dossiers en cours ; les nouveaux dossiers prennent la nouvelle version.
- Circuits initiaux (seed), repris de Django, à ajuster dans l'écran admin :
  - **Projet** : Direction centrale → Coordination.
  - **Programme** : Direction centrale → Comité technique → Direction exécutive.

### Règles

- **Soumettre** : le fichier TDR est obligatoire et l'activité doit avoir un budget pour l'exercice.
- **Renvoyer** : le commentaire est obligatoire. Le TDR revient au point focal à l'état « À corriger » et l'étape qui l'a renvoyé est mémorisée (`returned_from_step_id`). À la resoumission, il **reprend à cette étape**, sans repasser par les étapes déjà validées (décision validée).
- **Avis de la Coordination** (« accordé » / « injonction ») : saisi au moment où la Coordination valide.
- **Chaque transition** crée une ligne `workflow_transitions` (qui, quand, de → vers, commentaire), notifie l'acteur suivant (application, temps réel, e-mail) et met à jour les badges.
- **Versions** : chaque nouveau dépôt garde la version précédente, consultable dans l'historique.
- **Implémentation** : classes d'état `spatie/laravel-model-states`, une classe de transition par action, une policy `TdrPolicy` par action (`submit`, `approve`, `requestChanges`, `finalize`…).

Le même moteur sert à :
- la **soumission du PTBA** par direction : brouillon → direction centrale → coordination → arrêté ;
- les **rapports** : brouillon → soumis → consolidé.

---

## 6. Nouveaux parcours utilisateur

### 6.1 Navigation

```
Accueil · À traiter (badge)
Activités          ← PTBA Projet / Programme (bascule), arbre + liste + filtres
Validations        ← TDR, PTBA, rapports en attente (selon le rôle)
Suivi financier    ← coordination, DG
Rapports           ← calendrier des rapports attendus + dépôts
Carte
Parc automobile    ← gestionnaire de parc
Administration     ← admin
[Exercice 2026 ▾]  [🔔 notifications]  [Profil]
```

Le menu ne dépend plus des niveaux de validation : chaque rôle voit les mêmes entrées, filtrées sur son périmètre.

### 6.2 Point focal — « Préparer et faire valider mes activités »

1. **Accueil** : cartes « 3 TDR à corriger », « 2 rapports mensuels attendus avant le 10 », « 5 activités sans TDR qui démarrent ce mois-ci ».
2. Clic sur une carte → **fiche activité**, onglet concerné ouvert directement.
3. **Fiche activité** (onglets) : *Résumé* (statut, dates, responsable) · *Budget* (lignes par exercice, planifié / engagé / payé) · *TDR* (fichier, version, **timeline de validation** avec commentaires) · *Localités* · *Documents et rapports*.
4. **Déposer le TDR** : glisser-déposer le fichier, puis « Soumettre à ma direction ». Une seule action, avec un résumé de vérification avant envoi.
5. **TDR renvoyé** : le commentaire s'affiche en haut de l'onglet. « Déposer une nouvelle version » remplace le fichier et resoumet.
6. **Activité terminée** : assistant « Clôturer » en 2 étapes (TDR final, puis leçons, risques et recommandations).

### 6.3 Directeur central, Coordination, DG — « Valider vite et bien »

1. **Validations** : file d'attente triée par ancienneté, avec un indicateur de retard (par exemple plus de 5 jours).
2. **Panneau de revue** : à gauche la liste, à droite l'aperçu du TDR (Collabora en lecture ou PDF), le budget de l'activité et l'historique.
3. Actions : **Valider** (avec avis « accordé » / « injonction » pour la Coordination) ou **Renvoyer** (commentaire obligatoire).
4. Raccourcis clavier et passage automatique au dossier suivant.
5. **Validation groupée** possible pour le PTBA d'une direction.

### 6.4 Coordination — suivi financier

- **Arbre PTBA repliable** : composante → indicateur → activité, avec colonnes planifié / engagé / payé / reste et barres de taux d'exécution.
- Saisie des **engagements et paiements** depuis la ligne d'activité (panneau latéral), rattachement d'un paiement à une **DRF**.
- Page **DRF** : liste, paiements inclus, export Excel.
- Filtres : exercice, direction, ILD / hors ILD, statut.

### 6.5 Rapports

- **Calendrier des obligations** : pour chaque période, les rapports attendus par direction, avec leur statut (attendu, déposé, consolidé, en retard).
- Dépôt depuis la case du calendrier (type et période déjà renseignés).
- Édition à plusieurs dans Collabora, puis « Consolider » par le niveau supérieur.

### 6.6 Admin — mise en place d'un exercice

Assistant « Nouvel exercice » :
1. Créer l'exercice et générer ses périodes.
2. **Importer le PTBA** (Excel projet ou programme) avec **prévisualisation et rapport d'erreurs** avant validation.
3. Affecter les activités aux directions et aux points focaux.
4. Définir les obligations de rapports.
5. Ouvrir l'exercice.

### 6.7 Gestionnaire de parc

- Tableau des véhicules avec leur statut en couleur.
- « Nouvelle sortie » : le véhicule passe automatiquement « en sortie ».
- « Retour » : saisie du kilométrage et des incidents, le véhicule redevient disponible et son kilométrage est mis à jour.
- Alertes d'entretien (kilométrage ou date).

### 6.8 Tableau de bord (DG, Coordination)

Remplace la page de démo :
- KPI : taux d'exécution financière, TDR par état, délai moyen de validation, activités en retard.
- Graphique de progression par composante.
- Mini-carte des activités.

---

## 7. Organisation du code

```
app/
  Domain/
    Referentiel/   (Direction, Exercice, Periode…)
    Planning/      (Activity, BudgetLine, Project*/Programme* hierarchy)
    Finance/       (Engagement, Payment, Drf, ActivityFinancials)
    Workflow/      (States/, Transitions/, WorkflowTransition, HasWorkflow trait)
    Tdr/           (Tdr, TdrPolicy, actions)
    Reporting/     (Report, ReportObligation)
    Fleet/         (Vehicle, Trip, Maintenance)
    Geo/           (Drena, Localite)
  Imports/         (PtbaProjetImport, PtbaProgrammeImport)
  Http/Controllers/Wopi/
  Livewire/        (pages et composants par domaine)
database/
  migrations/ seeders/ (directions, rôles, référentiels)
  legacy/          (commande d'import des données Django)
```

---

## 8. Démarrage et module d'import

**Décision** : démarrage sur un **exercice neuf**, aucune reprise des données Django. Les directions et les rôles sont créés par des seeders. Les DRENA et localités sont chargées depuis un fichier (GeoJSON ou CSV).

Le **module d'import Excel** devient donc central. Il est revu par rapport aux scripts Django (`upload_ptba`, `ingest`) :
- **Un importeur par modèle de fichier** (PTBA projet, PTBA programme), décrit par un mapping de colonnes en configuration. Un changement de modèle Excel ne demande pas de réécrire le code.
- **Modèles Excel téléchargeables** générés par l'application, avec listes déroulantes (directions, catégories, unités) pour limiter les erreurs de saisie.
- **Assistant en 4 étapes** : téléverser → contrôler (erreurs ligne par ligne : direction inconnue, montant invalide, hiérarchie incohérente) → prévisualiser l'arbre et les totaux → valider.
- **Import transactionnel** : tout passe ou rien. Exécuté en file d'attente avec une barre de progression.
- **Réimport** : mise à jour des activités existantes grâce à leur code, sans doublons. Historique des imports (fichier, auteur, date, résultat) avec rapport d'erreurs téléchargeable.
- **Tests** sur les vrais fichiers `ptba.xlsx` et `ptba_programme_remake.xlsx` du projet Django.

---

## 9. Découpage en lots

| Lot | Contenu | Livrable testable |
|---|---|---|
| 0 | Socle : Laravel, `docker-compose.yaml` (app, PostgreSQL/PostGIS, Redis, Reverb, **Collabora**), auth, rôles, directions, exercices, layout | Connexion, menu par rôle, sélecteur d'exercice, Collabora joignable |
| 1 | Hiérarchies et activités, budget, **assistant d'import Excel** du PTBA | PTBA projet et programme importés et navigables |
| 2 | Moteur de workflow (circuits paramétrables), TDR, page Validations, notifications | Circuit TDR complet de bout en bout, écran admin des circuits |
| 3 | Suivi financier : engagements, paiements, DRF | Arbre financier et exports |
| 4 | Rapports, obligations, Collabora / WOPI | Calendrier et consolidation |
| 5 | Cartographie | Carte et affectation des localités |
| 6 | Parc automobile | Sorties, retours, entretiens |
| 7 | Tableau de bord, chargement des localités, mise en production | Bascule en production sur un exercice neuf |

---

## 10. Décisions (2026-10-21)

1. **Interface** : Livewire 3 + Flux.
2. **Circuits TDR** : différents selon le volet et appelés à évoluer → circuits **paramétrables en base**, versionnés, avec écran admin.
3. **Renvoi** : le TDR resoumis reprend **à l'étape qui l'a renvoyé**.
4. **Données** : exercice neuf, pas de reprise Django ; le **module d'import Excel** est renforcé (§8).
5. **Collabora** : une instance dans le `docker-compose.yaml`, créée au lot 0.
