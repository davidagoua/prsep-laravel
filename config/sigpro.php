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

    'collabora' => [
        'url' => env('COLLABORA_URL', 'http://localhost:9980'),
    ],

];
