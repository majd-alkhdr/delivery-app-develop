<?php

namespace Tests\Support;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\User;
use App\Services\Import\SheetImportService;
use App\Services\SheetDispatchService;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * أدوات لبناء ملفات إكسل للاختبار: نسخة معدّلة من ملف العيّنة، أو ملف صغير بالبنية نفسها.
 */
trait Workbooks
{
    protected function fixturePath(): string
    {
        return base_path('tests/fixtures/sample_workbook.xlsx');
    }

    /**
     * نسخة من ملف العيّنة بعد تطبيق تعديل على صفحاتها.
     *
     * @param  callable(Spreadsheet): void  $modify
     */
    protected function modifiedFixture(callable $modify): string
    {
        $spreadsheet = IOFactory::load($this->fixturePath());
        $modify($spreadsheet);

        return $this->save($spreadsheet);
    }

    /**
     * ملف صغير بالبنية نفسها.
     *
     * @param  array<int, array{number: string, customer: string, delivery?: string, order?: int|string, qty?: int, cc?: string, lines: array<int, array{0: string, 1: float}>}>  $invoices
     * @param  array<int, array{0: string, 1: ?string, 2: ?string, 3: float, 4: float}>|null  $balances  [الاسم، الكتلة، المنطقة، مدين، دائن]
     */
    protected function buildWorkbook(array $invoices, ?array $balances = null, string $balancesAsOf = '13-10-2026', ?float $summaryOverride = null): string
    {
        $spreadsheet = new Spreadsheet;

        $daily = $spreadsheet->getActiveSheet();
        $daily->setTitle('الحركة اليومية');
        $rows = [
            ['الحركة اليومية'],
            [' - تفصيلي'],
            ['اعتباراً من  1-1-2026 ولغاية  13-10-2026', 'العملة: ل.س.ج.'],
            ['الفاتورة', 'التاريخ', 'اسم الزبون', 'اسم المادة', 'المجموعة', 'كمية', 'الهدايا', 'الإفرادي', 'السعر الإجمالي', 'مركز الكلفة', 'بيان الفاتورة', 'تاريخ التسليم', 'المدخل', 'رقم امر التسليم'],
        ];
        $total = 0.0;
        foreach ($invoices as $invoice) {
            foreach ($invoice['lines'] as $i => [$item, $lineTotal]) {
                $first = $i === 0;
                $rows[] = [
                    $first ? $invoice['number'] : 0,
                    $first ? '12-10-2026' : 0,
                    $first ? $invoice['customer'] : 0,
                    $item, 'مجموعة', $invoice['qty'] ?? 1, 0, $lineTotal, $lineTotal,
                    $invoice['cc'] ?? '201201-كول سنتر', 0, $invoice['delivery'] ?? '13-10-2026', 'مدخل', $invoice['order'] ?? 3918,
                ];
                $total += $lineTotal;
            }
        }
        $rows[] = ['الفاتورة', 'المجموع', 'مجموع النقدي', 'مجموع الآجل'];
        $rows[] = ['الإجمالي', '-'.number_format($summaryOverride ?? $total, 2), 0, '-'.number_format($summaryOverride ?? $total, 2)];
        $daily->fromArray($rows, null, 'A1', true);

        if ($balances !== null) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('الأرصدة');
            $balanceRows = [
                ['أرصدة الزبائن', 0, 0, 0, 0],
                ['اسم الحساب: 1201-الزبائن', 'العملة: ل.س.', 0, 0, 0],
                ["اعتباراً من  1-1-2023 ولغاية  {$balancesAsOf}", 0, 0, 0, 0],
                ['اسم العميل', 'الكتلة', 'المنطقة', 'مدين', 'دائن'],
            ];
            $debit = 0.0;
            foreach ($balances as $row) {
                $balanceRows[] = $row;
                $debit += $row[3];
            }
            $balanceRows[] = ['الرصيد', 'مجموع مدين', 'مجموع دائن', 0, 0];
            $balanceRows[] = [$debit, $debit, '-', null, null];
            $sheet->fromArray($balanceRows, null, 'A1', true);
        }

