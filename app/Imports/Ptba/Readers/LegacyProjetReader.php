<?php

namespace App\Imports\Ptba\Readers;

use App\Imports\Ptba\ParsedPtba;
use App\Imports\Ptba\Spreadsheet;
use Illuminate\Support\Str;

/**
 * Format historique du PTBA projet (fichier « PforR ») : la colonne A indique le niveau
 * (C, S/C, ILD n, V pour un volet hors ILD, A activité, T tâche) ; les activités sans type
 * sont les lignes portant un libellé et une catégorie ou un coût. Un bloc de coûts par année.
 */
class LegacyProjetReader
{
    public function read(string $path, int $year): ParsedPtba
    {
        $parsed = new ParsedPtba('projet', 'legacy_projet');

        $map = null;
        $component = $subcomponent = $indicator = $activity = null;
        $counters = ['c' => 0, 'sc' => 0, 'v' => 0];
        $withTasks = [];

        foreach (Spreadsheet::rows($path) as ['sheet' => $sheet, 'row' => $row, 'cells' => $cells]) {
            if ($map === null) {
                $map = $this->headerMap($cells, $year, $parsed, $sheet, $row);

                continue;
            }
            if ($sheet !== $map['sheet']) {
                break;
            }

            $type = Spreadsheet::norm($cells[0] ?? '');
            $label = Spreadsheet::text($cells[$map['label']] ?? null);
            if ($label === null || Str::startsWith(Spreadsheet::norm($label), 'total')) {
                continue;
            }

            switch (true) {
                case $type === 'c':
                    $counters['c']++;
                    $counters['sc'] = 0;
                    $component = $parsed->node('project_component', 'C'.$counters['c'], $this->stripPrefix($label, 'composante'), null, $this->declared($cells, $map), $sheet, $row);
                    $subcomponent = $indicator = $activity = null;
                    break;

                case $type === 's/c':
                    if (! $component) {
                        $component = $parsed->node('project_component', 'C1', 'Composante', sheet: $sheet, row: $row);
                        $counters['c'] = 1;
                    }
                    $counters['sc']++;
                    $code = 'C'.$counters['c'].'.'.$counters['sc'];
                    $subcomponent = $parsed->node('project_subcomponent', $code, $label, $component, ['sigle' => 'S/C '.$code] + $this->declared($cells, $map), $sheet, $row);
                    $indicator = $activity = null;
                    break;

                case preg_match('/^ild\s*(\d+)/', $type, $m) === 1:
                    if (! $subcomponent) {
                        $parsed->error($sheet, $row, "Indicateur « {$label} » sans sous-composante (ligne S/C manquante au-dessus).");
                        break;
                    }
                    $indicator = $parsed->node('project_indicator', 'ILD '.$m[1], $label, $subcomponent, ['type' => 'ILD'] + $this->declared($cells, $map), $sheet, $row);
                    $activity = null;
                    break;

                case $type === 'v':
                    if (! $subcomponent) {
                        $parsed->error($sheet, $row, "Volet « {$label} » sans sous-composante (ligne S/C manquante au-dessus).");
                        break;
                    }
                    $counters['v']++;
                    $indicator = $parsed->node('project_indicator', 'HI '.$counters['v'], $label, $subcomponent, ['type' => 'HORS_ILD'] + $this->declared($cells, $map), $sheet, $row);
                    $activity = null;
                    break;

                case $type === 't':
                    if (! $activity) {
                        $parsed->warning($sheet, $row, "Tâche « {$label} » sans activité au-dessus : ignorée.");
                        break;
                    }
                    if (! isset($withTasks[$activity])) {
                        // Les tâches détaillent le coût de l'activité : elles remplacent sa ligne globale.
                        $withTasks[$activity] = true;
                        $parsed->activities[$activity]['lines'] = [];
                    }
                    $this->costLine($parsed, $activity, $cells, $map, $label, $row);
                    break;

                case $type === 'a' || $type === '':
                    if (! $this->looksLikeActivity($cells, $map)) {
                        break; // intertitre sans coût ni catégorie
                    }
                    if (! $indicator) {
                        if (! $subcomponent) {
                            $parsed->error($sheet, $row, "Activité « {$label} » placée avant toute sous-composante.");
                            break;
                        }
                        $counters['v']++;
                        $indicator = $parsed->node('project_indicator', 'HI '.$counters['v'], $parsed->nodes[$subcomponent]['label'], $subcomponent, ['type' => 'HORS_ILD'], $sheet, $row);
                        $parsed->warning($sheet, $row, 'Activités directement sous une sous-composante : regroupées dans un indicateur hors ILD.');
                    }

                    $activity = $parsed->activity([
                        'code' => null,
                        'label' => $label,
                        'parent' => $indicator,
                        'parent_activity' => null,
                        'direction' => Spreadsheet::text($cells[$map['direction'] ?? -1] ?? null),
                        'category' => Spreadsheet::text($cells[$map['category'] ?? -1] ?? null),
                        'procurement_method' => Spreadsheet::text($cells[$map['procurement'] ?? -1] ?? null),
                        'ugp' => Spreadsheet::text($cells[$map['ugp'] ?? -1] ?? null),
                        'starts_on' => Spreadsheet::date($cells[$map['starts_on'] ?? -1] ?? null),
                        'ends_on' => Spreadsheet::date($cells[$map['ends_on'] ?? -1] ?? null),
                        'months' => $this->months($cells, $map),
                        'ild_reference' => str_starts_with($parsed->nodes[$indicator]['code'], 'ILD') ? $parsed->nodes[$indicator]['code'] : null,
                    ], $sheet, $row);
                    $this->costLine($parsed, $activity, $cells, $map, null, $row);
                    break;
            }
        }

        if ($map === null) {
            $parsed->error(null, null, 'Ligne d\'en-tête « Composantes /Activités » introuvable.');
        }

        return $parsed;
    }

