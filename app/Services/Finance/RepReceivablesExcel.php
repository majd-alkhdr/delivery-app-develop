<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Services\SheetExportNamer;
use App\Support\Excel\WritesSpreadsheets;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * تصدير تقرير «ذمم المندوبين» (A4 أفقي، RTL، ترويسة متكررة، حماية حقن المعادلات). لا رصيد خام ولا مؤجل.
 */
class RepReceivablesExcel
{
    use WritesSpreadsheets;

    private const HEADERS = ['المندوب', 'الصيدلية', 'المنطقة', 'الفاتورة', 'التاريخ', 'أمر التسليم', 'العمر (يوم)', 'الإجمالي', 'بعد الموزع', 'سدّده المندوب', 'المتبقي', 'الحالة', 'سُدّد من'];

    private const STATUS = ['paid' => 'مسددة', 'partial' => 'جزئية', 'unpaid' => 'غير مسددة'];

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
        $spreadsheet->getProperties()->setCreator($by->name)->setTitle('ذمم المندوبين');
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('ذمم المندوبين');

        $date = now('Asia/Damascus')->format('Y-m-d');
        $t = $report['totals'];
        $ws->mergeCells('A1:M1');
        $this->text($ws, 'A1', 'ذمم المندوبين — '.$date.' — المتبقي: '.number_format($t['remaining'], 2).' — الصيدليات: '.$t['pharmacies']
            // المرحلة 8A: تقرير مشرف مندوبين بحصر مفعّل
            .(! empty($report['scope_label']) ? ' — مقيَّد بنطاق: '.preg_replace('/^النطاق: /u', '', $report['scope_label']) : ''));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headerRow = 2;
        $this->headerRow($ws, $headerRow, self::HEADERS);
        $row = $headerRow + 1;
        foreach ($report['groups'] as $group) {
            foreach ($group['pharmacies'] as $pharmacy) {
                foreach ($pharmacy['invoices'] as $invoice) {
                    $this->text($ws, "A{$row}", (string) $group['name']);
                    $this->text($ws, "B{$row}", (string) $pharmacy['name']);
                    $this->text($ws, "C{$row}", (string) $pharmacy['area']);
                    $this->text($ws, "D{$row}", (string) $invoice['label']);
                    $this->text($ws, "E{$row}", (string) $invoice['date']);
                    $this->text($ws, "F{$row}", (string) $invoice['order']);
                    $ws->setCellValue("G{$row}", $invoice['age_days']);
                    $ws->setCellValue("H{$row}", $invoice['total_cents'] / 100);
                    $ws->setCellValue("I{$row}", $invoice['base_cents'] / 100);
                    $ws->setCellValue("J{$row}", $invoice['rep_paid_cents'] / 100);
                    $ws->setCellValue("K{$row}", $invoice['remaining_cents'] / 100);
                    $this->text($ws, "L{$row}", self::STATUS[$invoice['status']] ?? $invoice['status']);
                    $this->text($ws, "M{$row}", collect($invoice['paid_by'])->map(fn ($p) => trim(($p['book_receipt_no'] ?: $p['receipt_number']).' '.$p['date']))->implode('، '));
                    $row++;
                }
            }
        }
        $last = max($headerRow, $row - 1);
        if ($last > $headerRow) {
            $ws->getStyle('H'.($headerRow + 1).":K{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle('G'.($headerRow + 1).":K{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $this->borders($ws, "A{$headerRow}:M{$last}");
        $this->widths($ws, ['A' => 22, 'B' => 28, 'C' => 16, 'D' => 14, 'E' => 12, 'F' => 10, 'G' => 9, 'H' => 13, 'I' => 13, 'J' => 13, 'K' => 13, 'L' => 11, 'M' => 26]);
        $this->a4Landscape($ws, 'M', $last, $headerRow, 'ذمم المندوبين — '.$date, $date);

        return [
            'spreadsheet' => $spreadsheet,
            'filename' => 'ذمم المندوبين - '.$this->namer->sanitize($date).'.xlsx',
            'ascii_filename' => 'rep_receivables_'.$date.'.xlsx',
        ];
    }
}