        return $this->save($spreadsheet);
    }

    /**
     * رفع ملف أو أكثر عبر خدمة الاستيراد (افتراضياً بحساب مسؤول كشوف جديد).
     *
     * @param  array<int, string>|string  $paths
     */
    protected function importFiles(array|string $paths, string $date = '2026-10-13', ?User $user = null): array
    {
        $user ??= User::factory()->role(Role::SheetManager)->create();
        $files = array_map(fn ($p) => $this->uploaded($p, basename($p)), (array) $paths);

        return app(SheetImportService::class)->importBatch($files, $date, $user);
    }

    /**
     * رفع ملف ثم إرسال كل كشوفه إلى مشرف التوزيع، وإرجاع أول كشف (للاختبارات التي تحتاج كشفاً منشوراً).
     */
    protected function publishWorkbook(string $path, string $date = '2026-10-13'): DistributionSheet
    {
        $manager = User::factory()->role(Role::SheetManager)->create();
        $result = $this->importFiles($path, $date, $manager);
        app(SheetDispatchService::class)->sendBatch($result['batch'], $manager);

        return DistributionSheet::where('batch_id', $result['batch_id'])->orderBy('id')->firstOrFail();
    }

    /**
     * ملف «الحركة اليومية» بالصيغة الجديدة (المرحلة 6B): أعمدة بترتيب مختلف، «الحسم» نسبة مئوية،
     * «السعر الإجمالي» قبل الحسم، وملخص «الفاتورة | المجموع | مجموع النقدي | مجموع الآجل | الحسم | الإضافات».
     *
     * كل سطر: ['item' => ..., 'qty' => 3, 'unit' => 102, 'discount' => 10 | '10.00%' | ['pct' => 0.1] | null,
     *          'gift' => 0, 'gift_discount' => 0, 'additions' => 0, 'total' => (اختياري: G مختلف عن qty×unit)]
     *
     * @param  array<int, array{number: string, customer: string, delivery?: string, order?: int|string, cc?: string, note?: mixed, lines: array}>  $invoices
     * @param  array{header_total?: string, entered_by?: bool, gift_discount?: bool, additions?: bool, summary_discount?: ?float, summary_total?: ?float, summary_additions?: float, no_discount_column?: bool}  $options
     */
    protected function buildDiscountWorkbook(array $invoices, ?array $balances = null, array $options = []): string
    {
        $spreadsheet = new Spreadsheet;
        $daily = $spreadsheet->getActiveSheet();
        $daily->setTitle('الحركة اليومية');

        $hasDiscount = ! ($options['no_discount_column'] ?? false);
        $headers = ['الفاتورة', 'التاريخ', 'اسم الزبون', 'اسم المادة', 'المجموعة', 'كمية', 'الهدايا', 'الإفرادي'];
        if ($hasDiscount) {
            $headers[] = 'الحسم';
        }
        $headers[] = $options['header_total'] ?? 'السعر الإجمالي';
        if ($options['gift_discount'] ?? false) {
            $headers[] = 'حسم هدايا';
        }
        if ($options['additions'] ?? false) {
            $headers[] = 'الإضافات';
        }
        array_push($headers, 'مركز الكلفة', 'بيان الفاتورة', 'تاريخ التسليم');
        if ($options['entered_by'] ?? false) {
            $headers[] = 'المدخل';
        }
        $headers[] = 'رقم امر التسليم';
        $col = array_flip($headers);

        $rows = [['الحركة اليومية'], [' - تفصيلي'], ['اعتباراً من  1-1-2026 ولغاية  13-10-2026', 'العملة: ل.س.ج.'], $headers];
        $percentCells = [];
        $gross = 0.0;
        $discount = 0.0;
        foreach ($invoices as $invoice) {
            foreach ($invoice['lines'] as $i => $line) {
                $first = $i === 0;
                $total = $line['total'] ?? round(($line['qty'] ?? 1) * ($line['unit'] ?? 0), 2);
                $row = array_fill(0, count($headers), 0);
                $row[$col['الفاتورة']] = $first ? $invoice['number'] : 0;
                $row[$col['التاريخ']] = $first ? '12-10-2026' : 0;
                $row[$col['اسم الزبون']] = $first ? $invoice['customer'] : 0;
                $row[$col['اسم المادة']] = $line['item'];
                $row[$col['المجموعة']] = 'مجموعة';
                $row[$col['كمية']] = $line['qty'] ?? 1;
                $row[$col['الهدايا']] = $line['gift'] ?? 0;
                $row[$col['الإفرادي']] = $line['unit'] ?? 0;
                $row[$col[$options['header_total'] ?? 'السعر الإجمالي']] = $total;
                if ($hasDiscount) {
                    $d = $line['discount'] ?? 0;
                    if (is_array($d)) {
                        $percentCells[] = [$col['الحسم'], count($rows)];
                        $row[$col['الحسم']] = $d['pct'];
                        $p = $d['pct'] * 100;
                    } else {
                        $row[$col['الحسم']] = $d ?? 0;
                        $p = is_string($d) ? (float) str_replace('%', '', $d) : (float) $d;
                    }
                    if ($p > 0 && $p <= 100 && ($line['unit'] ?? 0) > 0) {
                        $discount += round($total * $p / 100, 2);
                    }
                }
                if ($options['gift_discount'] ?? false) {
                    $row[$col['حسم هدايا']] = $line['gift_discount'] ?? 0;
                }
                if ($options['additions'] ?? false) {
                    $row[$col['الإضافات']] = $line['additions'] ?? 0;
                }
                $row[$col['مركز الكلفة']] = $invoice['cc'] ?? '201201-كول سنتر';
                $row[$col['بيان الفاتورة']] = $invoice['note'] ?? 0;
                $row[$col['تاريخ التسليم']] = $invoice['delivery'] ?? '13-10-2026';
                if ($options['entered_by'] ?? false) {
                    $row[$col['المدخل']] = 'مدخل';
                }
                $row[$col['رقم امر التسليم']] = $invoice['order'] ?? 3918;
                $rows[] = $row;
                $gross += $total;
            }
        }
        $summaryTotal = $options['summary_total'] ?? $gross;
        $summaryDiscount = array_key_exists('summary_discount', $options) ? $options['summary_discount'] : $discount;
        $rows[] = ['الفاتورة', 'المجموع', 'مجموع النقدي', 'مجموع الآجل', 'الحسم', 'الإضافات'];
        $rows[] = ['الإجمالي', '-'.number_format($summaryTotal, 2), 0, '-'.number_format($summaryTotal, 2),
            $summaryDiscount === null ? 0 : '-'.number_format($summaryDiscount, 2), number_format($options['summary_additions'] ?? 0, 2)];
        $daily->fromArray($rows, null, 'A1', true);
        foreach ($percentCells as [$c, $r]) {
            $daily->getStyle([$c + 1, $r + 1])->getNumberFormat()->setFormatCode('0.00%');
        }

        if ($balances !== null) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('الأرصدة');
            $balanceRows = [['أرصدة الزبائن'], ['اسم الحساب: 1201-الزبائن'], ['اعتباراً من  1-1-2023 ولغاية  13-10-2026'], ['اسم العميل', 'الكتلة', 'المنطقة', 'مدين', 'دائن']];
            foreach ($balances as $b) {
                $balanceRows[] = $b;
            }
            $sheet->fromArray($balanceRows, null, 'A1', true);
        }

        return $this->save($spreadsheet);
    }

    /**
     * الفاتورة 13722 (من لقطة برنامج المحاسبة): مجموع جزئي 3,095.00، حسم 149.25، صافي 2,945.75.
     */
    protected function invoice13722(array $overrides = []): array
    {
        return $overrides + [
            'number' => 'ع G: 13722',
            'customer' => 'صيدلية الحسم',
            'lines' => [
                ['item' => 'صنف 1', 'qty' => 3, 'unit' => 102, 'discount' => '10.00%'],
                ['item' => 'صنف 2', 'qty' => 3, 'unit' => 170, 'discount' => 10],
                ['item' => 'صنف 3', 'qty' => 4, 'unit' => 255, 'gift' => 1, 'discount' => 0],
                ['item' => 'صنف 4', 'qty' => 3, 'unit' => 116, 'discount' => ['pct' => 0.16]],
                ['item' => 'صنف 5', 'qty' => 4, 'unit' => 128],
                ['item' => 'صنف 6', 'qty' => 3, 'unit' => 133, 'discount' => 3],
            ],
        ];
    }

    protected function uploaded(string $path, string $name = 'كشف.xlsx'): UploadedFile
    {
        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function save(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