    private function headerMap(array $cells, int $year, ParsedPtba $parsed, string $sheet, int $row): ?array
    {
        $labelCol = collect($cells)->search(fn ($v) => str_starts_with(Spreadsheet::norm($v), 'composantes /activites'));
        if ($labelCol === false) {
            return null;
        }

        $map = ['sheet' => $sheet, 'label' => $labelCol, 'months' => []];
        $years = [];
        foreach ($cells as $index => $value) {
            $norm = Spreadsheet::norm($value);
            match (true) {
                str_starts_with($norm, 'categorie') => $map['category'] = $index,
                $norm === 'unite' => $map['unit'] = $index,
                str_starts_with($norm, 'responsable') => $map['direction'] = $index,
                str_starts_with($norm, 'procedure') => $map['procurement'] = $index,
                str_starts_with($norm, 'suivi ugp') => $map['ugp'] = $index,
                str_starts_with($norm, 'debut') => $map['starts_on'] = $index,
                str_starts_with($norm, 'echeancier') => $map['ends_on'] = $index,
                default => null,
            };
            if (preg_match('/^cout total (\d{4})/', $norm, $m)) {
                $years[(int) $m[1]] = $index;
            }
            if (($month = Spreadsheet::month($value)) && strlen($norm) >= 3 && ! str_contains($norm, ' ')) {
                $map['months'][$index] = $month;
            }
        }

        if ($years === []) {
            $parsed->error($sheet, $row, 'Aucune colonne « Coût total AAAA » trouvée.');

            return $map;
        }

        $chosen = $years[$year] ?? null;
        if ($chosen === null) {
            $chosen = max(array_keys($years));
            $parsed->warning($sheet, $row, "Pas de coûts pour {$year} dans le fichier : les coûts {$chosen} sont utilisés.");
            $year = $chosen;
            $chosen = $years[$chosen];
        }
        $parsed->year = array_search($chosen, $years, true);

        // Bloc d'une année : coût unitaire, quantité, fréquence, puis coût total.
        $map['total'] = $chosen;
        $map['unit_cost'] = $chosen - 3;
        $map['quantity'] = $chosen - 2;
        $map['frequency'] = $chosen - 1;

        return $map;
    }

    /** Coût total inscrit dans le fichier pour un niveau, pour contrôle de cohérence. */
    private function declared(array $cells, array $map): array
    {
        return ['declared_total' => isset($map['total']) ? Spreadsheet::number($cells[$map['total']] ?? null) : null];
    }

    private function looksLikeActivity(array $cells, array $map): bool
    {
        return isset($map['category'], $cells[$map['category']])
            || (Spreadsheet::number($cells[$map['total'] ?? -1] ?? null) ?? 0) > 0
            || (Spreadsheet::number($cells[$map['unit_cost'] ?? -1] ?? null) ?? 0) > 0;
    }

    private function costLine(ParsedPtba $parsed, string $activity, array $cells, array $map, ?string $label, int $row): void
    {
        if (! isset($map['total'])) {
            return;
        }

        $unitCost = Spreadsheet::number($cells[$map['unit_cost']] ?? null);
        $quantity = Spreadsheet::number($cells[$map['quantity']] ?? null);
        $frequency = Spreadsheet::number($cells[$map['frequency']] ?? null);
        $total = Spreadsheet::number($cells[$map['total']] ?? null) ?? 0;

        if (! $unitCost && $total <= 0) {
            return;
        }
        $computed = ($unitCost ?? 0) * ($quantity ?? 0) * ($frequency ?: 1);
        if (! $unitCost || ! $quantity) {
            // Seul le total est renseigné : une ligne forfaitaire.
            [$unitCost, $quantity, $frequency] = [$total, 1, 1];
        } elseif ($total > 0 && abs($computed - $total) > max(1, $total * 0.01)) {
            $parsed->warning($parsed->activities[$activity]['sheet'], $row, 'Coût unitaire × quantité × fréquence ne correspond pas au coût total : le coût total ('.number_format($total, 0, ',', ' ').' FCFA) est retenu.');
            [$unitCost, $quantity, $frequency] = [$total, 1, 1];
        }

        $parsed->line($activity, [
            'label' => $label ?? 'Coût '.$parsed->year,
            'unit' => Spreadsheet::text($cells[$map['unit'] ?? -1] ?? null),
            'quantity' => $quantity,
            'frequency' => $frequency ?: 1,
            'duration' => 1,
            'unit_cost' => $unitCost,
            'economic_nature' => null,
            'row' => $row,
        ]);
    }

    /** @return list<int> mois où un montant est échéancé */
    private function months(array $cells, array $map): array
    {
        $months = [];
        foreach ($map['months'] as $index => $month) {
            if ((Spreadsheet::number($cells[$index] ?? null) ?? 0) > 0) {
                $months[] = $month;
            }
        }

        return array_values(array_unique($months));
    }

    private function stripPrefix(string $label, string $prefix): string
    {
        return Str::of($label)->replaceMatches('/^\s*'.$prefix.'\s*:?\s*/iu', '')->squish()->toString() ?: $label;
    }
}
