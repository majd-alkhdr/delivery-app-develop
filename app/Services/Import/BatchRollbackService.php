<?php

namespace App\Services\Import;

use App\Models\ActivityLog;
use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\CustomerBalanceSnapshot;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\ImportBatchRollback;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\User;
use App\Services\Balances\BalancePreImage;
use App\Services\SheetActivityGuard;
use App\Services\SheetFiguresService;
use App\Support\BulkWrite;
use App\Support\CompressedJson;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المرحلة 7F: التراجع عن دفعة رفع بالكامل (للأدمن).
 * analyze(): الأهلية (موانع بأسباب عربية وأعداد وأمثلة) والمعاينة بالأرقام الفعلية، بعدد استعلامات ثابت لكل 500 صف.
 * rollback(): التنفيذ في معاملة واحدة (كلها أو لا شيء) بعد إعادة الفحص داخلها.
 *
 * «من هذه الدفعة» بالمفاتيح الفعلية: عناصر import_batch_items (created وأماكن «بلا تغيير» المنقولة)،
 * invoice_revisions.batch_id، invoice_change_proposals.batch_id، stats.created_sheet_ids وstats.new_customer_ids،
 * balance_imports.batch_id ولقطاته customer_balance_snapshots.balance_import_id وإشعاراتها (meta.snapshot_id).
 */
class BatchRollbackService
{
    /** حالات الكشف التي تجعل الفاتورة «في كشف منشور أو قيد التنفيذ أو مكتمل» */
    public const PUBLISHED = ['pending', 'in_progress', 'completed'];

    private const RELOCATED_PREFIX = 'لم يتغيّر محتواها، وتغيّر موقعها';

    private const EXAMPLES = 5;

    public function __construct(private SheetFiguresService $figures, private SheetActivityGuard $guard)
    {
    }

    // =====================================================================
    // الأهلية والمعاينة
    // =====================================================================

    /**
     * @return array{eligible: bool, blockers: array<int, array>, counts: array<string, int>, keeps: array<int, array>, notes: array<int, string>, plan: array}
     */
    public function analyze(ImportBatch $batch): array
    {
        $blockers = [];
        $keeps = [];
        $notes = [];
        $block = function (string $code, string $text, int $count = 0, array $examples = []) use (&$blockers) {
            $blockers[] = ['code' => $code, 'text' => $text, 'count' => $count, 'examples' => array_slice(array_values(array_unique($examples)), 0, self::EXAMPLES)];
        };

        if ($batch->isRolledBack()) {
            $block('rolled_back', 'تم التراجع عن هذه الدفعة سابقاً.');

            return $this->result($blockers, [], [], [], []);
        }
        if (! $batch->stat('items_version')) {
            $block('legacy', 'دفعة قديمة (قبل المرحلة 7A) بلا عناصر فواتير محفوظة: لا يمكن معرفة ما أنشأته وما عدّلته بدقة، فلا يُتراجع عنها.');

            return $this->result($blockers, [], [], [], []);
        }

        // 1) دفعة أحدث مطبَّقة (الترتيب: وقت الرفع، ثم أول عنصر للدفعة — تصاعدي — للدفعات في الثانية نفسها)
        $order = fn (string $id) => (int) (ImportBatchItem::where('batch_id', $id)->min('id') ?? PHP_INT_MAX);
        $mine = $order($batch->id);
        $newer = ImportBatch::where('id', '!=', $batch->id)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', ImportBatch::ROLLED_BACK))
            ->where('created_at', '>=', $batch->created_at)
            ->orderByDesc('created_at')->get(['id', 'file_names', 'distribution_date', 'created_at'])
            ->filter(fn ($b) => $b->created_at->gt($batch->created_at) || $order($b->id) > $mine)->values();
        if ($newer->isNotEmpty()) {
            $block('newer_batch', 'توجد دفعة أحدث مطبَّقة بعد هذه الدفعة: تراجع عنها أولاً (التراجع بالترتيب العكسي، وإلا تتعارض اللقطات والأرصدة). الأحدث: «'
                .implode('، ', (array) $newer->first()->file_names).'» بتاريخ توزيع '.$newer->first()->distribution_date?->toDateString()
                .' (رُفعت '.$newer->first()->created_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i').').',
                $newer->count(), $newer->map(fn ($b) => implode('، ', (array) $b->file_names).' — '.$b->distribution_date?->toDateString())->all());
        }

        $items = ImportBatchItem::where('batch_id', $batch->id)->where('kind', '!=', ImportBatchItem::NOTE)
            ->orderBy('id')->get(['id', 'kind', 'status', 'invoice_id', 'invoice_number', 'old', 'reason', 'proposal_id']);

