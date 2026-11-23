<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Services\SheetExportNamer;
use App\Support\Excel\WritesSpreadsheets;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * تصدير تقرير غير المسدد إلى Excel (A4 أفقي، RTL، ترويسة متكررة، حماية حقن المعادلات).
 */
class UnpaidReportExcel
{
    use WritesSpreadsheets;

    private const HEADERS = ['التاريخ', 'أمر التسليم', 'الصيدلية', 'المنطقة', 'الفاتورة', 'مركز الكلفة', 'الإجمالي', 'الموزَّع', 'غير المسدد', 'الحالة', 'حالة الزيارة', 'الموزع'];

    public function __construct(private SheetExportNamer $namer)
    {
    }

    /**
     * @return array{spreadsheet: Spreadsheet, filename: string, ascii_filename: string}
     */
    public function build(array $report, User $by): array
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $spreadsheet->getProperties()->setCreator($by->name)->setTitle('الطلبيات غير المسددة');
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('غير المسدد');

        $range = $report['from'] === $report['to'] ? $report['from'] : $report['from'].' إلى '.$report['to'];
        $ws->mergeCells('A1:L1');
        $this->text($ws, 'A1', 'الطلبيات غير المسددة — '.$range.' — عدد: '.$report['totals']['count'].' — القيمة: '.number_format($report['totals']['unpaid'], 2).(! empty($report['scope_label']) ? ' — مقيَّد بنطاق: '.preg_replace('/^النطاق: /u', '', $report['scope_label']) : ''));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ws->mergeCells('A2:L2');
        $this->text($ws, 'A2', 'أرقام حيّة وقد تختلف عن لقطة تصدير سابق إن عُدّلت الفواتير.');

        $headerRow = 3;
        $this->headerRow($ws, $headerRow, self::HEADERS);
        $row = $headerRow + 1;
        foreach ($report['rows'] as $r) {
            $this->text($ws, "A{$row}", (string) $r['date']);
            $this->text($ws, "B{$row}", (string) $r['order']);
            $this->text($ws, "C{$row}", (string) $r['pharmacy']);
            $this->text($ws, "D{$row}", (string) $r['area']);
            $this->text($ws, "E{$row}", (string) $r['invoice']);
            $this->text($ws, "F{$row}", (string) $r['cost_center']);
            $ws->setCellValue("G{$row}", $r['total']);
            $ws->setCellValue("H{$row}", $r['allocated']);
            $ws->setCellValue("I{$row}", $r['unpaid']);
            $this->text($ws, "J{$row}", UnpaidReportService::STATUSES[$r['status']] ?? $r['status']);
            $this->text($ws, "K{$row}", (string) $r['visit']);
            $this->text($ws, "L{$row}", (string) $r['distributor']);
            $row++;
        }
        $last = max($headerRow, $row - 1);
        if ($last > $headerRow) {
            $ws->getStyle('G'.($headerRow + 1).":I{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle('G'.($headerRow + 1).":I{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $this->borders($ws, "A{$headerRow}:L{$last}");
        $this->widths($ws, ['A' => 12, 'B' => 11, 'C' => 30, 'D' => 18, 'E' => 14, 'F' => 24, 'G' => 14, 'H' => 14, 'I' => 14, 'J' => 13, 'K' => 20, 'L' => 20]);
        $this->a4Landscape($ws, 'L', $last, $headerRow, 'الطلبيات غير المسددة — '.$range, $range);

        $safeRange = $this->namer->sanitize($report['from'] === $report['to'] ? $report['from'] : $report['from'].' إلى '.$report['to']);

        return [
            'spreadsheet' => $spreadsheet,
            'filename' => 'غير المسدد - '.$safeRange.'.xlsx',
            'ascii_filename' => 'unpaid_'.preg_replace('/[^0-9-]/', '', $report['from']).($report['from'] !== $report['to'] ? '_'.preg_replace('/[^0-9-]/', '', $report['to']) : '').'.xlsx',
        ];
    }
}
