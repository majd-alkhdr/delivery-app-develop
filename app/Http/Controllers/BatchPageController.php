<?php

namespace App\Http\Controllers;

use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\CustomerBalanceSnapshot;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\PrintEvent;
use App\Services\Balances\SnapshotScope;
use App\Services\Import\DuplicateUploadNotice;
use App\Services\Import\ImportReviewService;
use App\Services\Import\WorkbookImporter;
use App\Support\BatchWarnings;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * صفحة «معاينة الدفعة» (المرحلة 7E): ترويسة وبطاقات وشريط «يحتاج انتباهك» وتبويبات.
 * - تُحسب للصفحة عدّادات تجميعية فقط (استعلام واحد لكل مصدر)، وبيانات التبويب الحالي وحده.
 * - ?tab= يحدد التبويب، و?partial=1 يعيد محتوى التبويب وحده (للتبديل دون إعادة تحميل).
 * - الأجزاء الكسولة: ?partial=group (فواتير موقع واحد)، ?partial=note&code= (أمثلة ملاحظة)، ?partial=warnings&group= (قائمة تحذير مجمَّع).
 * لا يُخفى شيء بلا أثر: كل عنصر يمكن الوصول إليه بالمرشّحات والترقيم.
 */
class BatchPageController extends SheetManagerController
{
    public const TABS = [
        'sheets' => 'الكشوف',
        'invoices' => 'الفواتير (مراجعة الفروق)',
        'balances' => 'الأرصدة',
        'notes' => 'ملاحظات وتحذيرات',
        'deferred' => 'المؤجلة',
    ];

    /** حدّ «الجديدة/بلا تغيير» الذي تُعرض فوقه مجمَّعة حسب الموقع */
    public const GROUP_THRESHOLD = 50;

    /** أنواع للعلم تُعرض مجمَّعة حسب الموقع حين تكثر */
    public const QUIET_KINDS = [ImportBatchItem::CREATED, ImportBatchItem::UNCHANGED, ImportBatchItem::CLOSED_UNCHANGED];

    /** ملاحظات الأرصدة تنتقل إلى تبويب «الأرصدة» */
    public const BALANCE_NOTE_CODES = ['balances_below_threshold', 'balances_no_open_sheet', 'balances_same_date_unchanged'];

    /** المرحلة 7E2: مرشّح «تغيّر وصفي فقط» */
    public const DESCRIPTIVE = 'descriptive';

    public function show(Request $request, ImportBatch $batch, ImportReviewService $review)
    {
        $tab = array_key_exists((string) $request->query('tab'), self::TABS) ? (string) $request->query('tab') : 'sheets';
        $partial = (string) $request->query('partial', '');

        if ($partial === 'group') {
            return $this->groupItems($request, $batch);
        }
        if ($partial === 'note') {
            return $this->noteList($request, $batch);
        }
        if ($partial === 'warnings') {
            return $this->warningList($request, $batch);
        }

        $data = $this->common($request, $batch);
        $data['tab'] = $tab;
        $data += match ($tab) {
            'invoices' => $this->invoicesTab($request, $batch, $review, $data),
            'balances' => $this->balancesTab($batch, $data),
            'notes' => $this->notesTab($batch, $data),
            'deferred' => $this->deferredTab($request, $batch, $data),
            default => $this->sheetsTab($batch, $data),
        };

        return view($partial !== '' ? 'sheet-manager.batch.tab-'.$tab : 'sheet-manager.batch', $data);
    }

    // =====================================================================
    // ما تحتاجه الصفحة كلها (الترويسة، البطاقات، شريط الانتباه، شارات التبويبات)
    // =====================================================================

