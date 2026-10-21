<?php

namespace App\Imports\Ptba\Readers;

use App\Imports\Ptba\ParsedPtba;
use App\Imports\Ptba\Spreadsheet;
use Illuminate\Support\Str;

/**
 * Modèle SIGPRO (config/ptba_import.php) : une ligne par ligne budgétaire.
 * Les lignes d'une même activité partagent son code.
 */
class SigproReader
{
    public function read(string $path, string $volet, int $year): ParsedPtba
    {
        $parsed = new ParsedPtba($volet, 'sigpro');
        $parsed->year = $year;
        $columns = config("ptba_import.columns.{$volet}");
        $sheetName = config("ptba_import.sheets.{$volet}");

        $map = null;
        foreach (Spreadsheet::rows($path, fn ($name) => $name === $sheetName) as ['sheet' => $sheet, 'row' => $row, 'cells' => $cells]) {
            if ($map === null) {
                $map = $this->headerMap($cells, $columns);
                if ($map === null) {
                    continue;
                }
                foreach ($columns as $field => [$header, , $required]) {
                    if ($required && ! isset($map[$field])) {
                        $parsed->error($sheet, $row, "Colonne obligatoire absente : « {$header} ».");
                    }
                }
                if ($parsed->hasErrors()) {
                    return $parsed;
                }

                continue;
            }

            $get = fn (string $field) => isset($map[$field]) ? ($cells[$map[$field]] ?? null) : null;
            if (Spreadsheet::text($get('activity_label')) === null && Spreadsheet::text($get('activity_code')) === null) {
                continue;
            }

            $parent = $volet === 'projet'
                ? $this->projectParent($parsed, $get, $sheet, $row)
                : $this->programmeParent($parsed, $get, $sheet, $row);

            $code = Spreadsheet::text($get('activity_code'));
            $label = Spreadsheet::text($get('activity_label'));
            if ($code === null || $label === null || $parent === null) {
                $parsed->error($sheet, $row, 'Code, libellé de l\'activité et niveaux de la hiérarchie sont obligatoires.');

                continue;
            }

            $key = $volet.':'.Str::upper($code);
            if (! isset($parsed->activities[$key])) {
                $parentActivity = Spreadsheet::text($get('parent_activity_code'));
                $parsed->activity([
                    'code' => $code,
                    'label' => $label,
                    'parent' => $parent,
                    'parent_activity' => $parentActivity ? $volet.':'.Str::upper($parentActivity) : null,
                    'direction' => Spreadsheet::text($get('direction')),
                    'starts_on' => Spreadsheet::date($get('starts_on')),
                    'ends_on' => Spreadsheet::date($get('ends_on')),
                    'months' => Spreadsheet::monthList($get('months')),
                    'category' => Spreadsheet::text($get('category')),
                    'activity_type' => Spreadsheet::text($get('activity_type')),
                    'localisation' => Spreadsheet::text($get('localisation')),
                    'funding_source' => Spreadsheet::text($get('funding_source')),
                    'ugp' => Spreadsheet::text($get('ugp')),
                    'procurement_method' => Spreadsheet::text($get('procurement_method')),
                    'partner' => Spreadsheet::text($get('partner')),
                    'ild_reference' => Spreadsheet::text($get('ild_reference')),
                    'ddp_programme' => Spreadsheet::text($get('ddp_programme')),
                    'ddp_action' => Spreadsheet::text($get('ddp_action')),
                    'ddp_activite' => Spreadsheet::text($get('ddp_activite')),
                    'ddp_ligne' => Spreadsheet::text($get('ddp_ligne')),
                    'ppm_ref' => Spreadsheet::text($get('ppm_ref')),
                ], $sheet, $row);
            } elseif (Spreadsheet::norm($parsed->activities[$key]['label']) !== Spreadsheet::norm($label)) {
                $parsed->error($sheet, $row, "Le code « {$code} » est déjà utilisé par une autre activité (ligne {$parsed->activities[$key]['row']}).");

                continue;
            }

            $this->line($parsed, $key, $get, $sheet, $row);
        }

        if ($map === null) {
            $parsed->error(null, null, "Feuille « {$sheetName} » introuvable ou sans ligne d'en-tête reconnue.");
        }

        return $parsed;
    }

