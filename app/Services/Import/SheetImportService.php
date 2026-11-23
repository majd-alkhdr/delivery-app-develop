<?php

namespace App\Services\Import;

use App\Models\ActivityLog;
use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\InvoiceSeries;
use App\Models\SheetAssignment;
use App\Models\User;
use App\Services\SheetFiguresService;
use App\Support\ArabicNormalizer;
use App\Support\BulkWrite;
use App\Support\InvoiceFingerprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * رفع ملف أو عدة ملفات بالصيغة الجديدة (v2) وتشكيل الكشوف منها (المرحلة 2B):
 * - كل رقم أمر تسليم يصبح كشفاً مستقلاً بحالة "قيد التجهيز" (preparing).
 * - الطلبيات المؤجلة (تسليمها بعد تاريخ التوزيع) لا تدخل أي كشف.
 * - فواتير اليوم بلا أمر تسليم تذهب إلى "بلا كشف".
 * - الفاتورة تُطابق مع المخزن عبر invoice_key ولا تتكرر أبداً (InvoiceReconciler).
 * لكل ملف معاملة (transaction) مستقلة؛ أي خطأ = إلغاء كل ما حُفظ منه. نتيجة الدفعة تُحفظ في import_batches.
 */
class SheetImportService
{
    public const STORAGE_DIR = 'excel_sheets/v2';

    /** المرحلة 7D: مراحل الرفع (لسجل import.failed) */
    public const STAGES = [
        'parse' => 'قراءة الملف',
        'balances' => 'تطبيق الأرصدة واللقطات',
        'daily' => 'الفواتير وتشكيل الكشوف',
        'items' => 'حفظ عناصر الدفعة',
        'finalize' => 'تفسير الأرصدة والتنبيهات',
        'notes' => 'ملاحظات الملف',
        'missing' => 'فحص الفواتير الغائبة',
        'batch' => 'حفظ تقرير الدفعة',
        'done' => 'اكتمل',
    ];

    private string $stage = 'parse';

    public function __construct(
        private WorkbookImporter $parser,
        private SheetFiguresService $figures,
        private InvoiceReconciler $reconciler,
        private \App\Services\Balances\BalanceImportService $balances,
    ) {
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return array{batch_id: string, batch: ImportBatch, reports: array<int, array>}
     */
    public function importBatch(array $files, string $distributionDate, User $user, bool $checkMissing = false, ?string $manualAsOf = null, bool $columnsConfirmed = false): array
    {
        $this->stage = 'parse';

        // 1) حفظ كل ملف باسم فريد (لا يُكتب فوق أي ملف أبداً)
        $stored = [];
        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'xlsx');
            $storedPath = self::STORAGE_DIR.'/'.Str::uuid().'.'.$extension;
            Storage::putFileAs(self::STORAGE_DIR, $file, basename($storedPath));
            $stored[] = ['original_name' => $file->getClientOriginalName(), 'stored_path' => $storedPath];
        }

