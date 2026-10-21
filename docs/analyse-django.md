# PRSEP / SIGPRO — Analyse du projet Django (base du portage Laravel)

Source analysée : `prsep-django/app` (sous-module `davidagoua/sigpro-backend`, commit `bdeeb9d`).
Stack actuelle : Django 5.1, PostgreSQL/PostGIS, Channels + Soketi (Pusher), Collabora Online (WOPI),
thème admin Bootstrap (Cuba) + Alpine.js, ApexCharts, Leaflet, DataTables. Module cartographie séparé en **PHP brut**.

Objet métier : outil de **planification, suivi d'exécution et rapportage** du projet PRSEP
(financement à base de résultats : ILD / DLI) et du **programme sectoriel** associé, pour un ministère
organisé en directions (DSPA, DAF, DRH, DTSI, …, 25 au total).

---

## 1. Référentiels et acteurs

### Utilisateurs, rôles, directions (`core`)
- `User` (Django auth étendu) : contact, **rôle**, **direction** (`Departement`).
- Rôles seedés : `PointFocal`, `DirecteurLocal`, `Directeur`, `DirecteurExe` (+ `Coordination` testé dans le menu, jamais créé) ; `is_staff` = super-admin qui voit tout.
- Les droits sont **uniquement appliqués dans le menu** (template), pas dans les vues.
- Écrans : connexion, déconnexion, changement de mot de passe, création/liste/suppression d'utilisateurs (admin).
- Commandes : `create_roles`, `create_departments`, `seed_data`, `clear_data`.

### Exercice budgétaire
- `Exercice` (libellé, date début/fin, montant total, statut).
- **Sélecteur d'exercice courant** global dans l'en-tête (stocké en session, context processor).
  ⚠️ Plusieurs vues ignorent ce choix et utilisent `Exercice.objects.last()`.

---

## 2. Volet PROJET (PTBA Projet — financement Banque mondiale, logique ILD)

### Hiérarchie
`PTBAProjet` → `ComposantProjet` → `SousComposantProjet` (sigle) → `Indicateur` (type **ILD / HORS ILD**) → `Tache` (activité)

### Tâche / activité (`Tache`)
- Libellé, type (RLD / HORS RLD), catégorie de dépense, unité, UGP, coût unitaire, quantité, fréquence,
  dates début/fin, responsable, direction, exercice, dépendances (M2M), montant engagé.
- Montant planifié = coût × quantité × fréquence ; planification **pluriannuelle** via `PlanificationCout` (par exercice et direction).
- Statuts : `status_validation` (0→10→20→30, circuit de soumission du PTBA), `status_execution` (0 à venir, 10 en cours, 20 terminé).
- Indicateurs financiers agrégés à chaque niveau : **planifié, engagé, décaissé (DRF), reste à payer, reste à planifier**, coûts par année.

### Fonctionnalités
| # | Fonctionnalité | Écran / URL |
|---|---|---|
| P1 | Vue PTBA Projet de la direction (tableau hiérarchique composante → activité) pour l'exercice | `planification/ptba-projet/` |
| P2 | Ajout d'une activité (formulaire modal, validations dates et montants) | `planification/create-ild/` |
| P3 | Soumission du PTBA (passage du statut de validation 0→10→20→30) | `?action=soumettre&status=` |
| P4 | Téléchargement du **modèle Excel** PTBA projet | `planification/upload-ptba-template/` |
| P5 | Import initial du PTBA depuis JSON/Excel (script) | `core/services.upload_ptba`, `test/` |
| P6 | Détail d'une activité + démarrer / terminer l'exécution | `planification/tache/<id>/` |
| P7 | **Suivi PTBA Projet** : tableau planifié / engagé / payé / reste à payer, par exercice | `suivi/ptba-projet` |
| P8 | Modification d'une activité | `suivi/update-tache/<pk>` |
| P9 | **Décaissements** : saisie montant engagé + paiement rattaché à une **DRF** (demande de remboursement de fonds), suppression | `suivi/add-decaissement-projet/<pk>` |
| P10 | Liste des PPM (Plans de Passation des Marchés, fichiers) | `planification/ppm-list/` |