        // --- الفواتير ---
        $createdIds = $items->where('kind', ImportBatchItem::CREATED)->pluck('invoice_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $revisions = $this->firstRevisions($batch);
        $restoreIds = array_values(array_diff(array_keys($revisions), $createdIds));
        $relocated = [];
        foreach ($items as $item) {
            $id = (int) $item->invoice_id;
            if ($item->kind !== ImportBatchItem::UNCHANGED || ! $id || ! str_starts_with((string) $item->reason, self::RELOCATED_PREFIX)
                || in_array($id, $createdIds, true) || isset($revisions[$id])) {
                continue;
            }
            $relocated[$id] = $item;
        }

        $touchedIds = array_values(array_unique(array_merge($createdIds, $restoreIds, array_keys($relocated))));
        $invoices = $this->load(Invoice::class, $touchedIds, ['id', 'invoice_number', 'invoice_key', 'customer_id', 'customer_name', 'placement', 'distribution_sheet_id', 'invoice_amount', 'delivery_order_no', 'delivery_date', 'manual_placement', 'last_seen_batch_id']);
        $createdIds = array_values(array_filter($createdIds, fn ($id) => $invoices->has($id)));
        $sheets = DistributionSheet::whereIn('id', $invoices->pluck('distribution_sheet_id')->merge((array) $batch->stat('created_sheet_ids', []))
            ->merge(collect($revisions)->pluck('header.distribution_sheet_id'))->filter()->unique()->values()->all() ?: [0])
            ->get(['id', 'status', 'delivery_order_no', 'batch_id', 'distribution_date', 'format'])->keyBy('id');

        // استعادة الأماكن: من النسخة القديمة (revision) أو من موقع «بلا تغيير» المنقول
        $restores = [];
        foreach ($restoreIds as $id) {
            $restores[$id] = ['type' => 'revision', 'header' => $revisions[$id]['header'], 'lines' => $revisions[$id]['lines'], 'revision_id' => $revisions[$id]['id']];
        }
        $unresolved = [];
        foreach ($relocated as $id => $item) {
            $from = $this->relocatedFrom($item, $sheets);
            if ($from === null) {
                $unresolved[] = $item->invoice_number;

                continue;
            }
            $restores[$id] = ['type' => 'location'] + $from;
        }
        if ($unresolved) {
            $block('unresolved_location', 'فواتير نقلتها الدفعة ولا يمكن معرفة موقعها السابق بدقة (كشف مسودة سابق غير محدد):', count($unresolved), $unresolved);
        }
        $backToPublished = [];
        foreach ($restores as $id => $r) {
            $target = $r['type'] === 'revision' ? ($r['header']['distribution_sheet_id'] ?? null) : ($r['sheet_id'] ?? null);
            $placement = $r['type'] === 'revision' ? ($r['header']['placement'] ?? null) : $r['placement'];
            if ($placement === Invoice::IN_SHEET && $target && in_array($sheets[$target]->status ?? null, self::PUBLISHED, true)) {
                $backToPublished[] = $invoices[$id]->invoice_number ?? '#'.$id;
            }
        }
        if ($backToPublished) {
            $block('restore_published', 'فواتير كان موقعها قبل الدفعة كشفاً منشوراً أو مكتملاً: إعادتها إليه تغيّر كشف الموزع، فلا تُعاد تلقائياً.', count($backToPublished), $backToPublished);
        }

        // 2) نشاط فعلي على فواتير الدفعة
        $this->invoiceActivity($invoices, $sheets, $batch, $block);

        // --- الكشوف المنشأة ---
        $createdSheetIds = array_map('intval', (array) $batch->stat('created_sheet_ids', []));
        $deleteSheetIds = [];
        $remaining = $this->remainingInSheets($createdSheetIds, $createdIds, array_keys($restores));
        $publishedCreated = [];
        foreach ($createdSheetIds as $sheetId) {
            $sheet = $sheets[$sheetId] ?? null;
            if (! $sheet) {
                continue;
            }
            if (! in_array($sheet->status, ['preparing', 'draft'], true)) {
                $publishedCreated[] = $sheet->shortLabel().' ('.$sheet->status.')';

                continue;
            }
            if (($remaining[$sheetId] ?? 0) > 0) {
                $keeps[] = ['text' => 'كشف '.$sheet->shortLabel().' أنشأته الدفعة وفيه فواتير من خارجها: يبقى (تُحذف منه فواتير الدفعة فقط).', 'count' => (int) $remaining[$sheetId], 'examples' => []];

                continue;
            }
            $deleteSheetIds[] = $sheetId;
        }
        if ($publishedCreated) {
            $block('sheet_published', 'كشوف أنشأتها الدفعة أُرسلت للتوزيع أو أُغلقت:', count($publishedCreated), $publishedCreated);
        }
        $sheetRefs = $this->sheetReferences($deleteSheetIds);
        if ($sheetRefs) {
            $block('sheet_activity', 'كشوف أنشأتها الدفعة عليها سجلات (دفعات، طباعة، تصدير مالي، إشعارات):', count($sheetRefs), $sheetRefs);
        }

        // --- الأرصدة ---
        $balanceImports = BalanceImport::where('batch_id', $batch->id)->get();
        $applied = $balanceImports->firstWhere('status', BalanceImport::APPLIED);
        $snapshotIds = $applied ? CustomerBalanceSnapshot::where('balance_import_id', $applied->id)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        $preImage = $applied ? BalancePreImage::load($applied->id) : null;
        if ($applied) {
            $this->snapshotActivity($snapshotIds, $block);
            if ($preImage === null) {
                $notes[] = 'ملف الأرصدة طُبِّق قبل المرحلة 7F (بلا صورة أرصدة سابقة محفوظة): يعود رصيد كل زبون تغيّر رصيده وتاريخه من لقطته (دقيق)، '
                    .'ويعود تاريخ الرصيد لمن لم يتغيّر رصيده إلى تاريخ ملف الأرصدة السابق؛ أما «الكتلة» و«المنطقة» إن غيّرها الملف فلا تُستعاد.';
            }
        }

        // --- الزبائن المُنشَؤون ---
        $createdCustomers = array_values(array_unique(array_merge(
            array_map('intval', (array) $batch->stat('new_customer_ids', [])),
            $applied ? CustomerBalanceSnapshot::where('balance_import_id', $applied->id)->where('kind', 'created')->pluck('customer_id')->map(fn ($id) => (int) $id)->all() : [],
        )));
        [$deleteCustomers, $keepCustomers, $customerBlock] = $this->customerFates($createdCustomers, $createdIds, $batch->id);
        foreach ($customerBlock as $text => $names) {
            $block('customer_activity', $text, count($names), $names);
        }
        foreach ($keepCustomers as $text => $names) {
            $keeps[] = ['text' => $text, 'count' => count($names), 'examples' => array_slice($names, 0, self::EXAMPLES)];
        }

        // آخر دفعة «رأت» الفواتير التي ليست من إنشاء الدفعة
        $seenIds = Invoice::where('last_seen_batch_id', $batch->id)->whereNotIn('id', $createdIds ?: [0])->pluck('id')->map(fn ($id) => (int) $id)->all();

        $proposalIds = InvoiceChangeProposal::where('batch_id', $batch->id)->pluck('id')->all();
        $supersededItems = ImportBatchItem::whereIn('superseded_by_id', $items->pluck('id')->all() ?: [0])->count();

        $created = $invoices->only($createdIds);
        $balanceRestore = $applied ? $this->balanceRestorePlan($applied, $preImage, $snapshotIds, $deleteCustomers) : [];
        $notices = $snapshotIds ? $this->balanceNoticeIds($snapshotIds, $batch) : [];

        $counts = [
            'invoices_deleted' => count($createdIds),
            'invoices_deleted_unassigned' => $created->where('placement', Invoice::UNASSIGNED)->count(),
            'invoices_deleted_in_sheets' => $created->where('placement', Invoice::IN_SHEET)->count(),
            'invoices_deleted_other' => $created->whereNotIn('placement', [Invoice::UNASSIGNED, Invoice::IN_SHEET, Invoice::DEFERRED])->count(),
            'deferred_removed' => $created->where('placement', Invoice::DEFERRED)->count(),
            'invoices_restored' => count($restores),
            'invoices_restored_revision' => count($restoreIds),
            'invoices_restored_location' => count($restores) - count($restoreIds),
            'last_seen_reset' => count($seenIds),
            'sheets_deleted' => count($deleteSheetIds),
            'customers_deleted' => count($deleteCustomers),
            'customers_kept' => count($createdCustomers) - count($deleteCustomers),
            'customers_balance_restored' => count($balanceRestore),
            'snapshots_deleted' => count($snapshotIds),
            'notices_deleted' => count($notices),
            'proposals_deleted' => count($proposalIds),
            'items_reopened' => $supersededItems,
            'balance_imports' => $balanceImports->count(),
        ];

        $plan = compact('createdIds', 'restores', 'seenIds', 'deleteSheetIds', 'deleteCustomers', 'snapshotIds', 'notices', 'proposalIds', 'balanceRestore') + [
            'balanceImportIds' => $balanceImports->pluck('id')->all(),
            'appliedImportId' => $applied?->id,
            'itemIds' => $items->pluck('id')->all(),
            'affectedSheets' => $this->affectedSheets($invoices, $restores, $deleteSheetIds),
            'invoices' => $invoices,
        ];

        return $this->result($blockers, $counts, $keeps, $notes, $plan);
    }