    /** @return array<string, int>|null colonnes repérées par leur intitulé */
    private function headerMap(array $cells, array $columns): ?array
    {
        $byHeader = [];
        foreach ($cells as $index => $value) {
            $byHeader[Spreadsheet::norm($value)] = $index;
        }

        $map = [];
        foreach ($columns as $field => [$header]) {
            if (isset($byHeader[Spreadsheet::norm($header)])) {
                $map[$field] = $byHeader[Spreadsheet::norm($header)];
            }
        }

        return isset($map['activity_code'], $map['activity_label']) ? $map : null;
    }

    private function projectParent(ParsedPtba $parsed, callable $get, string $sheet, int $row): ?string
    {
        $c = Spreadsheet::text($get('component_code'));
        $sc = Spreadsheet::text($get('subcomponent_code'));
        $ind = Spreadsheet::text($get('indicator_code'));
        if (! $c || ! $sc || ! $ind) {
            return null;
        }

        $type = Str::contains(Spreadsheet::norm($get('indicator_type')), 'hors') ? 'HORS_ILD' : 'ILD';
        $ck = $parsed->node('project_component', $c, (string) Spreadsheet::text($get('component_label')), sheet: $sheet, row: $row);
        $sk = $parsed->node('project_subcomponent', $sc, (string) Spreadsheet::text($get('subcomponent_label')), $ck, sheet: $sheet, row: $row);

        return $parsed->node('project_indicator', $ind, (string) Spreadsheet::text($get('indicator_label')), $sk, ['type' => $type], $sheet, $row);
    }

    private function programmeParent(ParsedPtba $parsed, callable $get, string $sheet, int $row): ?string
    {
        $d = Spreadsheet::text($get('domain_code'));
        $sd = Spreadsheet::text($get('subdomain_code'));
        $c = Spreadsheet::text($get('component_code'));
        $a = Spreadsheet::text($get('action_code'));
        if (! $d || ! $sd || ! $c || ! $a) {
            return null;
        }

        $dk = $parsed->node('result_domain', $d, (string) Spreadsheet::text($get('domain_label')), sheet: $sheet, row: $row);
        $sk = $parsed->node('result_subdomain', $sd, (string) Spreadsheet::text($get('subdomain_label')), $dk, sheet: $sheet, row: $row);
        $ck = $parsed->node('programme_component', $c, (string) Spreadsheet::text($get('component_label')), $sk, sheet: $sheet, row: $row);

        return $parsed->node('programme_action', $a, (string) Spreadsheet::text($get('action_label')), $ck, sheet: $sheet, row: $row);
    }

    private function line(ParsedPtba $parsed, string $key, callable $get, string $sheet, int $row): void
    {
        $values = [];
        foreach (['quantity', 'frequency', 'duration', 'unit_cost'] as $field) {
            $raw = $get($field);
            $values[$field] = Spreadsheet::number($raw);
            if ($raw !== null && $values[$field] === null) {
                $parsed->error($sheet, $row, 'Valeur non numérique pour « '.config("ptba_import.columns.{$parsed->volet}.{$field}.0")." » : {$raw}.");
            } elseif ($values[$field] !== null && $values[$field] < 0) {
                $parsed->error($sheet, $row, 'Les montants et quantités ne peuvent pas être négatifs.');
            }
        }

        if ($values['unit_cost'] === null && Spreadsheet::text($get('line_label')) === null) {
            return;
        }

        $parsed->line($key, [
            'label' => Spreadsheet::text($get('line_label')),
            'unit' => Spreadsheet::text($get('unit')),
            'quantity' => $values['quantity'] ?? 1,
            'frequency' => $values['frequency'] ?? 1,
            'duration' => $values['duration'] ?? 1,
            'unit_cost' => $values['unit_cost'] ?? 0,
            'economic_nature' => Spreadsheet::text($get('economic_nature')),
            'row' => $row,
        ]);
    }
}