---

## 3. Volet PROGRAMME (PTBA Programme — budget-programme national)

### Hiérarchie (cadre de résultats)
`DomainResult` → `SousDomainResult` (résultat attendu) → `IndicateurProgram` (base, cible)
`ComposantesProgram` → `Action` → `Activite` (sous-activités via `parent`) → `TacheProgram`
- Activité : localisation, type, source de financement, références DDP (nature, action, activités, ligne), PPM, direction responsable.
- Chronogramme : `Quarter` / `ActiviteMonth` (mois d'exécution par trimestre).
- Coûts : `PlanificationCoutProgram` (unité, quantité, fréquence, durée, coût unitaire, exercice).

### Fonctionnalités
| # | Fonctionnalité | Écran / URL |
|---|---|---|
| G1 | Vue PTBA Programme (composantes, sous-domaines) | `planification/ptba-programme/` |
| G2 | Suivi PTBA Programme (domaines/résultats) | `programme/suivi-programme/` |
| G3 | Import du PTBA Programme depuis l'Excel `ptba_programme_remake.xlsx` (par feuille) | `programme/ingest/<sheet>` |
| G4 | Liste des activités de la direction (+ dépôt TDR) | `programme/liste-programme/` |

---

## 4. Circuit de validation des TDR (cœur du parcours utilisateur)

Un **TDR** (Termes de référence) est un fichier Word déposé pour une activité, qui doit être validé
par niveaux avant exécution, puis clôturé par un rapport de fin d'activité.
Deux circuits quasi identiques : `TDR` (projet) et `TDRProgramme` (programme).

### États (`state`, valeurs magiques)
| État | Signification | Acteur qui agit |
|---|---|---|
| 0 | Brouillon déposé / renvoyé pour correction | **Point focal** (agent de saisie) |
| 10 | Soumis à la **Direction centrale** | Directeur local |
| 20 | Soumis au **Comité technique / Coordination** | Directeur / Coordination |
| 30 | Soumis à la **Direction exécutive** (projet) | Coordination |
| 40 | Validé Direction exécutive (programme) | Directeur exécutif |
| 100 | **Finalisé** (TDR de fin d'activité déposé) | Point focal |

### Actions
- Déposer un TDR (fichier + libellé) sur une activité ; le modifier / remplacer ; le supprimer.
- **Soumettre** au niveau suivant (state + 10).
- **Rejeter / renvoyer** au niveau précédent (state − 10) avec **commentaire obligatoire** (`CommentaireTDR`, générique sur les deux types).
- Coordination : cocher **« accordé »** et **« injonction »** (avis de non-objection).
- Télécharger le TDR.
- **Finaliser** : dépôt du TDR de fin d'activité + leçons apprises, risques, recommandations → state 100.
- **Compteurs en temps réel** dans le menu (badges par état) via `get_tdr_stats` / `stats-programme` + WebSocket (Channels) et Pusher/Soketi.

### Écrans par rôle (menu)
- Suivi Exécution Projet : Agent de saisie → Direction centrale → Coordination.
- Suivi Exécution Programme : Agent de saisie → Direction centrale → Comité technique → Direction exécutive.

---

## 5. Rapportage

- Types : **Fiche mensuelle** (projet / programme), **Rapport trimestriel** (projet / programme), **Semestriel**, **Annuel**, **Circonstancié**.
- Dépôt d'un rapport (fichier + intitulé) **ciblé** sur des rôles et des directions (destinataires).
- Deux listes : rapports en cours (state 0) et **rapports consolidés** (state 10) ; action **Consolider**.
- **Édition en ligne** du document dans **Collabora Online** (protocole WOPI : info, lecture, écriture).
- Télécharger, remplacer le fichier/libellé, supprimer.
- Une même vue copiée 7 fois (seul le type change).

---

## 6. Gestion du parc automobile (`setting`)

- **Véhicules** : marque, modèle, année, immatriculation, n° châssis, entretiens effectués (liste), statut (disponible…). CRUD + fiche détail.
- **Emprunts / sorties** : véhicule, date sortie/retour, type (mission, course, déplacement), destination, responsable, chauffeur, objet de mission, km sortie/retour, incidents (panne sèche, crevaison, pièce retirée, autre).
  À la modification (retour), km de sortie / responsable / chauffeur sont verrouillés.
- Le menu « Entretiens » pointe vers la liste des emprunts (non implémenté).
- ⚠️ Le statut du véhicule n'est pas mis à jour lors d'un emprunt (code commenté).

---

## 7. Tableau de bord, analyse, cartographie

- **Tableau de bord** : 4 compteurs (nb activités, à venir, en cours, terminées) + résumé PTBA.
  Le reste du `home.html` (1 175 lignes) est du **contenu de démo du thème** (Top clients, timeline 2020…).
- **Stats TDR** : nombre de TDR par état (projet et programme) — `suivi/stats/`.
- **Analyse** : page quasi vide.
- **Cartographie** (Leaflet) : carte des activités par **DRENA** et **localités**, filtres composante / sous-composante / indicateur / activité ;
  écran d'**affectation des localités d'exécution** à une activité (`t_activite_localite`).
  Implémentée en **PHP séparé** qui lit directement les tables Django + tables `drenas`, `localites`, vue `vue_activite`.

---

## 8. Dette et problèmes à ne pas reporter dans Laravel

- **Sécurité** : la plupart des vues n'exigent pas de connexion ni de rôle ; suppressions et changements d'état en **GET** ;
  redirections ouvertes via `?next=` ; endpoints WOPI sans contrôle d'accès ni jeton ; secrets Pusher en dur ;
  `seed-compte`, `test/`, `ingest/` exposés publiquement.
- **Bugs** : `Decaissement(drf__pk=…)` (DRF jamais rattachée) ; `update_tdrprogram_state` compare une chaîne à 10 ;
  `upload_file` ne sauvegarde pas ; `update_state` redirige avec `pk` au lieu de `id` ;
  rôle `Coordination` référencé mais jamais créé ; import circulaire `app.suivi.models`.
- **Modèle** : TDR et TDRProgramme dupliqués ; responsable stocké tantôt en texte, tantôt en FK direction ;
  états en entiers magiques ; agrégats calculés en Python (N+1 massif).
- **UX** : pas de vue « mes actions en attente » ; navigation par niveau hiérarchique plutôt que par tâche ;
  formulaires en modales dans des tableaux géants ; pas d'historique visible du circuit d'un TDR ;
  sélecteur d'exercice incohérent ; pas de notifications ciblées (seulement des compteurs).

---

## 9. Pistes pour le portage Laravel (parcours utilisateur)

1. **Une boîte de réception unique « À traiter »** par utilisateur, alimentée par une machine à états (TDR, rapports, PTBA) au lieu d'un menu par niveau hiérarchique.
2. **Un seul modèle `Tdr` polymorphe** (projet / programme) + table `tdr_transitions` (qui, quand, de → vers, commentaire) → timeline visible.
3. **Policies Laravel** + rôles/permissions (spatie/laravel-permission) appliqués côté serveur, actions en POST.
4. **Fiche activité** comme pivot : planification, budget, décaissements, TDR, localités, rapports, sur une même page à onglets.
5. Exercice courant cohérent (middleware + scope global).
6. Agrégats financiers en SQL (vues ou colonnes matérialisées), tableaux arborescents repliables.
7. Cartographie intégrée (PostGIS + Leaflet dans l'app) au lieu du module PHP séparé.
8. Imports Excel (PTBA projet / programme) via un assistant avec prévisualisation et rapport d'erreurs.
9. Notifications temps réel ciblées (Laravel Reverb / Soketi) + e-mail.
10. Parc auto : mise à jour automatique du statut véhicule, vrai module entretiens, alertes kilométrage.