    private function common(Request $request, ImportBatch $batch): array
    {
        $date = $batch->distribution_date?->toDateString();
        $sheets = $this->dispatch->batchSheets($batch);
        $preparing = $sheets->where('status', 'preparing')->values();
        $batchBlockers = $preparing->isNotEmpty() ? $this->dispatch->blockers($preparing) : [];
        $balanceImport = BalanceImport::where('batch_id', $batch->id)->latest('id')->first();
        $hasItems = (bool) $batch->stat('items_version');

        // عدّادات العناصر: استعلام تجميعي واحد
        $rows = $hasItems
            ? ImportBatchItem::where('batch_id', $batch->id)->selectRaw('kind, status, level, COUNT(*) as c')->groupBy('kind', 'status', 'level')->get()
            : collect();
        $counts = ['kinds' => [], 'pending' => 0, 'blocked' => 0, 'attention' => 0, 'quiet' => 0, 'total' => 0, 'notes' => ['critical' => 0, 'warning' => 0, 'info' => 0], 'pendingByKind' => []];
        foreach ($rows as $row) {
            $c = (int) $row->c;
            if ($row->kind === ImportBatchItem::NOTE) {
                $counts['notes'][$row->level === 'critical' ? 'critical' : ($row->level === 'warning' ? 'warning' : 'info')] += $c;

                continue;
            }
            $counts['total'] += $c;
            $counts['kinds'][$row->kind] = ($counts['kinds'][$row->kind] ?? 0) + $c;
            if ($row->status === ImportBatchItem::PENDING) {
                $counts['pending'] += $c;
                $counts['pendingByKind'][$row->kind] = ($counts['pendingByKind'][$row->kind] ?? 0) + $c;
            }
            $counts['blocked'] += $row->status === ImportBatchItem::BLOCKED ? $c : 0;
            if (self::needsAttention($row->kind, $row->status, $row->level)) {
                $counts['attention'] += $c;
            }
            if (in_array($row->kind, [ImportBatchItem::UNCHANGED, ImportBatchItem::CLOSED_UNCHANGED], true) && $row->status === ImportBatchItem::INFO && $row->level !== ImportBatchItem::DUPLICATE) {
                $counts['quiet'] += $c;
            }
        }
        $counts['createdInfo'] = (int) $rows->where('kind', ImportBatchItem::CREATED)->where('status', ImportBatchItem::INFO)->sum('c');
        // المرحلة 7E2: «تغيّر وصفي فقط» المعلّقة (تُطبَّق دفعة واحدة)
        $counts['descriptive'] = $counts['pending'] > 0 ? ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()->count() : 0;

        // المرحلة 7G: ملاحظات غياب الأعمدة الحرجة (استعلام واحد، وعند وجود ملاحظة حرجة فقط)
        $columnNotes = $counts['notes']['critical'] > 0
            ? ImportBatchItem::where('batch_id', $batch->id)->where('kind', ImportBatchItem::NOTE)->where('level', 'critical')
                ->whereIn('new->code', WorkbookImporter::columnNoteCodes())->orderBy('id')->get()
            : collect();
        $costCenterNote = $columnNotes->first(fn ($n) => ($n->new['code'] ?? null) === WorkbookImporter::COST_CENTER_COLUMN_MISSING);
        // صُحّحت بإعادة رفع بالعمود وتطبيق: يبقى في الملاحظات تاريخاً، ولا تحذير ولا تأكيد إرسال
        if ($costCenterNote && ! self::costCenterMissing($batch)) {
            // المرحلة 7G: يبقى في مربع الأعمدة ما دامت فواتير الدفعة نفسها بلا مركز كلفة (ولو كانت كلها «بلا كشف»)؛ تأكيد الإرسال بقاعدة 7E
            if (! Invoice::where('last_seen_batch_id', $batch->id)->whereNull('cost_center_code')->exists()) {
                $columnNotes = $columnNotes->reject(fn ($n) => $n->is($costCenterNote));
            }
            $costCenterNote = null;
        }
        $missingColumns = $this->missingColumns($columnNotes);

        // لقطات الأرصدة: استعلام تجميعي واحد
        $snap = ['total' => 0, 'flagged' => 0, 'mismatch' => 0, 'app' => 0, 'external' => 0, 'created' => 0, 'zeroed' => 0, 'changed' => 0];
        if ($balanceImport?->isApplied()) {
            $snapRows = CustomerBalanceSnapshot::where('balance_import_id', $balanceImport->id)
                ->selectRaw('kind, status, scope, exported_mismatch, CASE WHEN reviewed_at IS NULL THEN 1 ELSE 0 END as unreviewed, COUNT(*) as c')
                ->groupBy('kind', 'status', 'scope', 'exported_mismatch', 'unreviewed')->get();
            foreach ($snapRows as $row) {
                $c = (int) $row->c;
                $snap['total'] += $c;
                $snap['flagged'] += $row->status === CustomerBalanceSnapshot::FLAGGED ? $c : 0;
                $snap['mismatch'] += ($row->exported_mismatch && $row->unreviewed) ? $c : 0;
                // «مرتبط/خارجي» لكل لقطة غير جديدة (كما في «تغيّرات الأرصدة»)؛ «جدد» و«مصفَّرون» و«تغيّر» تفصيل بالنوع
                $snap[$row->kind] = ($snap[$row->kind] ?? 0) + $c;
                if ($row->kind !== 'created') {
                    $row->scope === SnapshotScope::EXTERNAL ? $snap['external'] += $c : $snap['app'] += $c;
                }
            }
        }

        $warningGroups = BatchWarnings::groups($batch);
        $deferred = $this->deferredSummary($batch);
        $unassignedCount = $this->unassignedQuery($batch)->count();
        $missingDeferredCount = $this->missingDeferredQuery($batch, $date)?->count() ?? 0;
        $newCustomersCount = Customer::whereIn('id', (array) $batch->stat('new_customer_ids', []))->where('needs_review', true)->count();

        $attention = $this->attention($batch, $counts, $missingColumns, $balanceImport, $snap, $unassignedCount, $missingDeferredCount, $newCustomersCount, $warningGroups);

        $badges = [
            'sheets' => $unassignedCount,
            'invoices' => $counts['attention'],
            'balances' => ($balanceImport?->isHeld() ? 1 : 0) + $snap['flagged'],
            'notes' => $counts['notes']['critical'] + $counts['notes']['warning'] + collect($warningGroups)->where('tab', null)->count(),
            'deferred' => $missingDeferredCount,
        ];

        // المرحلة 7F: فواتير هذا الملف المستبعدة سابقاً (معلومات، مع خيار إعادة إدراجها جماعياً)
        $previouslyExcluded = $hasItems && ! $batch->isRolledBack()
            ? app(\App\Services\BulkInvoiceActionService::class)->query('batch_excluded', ['batch' => $batch->id])->count() : 0;

        // المرحلة 7G: الملف نفسه رُفع في دفعة سابقة (معلومات)
        $duplicate = DuplicateUploadNotice::for($batch->file_hash, (string) $date, $batch);

        // المرحلة 7H: ملاحق أُنشئت في الدفعة، وإشعارات بانتظار اطلاع الموزع على الكشوف المُرسلة التي مسّتها الدفعة
        $supplementSheets = DistributionSheet::whereIn('id', collect((array) $batch->stat('supplements', []))->pluck('sheet_id')->all() ?: [0])
            ->with('parent')->orderBy('id')->get();
        $ackSheets = DistributionSheet::whereIn('id', collect((array) $batch->stat('touched_sheet_ids', []))
            ->merge(ImportBatchItem::where('batch_id', $batch->id)->whereNotNull('target_sheet_id')->distinct()->pluck('target_sheet_id'))
            ->merge(ImportBatchItem::where('batch_id', $batch->id)->whereNotNull('sheet_id')->distinct()->pluck('sheet_id'))
            ->unique()->values()->all() ?: [0])->whereIn('status', \App\Services\SheetChangeNotifier::NOTIFY_STATUSES)->get();
        $ackPending = collect(app(\App\Services\NoticeAckService::class)->unackedCounts($ackSheets))->filter()
            ->map(fn ($count, $id) => ['sheet' => $ackSheets->firstWhere('id', $id), 'count' => $count])->values();

        return compact('batch', 'sheets', 'preparing', 'batchBlockers', 'balanceImport', 'hasItems', 'counts', 'costCenterNote', 'snap',
            'warningGroups', 'deferred', 'unassignedCount', 'missingDeferredCount', 'newCustomersCount', 'attention', 'badges', 'previouslyExcluded',
            'missingColumns', 'duplicate', 'supplementSheets', 'ackPending') + [
                'canDecide' => app(ImportReviewService::class)->canDecide($request->user()),
            ];
    }

