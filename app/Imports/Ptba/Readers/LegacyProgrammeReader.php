<?php

namespace App\Imports\Ptba\Readers;

use App\Imports\Ptba\ParsedPtba;
use App\Imports\Ptba\Spreadsheet;
use Illuminate\Support\Str;

/**
 * Format historique du PTBA programme : une feuille par sous-domaine de résultats.
 * La colonne A donne le niveau (Domaine, Sous-Domaine, Composante, Action, Activité, Sous-Activité, Tâche) ;
 * les mois d'exécution sont cochés d'un « X » ; les lignes de coût suivent les tâches.
 * Les feuilles sans en-tête de PTBA (synthèses, simulation) sont ignorées.
 */
class LegacyProgrammeReader
{
    public function read(string $path, int $year): ParsedPtba
    {
        $parsed = new ParsedPtba('programme', 'legacy_programme');
        $sheets = 0;

        $state = null;
        foreach (Spreadsheet::rows($path) as ['sheet' => $sheet, 'row' => $row, 'cells' => $cells]) {
            if (! $state || $state['sheet'] !== $sheet) {
                $state = ['sheet' => $sheet, 'map' => null, 'header_row' => null, 'domain' => null, 'subdomain' => null,
                    'component' => null, 'action' => null, 'action_direction' => null, 'activity' => null,
                    'sub_activity' => null, 'task' => null, 'indicators' => false];
            }

            if ($state['map'] === null) {
                if ($this->isHeader($cells)) {
                    $state['map'] = $this->headerMap($cells);
                    $state['header_row'] = $row;
                    $sheets++;
                }

                continue;
            }

            // Sous-en-têtes (Unité, Quantité…, mois) juste sous l'en-tête principal.
            if ($row <= $state['header_row'] + 3 && $this->completeHeader($state['map'], $cells, $parsed)) {
                continue;
            }

            $this->readRow($parsed, $state, $sheet, $row, $cells);
        }

        if ($sheets === 0) {
            $parsed->error(null, null, 'Aucune feuille de PTBA programme reconnue (en-tête « Domaines de résultats / Libellé / CHRONOGRAMME »).');
        }
        $parsed->year ??= $year;
        if ($parsed->year !== $year) {
            $parsed->warning(null, null, "Le chronogramme du fichier porte sur {$parsed->year}, l'exercice choisi est {$year}.");
        }

        return $parsed;
    }

    private function isHeader(array $cells): bool
    {
        $norms = array_map(fn ($v) => Spreadsheet::norm($v), $cells);

        return collect($norms)->contains(fn ($v) => str_starts_with($v, 'domaines de resultat'))
            && collect($norms)->contains(fn ($v) => str_starts_with($v, 'libelle'));
    }

