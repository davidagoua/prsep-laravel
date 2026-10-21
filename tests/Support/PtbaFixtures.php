<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Petits classeurs reproduisant la structure des trois formats d'import.
 */
class PtbaFixtures
{
    /** Modèle SIGPRO projet : 2 activités, 3 lignes de coût. */
    public static function sigproProjet(array $overrides = []): string
    {
        $headers = collect(config('ptba_import.columns.projet'))->map(fn ($c) => $c[0])->values()->all();
        $row = fn (array $v) => collect(array_keys(config('ptba_import.columns.projet')))->map(fn ($k) => $v[$k] ?? null)->all();
        $base = [
            'component_code' => 'C1', 'component_label' => 'Assistance technique',
            'subcomponent_code' => 'C1.1', 'subcomponent_label' => 'Appuis techniques',
            'indicator_code' => 'ILD 1', 'indicator_type' => 'ILD', 'indicator_label' => 'Alphabétisation familiale',
        ];

        return self::write('PTBA projet', [
            $headers,
            $row(array_merge($base + ['activity_code' => 'A1', 'activity_label' => 'Organiser les ateliers', 'direction' => 'DSPA', 'months' => '3,4',
                'line_label' => 'Perdiem', 'unit' => 'H/J', 'quantity' => 10, 'frequency' => 2, 'unit_cost' => 50000], $overrides['A1'] ?? [])),
            $row($base + ['activity_code' => 'A1', 'activity_label' => 'Organiser les ateliers', 'direction' => 'DSPA',
                'line_label' => 'Location de salle', 'quantity' => 1, 'unit_cost' => 300000]),
            $row(array_merge(['component_code' => 'C1', 'component_label' => 'Assistance technique', 'subcomponent_code' => 'C1.1', 'subcomponent_label' => 'Appuis techniques',
                'indicator_code' => 'HI 1', 'indicator_type' => 'HORS_ILD', 'indicator_label' => 'Gestion du projet',
                'activity_code' => 'A2', 'activity_label' => 'Auditer les comptes', 'direction' => 'INCONNUE',
                'line_label' => 'Audit', 'quantity' => 1, 'unit_cost' => 1000000], $overrides['A2'] ?? [])),
        ]);
    }

    /** Format historique projet : lignes typées C, S/C, ILD, V, activités et tâches. */
    public static function legacyProjet(): string
    {
        $h = array_fill(0, 22, null);
        $header = $h;
        $header[1] = 'Composantes /Activités/Nature dépense';
        $header[2] = 'Catégorie de dépense';
        $header[3] = 'unité';
        [$header[8], $header[9], $header[10], $header[11]] = ['Coût unitaire (F CFA)', 'Quantité', 'Fréquence', 'Coût total 2025 ( FCFA)'];
        $header[17] = 'RESPONSABLE MENA';
        $header[20] = 'Début Exécution';

        $line = function (?string $type, string $label, array $values = []) use ($h) {
            $r = $h;
            $r[0] = $type;
            $r[1] = $label;
            foreach ($values as $i => $v) {
                $r[$i] = $v;
            }

            return $r;
        };

        return self::write('PforR', [
            $header,
            $line(null, 'TOTAL PROJET', [11 => 999]),
            $line('C', 'COMPOSANTE : Assistance Technique', [11 => 2_600_000]),
            $line('S/C', 'Appuis Techniques aux Structures', [11 => 2_600_000]),
            $line('ILD 1', 'Alphabétisation familiale', [11 => 600_000]),
            $line(null, 'Organiser deux ateliers', [2 => 'Atelier', 3 => 'H/J', 8 => 10_000, 9 => 30, 10 => 2, 11 => 600_000, 17 => 'DSPA']),
            $line('V', 'Gestion du projet', [11 => 2_000_000]),
            $line('A', 'Assurer la rémunération du personnel', [2 => 'Consultance', 11 => 2_000_000, 17 => 'DAF']),
            $line('T', 'Contrat du coordonnateur', [8 => 1_500_000, 9 => 1, 10 => 1, 11 => 1_500_000]),
            $line('T', 'Contrat du comptable', [8 => 500_000, 9 => 1, 10 => 1, 11 => 500_000]),
        ]);
    }