    /**
     * «يحتاج انتباهاً» في تبويب الفواتير: معلّق أو محجوز، أو كشف مكتمل بقيمة مختلفة، أو غائبة، أو بملاحظة تحذيرية.
     */
    public static function needsAttention(string $kind, string $status, ?string $level): bool
    {
        return in_array($status, [ImportBatchItem::PENDING, ImportBatchItem::BLOCKED], true)
            || in_array($kind, [ImportBatchItem::CLOSED_CHANGED, ImportBatchItem::MISSING], true)
            || $level === 'warning';
    }

    private function attentionScope($query)
    {
        return $query->where(fn ($q) => $q->whereIn('status', [ImportBatchItem::PENDING, ImportBatchItem::BLOCKED])
            ->orWhereIn('kind', [ImportBatchItem::CLOSED_CHANGED, ImportBatchItem::MISSING])
            ->orWhere('level', 'warning'));
    }

    /**
     * صفوف شريط «يحتاج انتباهك» مرتبة بالخطورة: critical (أحمر)، decision (برتقالي)، info (أزرق).
     *
     * @return array<int, array{level: string, icon: string, text: string, count: ?int, url: string}>
     */
    /**
     * المرحلة 7G: الأعمدة الحرجة الغائبة لمربع واحد (ملفات الدفعة المتعددة تُدمج لكل عمود).
     *
     * @return array{columns: array<int, array>, present: array<string, array<int, string>>}
     */
    private function missingColumns(Collection $notes): array
    {
        $columns = [];
        $present = [];
        foreach ($notes as $note) {
            $code = $note->new['code'];
            $meta = (array) ($note->new['meta'] ?? []);
            $columns[$code] ??= ['code' => $code, 'column' => $meta['column'] ?? 'مركز الكلفة', 'text' => $note->reason, 'accepted' => $meta['accepted'] ?? [], 'count' => 0];
            $columns[$code]['count'] += (int) ($note->new['count'] ?? 0);
            if (isset($meta['present'])) {
                $present[(string) ($note->new['file'] ?? '')] = $meta['present'];
            }
        }

        return ['columns' => array_values($columns), 'present' => $present];
    }

