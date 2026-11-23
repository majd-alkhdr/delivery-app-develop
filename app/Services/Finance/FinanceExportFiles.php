<?php

namespace App\Services\Finance;

use App\Models\FinanceExport;
use App\Models\User;
use App\Services\SheetExportNamer;
use App\Support\Excel\WritesSpreadsheets;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * ملفات التصدير للمالية من اللقطة المحفوظة (لا إعادة حساب):
 * - import:   «ملف الاستيراد» الرباعي — ورقة واحدة، ترويسة حرفية، بلا إجمالي ولا ترويسة إضافية.
 * - receipts: «كشف المقبوضات» — صف لكل دفعة (المكافئ بالليرة).
 * - usd:      «الدولار» (مرجعي) — دفعات الدولار فقط، ويوجد فقط إن وُجدت.
 */
class FinanceExportFiles
{
    use WritesSpreadsheets;

    public const KINDS = [
        'import' => 'ملف الاستيراد',
        'receipts' => 'كشف المقبوضات',
        'usd' => 'الدولار',
    ];

    /** الترويسة الحرفية لملف الاستيراد وبهذا الترتيب */
    public const IMPORT_HEADERS = ['اسم الزبون', 'بيان المالية', 'مبلغ التسديد حسب مركز الكلفة', 'مركز الكلفة'];

    public const RECEIPT_HEADERS = ['اسم الزبون', 'بيان المالية', 'المبلغ المقبوض'];

    public const USD_HEADERS = ['اسم الزبون', 'بيان المالية', 'المبلغ بالدولار', 'سعر الصرف', 'المكافئ بالليرة', 'رقم الإيصال'];

    public function __construct(private SheetExportNamer $namer)
    {
    }

    public function hasUsd(FinanceExport $export): bool
    {
        return $export->rows()->where('row_type', 'receipt')->where('currency', 'USD')->exists();
    }

    /**
     * @return array{spreadsheet: Spreadsheet, filename: string, ascii_filename: string}
     */
    public function build(FinanceExport $export, string $kind, User $by): array
    {
        if ($export->isRep()) {
            return $this->buildRep($export, $kind, $by);
        }

        $export->loadMissing('sheet.assignments.distributor');
        $sheet = $export->sheet;

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $spreadsheet->getProperties()->setCreator($by->name)->setTitle(self::KINDS[$kind].' — أمر التسليم #'.$this->namer->orderPart($sheet));
        $ws = $spreadsheet->getActiveSheet();

        match ($kind) {
            'import' => $this->importSheet($ws, $export),
            'receipts' => $this->receiptsSheet($ws, $export),
            'usd' => $this->usdSheet($ws, $export),
        };

        $suffix = self::KINDS[$kind].($export->sequence > 1 ? ' '.$export->sequence : '');

        return [
            'spreadsheet' => $spreadsheet,
            'filename' => $this->namer->fileName($sheet, $by, ['rows' => $this->namerRows($export)], $suffix),
            'ascii_filename' => $this->namer->asciiFallback($sheet, $kind.($export->sequence > 1 ? '_'.$export->sequence : '')),
        ];
    }

    /**
     * دفعة تصديرية للمندوبين (المرحلة 6): الملفات نفسها والقالب نفسه، والاسم «… - م-{n} - …».
     */
    private function buildRep(FinanceExport $export, string $kind, User $by): array
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $spreadsheet->getProperties()->setCreator($by->name)->setTitle(self::KINDS[$kind].' — مندوبون '.$export->repOrder());
        $ws = $spreadsheet->getActiveSheet();

        match ($kind) {
            'import' => $this->importSheet($ws, $export),
            'receipts' => $this->receiptsSheet($ws, $export),
            'usd' => $this->usdSheet($ws, $export),
        };

        $names = array_values($export->meta['rep_names'] ?? []);

