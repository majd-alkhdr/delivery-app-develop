<?php

namespace App\Services\Import;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\InvoiceLine;
use App\Models\InvoiceRevision;
use App\Models\Payment;
use App\Models\PrintEvent;
use App\Models\SheetVisit;
use App\Models\User;
use App\Services\SheetChangeNotifier;
use App\Services\SheetFiguresService;
use App\Services\SheetInvoiceEditor;
use App\Support\BulkWrite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * قرارات «مراجعة الفروق» (المرحلة 7A): تطبيق عنصر دفعة أو تجاهله أو تطبيق الكل.
 * - الصلاحية: مسؤول الكشوف والمدير فقط.
 * - الحارس على مستوى الزبون (SheetActivityGuard دون تغيير): نشاط للزبون في الكشف ⇒ المدير وحده.
 * - الكشف المكتمل أو المسودة ⇒ العنصر «محجوز» لا يُطبَّق.
 * - التطبيق يمر عبر SheetInvoiceEditor::applyImported (نسخة قديمة، أرقام الكشف، الإشعارات).
 */
class ImportReviewService
{
    public const ACTIVITY_BLOCK = 'محجوز: سُجّل نشاط على هذه الصيدلية، يطبّقه المدير فقط.';

    public const CLOSED_BLOCK = 'أُغلق الكشف قبل القرار: لم يُطبَّق، وحُفظ التغيير في «التعديلات اللاحقة» للتسوية بعد الإغلاق.';

    /** نص 7A القديم لسبب الحجز (عناصر محفوظة قبل 7C) */
    public const LEGACY_CLOSED_BLOCK = 'أُغلق الكشف قبل القرار: لم يُطبَّق، وحُفظ التغيير للتسوية بعد الإغلاق (المرحلة 7C).';

    public function __construct(private SheetInvoiceEditor $editor, private InvoiceReconciler $reconciler)
    {
    }

