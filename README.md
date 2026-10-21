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