        return [
            'spreadsheet' => $spreadsheet,
            'filename' => $this->namer->repFileName($names, (int) $export->sequence, self::KINDS[$kind]),
            'ascii_filename' => $this->namer->repAsciiFallback((int) $export->sequence, $kind),
        ];
    }

    /**
     * صفوف الكشف (الكتلة/المنطقة) لقاعدة الاسم في SheetExportNamer: زبائن هذه الدفعة التصديرية بترتيب المنطقة ثم الاسم.
     */
    private function namerRows(FinanceExport $export): array
    {
        return \App\Models\Customer::query()
            ->whereIn('id', $export->rows()->whereNotNull('customer_id')->pluck('customer_id'))
            ->get(['id', 'name', 'block', 'area'])
            ->sortBy(fn ($c) => [(string) $c->area, (string) $c->name])
            ->map(fn ($c) => ['block' => $c->block, 'area' => $c->area])
            ->values()
            ->all();
    }

    private function importSheet(Worksheet $ws, FinanceExport $export): void
    {
        $ws->setTitle('الاستيراد');
        $this->plainHeader($ws, self::IMPORT_HEADERS);

        $row = 2;
        foreach ($export->rows()->whereIn('row_type', ['allocation', 'surplus'])->get() as $line) {
            $this->text($ws, "A{$row}", $line->customer_name);
            $this->text($ws, "B{$row}", $line->statement);
            $ws->setCellValue("C{$row}", round((float) $line->amount, 2));
            $this->text($ws, "D{$row}", (string) $line->cost_center);
            $row++;
        }
        $last = max(1, $row - 1);
        $ws->getStyle("C2:C{$last}")->getNumberFormat()->setFormatCode('0.00');
        $this->widths($ws, ['A' => 34, 'B' => 52, 'C' => 26, 'D' => 30]);
        $ws->setRightToLeft(true);
    }

    private function receiptsSheet(Worksheet $ws, FinanceExport $export): void
    {
        $ws->setTitle('المقبوضات');
        $this->plainHeader($ws, self::RECEIPT_HEADERS);

        $row = 2;
        foreach ($export->rows()->where('row_type', 'receipt')->get() as $line) {
            $this->text($ws, "A{$row}", $line->customer_name);
            $this->text($ws, "B{$row}", $line->statement);
            $ws->setCellValue("C{$row}", round((float) $line->amount, 2));
            $row++;
        }
        $last = max(1, $row - 1);
        $ws->getStyle("C2:C{$last}")->getNumberFormat()->setFormatCode('0.00');
        $this->widths($ws, ['A' => 34, 'B' => 52, 'C' => 20]);
        $ws->setRightToLeft(true);
    }

    private function usdSheet(Worksheet $ws, FinanceExport $export): void
    {
        $ws->setTitle('الدولار');
        $this->plainHeader($ws, self::USD_HEADERS);

        $row = 2;
        foreach ($export->rows()->where('row_type', 'receipt')->where('currency', 'USD')->get() as $line) {
            $this->text($ws, "A{$row}", $line->customer_name);
            $this->text($ws, "B{$row}", $line->statement);
            $ws->setCellValue("C{$row}", round((float) $line->amount_original, 2));
            $ws->setCellValue("D{$row}", (float) $line->exchange_rate);
            $ws->setCellValue("E{$row}", round((float) $line->amount, 2));
            $this->text($ws, "F{$row}", (string) $line->receipt_number);
            $row++;
        }
        $last = max(1, $row - 1);
        $ws->getStyle("C2:C{$last}")->getNumberFormat()->setFormatCode('0.00');
        $ws->getStyle("D2:D{$last}")->getNumberFormat()->setFormatCode('0.####');
        $ws->getStyle("E2:E{$last}")->getNumberFormat()->setFormatCode('0.00');
        $this->widths($ws, ['A' => 34, 'B' => 52, 'C' => 16, 'D' => 12, 'E' => 18, 'F' => 24]);
        $ws->setRightToLeft(true);
    }

    /**
     * ترويسة الصف الأول فقط (نص حرفي) بخط عريض، دون أي تنسيق يغيّر المحتوى.
     */
    private function plainHeader(Worksheet $ws, array $headers): void
    {
        foreach ($headers as $i => $header) {
            $this->text($ws, chr(ord('A') + $i).'1', $header);
        }
        $range = 'A1:'.chr(ord('A') + count($headers) - 1).'1';
        $ws->getStyle($range)->getFont()->setBold(true);
        $ws->getStyle($range)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }
}