    public function canDecide(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::SheetManager);
    }

    /**
     * سبب منع المستخدم من تطبيق العنصر الآن، أو null إن كان مسموحاً.
     *
     * @return array{code: string, message: string}|null code: decided | gone | closed | activity
     */
    public function blocker(ImportBatchItem $item, User $user): ?array
    {
        if (! $item->isOpen()) {
            return ['code' => 'decided', 'message' => 'تم البت في هذا العنصر ('.$item->statusLabel().').'];
        }
        $invoice = $item->invoice;
        if (! $invoice) {
            return ['code' => 'gone', 'message' => 'الفاتورة لم تعد موجودة.'];
        }

        foreach ($this->sheetsFor($item, $invoice) as $sheet) {
            if ($sheet->status === 'completed' || $sheet->isDraft()) {
                return ['code' => 'closed', 'message' => 'الكشف '.($sheet->isDraft() ? 'مسودة' : 'مكتمل').' ('.$sheet->shortLabel().'): لا يُطبَّق التغيير هنا.'];
            }
        }
        if (! $user->isAdmin()) {
            foreach ($this->sheetsFor($item, $invoice) as $sheet) {
                if ($this->editor->customerHasActivity($sheet, $invoice->customer_id)) {
                    return ['code' => 'activity', 'message' => self::ACTIVITY_BLOCK];
                }
            }
        }

        return null;
    }

    /**
     * @return array{ok: bool, status: string, message: string}
     */
    public function apply(ImportBatchItem $item, User $user, bool $confirmPrinted = false, bool $markActivityBlocked = true): array
    {
        if (! $this->canDecide($user)) {
            abort(403, 'تطبيق عناصر الدفعة لمسؤول الكشوف والمدير فقط.');
        }

        return DB::transaction(function () use ($item, $user, $confirmPrinted, $markActivityBlocked) {
            $item = ImportBatchItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $label = $item->invoice_number ?? '#'.$item->id;

            if ($blocker = $this->blocker($item, $user)) {
                if ($blocker['code'] === 'closed' || ($blocker['code'] === 'activity' && $markActivityBlocked)) {
                    $item->update(['status' => ImportBatchItem::BLOCKED, 'blocked_reason' => $blocker['message']]);
                }

                return ['ok' => false, 'status' => $blocker['code'], 'message' => "{$label}: {$blocker['message']}"];
            }

            $invoice = $item->invoice;
            $payload = in_array($item->kind, ImportBatchItem::PAYLOAD_KINDS, true) ? $item->proposal?->payload : null;
            if (in_array($item->kind, ImportBatchItem::PAYLOAD_KINDS, true) && ! $payload) {
                return ['ok' => false, 'status' => 'error', 'message' => "{$label}: لا توجد حمولة محفوظة لهذا التغيير."];
            }

            $printed = $payload !== null && PrintEvent::invoicePrinted($invoice);
            if ($printed && ! $confirmPrinted) {
                return ['ok' => false, 'status' => 'needs_print_confirm', 'message' => "طُبعت الفاتورة {$label} سابقاً للصيدلية. أكّد التطبيق من جديد."];
            }

            [$plan, $explain] = $this->plan($item, $invoice, $payload);
            if ($plan === null) {
                $item->update(['status' => ImportBatchItem::BLOCKED, 'blocked_reason' => $explain]);

                return ['ok' => false, 'status' => 'closed', 'message' => "{$label}: {$explain}"];
            }

            $result = $this->editor->applyImported($invoice, $plan + ['payload' => $payload, 'batch_id' => $item->batch_id, 'item_id' => $item->id], $user);

            $item->update([
                'status' => ImportBatchItem::APPLIED, 'blocked_reason' => null, 'decision_note' => $explain,
                'decided_by' => $user->id, 'decided_at' => now(),
            ]);
            $item->proposal?->update(['status' => InvoiceChangeProposal::APPLIED, 'decided_by' => $user->id, 'decided_at' => now()]);

            ActivityLog::record('import.item_applied', $invoice, $result['before'], $result['after'], [
                'item_id' => $item->id, 'batch_id' => $item->batch_id, 'kind' => $item->kind,
                'invoice_number' => $invoice->invoice_number, 'explanation' => $explain, 'by_role' => $user->role->value,
            ]);
            if ($printed) {
                ActivityLog::record('invoice.change_applied_after_print', $invoice, [], [], [
                    'proposal_id' => $item->proposal_id, 'item_id' => $item->id, 'invoice_number' => $invoice->invoice_number,
                ]);
            }

            return ['ok' => true, 'status' => 'applied', 'message' => "{$label}: طُبّق — {$explain}."];
        });
    }

    public function reject(ImportBatchItem $item, User $user, ?string $note = null): void
    {
        if (! $this->canDecide($user)) {
            abort(403, 'تجاهل عناصر الدفعة لمسؤول الكشوف والمدير فقط.');
        }

        DB::transaction(function () use ($item, $user, $note) {
            $item = ImportBatchItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if (! $item->isOpen()) {
                throw ValidationException::withMessages(['item' => 'تم البت في هذا العنصر مسبقاً ('.$item->statusLabel().').']);
            }
            $previous = $item->status;
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $item->update(['status' => ImportBatchItem::REJECTED, 'decision_note' => $note, 'decided_by' => $user->id, 'decided_at' => now()]);
            if ($item->proposal && in_array($item->proposal->status, [InvoiceChangeProposal::PENDING], true)) {
                $item->proposal->update(['status' => InvoiceChangeProposal::REJECTED, 'decided_by' => $user->id, 'decided_at' => now()]);
            }

            ActivityLog::record('import.item_rejected', $item->invoice, [
                'status' => $previous,
                'invoice' => $item->old,
            ], [
                'status' => ImportBatchItem::REJECTED,
                'proposed' => $item->new,
                'note' => $note,
            ], [
                'item_id' => $item->id, 'batch_id' => $item->batch_id, 'kind' => $item->kind,
                'invoice_number' => $item->invoice_number, 'kept_as_is' => true,
            ]);
        });
    }

    /**
     * تطبيق كل العناصر المعلّقة (التي يملك المستخدم صلاحيتها)، مع تخطي المحجوزة.
     *
     * @param  iterable<ImportBatchItem>  $items
     * @return array<int, array{ok: bool, status: string, message: string}>
     */
    public function applyMany(iterable $items, User $user, bool $confirmPrinted = false): array
    {
        if (! $this->canDecide($user)) {
            abort(403, 'تطبيق عناصر الدفعة لمسؤول الكشوف والمدير فقط.');
        }

        $results = [];
        foreach ($items as $item) {
            if ($item->status !== ImportBatchItem::PENDING) {
                continue;
            }
            $results[] = $this->apply($item, $user, $confirmPrinted, false);
        }

        return $results;
    }

    /**
     * المرحلة 7E2: «تطبيق هذه المجموعة» — كل عناصر «تغيّر وصفي فقط» المعلّقة في الدفعة.
     * النتيجة لكل عنصر هي نتيجة apply() نفسها (النسخة القديمة، تعبئة الحمولة، الأسطر، القرار، الاقتراح،
     * import.item_applied بالقيم قبل/بعد، تنبيه الكشف المنشور، أرقام الكشف)، لكن الكتابة جماعية لكل 200 عنصر
     * لأن المبلغ والأصناف والموقع لا تتغيّر. أي عنصر خارج هذه الحالة (محجوز، مطبوع بلا تأكيد، ينتقل موقعه،
     * لم يعد وصفياً…) يمر بـapply() العادي.
     *
     * @return array<int, array{ok: bool, status: string, message: string}>
     */
    public function applyDescriptive(ImportBatch $batch, User $user, bool $confirmPrinted = false): array
    {
        if (! $this->canDecide($user)) {
            abort(403, 'تطبيق عناصر الدفعة لمسؤول الكشوف والمدير فقط.');
        }

        $results = [];
        ImportBatchItem::where('batch_id', $batch->id)->descriptivePending()->orderBy('id')
            ->chunkById(200, function (Collection $chunk) use ($user, $confirmPrinted, &$results) {
                array_push($results, ...$this->applyDescriptiveChunk($chunk, $user, $confirmPrinted));
            });

        return $results;
    }

    /**
     * @param  Collection<int, ImportBatchItem>  $items
     * @return array<int, array{ok: bool, status: string, message: string}>
     */
    private function applyDescriptiveChunk(Collection $items, User $user, bool $confirmPrinted): array
    {
        $items->load(['invoice.distributionSheet', 'invoice.lines', 'proposal', 'batch']);
        $invoices = $items->pluck('invoice')->filter()->keyBy('id');
        $ids = $invoices->keys()->all() ?: [0];
        $customers = Customer::whereIn('id', $items->map(fn ($i) => $i->proposal?->payload['customer_id'] ?? null)->filter()->unique()->values()->all())->get()->keyBy('id');

        // المطبوعة: فاتورة مطبوعة، أو مطبوعة مدمجة لزبونها في كشفها (PrintEvent::invoicePrinted جماعياً)
        $sheetIds = $invoices->pluck('distribution_sheet_id')->filter()->unique()->values()->all() ?: [0];
        $printedInvoices = PrintEvent::counted()->where('kind', 'invoice')->whereIn('invoice_id', $ids)->pluck('invoice_id')->flip();
        $printedMerged = PrintEvent::counted()->where('kind', 'merged')->whereIn('distribution_sheet_id', $sheetIds)
            ->get(['distribution_sheet_id', 'customer_id'])->map(fn ($p) => $p->distribution_sheet_id.':'.$p->customer_id)->flip();
        $printed = fn (Invoice $inv) => isset($printedInvoices[$inv->id])
            || ($inv->distribution_sheet_id && $inv->customer_id && isset($printedMerged[$inv->distribution_sheet_id.':'.$inv->customer_id]));

        // نشاط الزبون في الكشف (الحارس نفسه) لغير المدير
        $activity = [];
        if (! $user->isAdmin()) {
            $customerIds = $invoices->pluck('customer_id')->filter()->unique()->values()->all() ?: [0];
            foreach ([SheetVisit::active(), Payment::active()] as $query) {
                $query->whereIn('distribution_sheet_id', $sheetIds)->whereIn('customer_id', $customerIds)
                    ->get(['distribution_sheet_id', 'customer_id'])->each(function ($r) use (&$activity) {
                        $activity[$r->distribution_sheet_id.':'.$r->customer_id] = true;
                    });
            }
        }
        $revisions = InvoiceRevision::whereIn('invoice_id', $ids)->selectRaw('invoice_id, MAX(revision_no) as m')->groupBy('invoice_id')->pluck('m', 'invoice_id');

        $results = [];
        $fast = [];
        foreach ($items as $item) {
            $invoice = $item->invoice;
            $payload = $item->proposal?->payload;
            $sheet = $invoice && $invoice->placement === Invoice::IN_SHEET ? $invoice->distributionSheet : null;
            $plan = null;
            $explain = '';
            $eligible = $invoice && $payload && $item->status === ImportBatchItem::PENDING
                && $invoice->placement !== Invoice::CANCELLED
                && (! $sheet || in_array($sheet->status, DistributionSheet::EDITABLE_STATUSES, true))
                && (! $printed($invoice) || $confirmPrinted)
                && ! ($sheet && isset($activity[$sheet->id.':'.$invoice->customer_id]))
                && $this->reconciler->descriptiveFields($invoice, $payload) !== null;
            if ($eligible) {
                [$plan, $explain] = $this->plan($item, $invoice, $payload);
                $eligible = $plan !== null && empty($plan['clear_cancel']) && (($plan['placement'] ?? null) === null
                    || ($plan['placement'] === $invoice->placement && (int) ($plan['sheet']?->id) === (int) $invoice->distribution_sheet_id));
            }
            if (! $eligible) {
                $results[] = $this->apply($item, $user, $confirmPrinted, false);

                continue;
            }
            $fast[] = [$item, $invoice, $payload, $plan, $explain];
        }
        if (! $fast) {
            return $results;
        }

        DB::transaction(function () use ($fast, $user, $customers, $printed, $revisions, &$results) {
            $now = now();
            $timestamp = (new Invoice)->freshTimestampString();
            $revisionModels = $invoiceRows = $lines = $logs = $notify = $sheets = $notes = $proposalIds = [];

            foreach ($fast as [$item, $invoice, $payload, $plan, $explain]) {
                $before = $this->editor->importSnapshot($invoice, $invoice->lines->count());
                $revisionModels[] = new InvoiceRevision(InvoiceRevision::attributesFor($invoice, $item->batch_id, $item->id, $user->id, (int) ($revisions[$invoice->id] ?? 0) + 1));

                // ما يفعله applyPayload ثم وضع الموقع (الموقع نفسه): حفظ واحد بالحقول المتغيّرة
                $this->reconciler->fill($invoice, $payload, $customers[$payload['customer_id']] ?? null);
                if (($plan['placement'] ?? null) !== null) {
                    $invoice->manual_placement = (bool) ($plan['manual'] ?? false);
                }
                $invoice->is_deferred = $invoice->placement === Invoice::DEFERRED;
                if ($dirty = $invoice->getDirty()) {
                    $invoiceRows[$invoice->id] = $dirty + ['updated_at' => $timestamp];
                }
                array_push($lines, ...$this->reconciler->lineRows($invoice, $payload['lines']));

                $after = $this->editor->importSnapshot($invoice, count($payload['lines']));
                $logs[] = ['import.item_applied', $invoice, $before, $after, [
                    'item_id' => $item->id, 'batch_id' => $item->batch_id, 'kind' => $item->kind,
                    'invoice_number' => $invoice->invoice_number, 'explanation' => $explain, 'by_role' => $user->role->value,
                ]];
                if ($printed($invoice)) {
                    $logs[] = ['invoice.change_applied_after_print', $invoice, [], [], [
                        'proposal_id' => $item->proposal_id, 'item_id' => $item->id, 'invoice_number' => $invoice->invoice_number,
                    ]];
                }
                if ($invoice->placement === Invoice::IN_SHEET && $invoice->distributionSheet) {
                    $sheets[$invoice->distribution_sheet_id] = $invoice->distributionSheet;
                    $notify[] = $invoice;
                }
                $notes[$explain][] = $item->id;
                if ($item->proposal_id) {
                    $proposalIds[] = $item->proposal_id;
                }
                $results[] = ['ok' => true, 'status' => 'applied', 'message' => ($item->invoice_number ?? '#'.$item->id).": طُبّق — {$explain}."];
            }

            BulkWrite::insertModels($revisionModels);
            BulkWrite::updateById('invoices', $invoiceRows);
            foreach (array_chunk(collect($fast)->map(fn ($f) => $f[1]->id)->all(), BulkWrite::CHUNK) as $chunk) {
                InvoiceLine::whereIn('invoice_id', $chunk)->delete();
            }
            foreach (array_chunk($lines, BulkWrite::CHUNK) as $chunk) {
                InvoiceLine::insert($chunk);
            }
            foreach ($notes as $explain => $itemIds) {
                ImportBatchItem::whereIn('id', $itemIds)->update([
                    'status' => ImportBatchItem::APPLIED, 'blocked_reason' => null, 'decision_note' => $explain,
                    'decided_by' => $user->id, 'decided_at' => $now,
                ]);
            }
            if ($proposalIds) {
                InvoiceChangeProposal::whereIn('id', $proposalIds)->update(['status' => InvoiceChangeProposal::APPLIED, 'decided_by' => $user->id, 'decided_at' => $now]);
            }
            foreach ($sheets as $sheet) {
                app(SheetFiguresService::class)->refresh($sheet);
            }
            // «حُدّثت بيانات فاتورة» للكشف المنشور (notify يتجاهل غير المنشور كما في applyImported)
            $notifier = app(SheetChangeNotifier::class);
            foreach ($notify as $invoice) {
                $sheet = $sheets[$invoice->distribution_sheet_id];
                if (in_array($sheet->status, SheetChangeNotifier::NOTIFY_STATUSES, true)) {
                    $notifier->notify($sheet, 'updated', $invoice, $user);
                }
            }
            ActivityLog::recordMany($logs);
        });

        return $results;
    }

    /**
     * إغلاق كشف بعناصر/اقتراحات معلّقة (E7): العناصر ⇒ محجوزة بسبب الإغلاق، والاقتراحات ⇒ post_close بانتظار التسوية.
     *
     * @return int عدد العناصر والاقتراحات المحوَّلة
     */
    public function closeSheet(DistributionSheet $sheet): int
    {
        $items = $this->openItemsForSheet($sheet)->get();
        $invoiceIds = Invoice::where('distribution_sheet_id', $sheet->id)->pluck('id');

        $proposalIds = $items->pluck('proposal_id')->filter()
            ->merge(InvoiceChangeProposal::whereIn('invoice_id', $invoiceIds)->where('status', InvoiceChangeProposal::PENDING)->pluck('id'))
            ->unique();

        ImportBatchItem::whereIn('id', $items->pluck('id'))->update(['status' => ImportBatchItem::BLOCKED, 'blocked_reason' => self::CLOSED_BLOCK, 'updated_at' => now()]);
        InvoiceChangeProposal::whereIn('id', $proposalIds)->whereIn('status', [InvoiceChangeProposal::PENDING])
            ->update(['type' => InvoiceChangeProposal::TYPE_POST_CLOSE, 'status' => InvoiceChangeProposal::AWAITING_ADJUSTMENT, 'updated_at' => now()]);

        return $items->count() + $proposalIds->diff($items->pluck('proposal_id'))->count();
    }

    /**
     * ما ينتظر القرار لفواتير كشف (لنافذة تأكيد الإغلاق): عناصر مفتوحة + اقتراحات قديمة معلّقة بلا عنصر.
     *
     * @return Collection<int, array{number: string, customer: ?string, label: string}>
     */
    public function pendingForSheet(DistributionSheet $sheet): Collection
    {
        $items = $this->openItemsForSheet($sheet)->get();
        $rows = $items->map(fn ($i) => ['number' => (string) $i->invoice_number, 'customer' => $i->customer_name, 'label' => $i->kindLabel()]);

        $legacy = InvoiceChangeProposal::with('invoice')
            ->whereIn('invoice_id', Invoice::where('distribution_sheet_id', $sheet->id)->pluck('id'))
            ->where('status', InvoiceChangeProposal::PENDING)
            ->whereNotIn('id', $items->pluck('proposal_id')->filter()->all() ?: [0])
            ->get()
            ->map(fn ($p) => ['number' => (string) $p->invoice?->invoice_number, 'customer' => $p->invoice?->customer_name, 'label' => 'تغيير ينتظر القرار']);

        return $rows->merge($legacy)->values();
    }

    private function openItemsForSheet(DistributionSheet $sheet)
    {
        return ImportBatchItem::query()
            ->whereIn('status', [ImportBatchItem::PENDING, ImportBatchItem::BLOCKED])
            ->where(fn ($q) => $q->where('sheet_id', $sheet->id)->orWhere('target_sheet_id', $sheet->id)
                ->orWhereIn('invoice_id', Invoice::where('distribution_sheet_id', $sheet->id)->where('placement', Invoice::IN_SHEET)->select('id')))
            ->where(fn ($q) => $q->whereNull('blocked_reason')->orWhereNotIn('blocked_reason', [self::CLOSED_BLOCK, self::LEGACY_CLOSED_BLOCK]));
    }

    // =====================================================================

    /**
     * الكشوف التي يمسّها تطبيق العنصر (الحالي والهدف).
     *
     * @return Collection<int, DistributionSheet>
     */
    private function sheetsFor(ImportBatchItem $item, Invoice $invoice): Collection
    {
        $ids = [];
        if ($invoice->placement === Invoice::IN_SHEET && $invoice->distribution_sheet_id) {
            $ids[] = $invoice->distribution_sheet_id;
        }
        if ($item->target_sheet_id && in_array($item->kind, [ImportBatchItem::CREATED, ImportBatchItem::UNCHANGED], true)) {
            $ids[] = $item->target_sheet_id;
        }

        return $ids ? DistributionSheet::whereIn('id', array_unique($ids))->get() : collect();
    }

    /**
     * خطة التطبيق للمحرّر وجملة تشرح ما حدث. null = لا يمكن التطبيق (والجملة هي السبب).
     *
     * @return array{0: ?array, 1: string}
     */
    private function plan(ImportBatchItem $item, Invoice $invoice, ?array $payload): array
    {
        $current = $invoice->placement === Invoice::IN_SHEET ? $invoice->distributionSheet : null;
        $batchDate = $item->batch?->distribution_date?->toDateString() ?? now('Asia/Damascus')->toDateString();
        $where = fn (?DistributionSheet $s) => $s ? 'كشف '.$s->shortLabel() : $invoice->placementLabel();

        switch ($item->kind) {
            case ImportBatchItem::CREATED:
                $target = DistributionSheet::find($item->target_sheet_id);
                if (! $target || ! in_array($target->status, DistributionSheet::EDITABLE_STATUSES, true)) {
                    return [null, 'الكشف الهدف لم يعد مفتوحاً (مكتمل أو مسودة): بقيت الفاتورة في «بلا كشف».'];
                }

                return [['placement' => Invoice::IN_SHEET, 'sheet' => $target, 'manual' => false], 'وُضعت في '.$where($target).($this->published($target) ? ' ونُبّه الموزع' : '')];

            case ImportBatchItem::MISSING:
                return [['placement' => Invoice::UNASSIGNED, 'sheet' => null, 'manual' => true, 'detach_reason' => 'غابت عن الملف الأحدث'],
                    'أُخرجت من '.$where($current).' إلى «بلا كشف» لأنها غابت عن الملف الأحدث (لا إلغاء ولا حذف)'];

            case ImportBatchItem::EXCLUDED_CHANGED:
            case ImportBatchItem::HELD_CHANGED:
                return [['placement' => null], 'حُدّثت بياناتها من الملف وبقيت في مكانها («'.$invoice->placementLabel().'»)'];

            case ImportBatchItem::UNCHANGED:
                $cancelled = $invoice->placement === Invoice::CANCELLED;
                $target = $item->target_sheet_id ? DistributionSheet::find($item->target_sheet_id) : null;
                if ($target && in_array($target->status, DistributionSheet::EDITABLE_STATUSES, true) && ! $cancelled) {
                    return [['placement' => Invoice::IN_SHEET, 'sheet' => $target, 'manual' => false], 'وُضعت في '.$where($target).($this->published($target) ? ' ونُبّه الموزع' : '')];
                }
                $spot = $this->reconciler->placementFor([
                    'delivery_date' => $invoice->delivery_date?->toDateString(),
                    'delivery_order_no' => $invoice->delivery_order_no,
                ], $batchDate, null);

                return [['placement' => $spot['placement'], 'sheet' => $spot['sheet'], 'manual' => false, 'clear_cancel' => $cancelled],
                    ($cancelled ? 'أُعيدت من الإلغاء: ' : '').$spot['reason']];

            default: // changed / order_removed / order_moved
                $cancelled = $invoice->placement === Invoice::CANCELLED;
                if ($current && $invoice->manual_placement) {
                    return [['placement' => null], 'حُدّثت بياناتها وبقيت في '.$where($current).' لأنها موضوعة يدوياً'];
                }
                $date = $current?->distribution_date?->toDateString() ?? $batchDate;
                $spot = $this->reconciler->placementFor($payload, $date, $current);

                return [['placement' => $spot['placement'], 'sheet' => $spot['sheet'], 'manual' => false, 'clear_cancel' => $cancelled],
                    'حُدّثت بياناتها من الملف'.($cancelled ? ' وأُعيدت من الإلغاء' : '').'، و'.$spot['reason']];
        }
    }

    private function published(DistributionSheet $sheet): bool
    {
        return in_array($sheet->status, ['pending', 'in_progress'], true);
    }
}
