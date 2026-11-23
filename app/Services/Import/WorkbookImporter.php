<?php

namespace App\Services\Import;

use App\Support\ArabicNormalizer;
use App\Support\InvoiceFingerprint;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * قراءة ملف الإكسل بالصيغة الجديدة (v2): ملف واحد بصفحتين
 * "الحركة اليومية" و"الأرصدة". تُكتشف كل صفحة من ترويسة أعمدتها
 * داخل أول 10 صفوف، وليس من اسمها أو ترتيبها.
 *
 * هذه الخدمة تقرأ وتتحقق فقط ولا تكتب في قاعدة البيانات؛ الحفظ في SheetImportService.
 */
class WorkbookImporter
{
    private const HEADER_SCAN_ROWS = 10;

    /** المرحلة 7E: ملاحظة حرجة عند غياب عمود «مركز الكلفة» من الملف */
    public const COST_CENTER_COLUMN_MISSING = 'cost_center_column_missing';

    public const COST_CENTER_COLUMN_MISSING_TEXT = 'ملف هذه الدفعة لا يحتوي عمود «مركز الكلفة». لن تُنسب أي فاتورة لمندوب، ولن تعمل ذمم المندوبين لهذه الفواتير. أعد تصدير الملف من المحاسبة مع هذا العمود قبل إرسال الدفعة.';

    /**
     * المرحلة 7G: سجل الأعمدة الاختيارية التي يغيّر غيابها التصنيف أو الحساب.
     * لكل عمود: رمز الملاحظة، والدرجة عند الغياب، ونص الأثر. الأسماء المقبولة من dailyColumns() (المسمى الأساسي + المرادفات).
     * غياب «مركز الكلفة» يبقى بملاحظته وتأكيد إرساله من 7E؛ بقية الحرجة تتطلب تأكيداً صريحاً قبل التطبيق.
     */
    public const COLUMN_REGISTRY = [
        'delivery_order' => [
            'code' => 'delivery_order_column_missing',
            'level' => 'critical',
            'impact' => 'لن يُنشأ أي كشف: كل الفواتير ستُعدّ بلا أمر تسليم (بلا كشف أو مؤجلة) بحسب تاريخ التسليم.',
        ],
        'delivery_date' => [
            'code' => 'delivery_date_column_missing',
            'level' => 'critical',
            'impact' => 'لن تُؤجَّل أي فاتورة: كل الفواتير ستُعدّ مستحقة يوم التوزيع فتدخل كشف أمرها (أو «بلا كشف» إن كانت بلا أمر) ولو كان تسليمها الفعلي لاحقاً، والفواتير الموجودة سابقاً بتاريخ تسليم ستظهر بتغيّر «تاريخ التسليم ← —».',
        ],
        'cost_center' => [
            'code' => self::COST_CENTER_COLUMN_MISSING,
            'level' => 'critical',
            'impact' => self::COST_CENTER_COLUMN_MISSING_TEXT,
        ],
        'unit_price' => [
            'code' => 'unit_price_column_missing',
            // حرجة إن وُجد عمود «الحسم»: الحسم لا يُحسب لسطر إفراديه صفر، فيصير الصافي = الإجمالي قبل الحسم
            'level' => 'warning',
            'impact' => 'سيُحفظ «الإفرادي» صفراً لكل الأسطر ويتوقف تحقق «الكمية × الإفرادي = السعر الإجمالي». قيم الفواتير لا تتأثر (لا حسم في الملف).',
            'impact_with_discount' => 'لن يُطبَّق أي حسم: الحسم لا يُحسب لسطر إفراديه صفر، فستكون قيم الفواتير قبل الحسم وأعلى من برنامج المحاسبة (إن لم يرفض تحقق «الحسم» في الملخص الملف). ويُحفظ «الإفرادي» صفراً.',
        ],
        'quantity' => [
            'code' => 'quantity_column_missing',
            'level' => 'warning',
            'impact' => 'ستُحفظ «الكمية» صفراً لكل الأسطر (يراها الموزع صفراً) ويتوقف تحقق «الكمية × الإفرادي = السعر الإجمالي». قيم الفواتير لا تتأثر.',
        ],
    ];

    /** رموز ملاحظات غياب الأعمدة (للتجميع في مربع واحد) */
    public static function columnNoteCodes(): array
    {
        return array_column(self::COLUMN_REGISTRY, 'code');
    }

    /**
     * ملاحظات غياب الأعمدة الحرجة التي تتطلب تأكيداً قبل التطبيق (كل الحرجة عدا «مركز الكلفة» الذي يُؤكَّد عند الإرسال منذ 7E).
     *
     * @param  array<int, array>  $notes  ملاحظات parse()
     * @return array<int, array>
     */
    public static function notesRequiringConfirmation(array $notes): array
    {
        return array_values(array_filter($notes, fn ($n) => ($n['level'] ?? null) === 'critical'
            && isset($n['meta']['column']) && $n['code'] !== self::COST_CENTER_COLUMN_MISSING));
    }

    private const DAILY_REQUIRED = ['الفاتورة', 'اسم الزبون', 'اسم المادة'];

    private const DAILY_COLUMNS = [
        'invoice' => 'الفاتورة',
        'date' => 'التاريخ',
        'customer' => 'اسم الزبون',
        'item' => 'اسم المادة',
        'group' => 'المجموعة',
        'quantity' => 'كمية',
        'gift' => 'الهدايا',
        'unit_price' => 'الإفرادي',
        'line_total' => 'السعر الإجمالي',
        'cost_center' => 'مركز الكلفة',
        'note' => 'بيان الفاتورة',
        'delivery_date' => 'تاريخ التسليم',
        'entered_by' => 'المدخل',
        'delivery_order' => 'رقم امر التسليم',
    ];

    /** أعمدة لا يمكن قراءة الصفحة بدونها («المدخل» و«الحسم» وما يشبهها اختيارية) */
    private const DAILY_MANDATORY_COLUMNS = ['invoice', 'date', 'customer', 'item', 'line_total'];

    /** تسامح التحقق الداخلي للسطر: G ≈ الكمية × الإفرادي */
    private const LINE_TOLERANCE_ABS = 0.05;