    private function attention(ImportBatch $batch, array $counts, array $missingColumns, ?BalanceImport $balanceImport, array $snap,
        int $unassigned, int $missingDeferred, int $newCustomers, array $warningGroups): array
    {
        $url = fn (array $q) => route('sheet-manager.batches.show', ['batch' => $batch] + $q);
        $rows = [];

        // المرحلة 7G: صفّ حرج واحد لكل الأعمدة الغائبة («مركز الكلفة» وحده بنصه من 7E كما هو)
        if ($cols = $missingColumns['columns']) {
            $rows[] = ['level' => 'critical', 'icon' => count($cols) === 1 && $cols[0]['code'] === WorkbookImporter::COST_CENTER_COLUMN_MISSING ? 'fa-user-slash' : 'fa-table-columns',
                'text' => count($cols) === 1 ? $cols[0]['text'] : 'أعمدة حرجة غير موجودة في ملف الدفعة: '.implode('، ', array_map(fn ($c) => '«'.$c['column'].'»', $cols)),
                'count' => max(array_column($cols, 'count')), 'url' => $url(['tab' => 'notes'])];
        }
        if ($balanceImport?->isHeld()) {
            $rows[] = ['level' => 'critical', 'icon' => 'fa-scale-unbalanced', 'text' => 'ملف الأرصدة محجوز ولم يُطبَّق: '.$balanceImport->hold_message,
                'count' => null, 'url' => $url(['tab' => 'balances'])];
        }
        if ($snap['mismatch'] > 0) {
            $rows[] = ['level' => 'critical', 'icon' => 'fa-money-bill-transfer', 'text' => 'دفعات مصدَّرة لم تنعكس في ملف الأرصدة', 'count' => $snap['mismatch'], 'url' => $url(['tab' => 'balances'])];
        }
        if ($counts['pending'] > 0) {
            $rows[] = ['level' => 'decision', 'icon' => 'fa-list-check', 'text' => 'فواتير في «مراجعة الفروق» بانتظار قرارك', 'count' => $counts['pending'],
                'url' => $url(['tab' => 'invoices', 'status' => ImportBatchItem::PENDING])];
        }
        if ($counts['descriptive'] > 0) {
            $rows[] = ['level' => 'decision', 'icon' => 'fa-tags', 'text' => 'فواتير تغيّرت فيها حقول وصفية فقط (مثل مركز الكلفة) والمبلغ والأصناف كما هي: يمكن تطبيقها دفعة واحدة', 'count' => $counts['descriptive'],
                'url' => $url(['tab' => 'invoices'])];
        }
        if ($counts['blocked'] > 0) {
            $rows[] = ['level' => 'decision', 'icon' => 'fa-hand', 'text' => 'عناصر محجوزة (نشاط على الصيدلية أو كشف مكتمل)', 'count' => $counts['blocked'],
                'url' => $url(['tab' => 'invoices', 'status' => ImportBatchItem::BLOCKED])];
        }
        if ($unassigned > 0) {
            $rows[] = ['level' => 'decision', 'icon' => 'fa-inbox', 'text' => 'فواتير بلا كشف تحتاج وضعها في كشف أو استبعادها', 'count' => $unassigned, 'url' => $url(['tab' => 'sheets']).'#unassigned'];
        }
        if ($missingDeferred > 0) {
            $rows[] = ['level' => 'decision', 'icon' => 'fa-ghost', 'text' => 'مؤجلة سابقة غير موجودة في هذا الملف', 'count' => $missingDeferred, 'url' => $url(['tab' => 'deferred'])];
        }
        if ($snap['flagged'] - $snap['mismatch'] > 0) {
            $rows[] = ['level' => 'decision', 'icon' => 'fa-magnifying-glass-dollar', 'text' => 'تغيّرات أرصدة مرتبطة بحركة التطبيق تحتاج تحققاً', 'count' => $snap['flagged'] - $snap['mismatch'],
                'url' => $url(['tab' => 'balances'])];
        }
        if ($newCustomers > 0) {
            $rows[] = ['level' => 'decision', 'icon' => 'fa-user-plus', 'text' => 'زبائن جدد بحاجة مراجعة', 'count' => $newCustomers, 'url' => route('customers.review')];
        }
        $notes = $counts['notes']['warning'] + $counts['notes']['info'] + collect($warningGroups)->where('tab', null)->count();
        if ($notes > 0) {
            $rows[] = ['level' => 'info', 'icon' => 'fa-circle-info', 'text' => 'ملاحظات المستورد وتحذيرات الملف', 'count' => $notes, 'url' => $url(['tab' => 'notes'])];
        }

        return $rows;
    }

