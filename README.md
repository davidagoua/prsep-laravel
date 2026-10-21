# SIGPRO — Planification, suivi et rapportage du PRSEP

Portage Laravel de l'application Django `prsep-django`.
Analyse : [docs/analyse-django.md](docs/analyse-django.md) · Architecture et lots : [docs/architecture-laravel.md](docs/architecture-laravel.md).

## Stack

Laravel 12 · Livewire 4 + Volt + Flux · PostgreSQL 16 / PostGIS 3.5 · Redis · Reverb · Collabora Online · Pest.

## Démarrer (Docker)

```bash
cp .env.example .env            # puis renseigner SIGPRO_ADMIN_PASSWORD et SIGPRO_DEMO_PASSWORD
composer install && npm install && npm run build
php artisan key:generate && php artisan reverb:install
DOCKER_UID=$(id -u) DOCKER_GID=$(id -g) docker compose up -d --build
docker compose exec app php artisan migrate --seed
```

| Service | URL locale |
|---|---|
| Application | http://localhost:8000 |
| Collabora Online | http://localhost:9980/hosting/discovery (console : `/browser/dist/admin/admin.html`) |
| Reverb (WebSocket) | ws://localhost:8080 |
| Mailpit (e-mails) | http://localhost:8025 |
| PostgreSQL | `localhost:5433` (sigpro / secret) |
| Redis | `localhost:6380` |

Les ports se changent dans `.env` (`FORWARD_*`).
Pendant le développement du front : `npm run dev`.

## Comptes initiaux

- **Administrateur** : `SIGPRO_ADMIN_EMAIL` / `SIGPRO_ADMIN_PASSWORD` (`.env`). Sans mot de passe, utiliser « Mot de passe oublié » : l'e-mail arrive dans Mailpit.
- **Démo** (environnement `local` uniquement) : un compte par rôle, `<role>@sigpro.test` (ex. `point.focal@sigpro.test`), mot de passe `SIGPRO_DEMO_PASSWORD`.

En local, `migrate --seed` crée aussi 8 activités de démonstration (DSPA, DAF) et des TDR à différents stades : connectez-vous en point focal puis en directeur central pour suivre le circuit.

Il n'y a pas d'inscription libre : l'administrateur crée les comptes et l'utilisateur reçoit un lien pour choisir son mot de passe.

## Tests

```bash
php artisan test
```

## Lot 0 — contenu livré

- Authentification en français, sans inscription ; comptes désactivables (déconnexion immédiate).
- Rôles : point focal, directeur central, coordination, directeur exécutif, gestionnaire de parc, lecteur, administrateur.
- Directions (25 sigles repris de Django, intitulés à compléter), exercices et périodes générées (mois, trimestres, semestres, année).
- Exercice de travail choisi dans le menu (session), exercice en cours défini par l'admin.
- Layout : menu par rôle, modules des lots suivants visibles mais désactivés.
- Administration : utilisateurs, directions, exercices ; accueil avec checklist de mise en place.

## Lot 2 — circuit de validation des TDR

- **Circuits paramétrables** (Administration › Circuits de validation) : étapes, rôle valideur, périmètre (direction de l'activité ou toutes), avis « accordé / injonction ». Modifier un circuit déjà utilisé publie une nouvelle version ; les dossiers en cours terminent sur la leur.
- **TDR** : dépôt, versions successives du fichier, soumission, validation ou renvoi commenté, reprise à l'étape qui a renvoyé, exécution puis clôture (TDR final, leçons, risques, recommandations). Historique complet de chaque dossier.
- **Écrans** : Activités (socle minimal, enrichi au lot 1), fiche activité, À traiter, Validations (file + revue, passage automatique au dossier suivant).
- **Notifications** : en base (cloche), par e-mail (Mailpit en local) et en temps réel (Reverb). Le worker `queue` doit tourner.
- **Charte** : couleurs et logo du projet Django (bleu marine, vert, orange PRSEB, police Montserrat, barre latérale sombre).

## Lot 1 — PTBA : hiérarchies, budget, import Excel

- **Hiérarchies** pluriannuelles : projet (composante → sous-composante → indicateur ILD / hors ILD) et programme (domaine → sous-domaine avec indicateurs base/cible → composante → action). Activités et sous-activités rattachées, avec chronogramme, catégorie, UGP, source de financement, références DPPD, PPM.
- **Budget** par activité et par exercice : lignes de coût (quantité × fréquence × durée × coût unitaire), modifiables par le point focal de la direction ou la coordination tant que l'exercice n'est pas clos.
- **Import Excel** (Activités › Importer le PTBA, admin et coordination), format détecté automatiquement :
  - **modèle SIGPRO** téléchargeable, généré avec les listes déroulantes à jour (directions, catégories, unités…) ; colonnes définies dans `config/ptba_import.php` ;
  - **formats historiques** du projet Django : PTBA projet (lignes C, S/C, ILD, V, A, T) et PTBA programme (une feuille par sous-domaine, mois cochés « X »).
  - Assistant : téléverser → contrôles ligne par ligne (erreurs bloquantes, avertissements, rapport CSV) → aperçu de l'arbre et des totaux → import en file d'attente, tout ou rien. Un réimport met à jour les activités existantes (code ou clé de rapprochement) et remplace leurs lignes budgétaires.
  - Contrôle de cohérence : écart entre le détail lu et les totaux inscrits dans le fichier signalé niveau par niveau.
- **Arborescence** du PTBA (Activités › Arborescence) avec montants cumulés, filtres direction et ILD.

## Lot 3 — suivi financier

- **Engagements et paiements** par activité et par exercice, saisis par la coordination depuis la fiche activité ou le panneau latéral du suivi financier. Règles : pas de paiement au-delà du reste à payer (de l'activité et de l'engagement choisi), un engagement payé ne se supprime pas, alerte quand les engagements dépassent le budget planifié.
- **DRF** (Suivi financier › DRF) : regroupement des paiements à faire rembourser, numérotation par exercice, cycle brouillon → soumise (paiements figés) → remboursée, ou rejet par le bailleur avec motif (la DRF redevient modifiable).
- **Tableau financier** : arborescence du PTBA avec planifié, engagé, payé, reste à payer et taux d'exécution à chaque niveau, indicateurs clés, filtres volet / direction / ILD.
- **Exports Excel** du tableau financier (mêmes filtres) et de chaque DRF.
- Accès : saisie par la coordination (et l'administrateur), consultation par la direction exécutive et les lecteurs ; le point focal voit l'exécution financière de ses activités.