    private const LINE_TOLERANCE_REL = 0.005;

    /** تسامح مطابقة «الحسم» في الجدول الملخص مع مجموع حسوم الأسطر */
    private const DISCOUNT_TOLERANCE = 0.05;

    /**
     * أعمدة الحركة اليومية: المسميات الأساسية + مرادفات config/import.php.
     *
     * @return array<string, array<int, string>>
     */
    private function dailyColumns(): array
    {
        $columns = array_map(fn ($label) => [$label], self::DAILY_COLUMNS);
        foreach ((array) config('import.daily_synonyms', []) as $key => $labels) {
            $columns[$key] = array_values(array_unique(array_merge($columns[$key] ?? [], (array) $labels)));
        }

        return $columns;
    }

    private const BALANCE_REQUIRED = ['اسم العميل', 'المنطقة', 'مدين', 'دائن'];

    private const BALANCE_COLUMNS = [
        'name' => 'اسم العميل',
        'block' => 'الكتلة',
        'area' => 'المنطقة',
        'debit' => 'مدين',
        'credit' => 'دائن',
    ];

    /**
     * @return array{daily: ?array, balances: ?array, warnings: array<int, string>, notes: array<int, array>}
     *
     * @throws ImportException
     */
    public function parse(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            // المرحلة 6B: تُقرأ تنسيقات الخلايا أيضاً (خلية الحسم بتنسيق نسبة مئوية 0.1 = 10%)
            $reader->setReadDataOnly(false);
            $workbook = $reader->load($path);
        } catch (Throwable $e) {
            throw new ImportException('تعذرت قراءة الملف. تأكد أنه ملف إكسل سليم (xlsx أو xls).');
        }

        $daily = null;
        $balances = null;
        $warnings = [];
        // المرحلة 7A: كل ما لم يُقرأ أو عُدِّل بقاعدة تلقائية يصير ملاحظة ظاهرة في شاشة الدفعة
        $notes = [];

        foreach ($workbook->getAllSheets() as $worksheet) {
            $rows = $this->readRows($worksheet);
            $title = $worksheet->getTitle();
            $dailyHeader = $this->findHeader($rows, self::DAILY_REQUIRED);
            $balanceHeader = $dailyHeader === null ? $this->findHeader($rows, self::BALANCE_REQUIRED) : null;

            if ($daily === null && $dailyHeader !== null) {
                $daily = $this->parseDaily($rows, $dailyHeader, $title, $warnings, $worksheet, $notes);
            } elseif ($balances === null && $balanceHeader !== null) {
                $balances = $this->parseBalances($rows, $balanceHeader, $title, $warnings, $notes);
            } else {
                $isEmpty = ! collect($rows)->flatten()->contains(fn ($v) => $v !== null && trim((string) $v) !== '' && (string) $v !== '0');
                $reason = match (true) {
                    $dailyHeader !== null => 'صفحة «حركة يومية» ثانية في الملف: قُرئت الأولى فقط',
                    $balanceHeader !== null => 'صفحة «أرصدة» ثانية في الملف: قُرئت الأولى فقط',
                    $isEmpty => 'صفحة فارغة',
                    default => 'لا تحوي ترويسة «الحركة اليومية» ولا «الأرصدة» في أول '.self::HEADER_SCAN_ROWS.' صفوف',
                };
                $this->addNote($notes, 'unread_sheets', $isEmpty ? 'info' : 'warning', 'صفحات في الملف لم تُقرأ', "«{$title}»: {$reason}");
            }
        }

        $workbook->disconnectWorksheets();

        if ($daily === null && $balances === null) {
            throw new ImportException('لم يُعثر في الملف على صفحة "الحركة اليومية" ولا صفحة "الأرصدة" (تُكتشف الصفحة من ترويسة أعمدتها).');
        }

