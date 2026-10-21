<?php

namespace App\Imports\Ptba;

use App\Models\Direction;

/**
 * Retrouve une direction à partir de la « Structure responsable » saisie dans Excel
 * (« DAPS COGES », « DAAJE/DAPS COGES/DCSPA », « DPFC, DELC »…) : la première reconnue est retenue.
 */
class DirectionResolver
{
    /** @var array<string, int> */
    private array $byCode;

    public function __construct()
    {
        $this->byCode = Direction::query()->pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [self::key($code) => $id])
            ->all();
    }

    public function resolve(?string $raw): ?int
    {
        foreach ($this->candidates($raw) as $candidate) {
            if (isset($this->byCode[self::key($candidate)])) {
                return $this->byCode[self::key($candidate)];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function candidates(?string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('#[/,;\n]+#', (string) $raw))));
    }

    private static function key(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
