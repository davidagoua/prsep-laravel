<?php

namespace App\Http\Controllers;

use App\Enums\Volet;
use App\Models\Drf;
use App\Support\CurrentExercice;
use App\Support\PtbaTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinanceExportController extends Controller
{
    private const LEVELS = [
        'component' => 'Composante', 'subcomponent' => 'Sous-composante', 'ild' => 'ILD', 'hors_ild' => 'Hors ILD',
        'domain' => 'Domaine', 'subdomain' => 'Sous-domaine', 'action' => 'Action', 'activity' => 'Activité',
    ];

    /** Tableau financier (mêmes filtres que l'écran), un niveau par ligne. */
    public function tree(Request $request, PtbaTree $tree, CurrentExercice $current): BinaryFileResponse
    {
        $volet = Volet::tryFrom((string) $request->query('volet')) ?? Volet::Projet;
        $exercice = $current->get();
        $data = $tree->build($volet, $exercice->id, $request->user(), [
            'direction' => $request->integer('direction') ?: null,
            'ild' => $request->query('ild') ?: null,
            'search' => $request->query('search') ?: null,
        ], fn (Builder $q, int $id) => $q->withFinancials($id));

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Suivi financier');
        $sheet->setCellValue('A1', "Suivi financier — volet {$volet->label()} — {$exercice->label}");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $this->header($sheet, 3, ['Niveau', 'Code', 'Libellé', 'Direction', 'Planifié', 'Engagé', 'Payé', 'Reste à payer', 'Exécution (%)']);

        $row = 4;
        $walk = function (array $nodes, int $depth) use (&$walk, $sheet, &$row) {
            foreach ($nodes as $node) {
                $m = $node['metrics'];
                $sheet->fromArray([
                    self::LEVELS[$node['type']] ?? '',
                    $node['code'],
                    str_repeat('   ', $depth).$node['label'],
                    $node['type'] === 'activity' ? $node['activity']->direction?->code : null,
                    $m['planned'], $m['engaged'], $m['paid'], $m['engaged'] - $m['paid'],
                    $m['planned'] > 0 ? round($m['paid'] / $m['planned'] * 100, 1) : null,
                ], null, "A{$row}");
                if ($node['type'] !== 'activity') {
                    $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);
                }
                $row++;
                $walk($node['children'], $depth + 1);
            }
        };
        $walk($data['roots'], 0);

        $t = $data['totals'];
        $sheet->fromArray(['Total', null, null, null, $t['planned'], $t['engaged'], $t['paid'], $t['engaged'] - $t['paid'],
            $t['planned'] > 0 ? round($t['paid'] / $t['planned'] * 100, 1) : null], null, "A{$row}");
        $sheet->getStyle("A{$row}:I{$row}")->getFont()->setBold(true);

        $this->finish($sheet, $row, ['A' => 16, 'B' => 14, 'C' => 70, 'D' => 12], 'E', 'H');

        return $this->download($book, "suivi-financier-{$volet->value}-{$exercice->year}.xlsx");
    }

    /** Détail d'une DRF : paiements avec leur activité. */
    public function drf(Drf $drf): BinaryFileResponse
    {
        $drf->load(['payments.activity.direction', 'payments.engagement', 'exercice']);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle($drf->number);
        $sheet->setCellValue('A1', "{$drf->number} — {$drf->status->label()} — {$drf->date->format('d/m/Y')}".($drf->label ? " — {$drf->label}" : ''));
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $this->header($sheet, 3, ['Code activité', 'Activité', 'Direction', 'Date', 'Référence', 'Objet', 'Engagement', 'Montant']);

        $row = 4;
        foreach ($drf->payments as $p) {
            $sheet->fromArray([
                $p->activity->code, $p->activity->label, $p->activity->direction?->code, $p->paid_on->format('d/m/Y'),
                $p->reference, $p->label, $p->engagement?->reference ?? $p->engagement?->label, $p->amount,
            ], null, "A{$row}");
            $row++;
        }
        $sheet->fromArray(['Total', null, null, null, null, null, null, $drf->payments->sum('amount')], null, "A{$row}");
        $sheet->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);

        $this->finish($sheet, $row, ['A' => 14, 'B' => 60, 'C' => 12, 'D' => 12, 'E' => 16, 'F' => 30, 'G' => 18], 'H', 'H');

        return $this->download($book, "{$drf->number}.xlsx");
    }

    private function header(Worksheet $sheet, int $row, array $titles): void
    {
        $sheet->fromArray($titles, null, "A{$row}");
        $last = chr(ord('A') + count($titles) - 1);
        $style = $sheet->getStyle("A{$row}:{$last}{$row}");
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1B5680');
        $sheet->freezePane('A'.($row + 1));
    }

    private function finish(Worksheet $sheet, int $lastRow, array $widths, string $firstMoney, string $lastMoney): void
    {
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        foreach (range($firstMoney, $lastMoney) as $col) {
            $sheet->getColumnDimension($col)->setWidth(18);
        }
        $sheet->getStyle("{$firstMoney}4:{$lastMoney}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
    }

    private function download(Spreadsheet $book, string $name): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'export').'.xlsx';
        (new Xlsx($book))->save($path);

        return response()->download($path, $name)->deleteFileAfterSend();
    }
}