    // =====================================================================
    // التبويبات
    // =====================================================================

    private function sheetsTab(ImportBatch $batch, array $common): array
    {
        $date = $batch->distribution_date?->toDateString();
        $sheets = $common['sheets'];
        $this->attachSheetFigures($sheets);

        // فواتير جديدة/معدّلة لكل كشف من هذه الدفعة
        $changes = [];
        if ($common['hasItems'] && $sheets->isNotEmpty()) {
            ImportBatchItem::where('batch_id', $batch->id)->whereIn('kind', array_merge([ImportBatchItem::CREATED], ImportBatchItem::PAYLOAD_KINDS))
                ->where(fn ($q) => $q->whereIn('sheet_id', $sheets->pluck('id'))->orWhereIn('target_sheet_id', $sheets->pluck('id')))
                ->get(['kind', 'sheet_id', 'target_sheet_id', 'status'])
                ->each(function ($item) use (&$changes) {
                    $sheetId = $item->kind === ImportBatchItem::CREATED && $item->status === ImportBatchItem::PENDING ? $item->target_sheet_id : ($item->sheet_id ?? $item->target_sheet_id);
                    $key = $item->kind === ImportBatchItem::CREATED ? 'new' : 'changed';
                    $changes[$sheetId][$key] = ($changes[$sheetId][$key] ?? 0) + 1;
                });
        }

        // بترقيم: كل صف يحمل نموذج الوضع في كشف بقائمة المناطق، فلا تُعرض كلها دفعة واحدة
        $unassigned = $common['unassignedCount'] > 0
            ? $this->unassignedQuery($batch)->orderBy('customer_name')->paginate(20, ['*'], 'upage')->withQueryString()->appends('partial', null)
            : collect();
        $legacy = ! $common['hasItems'];

        return [
            'sheetChanges' => $changes,
            'conflicts' => $date ? $this->dispatch->conflicts($date, $sheets->pluck('id')->all()) : [],
            'unassigned' => $unassigned,
            'targetSheets' => $unassigned->isNotEmpty() ? $this->targetSheets($date) : collect(),
            'areaOptions' => $unassigned->isNotEmpty() ? $this->areaOptions() : [],
            'proposals' => $legacy ? InvoiceChangeProposal::with('invoice.distributionSheet', 'invoice.lines')->where('batch_id', $batch->id)
                ->where('status', InvoiceChangeProposal::PENDING)->get() : collect(),
        ];
    }

