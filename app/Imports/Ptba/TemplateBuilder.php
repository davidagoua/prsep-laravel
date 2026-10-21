<?php

namespace App\Imports\Ptba;

use App\Models\Direction;
use App\Models\ExpenseCategory;
use App\Models\FundingSource;
use App\Models\Ugp;
use App\Models\Unit;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Génère le modèle Excel SIGPRO d'un volet : feuille de saisie avec listes déroulantes
 * (directions, catégories, unités…) alimentées par les référentiels, et mode d'emploi.
 */
class TemplateBuilder
{
    private const ROWS = 2000;

    public function build(string $volet, string $path): void
    {
        $columns = config("ptba_import.columns.{$volet}");
        $book = new Workbook;

        $sheet = $book->getActiveSheet()->setTitle(config("ptba_import.sheets.{$volet}"));
        $lists = $this->listsSheet($book);
        $this->dataSheet($sheet, $columns, $lists);
        $this->helpSheet($book->createSheet(), $volet, $columns);

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
    }

    /** @return array<string, string> plage de chaque liste dans la feuille cachée */
    private function listsSheet(Workbook $book): array
    {
        $values = [
            'directions' => Direction::active()->orderBy('code')->pluck('code')->all(),
            'categories' => ExpenseCategory::orderBy('label')->pluck('label')->all(),
            'unites' => Unit::orderBy('label')->pluck('label')->all(),
            'ugps' => Ugp::orderBy('label')->pluck('label')->all(),
            'sources' => FundingSource::orderBy('label')->pluck('label')->all(),
            'types' => config('ptba_import.activity_types'),
            'ild' => ['ILD', 'HORS_ILD'],
        ];

        $sheet = $book->createSheet()->setTitle('Listes');
        $ranges = [];
        $col = 1;
        foreach ($values as $name => $items) {
            $letter = Coordinate::stringFromColumnIndex($col++);
            $sheet->setCellValue("{$letter}1", $name);
            foreach (array_values($items) as $i => $item) {
                $sheet->setCellValue($letter.($i + 2), $item);
            }
            $ranges[$name] = "Listes!\${$letter}\$2:\${$letter}\$".max(2, count($items) + 1);
        }
        $sheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        return $ranges;
    }

    private function dataSheet(Worksheet $sheet, array $columns, array $lists): void
    {
        $col = 1;
        foreach ($columns as [$header, $width, $required, $list, $help]) {
            $letter = Coordinate::stringFromColumnIndex($col++);
            $sheet->setCellValue("{$letter}1", $header);
            $sheet->getColumnDimension($letter)->setWidth($width);
            $style = $sheet->getStyle("{$letter}1");
            $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($required ? 'FF1B5680' : 'FF729BBF');
            $style->getAlignment()->setWrapText(true)->setVertical('center');

            if ($help) {
                $sheet->getComment("{$letter}1")->getText()->createText($help);
            }

            if ($list && isset($lists[$list])) {
                $validation = new DataValidation;
                $validation->setType(DataValidation::TYPE_LIST)
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
                    // Avertissement seulement : une valeur hors liste reste possible (nouvelle unité…).
                    ->setErrorStyle(DataValidation::STYLE_WARNING)
                    ->setShowErrorMessage(true)
                    ->setErrorTitle('Valeur hors liste')
                    ->setError('Cette valeur n\'existe pas encore dans SIGPRO. Elle sera créée à l\'import (sauf pour les directions).')
                    ->setFormula1($lists[$list]);
                $sheet->setDataValidation("{$letter}2:{$letter}".self::ROWS, $validation);
            }
        }

        $sheet->getRowDimension(1)->setRowHeight(36);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.Coordinate::stringFromColumnIndex(count($columns)).'1');
    }

    private function helpSheet(Worksheet $sheet, string $volet, array $columns): void
    {
        $sheet->setTitle('Mode d\'emploi');
        $lines = [
            'Modèle d\'import du PTBA '.($volet === 'projet' ? 'projet' : 'programme').' — SIGPRO',
            '',
            '1. Une ligne par ligne de coût. Répétez la hiérarchie et l\'activité sur chaque ligne d\'une même activité.',
            '2. Le code activité est unique : il permet de mettre à jour l\'activité si le fichier est réimporté.',
            '3. Les colonnes en bleu foncé sont obligatoires ; survolez un intitulé pour voir l\'aide.',
            '4. Utilisez les listes déroulantes pour la structure responsable (sigle de la direction), la catégorie, l\'unité…',
            '5. Montant d\'une ligne = quantité × fréquence × durée × coût unitaire (FCFA). Fréquence et durée valent 1 si vides.',
            '6. Ne renommez pas la feuille « '.config("ptba_import.sheets.{$volet}").' » ; l\'ordre des colonnes peut changer.',
            '7. À l\'import, SIGPRO affiche les erreurs ligne par ligne et un aperçu des totaux avant toute écriture.',
            '',
            'Colonnes :',
        ];
        foreach ($columns as [$header, , $required, , $help]) {
            $lines[] = '• '.$header.($required ? ' (obligatoire)' : '').($help ? ' — '.$help : '');
        }

        foreach ($lines as $i => $text) {
            $sheet->setCellValue('A'.($i + 1), $text);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(120);
    }
}
