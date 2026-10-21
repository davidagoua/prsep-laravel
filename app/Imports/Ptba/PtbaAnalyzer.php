<?php

namespace App\Imports\Ptba;

use App\Imports\Ptba\Readers\LegacyProgrammeReader;
use App\Imports\Ptba\Readers\LegacyProjetReader;
use App\Imports\Ptba\Readers\SigproReader;
use App\Models\Activity;
use App\Models\BudgetLine;
use App\Models\Exercice;

/**
 * Étapes « contrôler » et « prévisualiser » de l'assistant : détecte le format, lit le fichier,
 * vérifie les données et calcule le résumé (arbre et totaux, activités créées ou mises à jour).
 */
class PtbaAnalyzer
{
    public const FORMATS = [
        'sigpro' => 'Modèle SIGPRO',
        'legacy_projet' => 'PTBA projet (format historique)',
        'legacy_programme' => 'PTBA programme (format historique)',
    ];

    /** @return array{format: string, volet: string}|null */
    public function detect(string $path): ?array
    {
        $sheets = Spreadsheet::sheetNames($path);
        foreach (config('ptba_import.sheets') as $volet => $name) {
            if (in_array($name, $sheets, true)) {
                return ['format' => 'sigpro', 'volet' => $volet];
            }
        }

        $seen = 0;
        foreach (Spreadsheet::rows($path) as ['cells' => $cells]) {
            $norms = array_map(fn ($v) => Spreadsheet::norm($v), $cells);
            if (collect($norms)->contains(fn ($v) => str_starts_with($v, 'composantes /activites'))) {
                return ['format' => 'legacy_projet', 'volet' => 'projet'];
            }
            if (collect($norms)->contains(fn ($v) => str_starts_with($v, 'domaines de resultat'))
                && collect($norms)->contains(fn ($v) => str_starts_with($v, 'libelle'))) {
                return ['format' => 'legacy_programme', 'volet' => 'programme'];
            }
            if (++$seen > 400) {
                break;
            }
        }

        return null;
    }

    public function analyze(string $path, Exercice $exercice): ParsedPtba
    {
        $detected = $this->detect($path);
        if (! $detected) {
            $parsed = new ParsedPtba('projet', 'inconnu');
            $parsed->error(null, null, 'Format non reconnu. Utilisez le modèle SIGPRO téléchargeable ou un PTBA au format historique (projet ou programme).');

            return $parsed;
        }

        $parsed = match ($detected['format']) {
            'sigpro' => app(SigproReader::class)->read($path, $detected['volet'], $exercice->year),
            'legacy_projet' => app(LegacyProjetReader::class)->read($path, $exercice->year),
            'legacy_programme' => app(LegacyProgrammeReader::class)->read($path, $exercice->year),
        };

        $this->check($parsed);

        return $parsed;
    }

    private function check(ParsedPtba $parsed): void
    {
        if ($parsed->activities === [] && ! $parsed->hasErrors()) {
            $parsed->error(null, null, 'Aucune activité trouvée dans le fichier.');
        }

        $directions = new DirectionResolver;
        $unknown = [];
        foreach ($parsed->activities as $key => $activity) {
            if ($activity['parent_activity'] && ! isset($parsed->activities[$activity['parent_activity']])) {
                $parsed->warning($activity['sheet'], $activity['row'], "Activité parente introuvable pour « {$activity['label']} » : importée comme activité principale.");
                $parsed->activities[$key]['parent_activity'] = null;
            }

            if (! $activity['direction']) {
                $parsed->warning($activity['sheet'], $activity['row'], "« {$activity['label']} » n'a pas de structure responsable.");
            } elseif (! $directions->resolve($activity['direction'])) {
                $unknown[$activity['direction']][] = $activity['row'];
            }

            if ($activity['starts_on'] && $activity['ends_on'] && $activity['ends_on'] < $activity['starts_on']) {
                $parsed->warning($activity['sheet'], $activity['row'], "« {$activity['label']} » se termine avant de commencer : dates ignorées.");
                $parsed->activities[$key]['starts_on'] = $parsed->activities[$key]['ends_on'] = null;
            }
        }

        // Cohérence avec les totaux inscrits dans le fichier (blocs annexes, doubles comptes…).
        $totals = $this->nodeTotals($parsed);
        foreach ($parsed->nodes as $key => $node) {
            $declared = $node['extra']['declared_total'] ?? null;
            $read = $totals[$key]['amount'] ?? 0;
            if ($declared !== null && $declared > 0 && abs($read - $declared) > max(1000, $declared * 0.02)) {
                $parsed->warning($node['sheet'], $node['row'], sprintf(
                    '« %s » : %s FCFA lus dans le détail, %s FCFA inscrits dans le fichier. Vérifiez les lignes de coût de ce niveau.',
                    $node['label'], number_format($read, 0, ',', ' '), number_format($declared, 0, ',', ' '),
                ));
            }
        }

        foreach ($unknown as $code => $rows) {
            $parsed->warning(null, $rows[0], "Structure « {$code} » inconnue (".count($rows).' activité(s)) : ajoutez-la dans Administration › Directions ou corrigez le fichier, sinon ces activités resteront sans direction.');
        }
    }

    /** @return array<string, array{amount: int, activities: int}> montant et nombre d'activités par nœud */
    private function nodeTotals(ParsedPtba $parsed): array
    {
        $totals = [];
        foreach ($parsed->activities as $activity) {
            $amount = collect($activity['lines'])->sum(fn ($l) => BudgetLine::computeAmount($l['quantity'], $l['frequency'], $l['duration'], $l['unit_cost']));
            for ($node = $activity['parent']; $node; $node = $parsed->nodes[$node]['parent'] ?? null) {
                $totals[$node]['amount'] = ($totals[$node]['amount'] ?? 0) + $amount;
                $totals[$node]['activities'] = ($totals[$node]['activities'] ?? 0) + 1;
            }
        }

        return $totals;
    }

    /**
     * Résumé pour la prévisualisation : comptes, total, arbre des niveaux avec leurs montants.
     */
    public function summarize(ParsedPtba $parsed, Exercice $exercice): array
    {
        $existing = Activity::where('exercice_id', $exercice->id)->whereIn('import_key', array_keys($parsed->activities))->count();

        $totals = $this->nodeTotals($parsed);
        $lines = collect($parsed->activities)->sum(fn ($a) => count($a['lines']));

        $tree = collect($parsed->nodes)
            ->map(fn ($node, $key) => [
                'key' => $key,
                'type' => $node['type'],
                'code' => $node['code'],
                'label' => $node['label'],
                'parent' => $node['parent'],
                'amount' => $totals[$key]['amount'] ?? 0,
                'activities' => $totals[$key]['activities'] ?? 0,
            ])
            ->values()
            ->all();

        return [
            'volet' => $parsed->volet,
            'format' => $parsed->format,
            'format_label' => self::FORMATS[$parsed->format] ?? $parsed->format,
            'year' => $parsed->year,
            'nodes' => count($parsed->nodes),
            'activities' => count($parsed->activities),
            'new_activities' => count($parsed->activities) - $existing,
            'updated_activities' => $existing,
            'lines' => $lines,
            'total' => collect($tree)->whereNull('parent')->sum('amount'),
            'tree' => $tree,
        ];
    }
}