    private function invoicesTab(Request $request, ImportBatch $batch, ImportReviewService $review, array $common): array
    {
        if (! $common['hasItems']) {
            return ['items' => null, 'groups' => null, 'blockers' => [], 'printed' => [], 'printedPending' => 0, 'filters' => ['kind' => '', 'status' => '', 'q' => ''], 'descriptive' => null];
        }
        $user = $request->user();
        $base = ImportBatchItem::where('batch_id', $batch->id)->invoices();
        $kind = (string) $request->query('kind', '');
        $status = (string) $request->query('status', '');
        $term = trim((string) $request->query('q', ''));

        // عرض مجمَّع حسب الموقع: نوع للعلم كثير العدد، بلا بحث ولا مرشّح حالة
        $groups = null;
        if (in_array($kind, self::QUIET_KINDS, true) && $term === '' && $status === ''
            && (clone $base)->where('kind', $kind)->count() > self::GROUP_THRESHOLD) {
            $groups = (clone $base)->where('kind', $kind)->selectRaw('sheet_id, sheet_label, status, COUNT(*) as c')
                ->groupBy('sheet_id', 'sheet_label', 'status')->orderBy('sheet_label')->get()
                ->groupBy(fn ($g) => $g->sheet_id ? 'sheet:'.$g->sheet_id : 'label:'.$g->sheet_label)
                ->map(fn ($rows, $key) => ['key' => $key, 'label' => $rows->first()->sheet_label, 'sheet_id' => $rows->first()->sheet_id,
                    'count' => (int) $rows->sum('c'), 'statuses' => $rows->pluck('c', 'status')->map(fn ($c) => (int) $c)->all()])
                ->sortByDesc('count')->values();
        }

        $items = null;
        $blockers = [];
        $printed = [];
        if ($groups === null) {
            $items = (clone $base)
                ->with(['sheet', 'targetSheet', 'proposal', 'invoice.distributionSheet', 'supersededBy', 'decider'])
                ->when($kind === '' && $term === '', fn ($q) => $this->attentionScope($q)->whereNot(fn ($w) => $w->descriptivePending()))
                ->when($kind === self::DESCRIPTIVE, fn ($q) => $q->descriptivePending())
                ->when($kind !== '' && $kind !== 'all' && $kind !== self::DESCRIPTIVE, fn ($q) => $q->where('kind', $kind))
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$term}%")->orWhere('customer_name', 'like', "%{$term}%")))
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'blocked' THEN 1 ELSE 2 END")
                ->orderBy('id')
                ->paginate(50)->withQueryString()->appends('partial', null);

            foreach ($items as $item) {
                if ($item->isOpen()) {
                    $blockers[$item->id] = $review->blocker($item, $user);
                }
                if ($item->invoice && in_array($item->kind, ImportBatchItem::PAYLOAD_KINDS, true) && $item->isOpen()) {
                    $printed[$item->id] = PrintEvent::invoicePrinted($item->invoice);
                }
            }
        }

