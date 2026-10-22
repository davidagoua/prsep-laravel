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
| GeoServer | http://localhost:8081/geoserver (admin / `GEOSERVER_ADMIN_PASSWORD`) |
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

## Lot 4 — rapports et édition en ligne

- **Rapports attendus** (Rapports › Rapports attendus, coordination) : un par type (fiche mensuelle, trimestriel projet / programme, semestriel, annuel), période et direction, avec un délai de remise ; les périodes déjà terminées peuvent être ignorées.
- **Directions** (point focal, directeur central) : liste « À remettre » avec échéances et retards, dépôt depuis la ligne, nouvelles versions, remise à la coordination, correction après renvoi. Rapports circonstanciés hors calendrier.
- **Coordination** : calendrier par type (directions × périodes, état de chaque rapport), validation ou renvoi commenté, page de consolidation par période (remis / attendus, téléchargements), rapport consolidé publié à des rôles et directions choisis.
- **Édition en ligne avec Collabora** : « Modifier en ligne » ouvre le document dans Collabora (écriture si l'utilisateur peut modifier le rapport, lecture sinon) ; les TDR Word se lisent en ligne. Hôte WOPI dans SIGPRO (`/wopi/files/...`) protégé par un jeton signé et limité dans le temps ; l'enregistrement met à jour la dernière version.
- Notifications (base, e-mail, temps réel) et compteurs dans « À traiter » et le menu.

## Lot 5 — carte

- **GeoServer** dans `docker-compose.yaml` (image `kartoza/geoserver`, données persistées dans le volume `geoserver-data`). L'espace de travail `sigpro` et l'entrepôt `sigpro_postgis` (base PostGIS de SIGPRO) sont prêts à recevoir les couches.
- **Page Carte** (Leaflet, chargé à la demande) : fonds OpenStreetMap et CARTO, couches WMS de GeoServer avec affichage / masquage, opacité, centrage sur l'emprise, plein écran, informations de l'objet cliqué.
- Les couches sont **découvertes automatiquement** (WMS GetCapabilities de l'espace `GEOSERVER_WORKSPACE`) : une couche publiée dans GeoServer apparaît sur la carte sans modification de SIGPRO (bouton « Actualiser les couches », cache de 5 minutes).
- L'interrogation des objets passe par SIGPRO (`/carte/objets`), limitée aux couches publiées : pas de CORS à configurer dans GeoServer.
- À venir : rattachement des localités d'exécution aux activités et affichage des activités sur la carte, une fois les couches DRENA / localités chargées.

## Lot 6 — parc automobile

- **Véhicules** (Parc automobile) : immatriculation, marque, modèle, châssis, kilométrage, statut (disponible, en sortie, en entretien, hors service), prochain entretien au kilométrage ou à la date.
- **Sorties** (mission, course, déplacement) : responsable, chauffeur, direction, destination, retour prévu. Le véhicule passe « en sortie », puis redevient disponible au retour ; s'il est immobilisé, il passe « en entretien ». Le kilométrage ne peut jamais reculer.
- **Incidents** au retour (panne sèche, crevaison, pièce retirée, accident, panne, autre) et **entretiens** (opérations reprises de Django, coût, garage, prochain entretien, remise en service).
- **Alertes** : retours en retard et entretiens à prévoir (500 km ou 15 jours), dans l'écran du parc, « À traiter » et le badge du menu.
- Accès : saisie par le gestionnaire du parc (et l'administrateur), consultation par la coordination et la direction exécutive.

## Lot 7 — pilotage et mise en production

- **Tableau de bord** (direction exécutive, coordination, lecteurs) : budget planifié, taux d'engagement et de décaissement, délai moyen de validation des TDR, points d'attention (TDR en attente depuis plus de 5 jours, activités démarrées sans TDR approuvé, rapports non remis, activités sans direction), exécution par composante ou domaine, TDR par état et par étape, rapports, parc, tableau par direction.
- **Production** : image `docker/php/Dockerfile.prod` (nginx + PHP-FPM, migrations et caches au démarrage), `docker-compose.prod.yaml` avec Caddy (HTTPS automatique pour l'application, Collabora et GeoServer), `.env.production.example`, HTTPS forcé et proxies de confiance. Guide : [docs/deploiement.md](docs/deploiement.md).
- **CI GitHub** : tests Pest (`php artisan test`) et contrôle du style (`pint --test`).