    private function headerMap(array $cells): array
    {
        $map = ['months' => []];
        foreach ($cells as $index => $value) {
            $norm = Spreadsheet::norm($value);
            $field = match (true) {
                str_starts_with($norm, 'libelle') => 'label',
                str_starts_with($norm, 'structure responsable') => 'direction',
                str_starts_with($norm, 'localisation') => 'localisation',
                str_starts_with($norm, 'partenaire') => 'partner',
                str_starts_with($norm, "type d'activite") => 'activity_type',
                str_contains($norm, 'en lien') => 'ild_reference',
                str_starts_with($norm, 'source de financement') => 'funding_source',
                str_starts_with($norm, 'cout total') => 'total',
                str_starts_with($norm, 'reference dppd programme') => 'ddp_programme',
                str_contains($norm, 'nature de dep') => 'ddp_nature',
                str_starts_with($norm, 'reference dppd action') => 'ddp_action',
                str_starts_with($norm, 'reference dppd activite') => 'ddp_activite',
                str_starts_with($norm, 'nature economique') => 'economic_nature',
                str_contains($norm, 'ligne budget') => 'ddp_ligne',
                $norm === 'ppm' => 'ppm_ref',
                default => null,
            };
            if ($field && ! isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    /** Complète la carte avec les sous-en-têtes ; renvoie vrai si la ligne en faisait partie. */
    private function completeHeader(array &$map, array $cells, ParsedPtba $parsed): bool
    {
        $found = false;
        foreach ($cells as $index => $value) {
            $norm = Spreadsheet::norm($value);
            $field = match ($norm) {
                'unite' => 'unit',
                'quantite' => 'quantity',
                'duree' => 'duration',
                'frequence' => 'frequency',
                'cout unitaire' => 'unit_cost',
                default => null,
            };
            if ($field) {
                $map[$field] = $index;
                $found = true;
            } elseif (preg_match('/^(\d{4})-q[1-4]$/', $norm, $m)) {
                $parsed->year ??= (int) $m[1];
                $found = true;
            } elseif (strlen($norm) <= 9 && ($month = Spreadsheet::month($value)) && ! str_contains($norm, ' ')) {
                $map['months'][$index] = $month;
                $found = true;
            }
        }

        // Une même ligne peut porter les mois et un niveau (« Domaine de Résultat 1 ») : elle est alors aussi lue.
        return $found && ! preg_match('/^(domaine|sous|resultat|composante|action|activit|tache)/', Spreadsheet::norm($cells[0] ?? ''));
    }

    private function readRow(ParsedPtba $parsed, array &$s, string $sheet, int $row, array $cells): void
    {
        $head = Spreadsheet::norm($cells[0] ?? '');
        $label = Spreadsheet::text($cells[$s['map']['label'] ?? 1] ?? null);
        $number = preg_match('/(\d+(?:\.\d+)*)/', $head, $m) ? $m[1] : null;
        $sd = $s['subdomain'] ? $parsed->nodes[$s['subdomain']]['code'] : null;

        if (str_starts_with($head, 'sous-total') || str_starts_with($head, 'total')) {
            return;
        }

        if (str_starts_with($head, 'domaine de resultat')) {
            $s['domain'] = $parsed->node('result_domain', $number ?? '?', (string) $label, sheet: $sheet, row: $row);

            return;
        }

        if (str_starts_with($head, 'sous-domaine')) {
            if (! $s['domain'] && $number) {
                $s['domain'] = $parsed->node('result_domain', Str::before($number, '.'), 'Domaine '.Str::before($number, '.'), sheet: $sheet, row: $row);
            }
            $s['subdomain'] = $parsed->node('result_subdomain', $number ?? $sheet, (string) $label, $s['domain'], $this->declared($cells, $s['map']), $sheet, $row);
            $s['component'] = $s['action'] = $s['activity'] = $s['sub_activity'] = null;

            return;
        }

        if (str_starts_with($head, 'resultat attendu')) {
            if ($s['subdomain']) {
                $parsed->nodes[$s['subdomain']]['extra']['expected_result'] = trim(Str::after((string) Spreadsheet::text($cells[0]), ':')) ?: null;
                $s['indicators'] = true;
            }

            return;
        }

        if (preg_match('/^composante/', $head)) {
            $s['indicators'] = false;
            $sd = $this->ensureSubdomain($parsed, $s, $sheet, $row);
            $s['component'] = $parsed->node('programme_component', "{$sd}-C".($number ?? '?'), (string) $label, $s['subdomain'], $this->declared($cells, $s['map']), $sheet, $row);
            $s['action'] = $s['activity'] = $s['sub_activity'] = null;

            return;
        }

        if ($s['indicators']) {
            // Lignes d'indicateurs du cadre de résultats : libellé, base, cible.
            $text = Spreadsheet::text($cells[2] ?? null);
            if ($text && $s['subdomain']) {
                $parsed->nodes[$s['subdomain']]['extra']['indicators'][] = [
                    'label' => $text,
                    'baseline' => Spreadsheet::text($cells[3] ?? null),
                    'target' => Spreadsheet::text($cells[4] ?? null),
                ];
            }

            return;
        }

        if (str_starts_with($head, 'action')) {
            if (! $s['component']) {
                $sd = $this->ensureSubdomain($parsed, $s, $sheet, $row);
                $s['component'] = $parsed->node('programme_component', "{$sd}-C0", 'Composante non précisée', $s['subdomain'], sheet: $sheet, row: $row);
                $parsed->warning($sheet, $row, "Action « {$label} » sans composante au-dessus : rattachée à une composante « non précisée », à reclasser.");
            }
            $s['action'] = $parsed->node('programme_action', "{$sd}-A".($number ?? '?'), (string) $label, $s['component'], $this->declared($cells, $s['map']), $sheet, $row);
            $s['action_direction'] = $this->value($cells, $s['map'], 'direction');
            $s['activity'] = $s['sub_activity'] = $s['task'] = null;

            return;
        }

        $isSub = str_starts_with($head, 'sous-activit');
        if ($isSub || str_starts_with($head, 'activit')) {
            if (! $s['action']) {
                $sd = $this->ensureSubdomain($parsed, $s, $sheet, $row);
                $s['component'] ??= $parsed->node('programme_component', "{$sd}-C0", 'Composante non précisée', $s['subdomain'], sheet: $sheet, row: $row);
                $s['action'] = $parsed->node('programme_action', "{$sd}-A0", 'Action non précisée', $s['component'], sheet: $sheet, row: $row);
                $parsed->warning($sheet, $row, "Activité « {$label} » sans action au-dessus : rattachée à une action « non précisée », à reclasser.");
            }
            if (! $label) {
                $parsed->warning($sheet, $row, 'Activité sans libellé : ignorée.');

                return;
            }

            $parentActivity = $isSub ? $s['activity'] : null;
            $key = $parsed->activity([
                'code' => "{$sd}-".($number ?? $row),
                'label' => $label,
                'parent' => $s['action'],
                'parent_activity' => $parentActivity,
                'direction' => $this->value($cells, $s['map'], 'direction') ?? $s['action_direction'],
                'localisation' => $this->value($cells, $s['map'], 'localisation'),
                'partner' => $this->value($cells, $s['map'], 'partner'),
                'activity_type' => $this->value($cells, $s['map'], 'activity_type'),
                'ild_reference' => $this->value($cells, $s['map'], 'ild_reference'),
                'funding_source' => $this->value($cells, $s['map'], 'funding_source'),
                'ddp_programme' => $this->value($cells, $s['map'], 'ddp_programme'),
                'ddp_nature' => $this->value($cells, $s['map'], 'ddp_nature'),
                'ddp_action' => $this->value($cells, $s['map'], 'ddp_action'),
                'ddp_activite' => $this->value($cells, $s['map'], 'ddp_activite'),
                'ddp_ligne' => $this->value($cells, $s['map'], 'ddp_ligne'),
                'ppm_ref' => $this->value($cells, $s['map'], 'ppm_ref'),
                'months' => $this->months($cells, $s['map']),
                'starts_on' => null,
                'ends_on' => null,
            ], $sheet, $row);

            if ($isSub) {
                $s['sub_activity'] = $key;
            } else {
                $s['activity'] = $key;
                $s['sub_activity'] = null;
            }
            $s['task'] = null;

            return;
        }

        $target = $s['sub_activity'] ?? $s['activity'];
        if (str_starts_with($head, 'tache')) {
            $s['task'] = trim(($number ? "Tâche {$number} : " : '').$label);
            if ($target) {
                $this->costLine($parsed, $target, $cells, $s['map'], $s['task'], $row);
            }

            return;
        }

        // Ligne de coût rattachée à la tâche précédente (ex. un axe géographique).
        if ($head === '' && $target && $label) {
            $this->costLine($parsed, $target, $cells, $s['map'], $s['task'] ? "{$s['task']} — {$label}" : $label, $row);
        }
    }

    private function costLine(ParsedPtba $parsed, string $activity, array $cells, array $map, string $label, int $row): void
    {
        $unitCost = Spreadsheet::number($cells[$map['unit_cost'] ?? -1] ?? null);
        $total = Spreadsheet::number($cells[$map['total'] ?? -1] ?? null) ?? 0;
        if (! $unitCost && $total <= 0) {
            return; // étape sans coût (élaboration des TDR, transmission…)
        }

        $quantity = Spreadsheet::number($cells[$map['quantity'] ?? -1] ?? null);
        $duration = Spreadsheet::number($cells[$map['duration'] ?? -1] ?? null);
        $frequency = Spreadsheet::number($cells[$map['frequency'] ?? -1] ?? null);
        $computed = ($unitCost ?? 0) * ($quantity ?? 0) * ($duration ?: 1) * ($frequency ?: 1);
        if (! $unitCost || ! $quantity) {
            [$unitCost, $quantity, $duration, $frequency] = [$total, 1, 1, 1];
        } elseif ($total > 0 && abs($computed - $total) > max(1, $total * 0.01)) {
            // Colonnes détournées (km, litres…) : le coût total saisi fait foi.
            $parsed->warning($parsed->activities[$activity]['sheet'], $row, 'Quantité × durée × fréquence × coût unitaire ne correspond pas au coût total : le coût total ('.number_format($total, 0, ',', ' ').' FCFA) est retenu.');
            [$unitCost, $quantity, $duration, $frequency] = [$total, 1, 1, 1];
        }

        $parsed->line($activity, [
            'label' => Str::limit($label, 500),
            'unit' => Spreadsheet::text($cells[$map['unit'] ?? -1] ?? null),
            'quantity' => $quantity,
            'frequency' => $frequency ?: 1,
            'duration' => $duration ?: 1,
            'unit_cost' => $unitCost,
            'economic_nature' => Spreadsheet::text($cells[$map['economic_nature'] ?? -1] ?? null),
            'row' => $row,
        ]);
    }

    /**
     * Feuille sans lignes Domaine / Sous-domaine : sous-domaine implicite portant le nom de la feuille.
     * Renvoie le code du sous-domaine courant.
     */
    private function ensureSubdomain(ParsedPtba $parsed, array &$s, string $sheet, int $row): string
    {
        if (! $s['subdomain']) {
            $domain = $parsed->node('result_domain', '0', 'Domaine non précisé', sheet: $sheet, row: $row);
            $code = 'F'.(preg_match('/^\s*(\d+)/', $sheet, $m) ? $m[1] : count($parsed->nodes));
            $s['subdomain'] = $parsed->node('result_subdomain', $code, trim(preg_replace('/^\s*\d+\.?\s*/', '', $sheet)), $domain, sheet: $sheet, row: $row);
            $parsed->warning($sheet, $row, "Feuille sans sous-domaine de résultats : ses activités sont rattachées au sous-domaine « {$sheet} », à reclasser.");
        }

        return $parsed->nodes[$s['subdomain']]['code'];
    }

    /** Coût total inscrit dans le fichier pour un niveau, pour contrôle de cohérence. */
    private function declared(array $cells, array $map): array
    {
        return ['declared_total' => isset($map['total']) ? Spreadsheet::number($cells[$map['total']] ?? null) : null];
    }

    private function value(array $cells, array $map, string $field): ?string
    {
        return isset($map[$field]) ? Spreadsheet::text($cells[$map[$field]] ?? null) : null;
    }

    /** @return list<int> */
    private function months(array $cells, array $map): array
    {
        $months = [];
        foreach ($map['months'] as $index => $month) {
            if (Spreadsheet::norm($cells[$index] ?? '') === 'x') {
                $months[] = $month;
            }
        }

        return array_values(array_unique($months));
    }
}
