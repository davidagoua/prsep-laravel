<?php

namespace App\Imports\Ptba;

use DateTimeInterface;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Lecture en flux d'un classeur Excel (valeurs calculées des formules) et petits outils de conversion.
 */
class Spreadsheet
{
    private const MONTHS = [
        'jan' => 1, 'fev' => 2, 'feb' => 2, 'mar' => 3, 'avr' => 4, 'apr' => 4, 'mai' => 5, 'may' => 5,
        'juin' => 6, 'jun' => 6, 'juil' => 7, 'jul' => 7, 'aou' => 8, 'aug' => 8, 'sep' => 9,
        'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Toutes les lignes non vides, feuille par feuille.
     *
     * @return Generator<int, array{sheet: string, row: int, cells: array<int, mixed>}>
     */
    public static function rows(string $path, ?callable $sheetFilter = null): Generator
    {
        $reader = new Reader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheetFilter && ! $sheetFilter($sheet->getName())) {
                    continue;
                }

                foreach ($sheet->getRowIterator() as $number => $row) {
                    $cells = [];
                    foreach ($row->cells as $index => $cell) {
                        $value = match (true) {
                            $cell instanceof FormulaCell => $cell->getComputedValue(),
                            $cell instanceof ErrorCell => null,
                            default => $cell->getValue(),
                        };
                        if (is_string($value)) {
                            $value = trim($value);
                        }
                        if ($value !== null && $value !== '') {
                            $cells[$index] = $value;
                        }
                    }

                    if ($cells !== []) {
                        yield ['sheet' => $sheet->getName(), 'row' => $number, 'cells' => $cells];
                    }
                }
            }
        } finally {
            $reader->close();
        }
    }

    /** @return list<string> */
    public static function sheetNames(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $names = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $names[] = $sheet->getName();
        }
        $reader->close();

        return $names;
    }

    /** Texte comparable : minuscules, sans accents ni espaces superflus. */
    public static function norm(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_scalar($value) && $value !== null) {
            return '';
        }

        return Str::of(Str::ascii((string) $value))->lower()->replace(["\n", "\r", "\t"], ' ')->squish()->toString();
    }

    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('d/m/Y');
        }
        $text = Str::of((string) $value)->replace(["\r\n", "\r"], "\n")->trim()->toString();

        return $text === '' ? null : $text;
    }

    /** Nombre ou null (« PM », « A déterminer », erreurs…). */
    public static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $clean = str_replace(["\u{00A0}", ' ', "\u{202F}"], '', $value);
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float) $clean : null;
    }

    public static function date(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim($value), $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (is_string($value) && preg_match('#^\d{4}-\d{2}-\d{2}#', trim($value))) {
            return substr(trim($value), 0, 10);
        }
        if (is_int($value) || is_float($value)) {
            // Numéro de série Excel.
            return $value > 20000 && $value < 80000
                ? Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString()
                : null;
        }

        return null;
    }

    /** Numéro de mois (1-12) d'un intitulé : « Janv », « FÉVRIER », « Aout »… */
    public static function month(mixed $value): ?int
    {
        $norm = self::norm($value);
        foreach (self::MONTHS as $prefix => $number) {
            if (str_starts_with($norm, $prefix)) {
                return $number;
            }
        }

        return null;
    }

    /**
     * Liste de mois saisie librement : « 1, 2, 3 », « janv; mars », « 4-6 ».
     *
     * @return list<int>
     */
    public static function monthList(mixed $value): array
    {
        $months = [];
        foreach (preg_split('/[;,\s]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $part, $m)) {
                $months = [...$months, ...range((int) $m[1], (int) $m[2])];
            } elseif (ctype_digit($part)) {
                $months[] = (int) $part;
            } elseif ($month = self::month($part)) {
                $months[] = $month;
            }
        }

        return array_values(array_unique(array_filter($months, fn ($m) => $m >= 1 && $m <= 12)));
    }
}
