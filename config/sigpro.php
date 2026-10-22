<?php

return [

    // Compte administrateur créé par le seeder. Sans mot de passe défini,
    // un mot de passe aléatoire est généré : utiliser « Mot de passe oublié ».
    'admin' => [
        'email' => env('SIGPRO_ADMIN_EMAIL', 'admin@sigpro.test'),
        'password' => env('SIGPRO_ADMIN_PASSWORD'),
    ],

    // Comptes de démonstration (un par rôle), créés uniquement en environnement local.
    'demo_password' => env('SIGPRO_DEMO_PASSWORD'),

    // Collabora Online : adresse vue par le navigateur, adresse vue par l'application
    // (réseau Docker) et adresse de SIGPRO vue par Collabora pour les appels WOPI.
    'collabora' => [
        'url' => env('COLLABORA_URL', 'http://localhost:9980'),
        'internal_url' => env('COLLABORA_INTERNAL_URL', env('COLLABORA_URL', 'http://localhost:9980')),
        'wopi_host' => env('COLLABORA_WOPI_HOST', env('APP_URL', 'http://localhost')),
    ],

    // GeoServer : adresse publique (tuiles WMS chargées par le navigateur) et adresse interne
    // (découverte des couches, interrogation des objets). Seules les couches de l'espace de travail sont proposées.
    'geoserver' => [
        'url' => env('GEOSERVER_URL', 'http://localhost:8081/geoserver'),
        'internal_url' => env('GEOSERVER_INTERNAL_URL', env('GEOSERVER_URL', 'http://localhost:8081/geoserver')),
        'workspace' => env('GEOSERVER_WORKSPACE', 'sigpro'),
    ],

];
