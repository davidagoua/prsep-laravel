# Déploiement de SIGPRO en production

Ce guide installe SIGPRO sur un serveur Linux avec Docker : application (nginx + PHP-FPM), worker de files,
Reverb (temps réel), PostgreSQL/PostGIS, Redis, Collabora Online, GeoServer et Caddy (HTTPS automatique).

## 1. Prérequis

- Serveur Linux 64 bits (x86 ou ARM), 4 cœurs, **8 Go de RAM minimum** (Collabora et GeoServer sont gourmands), 50 Go de disque.
- Docker Engine 24+ avec le plugin Compose.
- Trois noms de domaine pointant vers le serveur (enregistrements DNS A/AAAA) :
  `sigpro.domaine.ci`, `collabora.domaine.ci`, `geoserver.domaine.ci`.
- Ports 80 et 443 ouverts (Caddy obtient les certificats Let's Encrypt).
- Un serveur SMTP pour les invitations et notifications.

## 2. Installation

```bash
git clone https://github.com/davidagoua/prsep-laravel.git sigpro && cd sigpro
cp .env.production.example .env
```

Renseigner toutes les valeurs `<…>` de `.env`, puis générer les secrets :

```bash
# Clé de l'application (à copier dans APP_KEY)
docker run --rm php:8.3-cli php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
# Identifiants Reverb : REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET (chaînes aléatoires)
openssl rand -hex 10; openssl rand -hex 16; openssl rand -hex 24
```

Construire l'image (les paramètres Reverb publics sont intégrés au JavaScript à cette étape) puis démarrer :

```bash
docker compose -f docker-compose.prod.yaml --env-file .env build app
docker compose -f docker-compose.prod.yaml --env-file .env up -d
```

Au démarrage, le conteneur `app` applique les migrations et met en cache configuration, routes et vues.

## 3. Initialisation (une seule fois)

```bash
# Rôles, directions, exercice en cours, circuits de validation et premier administrateur
docker compose -f docker-compose.prod.yaml exec app php artisan db:seed --force
```

Si `SIGPRO_ADMIN_PASSWORD` est vide, un mot de passe aléatoire est attribué : utiliser « Mot de passe oublié »
sur la page de connexion (l'e-mail part par le SMTP configuré). Aucune donnée de démonstration n'est créée en production.

Ensuite, dans l'application (compte administrateur) :
1. **Administration › Directions** : compléter les intitulés, ajouter les structures manquantes (ex. DCSPA).
2. **Administration › Exercices** : vérifier l'exercice en cours.
3. **Administration › Utilisateurs** : créer les comptes (une invitation est envoyée).
4. **Administration › Circuits de validation** : ajuster les étapes.
5. **Administration › Import du PTBA** : importer les PTBA projet et programme.
6. **Rapports › Rapports attendus** (coordination) : définir le calendrier des rapports.

## 4. GeoServer

Interface : `https://geoserver.domaine.ci/geoserver` (identifiants `GEOSERVER_ADMIN_*`).
Créer l'espace de travail défini par `GEOSERVER_WORKSPACE` (par défaut `sigpro`) et y publier les couches
(DRENA, localités…) : elles apparaissent automatiquement sur la carte de SIGPRO.
Pour une source PostGIS, l'hôte de la base est `postgis` (réseau Docker interne).

## 5. Mises à jour

```bash
git pull
docker compose -f docker-compose.prod.yaml --env-file .env build app
docker compose -f docker-compose.prod.yaml --env-file .env up -d
```

Les migrations s'appliquent au redémarrage du conteneur `app` ; `queue` et `reverb` redémarrent avec la nouvelle image.

## 6. Sauvegardes

Données à sauvegarder : la base PostgreSQL et le volume `app-storage` (TDR, rapports, imports).

```bash
# Base (fichier daté dans ./backups)
docker compose -f docker-compose.prod.yaml exec postgis sh -c 'pg_dump -U "$POSTGRES_USER" -Fc "$POSTGRES_DB" > /backups/sigpro-$(date +%F).dump'
# Fichiers
docker run --rm -v sigpro-prod_app-storage:/data -v "$PWD/backups":/backups alpine tar czf /backups/storage-$(date +%F).tgz -C /data .
```

À planifier chaque nuit (cron) et à copier hors du serveur. Restauration de la base :
`pg_restore -U sigpro -d sigpro --clean /backups/sigpro-AAAA-MM-JJ.dump` dans le conteneur `postgis`.

## 7. Vérifications

| Contrôle | Commande ou adresse |
|---|---|
| Application | `https://sigpro.domaine.ci/up` doit répondre 200 |
| Conteneurs | `docker compose -f docker-compose.prod.yaml ps` (tous `healthy` ou `running`) |
| Journaux | `docker compose -f docker-compose.prod.yaml logs -f app queue reverb` |
| Collabora | `https://collabora.domaine.ci/hosting/discovery` |
| Temps réel | la cloche de notification se met à jour sans recharger la page |
| E-mails | créer un utilisateur de test : l'invitation doit arriver |

## 8. Sécurité

- `APP_DEBUG=false`, cookies de session chiffrés et sécurisés, HTTPS forcé, en-têtes du proxy pris en compte.
- La base et Redis ne sont pas exposés hors du réseau Docker.
- L'éditeur Collabora n'accède aux documents que par des jetons signés à durée limitée ; il n'est intégrable que depuis `APP_DOMAIN`.
- Changer les mots de passe par défaut de Collabora et GeoServer ; restreindre si possible l'accès à l'interface GeoServer.
