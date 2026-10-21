<?php

namespace App\Imports\Ptba;

use Illuminate\Support\Str;

/**
 * Résultat normalisé de la lecture d'un fichier, quel que soit son format :
 * nœuds de la hiérarchie, activités avec leurs lignes budgétaires, anomalies relevées.
 */
class ParsedPtba
{
    /** @var array<string, array<string, mixed>> */
    public array $nodes = [];

    /** @var array<string, array<string, mixed>> */
    public array $activities = [];

    /** @var list<array{level: string, sheet: ?string, row: ?int, message: string}> */
    public array $issues = [];

    public ?int $year = null;

    public function __construct(public string $volet, public string $format) {}

    /** Ajoute (ou complète) un nœud de hiérarchie ; renvoie sa clé. */
    public function node(string $type, string $code, string $label, ?string $parent = null, array $extra = [], ?string $sheet = null, ?int $row = null): string
    {
        $key = "{$type}:{$code}";

        if (isset($this->nodes[$key])) {
            if ($this->nodes[$key]['label'] === '' && $label !== '') {
                $this->nodes[$key]['label'] = $label;
            }
            $this->nodes[$key]['extra'] = array_merge($this->nodes[$key]['extra'], array_filter($extra, fn ($v) => $v !== null && $v !== []));
        } else {
            $this->nodes[$key] = compact('type', 'code', 'label', 'parent', 'extra', 'sheet', 'row') + ['position' => count($this->nodes)];
        }

        return $key;
    }

    /**
     * Ajoute une activité. Sans code, la clé dépend du parent et du libellé (rapprochement à la réimportation).
     *
     * @param  array<string, mixed>  $data
     */
    public function activity(array $data, ?string $sheet, ?int $row): string
    {
        $base = $data['code']
            ? $this->volet.':'.Str::upper($data['code'])
            : $this->volet.':'.$data['parent'].':'.substr(md5(Spreadsheet::norm($data['label'])), 0, 12);

        $key = $base;
        for ($i = 2; isset($this->activities[$key]); $i++) {
            $key = "{$base}#{$i}";
        }
        if ($key !== $base) {
            $this->warning($sheet, $row, "Activité en double (« {$data['label']} ») : elle est importée séparément.");
        }

        $this->activities[$key] = $data + [
            'key' => $key,
            'sheet' => $sheet,
            'row' => $row,
            'lines' => [],
            'position' => count($this->activities),
        ];

        return $key;
    }

    /** @param  array<string, mixed>  $line */
    public function line(string $activityKey, array $line): void
    {
        $this->activities[$activityKey]['lines'][] = $line;
    }

    public function error(?string $sheet, ?int $row, string $message): void
    {
        $this->issues[] = ['level' => 'error', 'sheet' => $sheet, 'row' => $row, 'message' => $message];
    }

    public function warning(?string $sheet, ?int $row, string $message): void
    {
        $this->issues[] = ['level' => 'warning', 'sheet' => $sheet, 'row' => $row, 'message' => $message];
    }

    public function hasErrors(): bool
    {
        return collect($this->issues)->contains('level', 'error');
    }

    public function toArray(): array
    {
        return [
            'volet' => $this->volet,
            'format' => $this->format,
            'year' => $this->year,
            'nodes' => $this->nodes,
            'activities' => $this->activities,
            'issues' => $this->issues,
        ];
    }

    public static function fromArray(array $data): self
    {
        $parsed = new self($data['volet'], $data['format']);
        $parsed->year = $data['year'];
        $parsed->nodes = $data['nodes'];
        $parsed->activities = $data['activities'];
        $parsed->issues = $data['issues'];

        return $parsed;
    }
}