        return $this->importStored($stored, $distributionDate, $user, $checkMissing, $manualAsOf, $columnsConfirmed);
    }

    /**
     * المرحلة 7G: قراءة ملفات محفوظة وتطبيقها. غياب عمود حرج (رقم أمر التسليم، تاريخ التسليم…) دون تأكيد صريح
     * ⇒ لا يُطبَّق شيء، وتبقى الملفات محفوظة، ويُرمى ColumnsConfirmationRequired بما تحتاجه شاشة التأكيد.
     *
     * @param  array<int, array{original_name: string, stored_path: string}>  $stored
     * @return array{batch_id: string, batch: ImportBatch, reports: array<int, array>}
     *
     * @throws ColumnsConfirmationRequired
     */
    public function importStored(array $stored, string $distributionDate, User $user, bool $checkMissing = false, ?string $manualAsOf = null, bool $columnsConfirmed = false): array
    {
        $batchId = (string) Str::uuid();
        $items = [];
        $this->stage = 'parse';

        foreach ($stored as $file) {
            $item = ['original_name' => $file['original_name'], 'stored_path' => $file['stored_path'], 'parsed' => null, 'error' => null];
            try {
                $item['parsed'] = $this->parser->parse(Storage::path($file['stored_path']));
            } catch (ImportException $e) {
                $item['error'] = $e->getMessage();
            }
            $items[] = $item;
        }

        // المرحلة 7G: بصمة الملف (SHA-256؛ لعدة ملفات بصمة بصماتها المرتّبة) لتنبيه «الملف نفسه رُفع سابقاً»
        $fileHash = self::fingerprint(array_map(fn ($i) => Storage::path($i['stored_path']), $items));

        if (! $columnsConfirmed && ($missing = $this->columnsNeedingConfirmation($items))) {
            throw new ColumnsConfirmationRequired([
                'files' => $stored,
                'distribution_date' => $distributionDate,
                'check_missing' => $checkMissing,
                'balances_as_of' => $manualAsOf,
                'file_hash' => $fileHash,
            ] + $missing + ['estimate' => $this->estimate($items, $distributionDate)]);
        }

        // 2) عند وجود أكثر من صفحة أرصدة: تُطبَّق الأحدث تاريخاً فقط
        $balancesIndex = null;
        foreach ($items as $i => $item) {
            if (! ($item['parsed']['balances'] ?? null)) {
                continue;
            }
            if ($balancesIndex === null
                || ($item['parsed']['balances']['as_of'] ?? '') >= ($items[$balancesIndex]['parsed']['balances']['as_of'] ?? '')) {
                $balancesIndex = $i;
            }
        }

        // الملف صاحب الأرصدة المعتمدة يُعالج أولاً حتى تُطبَّق الأرصدة قبل الفواتير
        $order = array_keys($items);
        if ($balancesIndex !== null) {
            $order = array_merge([$balancesIndex], array_values(array_diff($order, [$balancesIndex])));
        }

        // المرحلة 7D: الدفعة كلها معاملة واحدة (معاملة كل ملف نقطة حفظ داخلها): فشل ملف يُلغي ذلك الملف وحده كما كان،
        // وأي استثناء يفلت (الغائبة، حفظ الدفعة) يُلغي الدفعة كاملة فلا يبقى شيء نصف مطبَّق
        [$reports, $batch] = DB::transaction(function () use ($items, $order, $balancesIndex, $distributionDate, $batchId, $user, $manualAsOf, $checkMissing, $fileHash) {
            $batchTally = new ImportTally;
            $reports = [];
            foreach ($order as $i) {
                $reports[$i] = $this->importOne($items[$i], $i === $balancesIndex, $balancesIndex !== null ? $items[$balancesIndex] : null,
                    $distributionDate, $batchId, $user, $batchTally, $manualAsOf);
            }
            ksort($reports);
            $reports = array_values($reports);

            // المرحلة 7A (D): الفواتير الغائبة عن الملف (عند تفعيل الخيار فقط)
            $this->stage = 'missing';
            $this->detectMissing($batchId, $items, $reports, $batchTally, $checkMissing);

            $this->stage = 'batch';

            $batch = $this->saveBatch($batchId, $distributionDate, $user, $items, $reports, $batchTally, $checkMissing);
            if ($fileHash !== null) {
                $batch->forceFill(['file_hash' => $fileHash])->save();
            }

            return [$reports, $batch];
        });
        $this->stage = 'done';

        return ['batch_id' => $batchId, 'batch' => $batch, 'reports' => $reports];
    }

    /**
     * بصمة SHA-256 لملف واحد، أو لبصمات عدة ملفات مرتّبة (الترتيب لا يغيّرها).
     *
     * @param  array<int, string>  $paths
     */
    public static function fingerprint(array $paths): ?string
    {
        $hashes = array_values(array_filter(array_map(fn ($p) => is_file($p) ? hash_file('sha256', $p) : null, $paths)));
        if ($hashes === []) {
            return null;
        }
        sort($hashes);

        return count($hashes) === 1 ? $hashes[0] : hash('sha256', implode("\n", $hashes));
    }

    /**
     * أعمدة حرجة غائبة تتطلب تأكيداً (من ملفات قُرئت بنجاح)، مع كل الحرجة الأخرى (مركز الكلفة) لعرضها في مربع واحد.
     *
     * @return array{columns: array, present: array<string, array<int, string>>, text: string}|array{}
     */
    private function columnsNeedingConfirmation(array $items): array
    {
        $gating = false;
        $columns = [];
        $present = [];
        foreach ($items as $item) {
            $notes = $item['parsed']['notes'] ?? [];
            if (WorkbookImporter::notesRequiringConfirmation($notes)) {
                $gating = true;
            }
            foreach ($notes as $note) {
                if ($note['level'] === 'critical' && isset($note['meta']['column'])) {
                    $columns[$note['code']] ??= ['code' => $note['code'], 'column' => $note['meta']['column'], 'text' => $note['text'],
                        'accepted' => $note['meta']['accepted'], 'count' => 0, 'files' => []];
                    $columns[$note['code']]['count'] += count($note['examples']);
                    $columns[$note['code']]['files'][] = $item['original_name'];
                    $present[$item['original_name']] = $note['meta']['present'];
                }
            }
        }

        return $gating ? ['columns' => array_values($columns), 'present' => $present, 'text' => self::confirmationText(array_column($columns, 'column'))] : [];
    }

    /** نص التأكيد الذي يُعرض بجانب الخانة ويُحفظ في سجل النشاط كما هو */
    public static function confirmationText(array $columns): string
    {
        return 'أفهم أن العمود '.implode('، ', array_map(fn ($c) => '«'.$c.'»', $columns)).' غير موجود في الملف وأريد المتابعة.';
    }

    /**
     * تقدير الأثر قبل التطبيق من الملف وحده بقاعدة التصنيف نفسها (InvoiceReconciler::classify):
     * تسليم بعد تاريخ التوزيع ⇒ مؤجلة، وإلا بلا أمر ⇒ «بلا كشف»، وإلا كشف أمرها.
     *
     * @return array{invoices: int, sheets: int, unassigned: int, deferred: int, orders: int}
     */
    private function estimate(array $items, string $date): array
    {
        $estimate = ['invoices' => 0, 'sheets' => 0, 'unassigned' => 0, 'deferred' => 0, 'orders' => 0];
        $orders = [];
        foreach ($items as $item) {
            foreach ($item['parsed']['daily']['invoices'] ?? [] as $invoice) {
                $estimate['invoices']++;
                $delivery = $invoice['delivery_date'] ?? null;
                $order = $invoice['delivery_order_no'] ?? null;
                if ($delivery !== null && $delivery > $date) {
                    $estimate['deferred']++;
                } elseif ($order === null) {
                    $estimate['unassigned']++;
                } else {
                    $estimate['sheets']++;
                    $orders[$order] = true;
                }
            }
        }
        $estimate['orders'] = count($orders);

        return $estimate;
    }

    /**
     * المرحلة 7D: آخر مرحلة بلغها الرفع (لتسجيل import.failed عند استثناء).
     */
    public function stage(): string
    {
        return $this->stage;
    }

    /** اسم المرحلة بالعربية لسجل النشاط ورسالة الفشل */
    public static function stageLabel(string $stage): string
    {
        return self::STAGES[$stage] ?? $stage;
    }

    private function importOne(array $item, bool $applyBalances, ?array $balancesItem, string $distributionDate, string $batchId, User $user, ImportTally $batchTally, ?string $manualAsOf = null): array
    {
        $report = [
            'file' => $item['original_name'],
            'success' => false,
            'error' => null,
            'warnings' => [],
            'notes' => [],
            'counts' => [],
            'has_daily' => false,
        ];

        if ($item['error'] !== null) {
            Storage::delete($item['stored_path']);
            $report['error'] = $item['error'];

            return $report;
        }

        $parsed = $item['parsed'];
        $report['warnings'] = $parsed['warnings'];
        $report['has_daily'] = (bool) $parsed['daily'];

        if ($parsed['balances'] && ! $applyBalances) {
            $report['notes'][] = sprintf(
                'صفحة الأرصدة في هذا الملف (بتاريخ %s) تم تجاهلها، وطُبّقت الأرصدة الأحدث من الملف "%s" (بتاريخ %s).',
                $parsed['balances']['as_of'] ?? 'غير محدد',
                $balancesItem['original_name'],
                $balancesItem['parsed']['balances']['as_of'] ?? 'غير محدد'
            );
        }
        if (! $parsed['balances'] && ! $balancesItem) {
            $report['notes'][] = 'لا توجد صفحة أرصدة في الملف، فاستُخدمت أرصدة الزبائن الحالية في النظام.';
        }

        $tally = new ImportTally;
        $failed = false;

        try {
            DB::transaction(function () use ($parsed, $applyBalances, $item, $distributionDate, $batchId, $user, &$report, $tally, $manualAsOf) {
                // المرحلة 7B: حارسا التاريخ والتصفير قبل التطبيق، واللقطات عند التطبيق
                $balanceImport = null;
                if ($applyBalances) {
                    $this->stage = 'balances';
                    $balanceImport = $this->balances->stage($parsed['balances'], $item['original_name'], $item['stored_path'], $batchId, $user, $manualAsOf, $tally, $report['warnings']);
                    $report['balances'] = ($balanceImport->stats ?? []) + ['balance_import_id' => $balanceImport->id, 'status' => $balanceImport->status, 'as_of' => $balanceImport->as_of?->toDateString()];
                }

                if ($parsed['daily']) {
                    $this->stage = 'daily';
                    $this->processDaily($parsed['daily'], $item, $distributionDate, $batchId, $user, $report, $tally);
                } else {
                    $report['notes'][] = 'لا توجد صفحة حركة يومية في الملف: '.($balanceImport?->isApplied() ? 'حُدّثت الأرصدة فقط.' : 'ولم تُطبَّق الأرصدة بعد (راجع قسم الأرصدة أدناه).');
                }

                // المرحلة 7A: عناصر الدفعة وملاحظات الملف تُحفظ مع الملف نفسه (تُلغى معه إن فشل)
                $this->stage = 'items';
                $this->persistItems($batchId, $tally->items);
                // المرحلة 7B: التفسير بعد فواتير الملف نفسه (تعديلات مقترحة من الملف تدخل التفسير)
                if ($balanceImport?->isApplied()) {
                    $this->stage = 'finalize';
                    $this->balances->finalize($balanceImport, $tally);
                }
                $this->stage = 'notes';
                $this->persistNotes($batchId, array_merge($parsed['notes'] ?? [], array_values($tally->notes)), $item['original_name']);
            });
            $report['success'] = true;
        } catch (ImportException $e) {
            $report['error'] = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            // المرحلة 7D: معاملة الملف تراجعت كاملة (أرصدة، لقطات، فواتير، عناصر)؛ الملف المحفوظ يبقى لإعادة المحاولة
            $failed = true;
            $report['error'] = 'تعذّر إكمال الرفع ولم يُطبَّق أي شيء من هذا الملف، يمكنك إعادة المحاولة. (المرحلة: '
                .self::stageLabel($this->stage).'، السبب: '.$e->getMessage().')';
            ActivityLog::record('import.failed', null, [], ['status' => 'failed'], [
                'stage' => $this->stage,
                'reason' => mb_substr($e->getMessage(), 0, 1000),
                'file_name' => $item['original_name'],
                'stored_path' => $item['stored_path'],
                'batch_id' => $batchId,
            ]);
        }

        if ($report['success']) {
            $batchTally->merge($tally);
        } else {
            if (! $failed) {
                Storage::delete($item['stored_path']);
            }
            unset($report['balances']);
        }

        return $report;
    }

    // =====================================================================
    // المرحلة 7A: عناصر الدفعة
    // =====================================================================

    /**
     * حفظ عناصر الفواتير، وتعليم العناصر والاقتراحات المفتوحة السابقة لنفس الفاتورة «حلّ محله أحدث» (لا كتابة فوقها).
     */
    private function persistItems(string $batchId, array $items): void
    {
        // المرحلة 7D: إدخال جماعي بالترتيب نفسه، ثم «حلّ محله أحدث» محسوباً في الذاكرة كما لو نُفِّذ عنصراً عنصراً:
        // أول عنصر للفاتورة يأخذ عناصرها المعلّقة من دفعات سابقة، وكل عنصر يُبطل اقتراحاتها المفتوحة عدا اقتراحه.
        $models = array_map(fn ($data) => new ImportBatchItem($data + ['batch_id' => $batchId]), array_values($items));
        BulkWrite::insertModels($models);

        $invoiceIds = array_values(array_unique(array_filter(array_map(fn ($m) => $m->invoice_id, $models))));
        if (! $invoiceIds) {
            return;
        }
        $older = [];
        $proposals = [];
        foreach (array_chunk($invoiceIds, BulkWrite::CHUNK) as $chunk) {
            ImportBatchItem::whereIn('invoice_id', $chunk)->where('batch_id', '!=', $batchId)
                ->whereIn('status', [ImportBatchItem::PENDING, ImportBatchItem::BLOCKED])->orderBy('id')->get(['id', 'invoice_id'])
                ->each(function ($row) use (&$older) {
                    $older[$row->invoice_id][] = $row->id;
                });
            InvoiceChangeProposal::whereIn('invoice_id', $chunk)
                ->whereIn('status', [InvoiceChangeProposal::PENDING, InvoiceChangeProposal::AWAITING_ADJUSTMENT])->orderBy('id')->get(['id', 'invoice_id'])
                ->each(function ($row) use (&$proposals) {
                    $proposals[$row->invoice_id][$row->id] = true;
                });
        }

        $supersededBy = [];
        $supersededProposals = [];
        foreach ($models as $position => $item) {
            if (! $item->invoice_id) {
                continue;
            }
            if (! empty($older[$item->invoice_id])) {
                $supersededBy[$position] = $older[$item->invoice_id];
                $older[$item->invoice_id] = [];
            }
            foreach (array_keys($proposals[$item->invoice_id] ?? []) as $proposalId) {
                if ($item->proposal_id && (int) $proposalId === (int) $item->proposal_id) {
                    continue;
                }
                $supersededProposals[] = $proposalId;
                unset($proposals[$item->invoice_id][$proposalId]);
            }
        }

        if ($supersededBy) {
            // معرّفات العناصر المُدرجة: آخر count($models) عنصراً في الدفعة (تصاعدية بترتيب الإدخال)
            $ids = ImportBatchItem::where('batch_id', $batchId)->orderByDesc('id')->limit(count($models))->pluck('id')->reverse()->values();
            foreach ($supersededBy as $position => $olderIds) {
                ImportBatchItem::whereIn('id', $olderIds)
                    ->update(['status' => ImportBatchItem::SUPERSEDED, 'superseded_by_id' => $ids[$position], 'updated_at' => now()]);
            }
        }
        foreach (array_chunk($supersededProposals, BulkWrite::CHUNK) as $chunk) {
            InvoiceChangeProposal::whereIn('id', $chunk)->update(['status' => InvoiceChangeProposal::SUPERSEDED, 'updated_at' => now()]);
        }
    }

    /**
     * @param  array<int, array{code: string, level: string, text: string, examples: array}>  $notes
     */
    private function persistNotes(string $batchId, array $notes, ?string $fileName): void
    {
        foreach ($notes as $note) {
            ImportBatchItem::create([
                'batch_id' => $batchId,
                'kind' => ImportBatchItem::NOTE,
                'status' => ImportBatchItem::INFO,
                'level' => $note['level'],
                'reason' => $note['text'],
                'new' => [
                    'code' => $note['code'],
                    'file' => $fileName,
                    'count' => count($note['examples'] ?? []),
                    'examples' => array_values($note['examples'] ?? []),
                ] + (isset($note['meta']) ? ['meta' => $note['meta']] : []),
            ]);
        }
    }

    /**
     * الفواتير الغائبة (D): في كشف مفتوح وتاريخها ضمن مدى ملف ناجح ولم تظهر في أي ملف من الدفعة.
     */
    private function detectMissing(string $batchId, array $items, array $reports, ImportTally $tally, bool $checkMissing): void
    {
        $dailyFiles = [];
        foreach (array_values($items) as $i => $item) {
            // التقارير بترتيب الملفات نفسه (ksort في importBatch)
            if (($item['parsed']['daily'] ?? null) && ($reports[$i]['success'] ?? false)) {
                $dailyFiles[] = $item;
            }
        }
        if (! $dailyFiles) {
            return;
        }

        DB::transaction(function () use ($batchId, $dailyFiles, $tally, $checkMissing) {
            if (! $checkMissing) {
                $this->persistNotes($batchId, [[
                    'code' => 'missing_not_checked', 'level' => 'info', 'examples' => [],
                    'text' => 'لم يُفحص غياب الفواتير لأن الملف جزئي (خيار «هذا الملف يغطي كل حركة الفترة» غير مفعّل): فاتورة موجودة في كشف ولم تظهر في الملف لا تُذكر هنا.',
                ]], null);

                return;
            }

            $seen = array_keys($tally->seenKeys);
            $found = collect();
            foreach ($dailyFiles as $file) {
                $from = $file['parsed']['daily']['report_from'];
                $to = $file['parsed']['daily']['report_to'];
                if (! $from || ! $to) {
                    $this->persistNotes($batchId, [[
                        'code' => 'missing_no_range', 'level' => 'warning', 'examples' => [],
                        'text' => 'لم يُفحص غياب الفواتير لهذا الملف لأن مداه الزمني مجهول (لا نص «اعتباراً من … ولغاية …»).',
                    ]], $file['original_name']);

                    continue;
                }

                $found = $found->merge(Invoice::with('distributionSheet')
                    ->where('placement', Invoice::IN_SHEET)
                    ->whereHas('distributionSheet', fn ($q) => $q->where('format', 'v2')->whereIn('status', DistributionSheet::EDITABLE_STATUSES))
                    ->whereNotNull('invoice_key')
                    ->whereNotIn('invoice_key', $seen ?: ['__none__'])
                    ->whereDate('invoice_date', '>=', $from)
                    ->whereDate('invoice_date', '<=', $to)
                    ->get()
                    ->map(fn ($invoice) => [$invoice, $from, $to]));
            }

            $items = [];
            foreach ($found->unique(fn ($row) => $row[0]->id) as [$invoice, $from, $to]) {
                if (ImportBatchItem::where('batch_id', $batchId)->where('invoice_id', $invoice->id)->exists()) {
                    continue;
                }
                $sheet = $invoice->distributionSheet;
                $items[] = [
                    'kind' => ImportBatchItem::MISSING,
                    'status' => ImportBatchItem::PENDING,
                    'invoice_id' => $invoice->id,
                    'invoice_key' => $invoice->invoice_key,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_id' => $invoice->customer_id,
                    'customer_name' => $invoice->customer_name,
                    'sheet_id' => $sheet->id,
                    'sheet_label' => $sheet->shortLabel(),
                    'old' => $this->reconciler->invoiceSnapshot($invoice),
                    'reason' => 'في كشف '.$sheet->shortLabel().' ('.$sheet->statusLabel().') وتاريخها '.$invoice->invoice_date?->toDateString()
                        ." ضمن مدى الملف ({$from} – {$to})، ولم تظهر فيه: ربما أُلغيت في المحاسبة. التطبيق يُخرجها إلى «بلا كشف» بسبب «غابت عن الملف الأحدث» (لا إلغاء ولا حذف)، والتجاهل يُبقيها.",
                ];
            }
            $this->persistItems($batchId, $items);
        });
    }

    // =====================================================================
    // الفواتير وتشكيل الكشوف
    // =====================================================================

    private function processDaily(array $daily, array $item, string $distributionDate, string $batchId, User $user, array &$report, ImportTally $tally): void
    {
        // --- مطابقة الزبائن ---
        $byName = Customer::pluck('id', 'name_normalized')->all();
        $aliases = CustomerAlias::pluck('customer_id', 'alias_normalized')->all();
        $customerIds = [];
        $matched = 0;

        foreach ($daily['invoices'] as $invoice) {
            $name = $invoice['customer_name'];
            if (isset($customerIds[$name])) {
                continue;
            }
            $normalized = ArabicNormalizer::normalize($name);
            $id = $byName[$normalized] ?? $aliases[$normalized] ?? null;

            if ($id !== null) {
                $matched++;
            } else {
                $customer = Customer::create(['name' => $name, 'needs_review' => true]);
                $id = $customer->id;
                $byName[$normalized] = $id;
                $tally->newCustomerIds[] = $id;
            }
            $customerIds[$name] = $id;
        }

        $customers = Customer::whereIn('id', array_unique($customerIds))->get()->keyBy('id');

        // --- السلاسل ---
        $knownSeries = InvoiceSeries::pluck('key')->all();
        foreach (collect($daily['invoices'])->pluck('series')->filter()->unique() as $series) {
            if (! in_array(InvoiceSeries::keyFor($series), $knownSeries, true)) {
                InvoiceSeries::ensure($series);
                $tally->warn("سلسلة فواتير جديدة \"{$series}\" أُضيفت تلقائياً (يمكن تسميتها من شاشة السلاسل).");
            }
        }

        // --- الكشف الهدف لكل أمر تسليم ---
        $sheets = [];
        $completed = [];
        // المرحلة 7H: $newInvoice = فاتورة جديدة (لا مفتاح موجود). كشف الأمر مكتمل ولا كشف مفتوح له ⇒ «ملحق» قيد التجهيز لها؛
        // وللفواتير الموجودة (إعادة تصنيف موقعها) يبقى السلوك السابق: «بلا كشف».
        $sheetFor = function (string $order, bool $newInvoice = false) use (&$sheets, &$completed, $item, $distributionDate, $batchId, $user, $daily, $tally): ?DistributionSheet {
            if (array_key_exists($order, $sheets) && ($sheets[$order] !== null || ! $newInvoice || ! isset($completed[$order]))) {
                return $sheets[$order];
            }

            $base = DistributionSheet::where('format', 'v2')
                ->whereDate('distribution_date', $distributionDate)
                ->where('delivery_order_no', $order);

            if (! isset($completed[$order])) {
                // الكشف المفتوح للأمر (الأصل، أو الملحق المفتوح إن كان الأصل مكتملاً)
                $open = (clone $base)->whereIn('status', ['preparing', 'pending', 'in_progress'])->orderBy('id')->first();
                if ($open) {
                    return $sheets[$order] = $open;
                }

                if ((clone $base)->where('status', 'completed')->exists()) {
                    $completed[$order] = true;
                }
            }

            if (isset($completed[$order])) {
                if (! $newInvoice) {
                    $tally->warn("يوجد كشف مكتمل لأمر التسليم {$order} بتاريخ {$distributionDate}، فوُضعت فواتيره الموجودة سابقاً في \"بلا كشف\".");

                    return $sheets[$order] = null;
                }

                return $sheets[$order] = $this->createSupplement($base, $order, $item, $distributionDate, $batchId, $user, $daily, $tally);
            }

            $sheet = DistributionSheet::create([
                'format' => 'v2',
                'file_name' => $item['original_name'],
                'original_name' => $item['original_name'],
                'stored_path' => $item['stored_path'],
                'status' => 'preparing',
                'distributor_id' => null,
                'supervisor_id' => $user->id,
                'delivery_order_no' => $order,
                'report_from' => $daily['report_from'],
                'report_to' => $daily['report_to'],
                'distribution_date' => $distributionDate,
                'batch_id' => $batchId,
                'total_all' => 0,
                'total_deferred' => 0,
                'total_effective' => 0,
            ]);
            $tally->createdSheetIds[] = $sheet->id;

            ActivityLog::record('sheet.created_from_batch', $sheet, [], [
                'status' => 'preparing',
                'delivery_order_no' => $order,
                'distribution_date' => $distributionDate,
            ], [
                'batch_id' => $batchId,
                'file_name' => $item['original_name'],
            ]);

            return $sheets[$order] = $sheet;
        };

        // --- مطابقة كل فاتورة وتصنيفها ---
        // المرحلة 7D: فواتير الملف الموجودة وأسطرها وكشوفها تُحمَّل على دفعات، وما يُكتب لكل فاتورة يُجمَع
        $this->reconciler->prime(array_map(fn ($data) => InvoiceFingerprint::key($data['series'], $data['number']), $daily['invoices']), $batchId);
        try {
            foreach ($daily['invoices'] as $data) {
                $customer = $customers[$customerIds[$data['customer_name']]];
                $this->reconciler->reconcile($data, $customer, $distributionDate, $batchId, $sheetFor, $tally);
            }
            $this->reconciler->flush();
        } finally {
            $this->reconciler->forget();
        }

        // المرحلة 7H: سجل إنشاء كل ملحق بفواتيره
        foreach ($tally->supplements as $sheetId => &$supplement) {
            if (! empty($supplement['logged'])) {
                continue;
            }
            $supplement['invoices'] = Invoice::where('distribution_sheet_id', $sheetId)->orderBy('id')->pluck('invoice_number')->all();
            $supplement['logged'] = true;
            ActivityLog::record('sheet.supplement_created', DistributionSheet::find($sheetId), [], [
                'status' => 'preparing',
                'supplement_no' => $supplement['no'],
                'delivery_order_no' => $supplement['order'],
                'parent_sheet_id' => $supplement['parent_id'],
            ], [
                'batch_id' => $batchId,
                'file_name' => $item['original_name'],
                'invoices' => $supplement['invoices'],
            ]);
        }
        unset($supplement);

        // --- أرقام الكشوف المتأثرة والرصيد المستحق ---
        foreach (DistributionSheet::whereIn('id', $tally->touchedSheetIds)->get() as $sheet) {
            foreach ($this->figures->refresh($sheet) as $row) {
                $tally->warn(sprintf('الرصيد المستحق للزبون "%s" سالب بعد طرح المؤجل: %s.', $row['name'], number_format($row['due'], 2)));
            }
        }

        // --- زبائن بلا منطقة في الكشوف ---
        $noArea = Invoice::whereIn('distribution_sheet_id', $tally->touchedSheetIds)
            ->where('placement', Invoice::IN_SHEET)
            ->where('area_key', SheetAssignment::NO_AREA)
            ->distinct()
            ->pluck('customer_name');
        if ($noArea->isNotEmpty()) {
            $tally->warn('زبائن بلا منطقة ('.$noArea->count().'): '.$noArea->implode('، ').'.');
        }

        $report['counts'] = [
            'lines' => $daily['lines_count'],
            'invoices' => count($daily['invoices']),
            'customers' => count($customerIds),
            'matched' => $matched,
            'new' => count($customerIds) - $matched,
            'zero_value' => collect($daily['invoices'])->where('is_zero_value', true)->count(),
            'unchanged' => $tally->bucket('unchanged')['count'],
            'unchanged_total' => $tally->bucket('unchanged')['total'],
            'file_total' => round($daily['lines_total'], 2),
            // المرحلة 6B: المجاميع قبل الحسم والحسم والصافي
            'gross_total' => round($daily['lines_total'], 2),
            'discount_total' => round((float) ($daily['discount_total'] ?? 0), 2),
            'net_total' => round((float) ($daily['net_total'] ?? $daily['lines_total']), 2),
            'has_discount' => (bool) ($daily['has_discount'] ?? false),
            'net_uncertain' => collect($daily['invoices'])->where('net_uncertain', true)->count(),
            'star_notes' => (int) ($daily['star_notes'] ?? 0),
        ];
    }

    /**
     * المرحلة 7H: «ملحق N» قيد التجهيز لأمر كشفه مكتمل (أو مكتملة ملاحقه كلها) ولا كشف مفتوح له. بلا إرسال تلقائي.
     * الأصل = الكشف المكتمل الأول للأمر بالتاريخ نفسه (غير الملحق)، والرقم = أكبر رقم ملحق + 1.
     */
    private function createSupplement($base, string $order, array $item, string $distributionDate, string $batchId, User $user, array $daily, ImportTally $tally): DistributionSheet
    {
        $parent = (clone $base)->where('status', 'completed')->whereNull('supplement_no')->orderBy('id')->first()
            ?? (clone $base)->where('status', 'completed')->orderBy('id')->first();
        $no = (int) (clone $base)->max('supplement_no') + 1;

        $sheet = DistributionSheet::create([
            'format' => 'v2',
            'file_name' => $item['original_name'],
            'original_name' => $item['original_name'],
            'stored_path' => $item['stored_path'],
            'status' => 'preparing',
            'distributor_id' => null,
            'supervisor_id' => $user->id,
            'delivery_order_no' => $order,
            'report_from' => $daily['report_from'],
            'report_to' => $daily['report_to'],
            'distribution_date' => $distributionDate,
            'batch_id' => $batchId,
            'total_all' => 0,
            'total_deferred' => 0,
            'total_effective' => 0,
            'parent_sheet_id' => $parent->parent_sheet_id ?? $parent->id,
            'supplement_no' => $no,
        ]);
        $tally->createdSheetIds[] = $sheet->id;
        $tally->supplements[$sheet->id] = ['sheet_id' => $sheet->id, 'parent_id' => $sheet->parent_sheet_id, 'order' => $order, 'no' => $no, 'invoices' => []];
        $tally->note('supplement_created', 'info', 'أوامر كشوفها مكتملة فأُنشئ لها «ملحق» قيد التجهيز لفواتيرها الجديدة (يُعاين ويُرسل كأي كشف)', $sheet->shortLabel());

        return $sheet;
    }

    /**
     * حفظ تقرير الدفعة في import_batches وتسجيل sheet.uploaded للدفعة.
     */
    private function saveBatch(string $batchId, string $distributionDate, User $user, array $items, array $reports, ImportTally $tally, bool $checkMissing = false): ImportBatch
    {
        $successful = collect($reports)->where('success', true);

        $warnings = [];
        foreach ($reports as $report) {
            foreach ($report['warnings'] as $w) {
                $warnings[] = $w;
            }
        }
        $warnings = array_merge($warnings, $tally->warnings);

        $listMessages = [
            'no_order' => 'فواتير بلا أمر تسليم (وُضعت في "بلا كشف")',
            'old_delivery' => 'فواتير تاريخ تسليمها أقدم من تاريخ التوزيع',
            'deferred_with_order' => 'فواتير مؤجلة لها أمر تسليم لتاريخ مستقبلي',
            'completed_order' => 'فواتير أمرها لكشف مكتمل (وُضعت في "بلا كشف")',
        ];
        if ($tally->supplements) {
            $warnings[] = 'أُنشئ '.count($tally->supplements).' ملحقاً لأوامر كشوفها مكتملة: '
                .implode('، ', array_map(fn ($s) => 'أمر '.$s['order'].' — ملحق '.$s['no'].' ('.count($s['invoices']).' فاتورة)', $tally->supplements)).'.';
        }
        // المرحلة 7A: لم تعد القوائم تُقطع (كل فاتورة لها عنصر في «مراجعة الفروق» بترقيم صفحات)
        foreach ($listMessages as $type => $label) {
            if (! empty($tally->lists[$type])) {
                $values = $tally->lists[$type];
                $warnings[] = $label.' ('.count($values).'): '.implode('، ', $values);
            }
        }
        $pendingItems = ImportBatchItem::where('batch_id', $batchId)->where('status', ImportBatchItem::PENDING)->count();
        if ($pendingItems) {
            $warnings[] = "عناصر في هذه الدفعة تنتظر قرارك ({$pendingItems}): راجع «مراجعة الفروق» أدناه.";
        }
        $proposalIds = ImportBatchItem::where('batch_id', $batchId)->whereNotNull('proposal_id')
            ->where('status', ImportBatchItem::PENDING)->pluck('proposal_id')->all();

        $fileTotal = round((float) $successful->sum(fn ($r) => $r['counts']['file_total'] ?? 0), 2);

        $balances = $successful->pluck('balances')->filter()->first();

        $stats = [
            'has_daily' => $successful->contains('has_daily', true),
            'file_total' => $fileTotal,
            // المرحلة 6B: المجاميع قبل الحسم والحسم والصافي
            'totals' => [
                'gross' => round((float) $successful->sum(fn ($r) => $r['counts']['gross_total'] ?? $r['counts']['file_total'] ?? 0), 2),
                'discount' => round((float) $successful->sum(fn ($r) => $r['counts']['discount_total'] ?? 0), 2),
                'net' => round((float) $successful->sum(fn ($r) => $r['counts']['net_total'] ?? $r['counts']['file_total'] ?? 0), 2),
                'net_uncertain_invoices' => (int) $successful->sum(fn ($r) => $r['counts']['net_uncertain'] ?? 0),
            ],
            'lines' => (int) $successful->sum(fn ($r) => $r['counts']['lines'] ?? 0),
            'invoices' => (int) $successful->sum(fn ($r) => $r['counts']['invoices'] ?? 0),
            'sheets' => $tally->bucket('sheets'),
            'deferred' => $tally->bucket('deferred'),
            'unassigned' => $tally->bucket('unassigned'),
            'other' => $tally->bucket('other'),
            // فواتير موجودة مسبقاً في كشوف وبالبصمة نفسها (لا تغيير)
            'unchanged_count' => $tally->bucket('unchanged')['count'],
            'unchanged_total' => $tally->bucket('unchanged')['total'],
            'unchanged_sheets' => array_values($tally->unchangedSheets),
            'created_sheet_ids' => array_values($tally->createdSheetIds),
            // المرحلة 7H: ملاحق أُنشئت لأوامر كشوفها مكتملة
            'supplements' => array_values(array_map(fn ($s) => array_diff_key($s, ['logged' => true]), $tally->supplements)),
            'touched_sheet_ids' => array_values($tally->touchedSheetIds),
            'new_customer_ids' => $tally->newCustomerIds,
            'updates' => $tally->updates,
            'proposal_ids' => $proposalIds,
            // المرحلة 7A: الدفعة لها عناصر في import_batch_items (الدفعات الأقدم تُعرض من stats القديمة)
            'items_version' => 1,
            'check_missing' => $checkMissing,
            'pending_items' => $pendingItems,
            'added_to_published' => $tally->addedToPublished,
            'lists' => $tally->lists,
            'balances' => $balances,
            'files' => collect($reports)->map(fn ($r) => [
                'name' => $r['file'],
                'success' => $r['success'],
                'error' => $r['error'],
                'notes' => $r['notes'],
                'counts' => $r['counts'],
            ])->all(),
        ];

        $batch = ImportBatch::create([
            'id' => $batchId,
            'user_id' => $user->id,
            'distribution_date' => $distributionDate,
            'file_names' => collect($items)->pluck('original_name')->all(),
            'stats' => $stats,
            'warnings' => array_values(array_unique($warnings)),
        ]);

        if ($successful->isNotEmpty()) {
            ActivityLog::record('sheet.uploaded', null, [], [
                'file_names' => $batch->file_names,
                'distribution_date' => $distributionDate,
            ], [
                'batch_id' => $batchId,
                'invoices' => $stats['invoices'],
                'file_total' => $fileTotal,
                'sheets_created' => count($stats['created_sheet_ids']),
                'deferred' => $stats['deferred']['count'],
                'unassigned' => $stats['unassigned']['count'],
                'proposals' => count($stats['proposal_ids']),
            ]);
        }

        return $batch;
    }

    /**
     * أقرب الزبائن المعتمدين لاسم معيّن حسب تشابه الأسماء المطبَّعة.
     *
     * @return array<int, array{id: int, name: string, score: int}>
     */
    public function suggest(Customer $customer, int $limit = 3): array
    {
        $target = $this->stripCommon($customer->name_normalized);
        $scores = [];

        Customer::where('needs_review', false)
            ->where('id', '!=', $customer->id)
            ->select('id', 'name', 'name_normalized')
            ->chunk(1000, function ($chunk) use ($target, &$scores) {
                foreach ($chunk as $candidate) {
                    similar_text($target, $this->stripCommon($candidate->name_normalized), $percent);
                    $scores[] = ['id' => $candidate->id, 'name' => $candidate->name, 'score' => (int) round($percent)];
                }
            });

        usort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scores, 0, $limit);
    }

    private function stripCommon(string $normalized): string
    {
        return trim(preg_replace('/^صيدليه\s+/u', '', $normalized));
    }
}
