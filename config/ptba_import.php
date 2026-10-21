<?php

/*
| Modèle d'import SIGPRO : une ligne par ligne budgétaire, la hiérarchie et l'activité étant répétées.
| Le lecteur repère les colonnes par leur intitulé (l'ordre peut changer) ; le générateur de modèle
| utilise cette même définition. Pour modifier le modèle, changer ce fichier suffit.
|
| Chaque colonne : champ => [intitulé, largeur, obligatoire, liste déroulante (directions, categories,
| unites, ugps, sources, types, ild) ou null, aide].
*/

$activity = [
    'activity_code' => ['Code activité', 14, true, null, 'Identifiant unique de l\'activité, ex. A1.1.01. Sert à mettre à jour lors d\'une réimportation.'],
    'activity_label' => ['Activité', 60, true, null, null],
    'parent_activity_code' => ['Code activité parente', 14, false, null, 'Pour une sous-activité : code de l\'activité parente.'],
    'direction' => ['Structure responsable', 16, true, 'directions', 'Sigle de la direction (liste).'],
    'starts_on' => ['Début', 12, false, null, 'Date JJ/MM/AAAA.'],
    'ends_on' => ['Fin', 12, false, null, 'Date JJ/MM/AAAA.'],
    'months' => ['Mois d\'exécution', 14, false, null, 'Ex. 1,2,3 ou janv;mars.'],
    'category' => ['Catégorie de dépense', 22, false, 'categories', null],
    'activity_type' => ['Type d\'activité', 18, false, 'types', null],
    'localisation' => ['Localisation', 18, false, null, null],
    'funding_source' => ['Source de financement', 18, false, 'sources', null],
];

$line = [
    'line_label' => ['Ligne de coût', 40, false, null, 'Détail du coût (tâche, poste de dépense).'],
    'unit' => ['Unité', 14, false, 'unites', null],
    'quantity' => ['Quantité', 10, false, null, null],
    'frequency' => ['Fréquence', 10, false, null, null],
    'duration' => ['Durée', 10, false, null, null],
    'unit_cost' => ['Coût unitaire (FCFA)', 16, false, null, null],
    'economic_nature' => ['Nature économique', 18, false, null, null],
];

return [

    'sheets' => [
        'projet' => 'PTBA projet',
        'programme' => 'PTBA programme',
    ],

    'columns' => [
        'projet' => [
            'component_code' => ['Code composante', 12, true, null, 'Ex. C1.'],
            'component_label' => ['Composante', 30, true, null, null],
            'subcomponent_code' => ['Code sous-composante', 14, true, null, 'Ex. C1.1.'],
            'subcomponent_label' => ['Sous-composante', 30, true, null, null],
            'indicator_code' => ['Code indicateur', 12, true, null, 'Ex. ILD 1.'],
            'indicator_type' => ['Type indicateur', 12, true, 'ild', 'ILD ou HORS_ILD.'],
            'indicator_label' => ['Indicateur', 30, true, null, null],
            ...$activity,
            'ugp' => ['Suivi UGP', 12, false, 'ugps', null],
            'procurement_method' => ['Procédure d\'acquisition', 20, false, null, null],
            ...$line,
        ],
        'programme' => [
            'domain_code' => ['Code domaine', 10, true, null, 'Ex. 1.'],
            'domain_label' => ['Domaine de résultats', 30, true, null, null],
            'subdomain_code' => ['Code sous-domaine', 12, true, null, 'Ex. 1.2.'],
            'subdomain_label' => ['Sous-domaine', 30, true, null, null],
            'component_code' => ['Code composante', 12, true, null, 'Ex. 1.2-C1.'],
            'component_label' => ['Composante', 30, true, null, null],
            'action_code' => ['Code action', 12, true, null, 'Ex. 1.2-A1.1.'],
            'action_label' => ['Action', 30, true, null, null],
            ...$activity,
            'partner' => ['Partenaire de mise en œuvre', 18, false, null, null],
            'ild_reference' => ['ILD en lien', 10, false, null, null],
            'ddp_programme' => ['Référence DPPD Programme', 14, false, null, null],
            'ddp_action' => ['Référence DPPD Action', 14, false, null, null],
            'ddp_activite' => ['Référence DPPD Activité', 14, false, null, null],
            'ddp_ligne' => ['Référence DPPD Ligne budgétaire', 14, false, null, null],
            'ppm_ref' => ['PPM', 10, false, null, null],
            ...$line,
        ],
    ],

    'activity_types' => ['Contractuelle RLD', 'Non Contractuelle', 'Atelier', 'Formation', 'Mission', 'Acquisition', 'Étude', 'Consultance'],

    // Taille maximale du fichier importé (Ko).
    'max_size' => 20480,
];