    private function result(array $blockers, array $counts, array $keeps, array $notes, array $plan): array
    {
        return ['eligible' => $blockers === [], 'blockers' => $blockers, 'counts' => $counts, 'keeps' => $keeps, 'notes' => $notes, 'plan' => $plan];
    }

    // =====================================================================
    // التنفيذ
    // =====================================================================

    /**
     * @return array{rollback: ImportBatchRollback, counts: array}
     */
    public function rollback(ImportBatch $batch, User $user, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'سبب التراجع إلزامي (3 أحرف على الأقل).']);
        }

        return DB::transaction(function () use ($batch, $user, $reason) {
            $batch = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $analysis = $this->analyze($batch);
            if (! $analysis['eligible']) {
                throw ValidationException::withMessages(['rollback' => array_map(fn ($b) => $b['text'].($b['count'] ? " ({$b['count']})" : ''), $analysis['blockers'])]);
            }
            $plan = $analysis['plan'];
            $audit = ['batch_id' => $batch->id, 'file_names' => $batch->file_names];

            $audit['restored'] = $this->restoreInvoices($plan['restores'], $plan['invoices']);
            $this->resetLastSeen($plan['seenIds'], $batch);
            $this->reopenSuperseded($plan['itemIds']);
            foreach (array_chunk($plan['proposalIds'], BulkWrite::CHUNK) as $chunk) {
                InvoiceChangeProposal::whereIn('id', $chunk)->delete();
            }
            $audit['deleted_invoices'] = $this->deleteInvoices($plan['createdIds'], $plan['invoices']);
            $audit['deleted_sheets'] = $this->deleteSheets($plan['deleteSheetIds']);
            $audit['balances'] = $this->restoreBalances($plan, $batch);
            $audit['deleted_customers'] = $this->deleteCustomers($plan['deleteCustomers']);
            DB::table('invoice_revisions')->where('batch_id', $batch->id)->delete();

            foreach ($plan['balanceImportIds'] as $id) {
                BalanceImport::whereKey($id)->update(['status' => BalanceImport::ROLLED_BACK]);
            }
            $batch->update(['status' => ImportBatch::ROLLED_BACK, 'rolled_back_at' => now(), 'rolled_back_by' => $user->id, 'rollback_reason' => $reason]);
            $record = ImportBatchRollback::create(['batch_id' => $batch->id, 'user_id' => $user->id, 'reason' => $reason,
                'counts' => $analysis['counts'], 'audit' => CompressedJson::encode($audit)]);

            // أرقام الكشوف المتأثرة مرة واحدة لكل كشف
            $this->figures->refreshMany(array_values(array_diff($plan['affectedSheets'], $plan['deleteSheetIds'])));

            ActivityLog::record('import.rolled_back', null, ['status' => ImportBatch::APPLIED], ['status' => ImportBatch::ROLLED_BACK] + $analysis['counts'], [
                'batch_id' => $batch->id, 'file_names' => $batch->file_names, 'distribution_date' => $batch->distribution_date?->toDateString(),
                'reason' => $reason, 'rollback_id' => $record->id,
            ]);

            return ['rollback' => $record, 'counts' => $analysis['counts']];
        });
    }

    // =====================================================================
    // تفاصيل الأهلية
    // =====================================================================

    /**
     * أول نسخة قديمة كتبتها الدفعة لكل فاتورة (حالتها قبل الدفعة).
     *
     * @return array<int, array{id: int, header: array, lines: array}>
     */
    private function firstRevisions(ImportBatch $batch): array
    {
        $result = [];
        foreach (DB::table('invoice_revisions')->where('batch_id', $batch->id)->orderBy('invoice_id')->orderBy('revision_no')->get(['id', 'invoice_id', 'header', 'lines']) as $row) {
            $result[(int) $row->invoice_id] ??= ['id' => (int) $row->id, 'header' => json_decode($row->header, true), 'lines' => json_decode($row->lines, true)];
        }

        return $result;
    }

    /**
     * موقع فاتورة «بلا تغيير» قبل أن تنقلها الدفعة: من old.from (منذ 7F)، أو من نص السبب للدفعات الأقدم.
     */
    private function relocatedFrom(ImportBatchItem $item, Collection $sheets): ?array
    {
        $from = $item->old['from'] ?? null;
        if (is_array($from) && isset($from['placement'])) {
            return ['placement' => $from['placement'], 'sheet_id' => $from['sheet_id'] ?? null, 'manual_placement' => (bool) ($from['manual_placement'] ?? false)];
        }
        if (! preg_match('/من «(.+?)» إلى «/u', (string) $item->reason, $m)) {
            return null;
        }
        $label = $m[1];
        $placement = array_search($label, Invoice::PLACEMENTS, true);
        if ($placement !== false && $placement !== Invoice::IN_SHEET) {
            return ['placement' => $placement, 'sheet_id' => null, 'manual_placement' => false];
        }
        // «كشف مسودة (أمر N)» أو «أمر N»: كشف وحيد بهذا الأمر وإلا لا يُعرف
        if (preg_match('/أمر (\S+?)\)?$/u', $label, $o)) {
            $candidates = DistributionSheet::where('delivery_order_no', $o[1])->whereIn('status', ['draft', 'preparing'])->pluck('id');
            if ($candidates->count() === 1) {
                return ['placement' => Invoice::IN_SHEET, 'sheet_id' => (int) $candidates->first(), 'manual_placement' => false];
            }
        }

        return null;
    }

    private function invoiceActivity(Collection $invoices, Collection $sheets, ImportBatch $batch, callable $block): void
    {
        if ($invoices->isEmpty()) {
            return;
        }
        $ids = $invoices->keys()->all();
        $label = fn ($id) => $invoices[$id]->invoice_number ?? '#'.$id;

        $published = $invoices->filter(fn ($i) => $i->placement === Invoice::IN_SHEET && in_array($sheets[$i->distribution_sheet_id]->status ?? null, self::PUBLISHED, true));
        if ($published->isNotEmpty()) {
            $block('in_published_sheet', 'فواتير من الدفعة داخل كشف منشور أو قيد التنفيذ أو مكتمل:', $published->count(), $published->pluck('invoice_number')->all());
        }

        $pairs = $invoices->filter(fn ($i) => $i->placement === Invoice::IN_SHEET && $i->distribution_sheet_id && $i->customer_id)
            ->map(fn ($i) => [(int) $i->distribution_sheet_id, (int) $i->customer_id])->values()->all();
        $active = $pairs ? $this->guard->pairsWithActivity($pairs) : [];
        if ($active) {
            $hit = $invoices->filter(fn ($i) => isset($active[(int) $i->distribution_sheet_id.':'.(int) $i->customer_id]));
            $block('sheet_activity_customer', 'زيارات أو دفعات موزّع مسجَّلة على صيدليات فواتير الدفعة في كشوفها:', $hit->count(), $hit->pluck('invoice_number')->all());
        }

        $checks = [
            'payment_allocations' => ['finance', 'دفعات مالية موزَّعة على فواتير الدفعة (تصدير للمالية):'],
            'invoice_adjustments' => ['adjustments', 'تعديلات لاحقة (invoice_adjustments) على فواتير الدفعة:'],
            'print_events' => ['printed', 'فواتير أو إيصالات من الدفعة طُبعت:'],
            'sheet_change_notices' => ['notices', 'إشعارات تعديل كشف منشور تخص فواتير الدفعة:'],
        ];
        foreach ($checks as $table => [$code, $text]) {
            $found = [];
            foreach (array_chunk($ids, BulkWrite::CHUNK) as $chunk) {
                foreach (DB::table($table)->whereIn('invoice_id', $chunk)->distinct()->pluck('invoice_id') as $id) {
                    $found[] = $label((int) $id);
                }
            }
            if ($found) {
                $block($code, $text, count($found), $found);
            }
        }

        // 4) عُدّلت لاحقاً بدفعة أخرى
        $later = [];
        foreach (array_chunk($ids, BulkWrite::CHUNK) as $chunk) {
            foreach (DB::table('invoice_revisions')->whereIn('invoice_id', $chunk)->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', '!=', $batch->id))
                ->where('created_at', '>=', $batch->created_at)->distinct()->pluck('invoice_id') as $id) {
                $later[] = $label((int) $id);
            }
        }
        if ($later) {
            $block('revised_later', 'فواتير من الدفعة عُدّلت لاحقاً (بدفعة أخرى أو بتعديل يدوي):', count($later), $later);
        }
    }

    /**
     * عدد الفواتير التي تبقى في كل كشف أنشأته الدفعة بعد حذف فواتيرها وإعادة المنقولة.
     *
     * @return array<int, int>
     */
    private function remainingInSheets(array $sheetIds, array $deleteIds, array $movedIds): array
    {
        if (! $sheetIds) {
            return [];
        }
        $leaving = array_fill_keys(array_merge($deleteIds, $movedIds), true);
        $remaining = [];
        foreach (Invoice::whereIn('distribution_sheet_id', $sheetIds)->get(['id', 'distribution_sheet_id']) as $invoice) {
            if (! isset($leaving[(int) $invoice->id])) {
                $remaining[(int) $invoice->distribution_sheet_id] = ($remaining[(int) $invoice->distribution_sheet_id] ?? 0) + 1;
            }
        }

        return $remaining;
    }

    /**
     * @return array<int, string> أمثلة سجلات تمنع حذف الكشوف
     */
    private function sheetReferences(array $sheetIds): array
    {
        if (! $sheetIds) {
            return [];
        }
        $found = [];
        foreach (['payments' => 'دفعات', 'sheet_visits' => 'زيارات', 'print_events' => 'طباعة', 'finance_exports' => 'تصدير مالي', 'sheet_change_notices' => 'إشعارات',
            'payment_allocations' => 'توزيع دفعات', 'cash_counts' => 'مطابقة صندوق'] as $table => $label) {
            $hits = DB::table($table)->whereIn('distribution_sheet_id', $sheetIds)->distinct()->pluck('distribution_sheet_id');
            foreach ($hits as $id) {
                $found[] = "كشف #{$id}: {$label}";
            }
        }

        return $found;
    }

    private function snapshotActivity(array $snapshotIds, callable $block): void
    {
        if (! $snapshotIds) {
            return;
        }
        $reviewed = 0;
        $adjusted = 0;
        $receipts = 0;
        foreach (array_chunk($snapshotIds, BulkWrite::CHUNK) as $chunk) {
            $reviewed += CustomerBalanceSnapshot::whereIn('id', $chunk)->whereNotNull('reviewed_by')->count();
            $adjusted += DB::table('invoice_adjustments')->whereIn('balance_snapshot_id', $chunk)->count();
            $receipts += DB::table('payments')->whereIn('balance_snapshot_id', $chunk)->count();
        }
        if ($reviewed) {
            $block('snapshot_reviewed', 'لقطات أرصدة من هذه الدفعة رُوجعت بقرار من المحاسب أو الأدمن:', $reviewed);
        }
        if ($adjusted) {
            $block('snapshot_adjusted', 'لقطات أرصدة من هذه الدفعة بُني عليها تعديل لاحق:', $adjusted);
        }
        if ($receipts) {
            $block('snapshot_receipts', 'إيصالات موزّعين طُبع فيها رصيد من لقطات هذه الدفعة:', $receipts);
        }
    }

    /**
     * مصير الزبائن الذين أنشأتهم الدفعة: [يُحذفون، يبقون (سبب => أسماء)، موانع (سبب => أسماء)].
     *
     * @return array{0: array<int, int>, 1: array<string, array<int, string>>, 2: array<string, array<int, string>>}
     */
    private function customerFates(array $customerIds, array $deletedInvoiceIds, string $batchId): array
    {
        if (! $customerIds) {
            return [[], [], []];
        }
        $names = $this->load(Customer::class, $customerIds, ['id', 'name'])->pluck('name', 'id');
        $customerIds = $names->keys()->map(fn ($id) => (int) $id)->all();
        $blocking = [
            'payments' => 'زبائن أنشأتهم الدفعة عليهم دفعات (موزّع أو مندوب):',
            'sheet_visits' => 'زبائن أنشأتهم الدفعة عليهم زيارات:',
            'payment_allocations' => 'زبائن أنشأتهم الدفعة لهم توزيع دفعات مالية:',
            'finance_export_rows' => 'زبائن أنشأتهم الدفعة في ملفات تصدير مالي:',
            'print_events' => 'زبائن أنشأتهم الدفعة طُبع لهم:',
            'pharmacy_locks' => 'زبائن أنشأتهم الدفعة لهم أقفال صيدلية:',
        ];
        $block = [];
        $blocked = [];
        foreach ($blocking as $table => $text) {
            foreach (array_chunk($customerIds, BulkWrite::CHUNK) as $chunk) {
                foreach (DB::table($table)->whereIn('customer_id', $chunk)->distinct()->pluck('customer_id') as $id) {
                    $block[$text][] = $names[$id] ?? '#'.$id;
                    $blocked[(int) $id] = true;
                }
            }
        }

        $keep = [];
        $kept = [];
        $deleted = array_fill_keys($deletedInvoiceIds, true);
        foreach (array_chunk($customerIds, BulkWrite::CHUNK) as $chunk) {
            foreach (Invoice::whereIn('customer_id', $chunk)->get(['id', 'customer_id']) as $invoice) {
                if (! isset($deleted[(int) $invoice->id]) && ! isset($kept[(int) $invoice->customer_id])) {
                    $keep['زبائن أنشأتهم الدفعة لكن لهم فواتير من دفعة أخرى أو أُضيفت يدوياً: يبقون (ويعود رصيدهم كما كان قبل الدفعة):'][] = $names[$invoice->customer_id] ?? '';
                    $kept[(int) $invoice->customer_id] = true;
                }
            }
            foreach (DB::table('customer_aliases')->whereIn('customer_id', $chunk)->distinct()->pluck('customer_id') as $id) {
                if (! isset($kept[(int) $id])) {
                    $keep['زبائن أنشأتهم الدفعة ثم رُبطت بهم أسماء بديلة: يبقون:'][] = $names[$id] ?? '';
                    $kept[(int) $id] = true;
                }
            }
            // لقطات الدفعة نفسها تُحذف معها؛ لقطة من ملف أرصدة آخر تُبقي الزبون
            foreach (DB::table('customer_balance_snapshots')->whereIn('customer_id', $chunk)
                ->where(fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', '!=', $batchId))->distinct()->pluck('customer_id') as $id) {
                if (! isset($kept[(int) $id])) {
                    $keep['زبائن أنشأتهم الدفعة ولهم لقطات أرصدة من ملف آخر: يبقون:'][] = $names[$id] ?? '';
                    $kept[(int) $id] = true;
                }
            }
        }

        $delete = array_values(array_filter($customerIds, fn ($id) => ! isset($blocked[$id]) && ! isset($kept[$id])));

        return [$delete, $keep, $block];
    }

    /**
     * صفوف الزبائن التي يعود رصيدها: [customer_id => [عمود => قيمة]] (المتغيّرة فقط).
     */
    private function balanceRestorePlan(BalanceImport $import, ?array $preImage, array $snapshotIds, array $deleteCustomers): array
    {
        $deleted = array_fill_keys($deleteCustomers, true);
        $rows = [];
        $current = DB::table('customers')->get(array_merge(['id'], BalancePreImage::FIELDS))->keyBy('id');

        if ($preImage !== null) {
            foreach ($current as $id => $c) {
                if (isset($deleted[(int) $id])) {
                    continue;
                }
                $before = $preImage[(int) $id] ?? null;
                // زبون لم يكن موجوداً قبل ملف الأرصدة ويبقى (لروابط أخرى): رصيد صفري بلا تاريخ
                $target = $before ?? ['balance' => 0.0, 'balance_as_of' => null, 'last_balance_import_id' => null,
                    'block' => $c->block, 'area' => $c->area, 'area_normalized' => $c->area_normalized, 'needs_review' => (bool) $c->needs_review];
                $diff = $this->diff($c, $target);
                if ($diff) {
                    $rows[(int) $id] = $diff;
                }
            }

            return $rows;
        }

        // دفعة قبل 7F: من اللقطات (دقيق للرصيد) ومن ملف الأرصدة السابق (للتاريخ)
        $previous = BalanceImport::where('status', BalanceImport::APPLIED)->where('id', '!=', $import->id)->whereNotNull('as_of')
            ->where('applied_at', '<=', $import->applied_at)->orderByDesc('as_of')->orderByDesc('applied_at')->first();
        $snapshots = [];
        foreach (array_chunk($snapshotIds, BulkWrite::CHUNK) as $chunk) {
            foreach (CustomerBalanceSnapshot::whereIn('id', $chunk)->get(['customer_id', 'kind', 'prev_balance', 'prev_as_of']) as $s) {
                $snapshots[(int) $s->customer_id] = $s;
            }
        }
        $asOf = $import->as_of?->toDateString();
        $createdAt = DB::table('customers')->pluck('created_at', 'id');
        foreach ($current as $id => $c) {
            if (isset($deleted[(int) $id])) {
                continue;
            }
            $s = $snapshots[(int) $id] ?? null;
            $target = [];
            if ($s && $s->kind !== 'created') {
                $target['balance'] = round((float) $s->prev_balance, 2);
                $target['balance_as_of'] = $s->prev_as_of?->toDateString();
            } elseif ($s) {
                $target += ['balance' => 0.0, 'balance_as_of' => null, 'last_balance_import_id' => null];
            } elseif ($asOf !== null && substr((string) $c->balance_as_of, 0, 10) === $asOf) {
                $existedBefore = $previous && (string) ($createdAt[$id] ?? '') <= (string) $previous->applied_at;
                $target['balance_as_of'] = $existedBefore ? $previous->as_of?->toDateString() : null;
            }
            if ((int) $c->last_balance_import_id === (int) $import->id && ! array_key_exists('last_balance_import_id', $target)) {
                $target['last_balance_import_id'] = $previous?->id;
            }
            $diff = $this->diff($c, $target);
            if ($diff) {
                $rows[(int) $id] = $diff;
            }
        }

        return $rows;
    }

    private function diff(object $current, array $target): array
    {
        $diff = [];
        foreach ($target as $field => $value) {
            $now = $current->{$field} ?? null;
            $same = match ($field) {
                'balance' => abs((float) $now - (float) $value) < 0.005,
                'balance_as_of' => ($now !== null ? substr((string) $now, 0, 10) : null) === $value,
                'last_balance_import_id' => ($now !== null ? (int) $now : null) === ($value !== null ? (int) $value : null),
                'needs_review' => (bool) $now === (bool) $value,
                default => $now === $value,
            };
            if (! $same) {
                $diff[$field] = $field === 'needs_review' ? (int) (bool) $value : $value;
            }
        }

        return $diff;
    }

    /**
     * إشعارات «تغيّر رصيد الصيدلية» التي كتبتها لقطات الدفعة.
     *
     * @return array<int, int>
     */
    private function balanceNoticeIds(array $snapshotIds, ImportBatch $batch): array
    {
        $set = array_fill_keys($snapshotIds, true);
        $ids = [];
        DB::table('sheet_change_notices')->where('type', 'balance_changed')->where('created_at', '>=', $batch->created_at->copy()->subDay())
            ->orderBy('id')->select(['id', 'meta'])->chunk(BulkWrite::CHUNK, function ($rows) use ($set, &$ids) {
                foreach ($rows as $row) {
                    $meta = json_decode((string) $row->meta, true);
                    if (isset($meta['snapshot_id']) && isset($set[(int) $meta['snapshot_id']])) {
                        $ids[] = (int) $row->id;
                    }
                }
            });

        return $ids;
    }

    private function affectedSheets(Collection $invoices, array $restores, array $deleteSheetIds): array
    {
        $ids = $invoices->pluck('distribution_sheet_id')->filter()->map(fn ($id) => (int) $id)->all();
        foreach ($restores as $r) {
            $ids[] = (int) ($r['type'] === 'revision' ? ($r['header']['distribution_sheet_id'] ?? 0) : ($r['sheet_id'] ?? 0));
        }

        return array_values(array_diff(array_unique(array_filter($ids)), $deleteSheetIds));
    }

    // =====================================================================
    // تفاصيل التنفيذ (مجموعية على دفعات 500)
    // =====================================================================

    private function restoreInvoices(array $restores, Collection $invoices): array
    {
        $audit = [];
        $rows = [];
        $lineIds = [];
        $lines = [];
        $now = now();
        foreach ($restores as $id => $r) {
            $invoice = $invoices[$id];
            $before = ['placement' => $invoice->placement, 'sheet_id' => $invoice->distribution_sheet_id];
            if ($r['type'] === 'revision') {
                $h = $r['header'];
                $placement = $h['placement'] ?? Invoice::UNASSIGNED;
                $rows[$id] = [
                    'invoice_number' => $h['invoice_number'], 'customer_id' => $h['customer_id'], 'customer_name' => $h['customer_name'],
                    'invoice_amount' => $h['invoice_amount'], 'invoice_date' => $h['invoice_date'], 'delivery_date' => $h['delivery_date'],
                    'delivery_order_no' => $h['delivery_order_no'], 'cost_center_code' => $h['cost_center_code'], 'cost_center_name' => $h['cost_center_name'],
                    'entered_by_name' => $h['entered_by_name'], 'invoice_note' => $h['invoice_note'], 'placement' => $placement,
                    'is_deferred' => (int) ($placement === Invoice::DEFERRED), 'distribution_sheet_id' => $placement === Invoice::IN_SHEET ? ($h['distribution_sheet_id'] ?? null) : null,
                    'content_hash' => $h['content_hash'], 'net_uncertain' => (int) ($h['net_uncertain'] ?? false), 'updated_at' => $now,
                ];
                $lineIds[] = $id;
                foreach ($r['lines'] as $line) {
                    $lines[] = $line + ['invoice_id' => $id, 'created_at' => $now, 'updated_at' => $now];
                }
            } else {
                $rows[$id] = [
                    'placement' => $r['placement'], 'is_deferred' => (int) ($r['placement'] === Invoice::DEFERRED),
                    'distribution_sheet_id' => $r['placement'] === Invoice::IN_SHEET ? $r['sheet_id'] : null,
                    'manual_placement' => (int) $r['manual_placement'], 'updated_at' => $now,
                ];
            }
            $audit[] = ['id' => $id, 'key' => $invoice->invoice_key, 'number' => $invoice->invoice_number, 'type' => $r['type'],
                'before' => $before, 'after' => ['placement' => $rows[$id]['placement'], 'sheet_id' => $rows[$id]['distribution_sheet_id']]];
        }
        BulkWrite::updateById('invoices', $rows);
        foreach (array_chunk($lineIds, BulkWrite::CHUNK) as $chunk) {
            DB::table('invoice_lines')->whereIn('invoice_id', $chunk)->delete();
        }
        foreach (array_chunk($lines, BulkWrite::CHUNK) as $chunk) {
            $columns = array_unique(array_merge(...array_map('array_keys', $chunk)));
            DB::table('invoice_lines')->insert(array_map(fn ($l) => array_merge(array_fill_keys($columns, null), $l), $chunk));
        }

        return $audit;
    }

    /**
     * last_seen_batch_id يعود لآخر دفعة سابقة (غير متراجع عنها) لها عنصر للفاتورة، وإلا null.
     */
    private function resetLastSeen(array $invoiceIds, ImportBatch $batch): void
    {
        $previous = [];
        foreach (array_chunk($invoiceIds, BulkWrite::CHUNK) as $chunk) {
            $rows = DB::table('import_batch_items')->join('import_batches', 'import_batches.id', '=', 'import_batch_items.batch_id')
                ->whereIn('import_batch_items.invoice_id', $chunk)->where('import_batch_items.batch_id', '!=', $batch->id)
                ->where(fn ($q) => $q->whereNull('import_batches.status')->orWhere('import_batches.status', '!=', ImportBatch::ROLLED_BACK))
                ->where('import_batches.created_at', '<=', $batch->created_at)
                ->select(['import_batch_items.invoice_id', 'import_batch_items.batch_id', 'import_batches.created_at'])->get();
            foreach ($rows->sortBy('created_at') as $row) {
                $previous[(int) $row->invoice_id] = $row->batch_id;
            }
        }
        $groups = [];
        foreach ($invoiceIds as $id) {
            $groups[$previous[$id] ?? ''][] = $id;
        }
        foreach ($groups as $batchId => $ids) {
            foreach (array_chunk($ids, BulkWrite::CHUNK) as $chunk) {
                Invoice::whereIn('id', $chunk)->update(['last_seen_batch_id' => $batchId !== '' ? $batchId : null]);
            }
        }
    }

    /**
     * عناصر واقتراحات دفعات سابقة جعلتها هذه الدفعة «حلّ محله أحدث»: تعود مفتوحة.
     */
    private function reopenSuperseded(array $itemIds): void
    {
        foreach (array_chunk($itemIds, BulkWrite::CHUNK) as $chunk) {
            $older = ImportBatchItem::whereIn('superseded_by_id', $chunk)->get(['id', 'proposal_id']);
            if ($older->isEmpty()) {
                continue;
            }
            ImportBatchItem::whereIn('id', $older->pluck('id'))->update(['status' => ImportBatchItem::PENDING, 'superseded_by_id' => null, 'updated_at' => now()]);
            $proposals = InvoiceChangeProposal::whereIn('id', $older->pluck('proposal_id')->filter()->all() ?: [0])->where('status', InvoiceChangeProposal::SUPERSEDED)->get(['id', 'type']);
            foreach ($proposals->groupBy('type') as $type => $group) {
                InvoiceChangeProposal::whereIn('id', $group->pluck('id'))->update(['status' => $type === InvoiceChangeProposal::TYPE_POST_CLOSE
                    ? InvoiceChangeProposal::AWAITING_ADJUSTMENT : InvoiceChangeProposal::PENDING, 'updated_at' => now()]);
            }
        }
    }

    private function deleteInvoices(array $ids, Collection $invoices): array
    {
        $audit = $invoices->only($ids)->map(fn ($i) => [
            'id' => $i->id, 'key' => $i->invoice_key, 'number' => $i->invoice_number, 'customer_id' => $i->customer_id, 'customer' => $i->customer_name,
            'amount' => (float) $i->invoice_amount, 'placement' => $i->placement, 'sheet_id' => $i->distribution_sheet_id,
            'order' => $i->delivery_order_no, 'delivery_date' => $i->delivery_date?->toDateString(),
        ])->values()->all();
        foreach (array_chunk($ids, BulkWrite::CHUNK) as $chunk) {
            DB::table('invoice_lines')->whereIn('invoice_id', $chunk)->delete();
            DB::table('invoice_revisions')->whereIn('invoice_id', $chunk)->delete();
            DB::table('invoice_change_proposals')->whereIn('invoice_id', $chunk)->delete();
            DB::table('invoices')->whereIn('id', $chunk)->delete();
        }

        return $audit;
    }

    private function deleteSheets(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $audit = DistributionSheet::whereIn('id', $ids)->get(['id', 'delivery_order_no', 'status', 'distribution_date'])
            ->map(fn ($s) => ['id' => $s->id, 'order' => $s->delivery_order_no, 'status' => $s->status, 'date' => $s->distribution_date?->toDateString()])->all();
        DB::table('sheet_assignments')->whereIn('sheet_id', $ids)->delete();
        DB::table('sheet_user_seen')->whereIn('distribution_sheet_id', $ids)->delete();
        DB::table('distribution_sheets')->whereIn('id', $ids)->delete();

        return $audit;
    }

    private function restoreBalances(array $plan, ImportBatch $batch): array
    {
        $audit = ['customers' => count($plan['balanceRestore']), 'snapshots' => count($plan['snapshotIds']), 'notices' => count($plan['notices'])];
        if ($plan['balanceRestore']) {
            $now = now();
            BulkWrite::updateById('customers', array_map(fn ($row) => $row + ['updated_at' => $now], $plan['balanceRestore']));
            $audit['restored'] = $plan['balanceRestore'];
        }
        $snapshots = $plan['snapshotIds'];
        foreach (array_chunk($snapshots, BulkWrite::CHUNK) as $chunk) {
            DB::table('payments')->whereIn('reflected_snapshot_id', $chunk)->update(['reflected_at' => null, 'reflected_snapshot_id' => null]);
            DB::table('balance_change_acks')->whereIn('snapshot_id', $chunk)->delete();
        }
        // تحذيرات «لم تنعكس» سابقة أُغلقت تلقائياً بانعكاس في لقطات هذه الدفعة: تعود مفتوحة
        if ($snapshots) {
            $notes = array_map(fn ($id) => 'انعكست الدفعات المصدَّرة في ملف أرصدة لاحق (لقطة #'.$id.').', $snapshots);
            foreach (array_chunk($notes, BulkWrite::CHUNK) as $chunk) {
                CustomerBalanceSnapshot::whereIn('review_note', $chunk)->whereNull('reviewed_by')->update(['reviewed_at' => null, 'review_note' => null]);
            }
        }
        foreach (array_chunk($plan['notices'], BulkWrite::CHUNK) as $chunk) {
            DB::table('sheet_change_notices')->whereIn('id', $chunk)->delete();
        }
        foreach (array_chunk($snapshots, BulkWrite::CHUNK) as $chunk) {
            DB::table('customer_balance_snapshots')->whereIn('id', $chunk)->delete();
        }

        return $audit;
    }

    private function deleteCustomers(array $ids): array
    {
        $audit = [];
        foreach (array_chunk($ids, BulkWrite::CHUNK) as $chunk) {
            foreach (DB::table('customers')->whereIn('id', $chunk)->get(['id', 'name', 'balance', 'area', 'block']) as $c) {
                $audit[] = (array) $c;
            }
            DB::table('customer_aliases')->whereIn('customer_id', $chunk)->delete();
            DB::table('customer_balance_snapshots')->whereIn('customer_id', $chunk)->delete();
            DB::table('customers')->whereIn('id', $chunk)->delete();
        }

        return $audit;
    }

    private function load(string $model, array $ids, array $columns): Collection
    {
        $rows = collect();
        foreach (array_chunk($ids, BulkWrite::CHUNK) as $chunk) {
            $rows = $rows->concat($model::whereIn('id', $chunk)->get($columns));
        }

        return $rows->keyBy('id');
    }
}
