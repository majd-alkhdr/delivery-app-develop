<?php

namespace App\Services;

use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\SheetAssignment;
use App\Models\User;
use App\Support\Excel\WritesSpreadsheets;
use App\Support\InvoiceFingerprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * تصدير كشف v2 إلى Excel للطباعة على A4 (ليس كشف المالية).
 *
 * القاعدة الحاكمة: النسخة المطبوعة قد تصل إلى الموزع، فلا يظهر فيها أي فاتورة مؤجلة
 * ولا قيمتها ولا الرصيد الخام. الفواتير: الموضوعة في الكشف (in_sheet) غير المؤجلة فقط،
 * والرصيد هو المستحق المحفوظ (final_balance) كما حسبته SheetFiguresService.
 */
class SheetExportService
{
    use WritesSpreadsheets;

    private const MONEY_FORMAT = '#,##0.00';

    private const FONT = 'Arial';

    public function __construct(private SheetExportNamer $namer)
    {
    }

    /**
     * @return array{spreadsheet: Spreadsheet, filename: string, ascii_filename: string, scope_label: string, customers: int, invoices: int, total: float}
     */
    public function build(DistributionSheet $sheet, User $by, ?User $distributor = null, ?string $areaKey = null, bool $withLines = false, ?\App\Services\Reps\RepViewerScope $repScope = null): array
    {
        $invoices = $this->invoices($sheet, $distributor, $areaKey, $withLines);
        // المرحلة 8A: مشرف المندوبين بحصر مفعّل يصدّر فواتير نطاقه فقط، والترويسة تحمل «مقيَّد بنطاق: …»
        if ($repScope !== null) {
            $invoices = $invoices->filter(fn ($i) => $repScope->allowsInvoiceId($i->id))->values();
        }
        $areas = $this->groupByAreaAndCustomer($invoices);

        $scopeLabel = match (true) {
            $distributor !== null => 'الموزع: '.$distributor->name,
            $areaKey !== null => 'المنطقة: '.($areaKey === SheetAssignment::NO_AREA ? 'بلا منطقة' : ($invoices->first()?->area ?: $areaKey)),
            default => 'كل الكشف',
        };
        if ($repScope !== null) {
            $scopeLabel = 'مقيَّد بنطاق: '.preg_replace('/^النطاق: /u', '', $repScope->label()).' — '.$scopeLabel;
        }

        // اسم الملف وسطر العنوان (SheetExportNamer): صفوف الملف بترتيبها لأول كتلة/منطقة
        $namerScope = [
            'distributor' => $distributor,
            'area_key' => $areaKey,
            'area_label' => $areaKey !== null ? ($areaKey === SheetAssignment::NO_AREA ? 'بلا منطقة' : ($invoices->first()?->area ?: 'بلا منطقة')) : null,
            'rows' => $areas->flatMap(fn ($a) => $a['customers']->map(fn ($c) => ['block' => $c['block'], 'area' => $a['key'] === SheetAssignment::NO_AREA ? null : $a['label']]))->values()->all(),
        ];
        $nameLabel = $this->namer->label($sheet, $by, $namerScope);
        $filename = $this->namer->fileName($sheet, $by, $namerScope);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName(self::FONT)->setSize(11);
        $spreadsheet->getProperties()->setCreator($by->name)->setTitle('كشف توزيع — أمر التسليم #'.$sheet->orderLabel());

        $exportedAt = now('Asia/Damascus');
        $customersCount = $areas->sum(fn ($a) => $a['customers']->count());
        $total = round((float) $invoices->sum('invoice_amount'), 2);

        $this->sheetSummary($spreadsheet->getActiveSheet(), $sheet, $areas, $scopeLabel, $customersCount, $total, $exportedAt->format('Y-m-d H:i'), $by->name,
            $nameLabel['kind'].': '.$this->namer->sanitize($nameLabel['text']));
        $this->sheetInvoices($spreadsheet->createSheet(), $sheet, $areas);
        if ($withLines) {
            $this->sheetLines($spreadsheet->createSheet(), $sheet, $areas);
        }
        $spreadsheet->setActiveSheetIndex(0);


        return [
            'spreadsheet' => $spreadsheet,
            'filename' => $filename,
            'ascii_filename' => $this->namer->asciiFallback($sheet),
            'scope_label' => $scopeLabel,
            'customers' => $customersCount,
            'invoices' => $invoices->count(),
            'total' => $total,
        ];
    }