    /** Format historique programme : une feuille de sous-domaine avec indicateurs, composante, action, activités, tâches. */
    public static function legacyProgramme(): string
    {
        $w = fn (array $values) => array_replace(array_fill(0, 39, null), $values);
        $months = [9 => 'Janv', 10 => 'Fev', 11 => 'Mars', 13 => 'Avril', 14 => 'Mai', 15 => 'Juin'];

        return self::write('2.AE-Prg', [
            $w([0 => 'MINISTERE DE L\'EDUCATION NATIONALE']),
            $w([0 => 'Domaines de résultats', 1 => 'Libellé', 5 => 'Structure responsable ', 6 => 'Localisation géographique', 8 => "Type d'activité", 9 => 'CHRONOGRAMME', 24 => 'DLI en lien', 25 => 'Source de financement', 31 => 'Coût total', 38 => 'PPM']),
            $w([9 => '2025-Q1', 13 => '2025-Q2', 26 => 'Unité', 27 => 'Quantité', 28 => 'Durée', 29 => 'Fréquence', 30 => 'Coût unitaire']),
            $w([0 => 'Domaine de Résultat 1', 1 => 'Améliorer l\'accès'] + $months),
            $w([0 => 'Sous-Domaine de Résultat 1.2', 1 => 'Accès à l\'école', 31 => 12_300_000]),
            $w([0 => 'Résultat attendu : Le MENA assure l\'accès', 1 => 'Indicateurs ', 3 => 'Base ', 4 => 'Cible ']),
            $w([1 => 1, 2 => 'Nombre de salles construites', 3 => 0, 4 => 1000]),
            $w([0 => 'Composante 1', 1 => 'Carte scolaire', 31 => 12_300_000]),
            $w([0 => 'Action 1.1', 1 => 'Renforcer le cadre', 5 => 'DSPA']),
            $w([0 => 'Activité 1.1.1', 1 => 'Développer les normes', 8 => 'Contractuelle RLD', 10 => 'X', 11 => 'X', 25 => 'Programme PRSEB']),
            $w([0 => 'Sous-Activité 1.1.1.1', 1 => 'Recruter un cabinet', 13 => 'X']),
            $w([0 => 'Tâche 1.1.1.1.1', 1 => 'Elaborer les TDR', 27 => 1, 28 => 1, 29 => 1, 30 => 0, 31 => 0]),
            $w([0 => 'Tâche 1.1.1.1.2', 1 => 'Recruter un consultant', 26 => 'Homme/jour', 27 => 1, 28 => 45, 29 => 1, 30 => 200_000, 31 => 9_000_000]),
            $w([0 => 'Tâche 1.1.1.1.3', 1 => 'Carburant', 26 => '1896 km', 27 => 284, 28 => 248850, 29 => 7000, 30 => 875, 31 => 300_000]),
            $w([0 => 'Activité 1.1.2', 1 => 'Former les agents']),
            $w([0 => 'Tâche 1.1.2.1', 1 => 'Perdiem des participants']),
            $w([1 => 'Axe 1 : Bouaké', 26 => 'Perdiem', 27 => 10, 28 => 5, 29 => 1, 30 => 30_000, 31 => 1_500_000]),
            $w([1 => 'Axe 2 : Korhogo', 26 => 'Perdiem', 27 => 10, 28 => 5, 29 => 1, 30 => 30_000, 31 => 1_500_000]),
            $w([0 => 'Sous-Total 1.2', 31 => 12_300_000]),
        ]);
    }

    private static function write(string $sheetName, array $rows): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle($sheetName)->fromArray($rows, null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'ptba').'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