        return [
            'items' => $items,
            'groups' => $groups,
            'blockers' => $blockers,
            'printed' => $printed,
            'printedPending' => $common['counts']['pending'] > 0
                ? (clone $base)->where('status', ImportBatchItem::PENDING)->whereIn('kind', ImportBatchItem::PAYLOAD_KINDS)->with('invoice')->get()
                    ->filter(fn ($i) => $i->invoice && PrintEvent::invoicePrinted($i->invoice))->count()
                : 0,
            'filters' => ['kind' => $kind, 'status' => $status, 'q' => $term],
            'descriptive' => $common['counts']['descriptive'] > 0 ? $this->descriptiveSummary($batch) : null,
        ];
    }

    /**
     * المرحلة 7E2: ملخص مجموعة «تغيّر وصفي فقط»: العدد، الحقول، عنوان يقول ما تغيّر، ومثال.
     *
     * @return array{count: int, fields: array<string, int>, title: string, example: ?ImportBatchItem}
     */
    private function descriptiveSummary(ImportBatch $batch): array
    {
        $wrap = fn (string $c) => DB::getQueryGrammar()->wrap($c);
        $rows = ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()
            ->selectRaw($wrap('new->descriptive').' as d, '.$wrap('old->cost_center').' as occ')->get();
        $fields = [];
        $fromNone = 0;
        foreach ($rows as $row) {
            foreach ((array) json_decode((string) $row->d, true) as $field) {
                $fields[$field] = ($fields[$field] ?? 0) + 1;
            }
            $fromNone += in_array($row->occ, [null, '', 'null'], true) ? 1 : 0;
        }
        $count = $rows->count();
        $labels = collect(array_keys($fields))->map(fn ($f) => ImportBatchItem::DESCRIPTIVE_LABELS[$f] ?? $f)->implode(' و');
        $title = number_format($count).' فاتورة تغيّر فيها '.$labels.' فقط'
            .(array_keys($fields) === ['cost_center'] && $fromNone === $count ? ' (من «بلا» إلى كود المندوب)' : '');

        return ['count' => $count, 'fields' => $fields, 'title' => $title,
            'example' => ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()->orderBy('id')->first()];
    }

    private function balancesTab(ImportBatch $batch, array $common): array
    {
        $bi = $common['balanceImport'];
        if (! $bi || ! $bi->isApplied()) {
            return ['mismatch' => collect(), 'zeroed' => collect(), 'balanceNotes' => collect()];
        }

        return [
            'mismatch' => CustomerBalanceSnapshot::with('customer:id,name')->where('balance_import_id', $bi->id)
                ->where('exported_mismatch', true)->whereNull('reviewed_at')->orderBy('id')->get(),
            'zeroed' => CustomerBalanceSnapshot::with('customer:id,name')->where('balance_import_id', $bi->id)->where('kind', 'zeroed')->orderBy('id')->limit(20)->get(),
            'balanceNotes' => $common['hasItems'] ? $this->notes($batch)->filter(fn ($n) => in_array($n->new['code'] ?? null, self::BALANCE_NOTE_CODES, true))->values() : collect(),
        ];
    }

    private function notesTab(ImportBatch $batch, array $common): array
    {
        $notes = $common['hasItems'] ? $this->notes($batch)->reject(fn ($n) => in_array($n->new['code'] ?? null, self::BALANCE_NOTE_CODES, true)) : collect();

        // نوع الملاحظة صفّ واحد (ملفات الدفعة المتعددة تُدمج)
        $grouped = $notes->groupBy(fn ($n) => $n->new['code'] ?? 'note_'.$n->id)->map(function (Collection $group, $code) {
            $examples = $group->flatMap(fn ($n) => (array) ($n->new['examples'] ?? []))->values();
            $level = $group->contains('level', 'critical') ? 'critical' : ($group->contains('level', 'warning') ? 'warning' : 'info');

            return ['code' => (string) $code, 'level' => $level, 'text' => $group->first()->reason, 'count' => $examples->count(),
                'examples' => $examples->take(5)->all(), 'files' => $group->pluck('new.file')->filter()->unique()->values()->all()];
        })->sortBy(fn ($n) => ['critical' => 0, 'warning' => 1, 'info' => 2][$n['level']])->values();

        return [
            'noteRows' => $grouped,
            'newCustomers' => Customer::whereIn('id', (array) $batch->stat('new_customer_ids', []))->where('needs_review', true)->orderBy('name')->limit(5)->get(['id', 'name']),
        ];
    }

    private function deferredTab(Request $request, ImportBatch $batch, array $common): array
    {
        $date = $batch->distribution_date?->toDateString();
        $term = trim((string) $request->query('dq', ''));
        $search = fn ($q) => $q->when($term !== '', fn ($w) => $w->where(fn ($x) => $x->where('invoice_number', 'like', "%{$term}%")->orWhere('customer_name', 'like', "%{$term}%")));

        $rows = $common['hasItems']
            ? $this->deferredItemsQuery($batch)->tap($search)->orderBy('id')->paginate(50, ['*'], 'dpage')->withQueryString()->appends('partial', null)
            : Invoice::where('placement', Invoice::DEFERRED)->where('last_seen_batch_id', $batch->id)->withCount('lines')->tap($search)
                ->orderBy('delivery_date')->orderBy('customer_name')->paginate(50, ['*'], 'dpage')->withQueryString()->appends('partial', null);

        return [
            'deferredRows' => $rows,
            'missingDeferred' => $common['missingDeferredCount'] > 0 ? $this->missingDeferredQuery($batch, $date)->orderBy('delivery_date')->get() : collect(),
            'deferredTerm' => $term,
        ];
    }

    // =====================================================================
    // الأجزاء الكسولة
    // =====================================================================

    /**
     * فواتير موقع واحد من نوع للعلم (توسيع صف المجموعة)، بترقيم.
     */
    private function groupItems(Request $request, ImportBatch $batch)
    {
        if ($request->query('kind') === self::DESCRIPTIVE) {
            $items = ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()->with('sheet')->orderBy('id')->paginate(50)->withQueryString();

            return view('sheet-manager.batch.group-items', compact('batch', 'items'));
        }
        $kind = in_array($request->query('kind'), self::QUIET_KINDS, true) ? $request->query('kind') : ImportBatchItem::CREATED;
        [$type, $value] = array_pad(explode(':', (string) $request->query('loc', ''), 2), 2, '');
        $items = ImportBatchItem::where('batch_id', $batch->id)->where('kind', $kind)
            ->when($type === 'sheet', fn ($q) => $q->where('sheet_id', (int) $value), fn ($q) => $q->whereNull('sheet_id')->where('sheet_label', $value))
            ->with('sheet')->orderBy('id')->paginate(50)->withQueryString();

        return view('sheet-manager.batch.group-items', compact('batch', 'items'));
    }

    /**
     * كل أمثلة نوع ملاحظة (من القاعدة)، بترقيم وبحث.
     */
    private function noteList(Request $request, ImportBatch $batch)
    {
        $code = (string) $request->query('code');
        $notes = $this->notes($batch)->filter(fn ($n) => ($n->new['code'] ?? 'note_'.$n->id) === $code);
        $rows = $notes->flatMap(fn ($n) => (array) ($n->new['examples'] ?? []))
            ->map(fn ($example) => self::splitExample((string) $example))->values();

        return $this->listView($request, $batch, $notes->first()?->reason ?? 'ملاحظة', $rows, ['partial' => 'note', 'code' => $code]);
    }

    /**
     * قائمة تحذير مجمَّع (النص الأصلي لكل فاتورة محفوظ في عمود)، بترقيم وبحث.
     */
    private function warningList(Request $request, ImportBatch $batch)
    {
        $group = BatchWarnings::groups($batch)[(string) $request->query('group')] ?? null;

        return $this->listView($request, $batch, $group['title'] ?? 'تحذير', collect($group['rows'] ?? []), ['partial' => 'warnings', 'group' => (string) $request->query('group')]);
    }

    private function listView(Request $request, ImportBatch $batch, string $title, Collection $rows, array $query)
    {
        $term = trim((string) $request->query('lq', ''));
        if ($term !== '') {
            $rows = $rows->filter(fn ($r) => str_contains(implode(' ', $r), $term))->values();
        }
        $page = max(1, (int) $request->query('lpage', 1));
        $paginator = new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, [
            'path' => route('sheet-manager.batches.show', $batch), 'pageName' => 'lpage', 'query' => $query + ['lq' => $term],
        ]);

        return view('sheet-manager.batch.list', ['batch' => $batch, 'title' => $title, 'rows' => $paginator, 'term' => $term, 'query' => $query]);
    }

    /**
     * «G:12: بلا مركز كلفة» ⇒ [«G:12»، «بلا مركز كلفة»، النص كاملاً]
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function splitExample(string $example): array
    {
        $at = mb_strpos($example, ': ');

        return $at === false ? [$example, '', $example] : [mb_substr($example, 0, $at), mb_substr($example, $at + 2), $example];
    }

    // =====================================================================

    /** @return Collection<int, ImportBatchItem> */
    private function notes(ImportBatch $batch): Collection
    {
        return ImportBatchItem::where('batch_id', $batch->id)->where('kind', ImportBatchItem::NOTE)
            ->orderByRaw("CASE level WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->orderBy('id')->get();
    }

    /**
     * المؤجلة في هذه الدفعة (عناصرها «للعلم» بموقع «مؤجلة»): المصدر الواحد لعدد البطاقة والتبويب والملاحظة.
     */
    private function deferredItemsQuery(ImportBatch $batch)
    {
        return ImportBatchItem::where('batch_id', $batch->id)->invoices()
            ->whereNull('sheet_id')->where('sheet_label', Invoice::PLACEMENTS[Invoice::DEFERRED])
            ->where('status', ImportBatchItem::INFO)
            ->where(fn ($q) => $q->whereNull('level')->orWhere('level', '!=', ImportBatchItem::DUPLICATE));
    }

    /**
     * @return array{count: int, total: float, with_order: int, without_order: int, manual: int}
     */
    private function deferredSummary(ImportBatch $batch): array
    {
        if (! $batch->stat('items_version')) {
            return ['count' => (int) $batch->stat('deferred.count', 0), 'total' => (float) $batch->stat('deferred.total', 0), 'with_order' => 0, 'without_order' => 0, 'manual' => 0];
        }
        $summary = ['count' => 0, 'total' => 0.0, 'with_order' => 0, 'without_order' => 0, 'manual' => 0];
        foreach ($this->deferredItemsQuery($batch)->get(['new', 'reason']) as $item) {
            $summary['count']++;
            $summary['total'] += (float) ($item->new['amount'] ?? 0);
            match (true) {
                str_contains((string) $item->reason, 'يدوياً') => $summary['manual']++,
                ($item->new['order'] ?? null) !== null => $summary['with_order']++,
                default => $summary['without_order']++,
            };
        }
        $summary['total'] = round($summary['total'], 2);

        return $summary;
    }

    private function unassignedQuery(ImportBatch $batch)
    {
        // فواتير جديدة تنتظر قرار إضافتها لكشف منشور تُعرض في «مراجعة الفروق» لا في «بلا كشف»
        return Invoice::where('placement', Invoice::UNASSIGNED)
            ->where('last_seen_batch_id', $batch->id)
            ->whereNotIn('id', ImportBatchItem::where('kind', ImportBatchItem::CREATED)->where('status', ImportBatchItem::PENDING)->whereNotNull('invoice_id')->select('invoice_id'));
    }

    /** مؤجلة سابقة لم تظهر في هذه الدفعة (فقط إن احتوت الدفعة صفحة الحركة اليومية) */
    private function missingDeferredQuery(ImportBatch $batch, ?string $date)
    {
        if (! $batch->stat('has_daily') || ! $date) {
            return null;
        }

        return Invoice::where('placement', Invoice::DEFERRED)
            ->whereDate('delivery_date', '>=', $date)
            ->where(fn ($q) => $q->whereNull('last_seen_batch_id')->orWhere('last_seen_batch_id', '!=', $batch->id));
    }
}