    // =====================================================================
    // البيانات (استعلام واحد للفواتير، وآخر اختياري للأسطر)
    // =====================================================================

    private function invoices(DistributionSheet $sheet, ?User $distributor, ?string $areaKey, bool $withLines): Collection
    {
        $query = Invoice::query()
            ->where('distribution_sheet_id', $sheet->id)
            ->where('placement', Invoice::IN_SHEET)
            ->where('is_deferred', false)
            // الدفعات الخارجية سجلات تحصيل وليست فواتير توزيع
            ->where(fn (Builder $q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'));

        if ($distributor !== null) {
            $keys = $sheet->areaKeysFor($distributor);   // null = كل المناطق
            if ($keys !== null) {
                $query->whereIn('area_key', $keys ?: ['__no_access__']);
            }
        }
        if ($areaKey !== null) {
            $query->where('area_key', $areaKey);
        }
        $query->with('customer:id,block');
        if ($withLines) {
            $query->with('lines');
        }

        return $query->orderBy('id')->get();
    }

    /**
     * @return Collection<int, array{label: string, customers: Collection}>
     */
    private function groupByAreaAndCustomer(Collection $invoices): Collection
    {
        return $invoices
            ->groupBy(fn ($i) => $i->area_key ?: SheetAssignment::NO_AREA)
            ->map(function (Collection $areaInvoices, $key) {
                $label = $key === SheetAssignment::NO_AREA ? 'بلا منطقة' : ($areaInvoices->pluck('area')->filter()->first() ?: 'بلا منطقة');

                $customers = $areaInvoices
                    ->groupBy(fn ($i) => $i->customer_id ? 'c'.$i->customer_id : 'n'.$i->customer_name)
                    ->map(fn (Collection $rows) => [
                        'name' => $rows->first()->customer_name,
                        'block' => $rows->first()->customer?->block,
                        'area' => $label,
                        'invoices' => $rows->values(),
                        'total' => round((float) $rows->sum('invoice_amount'), 2),
                        // الرصيد المستحق كما حفظته SheetFiguresService (لا الرصيد الخام)
                        'due' => round((float) $rows->first()->final_balance, 2),
                    ])
                    ->sortBy('name', SORT_NATURAL)
                    ->values();

                return ['key' => $key, 'label' => $label, 'customers' => $customers];
            })
            // بالترتيب الأبجدي للمنطقة، و"بلا منطقة" في النهاية
            ->sortBy(fn ($a) => ($a['key'] === SheetAssignment::NO_AREA ? '1' : '0').$a['label'], SORT_NATURAL)
            ->values();
    }

    // =====================================================================
    // الورقة 1: الكشف
    // =====================================================================

    private function sheetSummary(Worksheet $ws, DistributionSheet $sheet, Collection $areas, string $scope, int $customers, float $total, string $exportedAt, string $by, string $nameLine): void
    {
        $ws->setTitle('الكشف');
        // العنوان يعرض الاسم نفسه المستخدم في اسم الملف («الموزع: …» أو «الكتلة/المنطقة: …»)
        $title = 'كشف توزيع — أمر التسليم #'.$this->namer->orderPart($sheet).' — '.$nameLine;

        $ws->mergeCells('A1:H1');
        $this->text($ws, 'A1', $title);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->getRowDimension(1)->setRowHeight(24);

        $info = [
            ['تاريخ التوزيع:', $sheet->distribution_date?->format('Y-m-d') ?? '—'],
            ['النطاق:', $scope],
            ['عدد الزبائن:', $customers],
            ['إجمالي الفواتير:', $total],
            ['تاريخ التصدير:', $exportedAt.' — بواسطة: '.$by],
        ];
        $row = 2;
        foreach ($info as [$label, $value]) {
            $ws->mergeCells("A{$row}:B{$row}");
            $ws->mergeCells("C{$row}:E{$row}");
            $this->text($ws, "A{$row}", $label);
            $ws->getStyle("A{$row}")->getFont()->setBold(true);
            if (is_string($value)) {
                $this->text($ws, "C{$row}", $value);
            } else {
                $ws->setCellValue("C{$row}", $value);
                $ws->getStyle("C{$row}")->getNumberFormat()->setFormatCode(is_float($value) ? self::MONEY_FORMAT : '0');
                $ws->getStyle("C{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $row++;
        }

        $headerRow = $row + 1;
        $headers = ['م', 'اسم الزبون', 'المنطقة', 'أرقام الفواتير', 'إجمالي الفواتير المستحقة', 'الرصيد المستحق', 'المبلغ المقبوض', 'ملاحظات'];
        $this->headerRow($ws, $headerRow, $headers);

        $row = $headerRow + 1;
        $n = 0;
        $dueTotal = 0.0;
        foreach ($areas as $area) {
            // صف عنوان المنطقة
            $ws->mergeCells("A{$row}:H{$row}");
            $this->text($ws, "A{$row}", 'المنطقة: '.$area['label'].' ('.$area['customers']->count().' زبون)');
            $ws->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
            $ws->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E9ECEF');
            $row++;

            foreach ($area['customers'] as $customer) {
                $n++;
                $ws->setCellValue("A{$row}", $n);
                $this->text($ws, "B{$row}", $customer['name']);
                $this->text($ws, "C{$row}", $customer['area']);
                $this->text($ws, "D{$row}", $customer['invoices']->map(fn ($i) => $this->invoiceLabel($i))->implode("\n"));
                $ws->setCellValue("E{$row}", $customer['total']);
                $ws->setCellValue("F{$row}", $customer['due']);
                $dueTotal += $customer['due'];
                $row++;
            }
        }

        // صف الإجمالي
        $this->text($ws, "B{$row}", 'الإجمالي');
        $ws->setCellValue("E{$row}", $total);
        $ws->setCellValue("F{$row}", round($dueTotal, 2));
        $ws->getStyle("A{$row}:H{$row}")->getFont()->setBold(true);
        $ws->getStyle("A{$row}:H{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DEE2E6');
        $lastRow = $row;

        if ($lastRow > $headerRow) {
            $data = 'A'.($headerRow + 1).":H{$lastRow}";
            $ws->getStyle($data)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
            $ws->getStyle('A'.($headerRow + 1).":A{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $ws->getStyle('B'.($headerRow + 1).":D{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $ws->getStyle('D'.($headerRow + 1).":D{$lastRow}")->getAlignment()->setWrapText(true);
            $ws->getStyle('E'.($headerRow + 1).":G{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
            $ws->getStyle('E'.($headerRow + 1).":G{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        $this->borders($ws, "A{$headerRow}:H{$lastRow}");
        $this->widths($ws, ['A' => 6, 'B' => 34, 'C' => 18, 'D' => 22, 'E' => 18, 'F' => 18, 'G' => 18, 'H' => 26]);
        $this->pageSetup($ws, $sheet, 'H', $lastRow, $headerRow);
    }

    // =====================================================================
    // الورقة 2: الفواتير
    // =====================================================================

    private function sheetInvoices(Worksheet $ws, DistributionSheet $sheet, Collection $areas): void
    {
        $ws->setTitle('الفواتير');
        $this->headerRow($ws, 1, ['الزبون', 'المنطقة', 'السلسلة', 'رقم الفاتورة', 'تاريخ الفاتورة', 'مركز الكلفة', 'القيمة', 'بيان الفاتورة', 'وسم']);

        $row = 2;
        foreach ($areas as $area) {
            foreach ($area['customers'] as $customer) {
                foreach ($customer['invoices'] as $invoice) {
                    $this->text($ws, "A{$row}", $customer['name']);
                    $this->text($ws, "B{$row}", $area['label']);
                    $this->text($ws, "C{$row}", (string) (InvoiceSeries::labelFor($invoice->series) ?? $invoice->series ?? ''));
                    $this->text($ws, "D{$row}", $this->invoiceLabel($invoice));
                    $this->text($ws, "E{$row}", $invoice->invoice_date?->format('Y-m-d') ?? '');
                    $this->text($ws, "F{$row}", (string) ($invoice->cost_center_name ?? ''));
                    $ws->setCellValue("G{$row}", round((float) $invoice->invoice_amount, 2));
                    $this->text($ws, "H{$row}", (string) ($invoice->invoice_note ?? ''));
                    $this->text($ws, "I{$row}", $invoice->is_zero_value ? 'هدية / صفر' : '');
                    $row++;
                }
            }
        }
        $lastRow = max(1, $row - 1);

        $ws->getStyle("G2:G{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $ws->getStyle("D2:G{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("H2:H{$lastRow}")->getAlignment()->setWrapText(true);
        $this->borders($ws, "A1:I{$lastRow}");
        $this->widths($ws, ['A' => 32, 'B' => 18, 'C' => 16, 'D' => 16, 'E' => 13, 'F' => 24, 'G' => 15, 'H' => 28, 'I' => 12]);
        $this->pageSetup($ws, $sheet, 'I', $lastRow, 1);
    }

    // =====================================================================
    // الورقة 3: الأصناف (عند with_lines)
    // =====================================================================

    private function sheetLines(Worksheet $ws, DistributionSheet $sheet, Collection $areas): void
    {
        $ws->setTitle('الأصناف');
        $this->headerRow($ws, 1, ['رقم الفاتورة', 'الزبون', 'الصنف', 'المجموعة', 'الكمية', 'الهدايا', 'الإفرادي', 'الإجمالي']);

        $row = 2;
        foreach ($areas as $area) {
            foreach ($area['customers'] as $customer) {
                foreach ($customer['invoices'] as $invoice) {
                    foreach ($invoice->lines as $line) {
                        $this->text($ws, "A{$row}", $this->invoiceLabel($invoice));
                        $this->text($ws, "B{$row}", $customer['name']);
                        $this->text($ws, "C{$row}", (string) $line->item_name);
                        $this->text($ws, "D{$row}", (string) ($line->item_group ?? ''));
                        $ws->setCellValue("E{$row}", (float) $line->quantity);
                        $ws->setCellValue("F{$row}", (float) $line->gift_quantity);
                        $ws->setCellValue("G{$row}", round((float) $line->unit_price, 2));
                        $ws->setCellValue("H{$row}", round((float) $line->line_total, 2));
                        $row++;
                    }
                }
            }
        }
        $lastRow = max(1, $row - 1);

        $ws->getStyle("E2:F{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.###');
        $ws->getStyle("G2:H{$lastRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $ws->getStyle("E2:H{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws->getStyle("C2:C{$lastRow}")->getAlignment()->setWrapText(true);
        $this->borders($ws, "A1:H{$lastRow}");
        $this->widths($ws, ['A' => 16, 'B' => 30, 'C' => 36, 'D' => 18, 'E' => 10, 'F' => 10, 'G' => 14, 'H' => 15]);
        $this->pageSetup($ws, $sheet, 'H', $lastRow, 1);
    }

    // =====================================================================
    // أدوات
    // =====================================================================

    /**
     * "السلسلة: الرقم" مثل "G: 12830".
     */
    private function invoiceLabel(Invoice $invoice): string
    {
        [$series, $number] = InvoiceFingerprint::split((string) $invoice->invoice_number);

        return $series !== null ? "{$series}: {$number}" : (string) $number;
    }

    /**
     * A4 أفقي، عرض صفحة واحدة وصفحات بقدر الحاجة، هوامش ~1 سم، توسيط أفقي، تكرار صف الترويسة،
     * بلا خطوط شبكة، RTL، وترويسة/تذييل بأرقام الصفحات وتاريخ الطباعة وأمر التسليم.
     */
    private function pageSetup(Worksheet $ws, DistributionSheet $sheet, string $lastColumn, int $lastRow, int $headerRow): void
    {
        $order = 'أمر التسليم #'.$sheet->orderLabel();
        $this->a4Landscape($ws, $lastColumn, $lastRow, $headerRow, 'كشف توزيع — '.$order, $order);
    }
}