        return ['daily' => $daily, 'balances' => $balances, 'warnings' => $warnings, 'notes' => array_values($notes)];
    }

    /**
     * ملاحظة على الملف (المرحلة 7A): نص ثابت وأمثلة (كلها، والواجهة تعرض أول 10 مع «عرض الكل»).
     */
    private function addNote(array &$notes, string $code, string $level, string $text, ?string $example = null): void
    {
        $notes[$code] ??= ['code' => $code, 'level' => $level, 'text' => $text, 'examples' => []];
        if ($level === 'warning' && $notes[$code]['level'] !== 'critical') {
            $notes[$code]['level'] = 'warning';
        }
        if ($example !== null) {
            $notes[$code]['examples'][] = $example;
        }
    }

    /**
     * أعمدة الترويسة غير المعروفة (بأسمائها كما في الملف).
     *
     * @return array<int, string>
     */
    private function unknownColumns(array $headerRow, array $columnMap): array
    {
        $mapped = array_values($columnMap);
        $unknown = [];
        foreach ($headerRow as $col => $value) {
            if (is_string($value) && trim($value) !== '' && ! in_array($col, $mapped, true)) {
                $unknown[] = trim(preg_replace('/\s+/u', ' ', $value));
            }
        }

        return $unknown;
    }

    // =====================================================================
    // صفحة الحركة اليومية
    // =====================================================================

    private function parseDaily(array $rows, array $header, string $title, array &$warnings, ?Worksheet $worksheet = null, array &$notes = []): array
    {
        $columns = $this->dailyColumns();
        [$headerIndex, $columnMap] = [$header['row'], $this->mapColumns($header['cells'], $columns)];

        $missing = array_diff(self::DAILY_MANDATORY_COLUMNS, array_keys($columnMap));
        if ($missing) {
            $labels = array_map(fn ($k) => $columns[$k][0], $missing);
            throw new ImportException("صفحة الحركة اليومية ({$title}) ينقصها الأعمدة: ".implode('، ', $labels).'.');
        }

        [$reportFrom, $reportTo] = $this->findReportRange($rows, $headerIndex);
        if ($reportFrom === null) {
            $this->addNote($notes, 'no_report_range', 'warning',
                "لم يُعثر على نص «اعتباراً من … ولغاية …» في صفحة الحركة اليومية ({$title}): مدى الملف مجهول، فلا يعمل فحص الفواتير الغائبة.");
        }

        foreach ($this->unknownColumns($rows[$headerIndex] ?? [], $columnMap) as $label) {
            $this->addNote($notes, 'unknown_columns', 'info', 'أعمدة غير معروفة لم تُقرأ', "الحركة اليومية: «{$label}»");
        }
        foreach ([
            'entered_by' => 'يُحفظ اسم المُدخِل للعرض فقط ولا يدخل في أي حساب',
            'gift_discount' => 'لا يطبّقه النظام؛ القيمة غير الصفرية تعلّم الفاتورة «قد يختلف الصافي عن برنامج المحاسبة»',
            'additions' => 'لا يطبّقه النظام؛ القيمة غير الصفرية تعلّم الفاتورة «قد يختلف الصافي عن برنامج المحاسبة»',
        ] as $key => $why) {
            if (isset($columnMap[$key])) {
                $label = trim((string) ($rows[$headerIndex][$columnMap[$key]] ?? $columns[$key][0]));
                $this->addNote($notes, 'ignored_columns', 'info', 'أعمدة معروفة لا يدخلها النظام في الحساب (تُتجاهل عمداً)', "«{$label}»: {$why}");
            }
        }

        $hasDiscount = isset($columnMap['discount']);
        // المرحلة 7G: الأعمدة الموجودة فعلاً في الملف، والأعمدة المؤثرة الغائبة (ملاحظة واحدة لكل عمود، أمثلتها الفواتير)
        $presentColumns = array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '', $rows[$headerIndex] ?? []), fn ($v) => $v !== ''));
        $missingColumns = [];
        foreach (self::COLUMN_REGISTRY as $key => $entry) {
            // «مركز الكلفة» بملاحظته من 7E كما هي (تُضاف إليها بيانات العمود بعد الحلقة)
            if (! isset($columnMap[$key]) && $key !== 'cost_center') {
                $missingColumns[$key] = $entry['code'];
            }
        }
        // المرحلة 7E: عمود «مركز الكلفة» غائب من الترويسة كلها (خطأ تصدير): ملاحظة حرجة واحدة تُحصى فيها الفواتير، بلا ملاحظات فردية
        $noCostCenterColumn = ! isset($columnMap['cost_center']);
        $invCol = $columnMap['invoice'];
        $invoices = [];
        $current = null;
        $linesCount = 0;
        $linesTotal = 0.0;       // Σ إجمالي الأسطر قبل الحسم (يُطابَق مع «المجموع» في الملخص)
        $discountCents = 0;      // Σ حسوم الأسطر بالقروش
        $deliveryOrders = [];
        $summaryStart = null;
        $fractionCandidates = [];   // نسب رقمية غير صفرية بلا تنسيق نسبة (لتحذير «قد تكون كسراً»)
        $invalidOrders = [];

        for ($i = $headerIndex + 1, $n = count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            $excelRow = $i + 1;
            $invoiceCell = $this->text($row[$invCol] ?? null);

            // نهاية البيانات: صف ملخص "الفاتورة | المجموع"
            if ($invoiceCell === 'الفاتورة' && $this->text($row[$invCol + 1] ?? null) === 'المجموع') {
                $summaryStart = $i;
                break;
            }

            $itemName = $this->text($this->cell($row, $columnMap, 'item'));
            $hasInvoiceHeader = $invoiceCell !== '' && $invoiceCell !== '0';

            if (! $hasInvoiceHeader && $itemName === '') {
                continue; // صف فارغ
            }

            if ($hasInvoiceHeader) {
                $customerName = $this->text($this->cell($row, $columnMap, 'customer'));
                if ($customerName === '' || $customerName === '0') {
                    throw new ImportException("السطر {$excelRow}: الفاتورة {$invoiceCell} بلا اسم زبون.");
                }

                $invoiceDate = $this->parseDate($this->cell($row, $columnMap, 'date'));
                if ($invoiceDate === null) {
                    throw new ImportException("السطر {$excelRow}: تاريخ الفاتورة {$invoiceCell} غير صالح (المتوقع dd-mm-yyyy).");
                }

                if (isset($invoices[$invoiceCell])) {
                    // نفس رقم الفاتورة بزبونين مختلفين خطأ فادح
                    if (ArabicNormalizer::normalize($invoices[$invoiceCell]['customer_name']) !== ArabicNormalizer::normalize($customerName)) {
                        throw new ImportException("الفاتورة {$invoiceCell} مكررة بزبونين مختلفين: \"{$invoices[$invoiceCell]['customer_name']}\" و\"{$customerName}\" (السطر {$excelRow}).");
                    }
                    // ترويسة مكررة لنفس الزبون: تُدمج الأسطر وتُعتمد بيانات الترويسة الأولى (مع ذكر الفرق)
                    $first = $invoices[$invoiceCell];
                    [$ccCode2, $ccName2] = $this->splitCostCenter($this->text($this->cell($row, $columnMap, 'cost_center')));
                    $enteredBy2 = $this->nullIfEmpty($this->text($this->cell($row, $columnMap, 'entered_by')));
                    $diffs = [];
                    if ($invoiceDate !== $first['invoice_date']) {
                        $diffs[] = "التاريخ {$invoiceDate} بدل {$first['invoice_date']}";
                    }
                    if ([$ccCode2, $ccName2] !== [$first['cost_center_code'], $first['cost_center_name']]) {
                        $diffs[] = 'مركز الكلفة «'.trim(($ccCode2 ?? '').'-'.($ccName2 ?? ''), '-').'» بدل «'.trim(($first['cost_center_code'] ?? '').'-'.($first['cost_center_name'] ?? ''), '-').'»';
                    }
                    if ($enteredBy2 !== $first['entered_by_name']) {
                        $diffs[] = 'المُدخِل «'.($enteredBy2 ?? '—').'» بدل «'.($first['entered_by_name'] ?? '—').'»';
                    }
                    $diffs
                        ? $this->addNote($notes, 'duplicate_header_diff', 'warning', 'ترويسة فاتورة مكررة لنفس الزبون ببيانات مختلفة عن الأولى (دُمجت الأسطر واعتُمدت بيانات الترويسة الأولى)', "{$invoiceCell} (السطر {$excelRow}): ".implode('؛ ', $diffs))
                        : $this->addNote($notes, 'duplicate_header', 'info', 'ترويسة فاتورة تكررت في الملف فدُمجت أسطرها مع الأولى', "{$invoiceCell} (السطر {$excelRow})");
                } else {
                    [$series, $number] = $this->parseInvoiceNumber($invoiceCell);
                    $ccRaw = $this->text($this->cell($row, $columnMap, 'cost_center'));
                    [$ccCode, $ccName] = $this->splitCostCenter($ccRaw);
                    foreach ($missingColumns as $key => $code) {
                        $entry = self::COLUMN_REGISTRY[$key];
                        $withDiscount = $key === 'unit_price' && $hasDiscount;
                        $this->addNote($notes, $code, $entry['level'] === 'critical' || $withDiscount ? 'critical' : 'warning',
                            'ملف هذه الدفعة لا يحتوي عمود «'.self::DAILY_COLUMNS[$key].'». '.($withDiscount ? $entry['impact_with_discount'] : $entry['impact']), $invoiceCell);
                    }
                    if ($noCostCenterColumn) {
                        $this->addNote($notes, self::COST_CENTER_COLUMN_MISSING, 'critical', self::COST_CENTER_COLUMN_MISSING_TEXT, $invoiceCell);
                    } elseif ($ccCode === null) {
                        $this->addNote($notes, 'cost_center_no_code', 'warning', 'فواتير مركز كلفتها بلا كود (لن تُنسب لأي مندوب، وتظهر تحت «بلا مندوب»)',
                            $ccName === null ? "{$invoiceCell}: بلا مركز كلفة" : "{$invoiceCell}: «{$ccRaw}»");
                    }

                    $invoices[$invoiceCell] = [
                        'invoice_number' => $invoiceCell,
                        'series' => $series,
                        'number' => $number,
                        'invoice_date' => $invoiceDate,
                        'customer_name' => $customerName,
                        'delivery_date' => null,
                        'cost_center_code' => $ccCode,
                        'cost_center_name' => $ccName,
                        'entered_by_name' => $this->nullIfEmpty($this->text($this->cell($row, $columnMap, 'entered_by'))),
                        'invoice_note' => null,
                        'total' => 0.0,
                        'gross_total' => 0.0,
                        'discount_total' => 0.0,
                        'net_uncertain' => false,
                        'lines' => [],
                        'orders' => [],
                        'first_row' => $excelRow,
                        'delivery_dates' => [],
                        'notes_seen' => [],
                    ];
                }
                $current = $invoiceCell;
            }

            if ($current === null) {
                throw new ImportException("السطر {$excelRow}: صنف بدون رقم فاتورة قبله.");
            }

            // بيان الفاتورة: أول قيمة غير فارغة بين أسطرها («0» والمسافات = لا بيان؛ «***» بيان عادي)
            $note = $this->text($this->cell($row, $columnMap, 'note'));
            if ($note !== '' && $note !== '0') {
                $invoices[$current]['invoice_note'] ??= $note;
                $invoices[$current]['notes_seen'][$note] = true;
            }

            if ($itemName === '') {
                // ترويسة فاتورة بلا صنف (غير متوقع، لا يُحسب سطراً). سعر إجمالي غير صفري فيها = قيمة لم تُقرأ
                $orphan = $this->number($this->cell($row, $columnMap, 'line_total'));
                if ($orphan !== null && abs($orphan) >= 0.005) {
                    $this->addNote($notes, 'line_without_item', 'warning', 'أسطر بلا اسم مادة وفيها سعر إجمالي (لم تُحسب)', "السطر {$excelRow} (الفاتورة {$current}): ".number_format($orphan, 2));
                }

                continue;
            }

            $lineTotal = $this->number($this->cell($row, $columnMap, 'line_total'));
            if ($lineTotal === null) {
                throw new ImportException("السطر {$excelRow}: السعر الإجمالي غير رقمي.");
            }
            $lineTotal = round($lineTotal, 2);

            $deliveryRaw = $this->cell($row, $columnMap, 'delivery_date');
            $deliveryDate = $this->parseDate($deliveryRaw);
            if ($deliveryDate === null && $this->text($deliveryRaw) !== '' && $this->text($deliveryRaw) !== '0') {
                throw new ImportException("السطر {$excelRow}: تاريخ التسليم غير صالح.");
            }
            if ($deliveryDate !== null) {
                $invoices[$current]['delivery_dates'][$deliveryDate] = true;
            }
            if ($deliveryDate !== null && ($invoices[$current]['delivery_date'] === null || $deliveryDate > $invoices[$current]['delivery_date'])) {
                $invoices[$current]['delivery_date'] = $deliveryDate;
            }

            // رقم أمر التسليم لكل سطر (0 أو الفراغ = بلا أمر). غير الرقمي (مثل 840*) ⇒ بلا أمر مع تحذير
            $orderRaw = $this->text($this->cell($row, $columnMap, 'delivery_order'));
            $order = InvoiceFingerprint::order($orderRaw);
            if ($order !== null && ! ctype_digit($this->latinDigits($order))) {
                $invalidOrders[$current] = $orderRaw;
                $order = null;
            } elseif ($order === null && $orderRaw === '0' && ! isset($invalidOrders[$current])) {
                // «0» صفري: بلا أمر، ويُذكر في التحذير إن بقيت الفاتورة كلها بلا أمر
                $invalidOrders[$current] = '0';
            }
            $orderKey = $order ?? '';
            $invoices[$current]['orders'][$orderKey] = ($invoices[$current]['orders'][$orderKey] ?? 0) + 1;
            if ($order !== null) {
                $deliveryOrders[$order] = ($deliveryOrders[$order] ?? 0) + 1;
            }

            foreach (['quantity' => 'الكمية', 'unit_price' => 'الإفرادي', 'gift' => 'الهدايا'] as $key => $label) {
                $raw = $this->cell($row, $columnMap, $key);
                if ($raw !== null && $this->text($raw) !== '' && $this->number($raw) === null) {
                    $this->addNote($notes, 'non_numeric', 'warning', 'قيم غير رقمية في الكمية أو الإفرادي أو الهدايا عوملت صفراً', "{$current} السطر {$excelRow}: {$label} «".$this->text($raw).'»');
                }
            }
            $quantity = $this->number($this->cell($row, $columnMap, 'quantity')) ?? 0.0;
            $unitPrice = $this->number($this->cell($row, $columnMap, 'unit_price')) ?? 0.0;

            // تحقق داخلي: G ≈ الكمية × الإفرادي (الهدايا لا تدخل)
            if (isset($columnMap['quantity'], $columnMap['unit_price'])) {
                $expected = round($quantity * $unitPrice, 2);
                if (abs($expected - $lineTotal) > max(self::LINE_TOLERANCE_ABS, abs($lineTotal) * self::LINE_TOLERANCE_REL)) {
                    $warnings[] = sprintf('عدم اتساق السطر %d (الفاتورة %s): الكمية × الإفرادي = %s والسعر الإجمالي %s. اعتُمدت قيمة الملف.',
                        $excelRow, $current, number_format($expected, 2), number_format($lineTotal, 2));
                }
            }

            // الحسم % (اختياري)
            $discountPercent = null;
            if ($hasDiscount) {
                $discountPercent = $this->parsePercent($row, $columnMap, $i, $worksheet, $excelRow, $current, $warnings, $fractionCandidates);
            }
            $discountAmount = 0.0;
            if ($discountPercent !== null && $discountPercent > 0 && abs($unitPrice) >= 0.005 && abs($lineTotal) >= 0.005) {
                $discountAmount = round($lineTotal * $discountPercent / 100, 2);
            }
            $lineNet = round($lineTotal - $discountAmount, 2);

            // أعمدة غير مُنمذجة: «حسم هدايا» و«الإضافات» غير الصفرية ⇒ تحذير وتعليم الفاتورة
            foreach (['gift_discount' => 'حسم هدايا', 'additions' => 'الإضافات'] as $key => $label) {
                $value = isset($columnMap[$key]) ? $this->number($this->percentText($this->cell($row, $columnMap, $key))) : null;
                if ($value !== null && abs($value) >= 0.005) {
                    if (! $invoices[$current]['net_uncertain']) {
                        $warnings[] = "الفاتورة {$current}: قيمة غير صفرية في عمود «{$label}» (السطر {$excelRow}) لا يطبّقها النظام، وقد يختلف الصافي عن برنامج المحاسبة.";
                    }
                    $invoices[$current]['net_uncertain'] = true;
                }
            }

            $invoices[$current]['lines'][] = [
                'line_no' => count($invoices[$current]['lines']) + 1,
                'item_name' => $itemName,
                'item_group' => $this->nullIfEmpty($this->text($this->cell($row, $columnMap, 'group'))),
                'quantity' => $quantity,
                'gift_quantity' => $this->number($this->cell($row, $columnMap, 'gift')) ?? 0.0,
                'unit_price' => $unitPrice,
                // إجمالي السطر قبل الحسم كما في الملف
                'line_total' => $lineTotal,
                'discount_percent' => $hasDiscount ? ($discountPercent ?? 0.0) : null,
                'discount_amount' => $hasDiscount ? $discountAmount : null,
                'line_net' => $lineNet,
            ];
            $invoices[$current]['total'] += $lineNet;
            $invoices[$current]['gross_total'] += $lineTotal;
            $invoices[$current]['discount_total'] += $discountAmount;
            $linesCount++;
            $linesTotal += $lineTotal;
            $discountCents += (int) round($discountAmount * 100);
        }

        if ($linesCount === 0) {
            throw new ImportException('صفحة الحركة اليومية لا تحتوي أي أسطر أصناف.');
        }

        // المرحلة 7G: بيانات العمود الغائب للعرض (اسمه وأسماؤه المقبولة وأعمدة الملف الموجودة)
        foreach ($missingColumns + ($noCostCenterColumn ? ['cost_center' => self::COST_CENTER_COLUMN_MISSING] : []) as $key => $code) {
            if (isset($notes[$code])) {
                $notes[$code]['meta'] = ['column' => self::DAILY_COLUMNS[$key], 'column_key' => $key, 'accepted' => $columns[$key], 'present' => $presentColumns];
            }
        }

        if ($fractionCandidates && max($fractionCandidates) <= 1) {
            $warnings[] = 'نسب الحسم في الملف كلها ≤ 1 وتنسيقها ليس نسبة مئوية: النسبة قد تكون مخزنة كسراً (0.1 بدل 10). اعتُمدت كما هي نقاطاً مئوية.';
        }

        // التحقق المالي الإلزامي: Σ إجمالي الأسطر قبل الحسم = صف "الإجمالي" (عمود «المجموع») في الجدول الملخص
        $summary = $summaryStart !== null ? $this->findSummary($rows, $summaryStart, $invCol) : null;
        $summaryTotal = $summary['total'] ?? null;
        if (($summary['total_rows'] ?? 0) > 1) {
            $this->addNote($notes, 'multiple_totals', 'warning', 'أكثر من صف «الإجمالي» في الجدول الملخص (اعتُمد الأخير)', 'عدد الصفوف: '.$summary['total_rows']);
        }
        if ($summary['discount_missing'] ?? false) {
            $this->addNote($notes, 'summary_discount_missing', 'warning', 'خلية «الحسم» في صف «الإجمالي» فارغة أو غير رقمية (اعتُبرت صفراً)');
        }
        if ($summaryTotal === null) {
            throw new ImportException('لم يُعثر على صف "الإجمالي" في الجدول الملخص أسفل صفحة الحركة اليومية، فلا يمكن التحقق من المجموع.');
        }

        $linesTotal = round($linesTotal, 2);
        if (abs($linesTotal - $summaryTotal) >= 0.01) {
            throw new ImportException(sprintf(
                'مجموع أسطر الأصناف (%s) لا يطابق صف "الإجمالي" في الملف (%s). رُفض الملف كاملاً.',
                number_format($linesTotal, 2),
                number_format($summaryTotal, 2)
            ));
        }

        // تحقق «الحسم» في الملخص (إن وُجد العمود): = Σ حسوم الأسطر بتسامح 0.05
        $discountTotal = $discountCents / 100;
        if (($summary['discount'] ?? null) !== null && abs($summary['discount'] - $discountTotal) > self::DISCOUNT_TOLERANCE) {
            throw new ImportException(sprintf(
                'مجموع حسوم الأسطر (%s) لا يطابق عمود "الحسم" في صف "الإجمالي" (%s). رُفض الملف كاملاً.',
                number_format($discountTotal, 2),
                number_format($summary['discount'], 2)
            ));
        }
        if (($summary['additions'] ?? null) !== null && abs($summary['additions']) >= 0.005) {
            $warnings[] = sprintf('عمود "الإضافات" في الجدول الملخص غير صفري (%s): لا يطبّقه النظام، وقد يختلف الصافي عن برنامج المحاسبة.', number_format($summary['additions'], 2));
        }

        $starNotes = 0;
        foreach ($invoices as $number => &$invoice) {
            $invoice['total'] = round($invoice['total'], 2);
            $invoice['gross_total'] = round($invoice['gross_total'], 2);
            $invoice['discount_total'] = round($invoice['discount_total'], 2);
            $invoice['has_discount'] = $hasDiscount;
            $invoice['is_zero_value'] = abs($invoice['total']) < 0.005;
            if ($invoice['invoice_note'] === '***') {
                $starNotes++;
            }

            // أمر التسليم للفاتورة: الأكثر تكراراً بين أسطرها، مع تحذير عند الاختلاف
            $orders = $invoice['orders'];
            arsort($orders);
            $chosen = (string) array_key_first($orders);
            $invoice['delivery_order_no'] = $chosen === '' ? null : $chosen;
            if (count($orders) > 1) {
                $labels = array_map(fn ($o) => $o === '' ? 'بلا أمر' : $o, array_map('strval', array_keys($orders)));
                $warnings[] = "الفاتورة {$number} تحمل أكثر من رقم أمر تسليم بين أسطرها (".implode('، ', $labels).'). اعتُمد: '.($invoice['delivery_order_no'] ?? 'بلا أمر').'.';
            }
            if (count($invoice['delivery_dates']) > 1) {
                $dates = array_keys($invoice['delivery_dates']);
                sort($dates);
                $this->addNote($notes, 'mixed_delivery_dates', 'info', 'أسطر فاتورة بتواريخ تسليم مختلفة (اعتُمد الأحدث)', "{$number}: ".implode('، ', $dates)." ← اعتُمد {$invoice['delivery_date']}");
            }
            if (count($invoice['notes_seen']) > 1) {
                $this->addNote($notes, 'mixed_notes', 'info', 'أسطر فاتورة ببيانات (بيان الفاتورة) مختلفة (اعتُمد أول بيان غير فارغ)',
                    "{$number}: «".implode('» و«', array_keys($invoice['notes_seen']))."» ← اعتُمد «{$invoice['invoice_note']}»");
            }
            if ($invoice['lines'] === []) {
                $this->addNote($notes, 'no_lines', 'warning', 'ترويسة فاتورة بلا أي صنف (أُنشئت بقيمة صفر)', "{$number} (السطر {$invoice['first_row']})");
            }
            unset($invoice['delivery_dates'], $invoice['notes_seen']);
            if (isset($invalidOrders[$number]) && $invoice['delivery_order_no'] === null) {
                $warnings[] = "الفاتورة {$number}: رقم أمر التسليم «{$invalidOrders[$number]}» غير صالح، فعوملت بلا أمر تسليم («بلا كشف»، أو مؤجلة إن كان تسليمها بعد تاريخ التوزيع).";
            }
            unset($invoice['orders']);
        }
        unset($invoice);
        if ($starNotes > 0) {
            $warnings[] = "عدد الفواتير التي بيانها «***»: {$starNotes} (يُعرض البيان كما هو).";
        }

        // أمر التسليم الأكثر تكراراً في الملف (للعرض فقط؛ كل أمر يصبح كشفاً مستقلاً)
        $deliveryOrderNo = null;
        if ($deliveryOrders) {
            arsort($deliveryOrders);
            $deliveryOrderNo = (string) array_key_first($deliveryOrders);
        }

        return [
            'title' => $title,
            'report_from' => $reportFrom,
            'report_to' => $reportTo,
            'invoices' => $invoices,
            'lines_count' => $linesCount,
            'lines_total' => $linesTotal,
            'summary_total' => $summaryTotal,
            'has_discount' => $hasDiscount,
            'discount_total' => round($discountTotal, 2),
            'net_total' => round($linesTotal - $discountTotal, 2),
            'star_notes' => $starNotes,
            'delivery_order_no' => $deliveryOrderNo,
            'delivery_orders' => $deliveryOrders,
            'columns_present' => $presentColumns,
        ];
    }

    /**
     * نسبة الحسم لسطر (نقاط مئوية): «10.00%» ⇒ 10، رقم بتنسيق نسبة (0.1) ⇒ 10، رقم عادي (10) ⇒ 10.
     * سالبة أو > 100 ⇒ تحذير وتُهمل لذلك السطر (null).
     */
    private function parsePercent(array $row, array $columnMap, int $rowIndex, ?Worksheet $worksheet, int $excelRow, string $invoice, array &$warnings, array &$fractionCandidates): ?float
    {
        $raw = $this->cell($row, $columnMap, 'discount');
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        if (is_string($raw) && str_contains($raw, '%')) {
            $value = $this->number($this->percentText($raw));
        } else {
            $value = $this->number($raw);
            if ($value !== null && (is_int($raw) || is_float($raw))) {
                $format = $worksheet?->getStyle([$columnMap['discount'] + 1, $rowIndex + 1])->getNumberFormat()->getFormatCode() ?? '';
                if (str_contains((string) $format, '%')) {
                    $value *= 100;
                } elseif (abs($value) >= 0.0005) {
                    $fractionCandidates[] = abs($value);
                }
            }
        }

        if ($value === null) {
            $warnings[] = "نسبة الحسم في السطر {$excelRow} (الفاتورة {$invoice}) غير رقمية، وأُهملت.";

            return null;
        }
        $value = round($value, 3);
        if ($value < 0 || $value > 100) {
            $warnings[] = "نسبة الحسم في السطر {$excelRow} (الفاتورة {$invoice}) خارج المجال (".$value.')، وأُهملت لذلك السطر.';

            return null;
        }

        return $value;
    }

    /**
     * نص نسبة مثل «10.00%» بلا علامة النسبة (والقيم الأخرى كما هي).
     */
    private function percentText(mixed $value): mixed
    {
        return is_string($value) ? str_replace(['%', '٪'], '', $value) : $value;
    }

    /**
     * صف "الإجمالي" في الجدول الملخص: «المجموع» (قبل الحسم)، و«الحسم» و«الإضافات» إن وُجد عموداهما
     * في ترويسة الملخص (الفاتورة | المجموع | مجموع النقدي | مجموع الآجل | الحسم | الإضافات). القيم مطلقة بعد إزالة الفواصل.
     *
     * @return array{total: ?float, discount: ?float, additions: ?float}
     */
    private function findSummary(array $rows, int $summaryStart, int $invCol): array
    {
        $header = $rows[$summaryStart] ?? [];
        $discountCol = null;
        $additionsCol = null;
        foreach ($header as $col => $value) {
            $label = ArabicNormalizer::normalize($this->text($value));
            if ($label === ArabicNormalizer::normalize('الحسم')) {
                $discountCol = $col;
            } elseif ($label === ArabicNormalizer::normalize('الإضافات')) {
                $additionsCol = $col;
            }
        }

        $result = ['total' => null, 'discount' => null, 'additions' => null, 'total_rows' => 0, 'discount_missing' => false];
        for ($i = $summaryStart + 1, $n = count($rows); $i < $n; $i++) {
            if ($this->text($rows[$i][$invCol] ?? null) !== 'الإجمالي') {
                continue;
            }
            $result['total_rows']++;
            $value = $this->number($rows[$i][$invCol + 1] ?? null);
            if ($value !== null) {
                $result['total'] = round(abs($value), 2);
            }
            if ($discountCol !== null) {
                $d = $this->number($rows[$i][$discountCol] ?? null);
                $result['discount'] = $d !== null ? round(abs($d), 2) : 0.0;
                $result['discount_missing'] = $d === null;
            }
            if ($additionsCol !== null) {
                $a = $this->number($rows[$i][$additionsCol] ?? null);
                $result['additions'] = $a !== null ? round(abs($a), 2) : 0.0;
            }
        }

        return $result;
    }

    /**
     * سلسلة الفاتورة ورقمها من صيغ مثل "ع G: 12830" و"ارجيفيت: 2571".
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function parseInvoiceNumber(string $invoiceNumber): array
    {
        return InvoiceFingerprint::split($invoiceNumber);
    }

    /**
     * "201201-كول سنتر سارة غوراني" → ["201201", "كول سنتر سارة غوراني"]
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitCostCenter(string $value): array
    {
        if ($value === '' || $value === '0') {
            return [null, null];
        }
        if (! str_contains($value, '-')) {
            return [null, $value];
        }
        [$code, $name] = explode('-', $value, 2);

        return [$this->nullIfEmpty(trim($code)), $this->nullIfEmpty(trim($name))];
    }

    /**
     * التاريخان من نص "اعتباراً من 1-1-2026 ولغاية 13-10-2026" قبل الترويسة.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function findReportRange(array $rows, int $headerIndex): array
    {
        for ($i = 0; $i < $headerIndex; $i++) {
            foreach ($rows[$i] as $value) {
                $text = $this->text($value);
                if ($text === '' || ! str_contains($text, 'لغاية')) {
                    continue;
                }
                preg_match_all('/(\d{1,2})-(\d{1,2})-(\d{4})/u', $this->latinDigits($text), $m, PREG_SET_ORDER);
                $dates = [];
                foreach ($m as $match) {
                    if (checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
                        $dates[] = sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);
                    }
                }
                if ($dates) {
                    return [$dates[0], $dates[1] ?? $dates[0]];
                }
            }
        }

        return [null, null];
    }

    // =====================================================================
    // صفحة الأرصدة
    // =====================================================================

    private function parseBalances(array $rows, array $header, string $title, array &$warnings, array &$notes = []): array
    {
        $headerIndex = $header['row'];
        $columnMap = $this->mapColumns($header['cells'], self::BALANCE_COLUMNS);
        [, $asOf] = $this->findReportRange($rows, $headerIndex);
        if ($asOf === null) {
            $this->addNote($notes, 'balances_no_date', 'warning', "صفحة الأرصدة ({$title}) بلا نص «ولغاية …»: طُبّقت أرصدتها بلا تاريخ.");
        }
        foreach ($this->unknownColumns($rows[$headerIndex] ?? [], $columnMap) as $label) {
            $this->addNote($notes, 'unknown_columns', 'info', 'أعمدة غير معروفة لم تُقرأ', "الأرصدة: «{$label}»");
        }

        $result = [];
        $totalDebit = 0.0;
        $fileTotal = null;
        $debitTotalLabelCol = null;

        for ($i = $headerIndex + 1, $n = count($rows); $i < $n; $i++) {
            $row = $rows[$i];
            $nameRaw = $this->cell($row, $columnMap, 'name');

            // صف الإجمالي: يأتي بعد صف "الرصيد | مجموع مدين | مجموع دائن"
            foreach ($row as $col => $value) {
                if ($this->text($value) === 'مجموع مدين') {
                    $debitTotalLabelCol = $col;
                }
            }
            if ($debitTotalLabelCol !== null && ! is_string($nameRaw) && $this->number($row[$debitTotalLabelCol] ?? null) !== null
                && $this->number($row[$debitTotalLabelCol] ?? null) != 0) {
                $fileTotal = round(abs($this->number($row[$debitTotalLabelCol])), 2);
                continue;
            }

            if (! is_string($nameRaw) || trim($nameRaw) === '') {
                // صف إجمالي بلا ترويسة واضحة: اسم رقمي غير صفري
                $numericName = $this->number($nameRaw);
                if ($numericName !== null && $numericName != 0) {
                    $fileTotal = round(abs($numericName), 2);
                } else {
                    $d = $this->number($this->cell($row, $columnMap, 'debit'));
                    $c = $this->number($this->cell($row, $columnMap, 'credit'));
                    if (($d !== null && abs($d) >= 0.005) || ($c !== null && abs($c) >= 0.005)) {
                        $this->addNote($notes, 'balance_rows_skipped', 'warning', 'صفوف في صفحة الأرصدة تُخطّيت (لم تُطبَّق)',
                            'السطر '.($i + 1).': بلا اسم عميل (مدين '.number_format((float) $d, 2).'، دائن '.number_format((float) $c, 2).')');
                    }
                }
                continue;
            }

            $debit = $this->number($this->cell($row, $columnMap, 'debit'));
            $credit = $this->number($this->cell($row, $columnMap, 'credit'));
            if ($debit === null && $credit === null) {
                $this->addNote($notes, 'balance_rows_skipped', 'warning', 'صفوف في صفحة الأرصدة تُخطّيت (لم تُطبَّق)',
                    'السطر '.($i + 1).' «'.trim($nameRaw).'»: المدين والدائن غير رقميين');
                continue;
            }
            $debit ??= 0.0;
            $credit ??= 0.0;
            if ($debit == 0 && $credit == 0) {
                // مثل صف "الرصيد" الصفري
                if (trim($nameRaw) !== 'الرصيد') {
                    $this->addNote($notes, 'balance_zero_rows', 'info', 'صفوف أرصدة بمدين ودائن صفريين (لم تُطبَّق: لم تُحدَّث منطقتها وكتلتها، ويُصفَّر رصيد صاحبها إن كان موجوداً)',
                        'السطر '.($i + 1).' «'.trim($nameRaw).'»');
                }
                continue;
            }

            $name = trim(preg_replace('/\s+/u', ' ', $nameRaw));
            $area = $this->nullIfEmpty($this->text($this->cell($row, $columnMap, 'area')));
            $block = $this->nullIfEmpty($this->text($this->cell($row, $columnMap, 'block')));

            $result[] = [
                'name' => $name,
                'name_normalized' => ArabicNormalizer::normalize($name),
                'block' => $block === '0' ? null : $block,
                'area' => $area === '0' ? null : $area,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => round($debit - $credit, 2),
            ];
            $totalDebit += $debit;
        }

        $totalDebit = round($totalDebit, 2);
        if ($fileTotal !== null && abs($fileTotal - $totalDebit) >= 0.01) {
            $warnings[] = sprintf('مجموع المدين في صفحة الأرصدة (%s) لا يطابق صف الإجمالي (%s).', number_format($totalDebit, 2), number_format($fileTotal, 2));
        }

        return [
            'title' => $title,
            'as_of' => $asOf,
            'rows' => $result,
            'total_debit' => $totalDebit,
            'file_total' => $fileTotal,
        ];
    }

    // =====================================================================
    // أدوات مساعدة
    // =====================================================================

    /**
     * قراءة الصفوف كقيم خام؛ خلايا المعادلات تُستبدل بقيمتها المحسوبة المخزنة في الملف.
     */
    private function readRows(Worksheet $worksheet): array
    {
        $rows = $worksheet->toArray(null, false, false, false);

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if (is_string($value) && str_starts_with($value, '=')) {
                    $rows[$r][$c] = $worksheet->getCell([$c + 1, $r + 1])->getOldCalculatedValue();
                }
            }
        }

        return $rows;
    }

    /**
     * @return array{row: int, cells: array<int, string>}|null
     */
    private function findHeader(array $rows, array $required): ?array
    {
        $requiredNormalized = array_map([ArabicNormalizer::class, 'normalize'], $required);

        foreach (array_slice($rows, 0, self::HEADER_SCAN_ROWS, true) as $index => $row) {
            $cells = [];
            foreach ($row as $col => $value) {
                if (is_string($value) && trim($value) !== '') {
                    $cells[$col] = ArabicNormalizer::normalize($value);
                }
            }
            if (! array_diff($requiredNormalized, $cells)) {
                return ['row' => $index, 'cells' => $cells];
            }
        }

        return null;
    }

    /**
     * @return array<string, int> مفتاح العمود => رقم العمود
     */
    private function mapColumns(array $headerCells, array $columns): array
    {
        $map = [];
        foreach ($columns as $key => $labels) {
            // اسم واحد أو قائمة مرادفات (أول مرادف موجود في الترويسة)
            foreach ((array) $labels as $label) {
                $col = array_search(ArabicNormalizer::normalize($label), $headerCells, true);
                if ($col !== false) {
                    $map[$key] = $col;
                    break;
                }
            }
        }

        return $map;
    }

    private function cell(array $row, array $columnMap, string $key): mixed
    {
        return isset($columnMap[$key]) ? ($row[$columnMap[$key]] ?? null) : null;
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_float($value) && floor($value) == $value) {
            $value = (int) $value;
        }

        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    private function latinDigits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '٬' => ',',
        ]);
    }

    /**
     * رقم من خلية: يقبل الأرقام والنصوص مثل "-105,898.04" و"0".
     */
    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $clean = str_replace([',', ' ', "\u{00A0}"], '', $this->latinDigits(trim($value)));

        return ($clean !== '' && is_numeric($clean)) ? (float) $clean : null;
    }

    /**
     * تاريخ بصيغة dd-mm-yyyy (أو رقم تسلسلي من إكسل) إلى Y-m-d.
     */
    private function parseDate(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            if ($value > 20000 && $value < 80000) {
                return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
            }

            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (! is_string($value)) {
            return null;
        }

        $text = $this->latinDigits(trim($value));
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $text, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        }

        return null;
    }
}
