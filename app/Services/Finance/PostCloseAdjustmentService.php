<?php

namespace App\Services\Finance;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\CustomerBalanceSnapshot;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceAdjustment;
use App\Models\InvoiceChangeProposal;
use App\Models\InvoiceLine;
use App\Models\InvoiceRevision;
use App\Models\User;
use App\Services\Balances\BalanceDeltaExplainer;
use App\Services\Import\InvoiceReconciler;
use App\Support\CostCenter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «تعديل لاحق» على فاتورة في كشف مكتمل (المرحلة 7C). مسار مستقل عن مراجعة الفروق (ImportReviewService::blocker يبقى يمنع المكتمل)
 * وعن SheetInvoiceEditor::applyImported (لا نقل ولا إعادة تصنيف):
 * - من الملف (اقتراح post_close بحالة awaiting_adjustment): نسخة invoice_revisions ثم الترويسة والأسطر من الحمولة، والفاتورة تبقى في كشفها.
 * - يدوي: لا يمس الفاتورة؛ delta يدخل القيمة الفعالة (Invoice::effectiveAmount).
 * - ذمة مندوب مركز كلفة الفاتورة على الصيدلية قبل/بعد، والفائض إن نتج (لا يُقصّ بصمت)، ولقطة الرصيد المطابقة.
 * - الكشف لا يتغيّر (لا status ولا revision ولا إشعارات موزع)؛ الدفعات والتوزيعات والتصديرات السابقة لا تُمَس.
 * الصلاحية: المحاسب والأدمن يطبّقان ويتجاهلان، والتراجع للأدمن فقط.
 */
class PostCloseAdjustmentService
{
    public const SNAPSHOT_WINDOW_DAYS = 30;

    public const APPLIED_NOTE = 'طُبّق كتعديل لاحق';

    public function __construct(
        private RepReceivableService $receivables,
        private InvoiceReconciler $reconciler,
        private BalanceDeltaExplainer $explainer,
    ) {
    }

    public static function canApply(User $user): bool
    {
        return $user->hasRole(Role::Admin, Role::Accountant);
    }

    public static function canRevert(User $user): bool
    {
        return $user->isAdmin();
    }

    // =====================================================================
    // القوائم
    // =====================================================================

    /**
     * اقتراحات «من الملف» بانتظار التطبيق.
     */
    public function pendingQuery()
    {
        return InvoiceChangeProposal::query()
            ->where('type', InvoiceChangeProposal::TYPE_POST_CLOSE)
            ->where('status', InvoiceChangeProposal::AWAITING_ADJUSTMENT)
            ->whereHas('invoice');
    }

    public function pendingCount(): int
    {
        return $this->pendingQuery()->count();
    }

    /**
     * مندوب مركز كلفة الفاتورة (المطابقة بالتطبيع كما في ذمم المندوب)، أو null.
     */
    public function repFor(?string $costCenterCode): ?User
    {
        $code = CostCenter::normalizeCode($costCenterCode);
        if ($code === null || $code === '') {
            return null;
        }

        return User::where('role', Role::Rep)->whereIn('cost_center_code', CostCenter::codeVariants($code))->get()
            ->first(fn ($u) => CostCenter::normalizeCode($u->cost_center_code) === $code);
    }

    // =====================================================================
    // المعاينة (الأثر بالأرقام دون كتابة)
    // =====================================================================

    /**
     * أثر تطبيق اقتراح من الملف: يُطبَّق داخل transaction ثم يُتراجع عنه.
     *
     * @return array{rep: ?string, debt_before: ?float, debt_after: ?float, surplus: float, old: float, new: float, delta: float, message: string}
     */
    public function previewProposal(InvoiceChangeProposal $proposal, User $user): array
    {
        return $this->dryRun(fn () => $this->applyProposal($proposal, $user, null, true));
    }

    /**
     * @return array{rep: ?string, debt_before: ?float, debt_after: ?float, surplus: float, old: float, new: float, delta: float, message: string}
     */
    public function previewManual(Invoice $invoice, float $delta, User $user): array
    {
        return $this->dryRun(fn () => $this->applyManual($invoice, $delta, 'other', null, $user, null, true));
    }

    /**
     * «ذمة المندوب أحمد على هذه الصيدلية ستنقص من 400 إلى 200»
     */
    public static function impactMessage(?string $rep, ?float $before, ?float $after, float $surplus, bool $future = true): string
    {
        $money = fn ($v) => BalanceDeltaExplainer::money((float) $v);
        if ($rep === null || $before === null || $after === null) {
            $text = 'لا مندوب لمركز كلفة هذه الفاتورة: لا أثر على ذمة مندوب';
        } elseif (abs($after - $before) < 0.005) {
            $text = "ذمة المندوب {$rep} ".($future ? 'لن تتغيّر' : 'لم تتغيّر').' ('.$money($before).')';
        } else {
            $verb = $after < $before ? ($future ? 'ستنقص' : 'نقصت') : ($future ? 'ستزيد' : 'زادت');
            $text = "ذمة المندوب {$rep} {$verb} من ".$money($before).' إلى '.$money($after);
        }
        if ($surplus > 0.005) {
            $text .= '، وزيادة تحصيل '.$money($surplus).' تحتاج قراراً';
        }

        return $text;
    }

    // =====================================================================
    // التطبيق
    // =====================================================================

    /**
     * تطبيق اقتراح post_close من الملف.
     */
    public function applyProposal(InvoiceChangeProposal $proposal, User $user, ?string $note = null, bool $dry = false): InvoiceAdjustment|array
    {
        $this->authorizeApply($user);

        $run = function () use ($proposal, $user, $note, $dry) {
            $proposal = InvoiceChangeProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($proposal->type !== InvoiceChangeProposal::TYPE_POST_CLOSE || $proposal->status !== InvoiceChangeProposal::AWAITING_ADJUSTMENT) {
                throw ValidationException::withMessages(['proposal' => 'هذا التعديل ليس بانتظار التطبيق (تم البت فيه أو حلّ محله تغيير أحدث).']);
            }
            $invoice = Invoice::whereKey($proposal->invoice_id)->lockForUpdate()->firstOrFail();
            $sheet = $this->completedSheet($invoice);
            $payload = $proposal->payload;
            if (! is_array($payload) || ! isset($payload['invoice_amount'], $payload['lines'])) {
                throw ValidationException::withMessages(['proposal' => 'لا توجد حمولة محفوظة لهذا التغيير.']);
            }

            $rep = $this->repFor($invoice->cost_center_code);
            $before = $this->figures($invoice, $rep);
            $oldAmount = round((float) $invoice->invoice_amount, 2);
            $newAmount = round((float) $payload['invoice_amount'], 2);
            $item = ImportBatchItem::where('proposal_id', $proposal->id)->latest('id')->first();
            $fileName = $proposal->batch_id ? \App\Models\ImportBatch::find($proposal->batch_id)?->file_names : null;
            $headerBefore = $this->header($invoice);

            // نسخة قديمة بالصيغة نفسها (revision_no)، ثم الترويسة والأسطر فقط: لا placement ولا كشف ولا status
            $revision = InvoiceRevision::snapshot($invoice, $proposal->batch_id, $item?->id, $user->id);
            $this->reconciler->applyPayload($invoice, $payload);
            $invoice->refresh();

            $adjustment = InvoiceAdjustment::create([
                'invoice_id' => $invoice->id,
                'sheet_id' => $sheet->id,
                'customer_id' => $invoice->customer_id,
                'cost_center_code' => $headerBefore['cost_center_code'],
                'rep_user_id' => $rep?->id,
                'kind' => InvoiceAdjustment::KIND_FILE,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'delta' => round($newAmount - $oldAmount, 2),
                'reason_code' => 'file',
                'reason_text' => trim('وصل في ملف '.($fileName ? '«'.(is_array($fileName) ? implode('، ', $fileName) : $fileName).'»' : ($proposal->batch_id ? 'دفعة '.$proposal->batch_id : '')).($note ? ' — '.$note : '')),
                'source_batch_id' => $proposal->batch_id,
                'proposal_id' => $proposal->id,
                'import_batch_item_id' => $item?->id,
                'invoice_revision_id' => $revision->id,
                'status' => InvoiceAdjustment::APPLIED,
                'applied_by' => $user->id,
                'applied_at' => now(),
            ]);

            return $this->finish($adjustment, $invoice, $rep, $before, $dry, function () use ($proposal, $item, $user) {
                $proposal->update(['status' => InvoiceChangeProposal::APPLIED, 'decided_by' => $user->id, 'decided_at' => now()]);
                // عنصر 7A المرتبط (closed_changed للعلم، أو محجوز بسبب الإغلاق)
                if ($item) {
                    $item->update(['status' => ImportBatchItem::APPLIED, 'blocked_reason' => null, 'decision_note' => self::APPLIED_NOTE,
                        'decided_by' => $user->id, 'decided_at' => now()]);
                }
            }, ['header' => $headerBefore, 'lines_count' => count($revision->lines ?? [])], ['header' => $this->header($invoice), 'lines_count' => $invoice->lines()->count()]);
        };

        return $dry ? $run() : DB::transaction($run);
    }

    /**
     * تعديل يدوي بفرق (delta) على فاتورة في كشف مكتمل: لا يمس أسطرها.
     */
    public function applyManual(Invoice $invoice, float $delta, string $reasonCode, ?string $reasonText, User $user, ?int $snapshotId = null, bool $dry = false): InvoiceAdjustment|array
    {
        $this->authorizeApply($user);
        if (! in_array($reasonCode, InvoiceAdjustment::MANUAL_REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => 'اختر سبب التعديل.']);
        }
        $reasonText = $reasonText !== null && trim($reasonText) !== '' ? trim($reasonText) : null;
        if ($reasonCode === 'other' && $reasonText === null && ! $dry) {
            throw ValidationException::withMessages(['reason_text' => 'اكتب السبب عند اختيار «أخرى».']);
        }
        $delta = round($delta, 2);
        if (abs($delta) < 0.005) {
            throw ValidationException::withMessages(['delta' => 'مقدار التعديل صفر: لا شيء يُطبَّق.']);
        }

        $run = function () use ($invoice, $delta, $reasonCode, $reasonText, $user, $snapshotId, $dry) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $sheet = $this->completedSheet($invoice);
            $oldAmount = $invoice->effectiveAmount();
            $newAmount = round($oldAmount + $delta, 2);
            if ($newAmount < 0) {
                throw ValidationException::withMessages(['delta' => 'القيمة الجديدة لا تكون سالبة (القيمة الحالية '.BalanceDeltaExplainer::money($oldAmount).').']);
            }

            $rep = $this->repFor($invoice->cost_center_code);
            $before = $this->figures($invoice, $rep);

            $adjustment = InvoiceAdjustment::create([
                'invoice_id' => $invoice->id,
                'sheet_id' => $sheet->id,
                'customer_id' => $invoice->customer_id,
                'cost_center_code' => $invoice->cost_center_code,
                'rep_user_id' => $rep?->id,
                'kind' => InvoiceAdjustment::KIND_MANUAL,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'delta' => $delta,
                'reason_code' => $reasonCode,
                'reason_text' => $reasonText,
                'balance_snapshot_id' => $snapshotId,
                'status' => InvoiceAdjustment::APPLIED,
                'applied_by' => $user->id,
                'applied_at' => now(),
            ]);
            $fresh = Invoice::whereKey($invoice->id)->first();

            return $this->finish($adjustment, $fresh, $rep, $before, $dry, null,
                ['effective_amount' => $oldAmount, 'invoice_amount' => (float) $invoice->invoice_amount],
                ['effective_amount' => $fresh->effectiveAmount(), 'invoice_amount' => (float) $fresh->invoice_amount, 'lines_unchanged' => true]);
        };

        return $dry ? $run() : DB::transaction($run);
    }

    /**
     * تجاهل اقتراح من الملف مع سبب (يبقى ظاهراً في الشاشة وسجل النشاط).
     */
    public function ignore(InvoiceChangeProposal $proposal, string $reason, User $user): void
    {
        $this->authorizeApply($user);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب التجاهل.']);
        }

        DB::transaction(function () use ($proposal, $reason, $user) {
            $proposal = InvoiceChangeProposal::whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($proposal->status !== InvoiceChangeProposal::AWAITING_ADJUSTMENT) {
                throw ValidationException::withMessages(['proposal' => 'هذا التعديل ليس بانتظار القرار.']);
            }
            $proposal->update(['status' => InvoiceChangeProposal::REJECTED, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason]);
            $item = ImportBatchItem::where('proposal_id', $proposal->id)->latest('id')->first();
            $item?->update(['status' => ImportBatchItem::REJECTED, 'decision_note' => 'تُجوهل التعديل اللاحق: '.$reason, 'decided_by' => $user->id, 'decided_at' => now()]);

            ActivityLog::record('invoice.adjustment_ignored', $proposal->invoice, [
                'status' => InvoiceChangeProposal::AWAITING_ADJUSTMENT,
                'amount' => (float) $proposal->invoice?->invoice_amount,
            ], [
                'status' => InvoiceChangeProposal::REJECTED,
                'proposed_amount' => (float) ($proposal->payload['invoice_amount'] ?? 0),
                'reason' => $reason,
            ], ['proposal_id' => $proposal->id, 'batch_id' => $proposal->batch_id, 'invoice_number' => $proposal->invoice?->invoice_number, 'item_id' => $item?->id]);
        });
    }

    /**
     * تراجع (الأدمن فقط، بسبب إلزامي): من الملف ⇒ القيم من invoice_revisions؛ يدوي ⇒ يُلغى أثر الـdelta.
     */
    public function revert(InvoiceAdjustment $adjustment, string $reason, User $user): InvoiceAdjustment
    {
        if (! self::canRevert($user)) {
            abort(403, 'التراجع عن التعديل اللاحق للمدير فقط.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'اكتب سبب التراجع.']);
        }

        return DB::transaction(function () use ($adjustment, $reason, $user) {
            $adjustment = InvoiceAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if (! $adjustment->isApplied()) {
                throw ValidationException::withMessages(['adjustment' => 'تم التراجع عن هذا التعديل مسبقاً.']);
            }
            $invoice = Invoice::whereKey($adjustment->invoice_id)->lockForUpdate()->firstOrFail();
            $rep = $adjustment->rep_user_id ? User::find($adjustment->rep_user_id) : null;
            $before = $this->figures($invoice, $rep);
            $amountBefore = $invoice->effectiveAmount();

            if ($adjustment->kind === InvoiceAdjustment::KIND_FILE) {
                $revision = InvoiceRevision::find($adjustment->invoice_revision_id);
                if (! $revision) {
                    throw ValidationException::withMessages(['adjustment' => 'لا توجد نسخة محفوظة للفاتورة قبل التعديل.']);
                }
                InvoiceRevision::snapshot($invoice, $adjustment->source_batch_id, $adjustment->import_batch_item_id, $user->id);
                $this->restore($invoice, $revision);
                // الاقتراح يعود بانتظار القرار، والعنصر يذكر التراجع
                $adjustment->proposal?->update(['status' => InvoiceChangeProposal::AWAITING_ADJUSTMENT, 'decided_by' => null, 'decided_at' => null]);
                if ($adjustment->import_batch_item_id) {
                    ImportBatchItem::whereKey($adjustment->import_batch_item_id)->update([
                        'status' => ImportBatchItem::INFO, 'decision_note' => 'تُراجع عن التعديل اللاحق: '.$reason, 'updated_at' => now(),
                    ]);
                }
            }

            $adjustment->update(['status' => InvoiceAdjustment::REVERTED, 'reverted_by' => $user->id, 'reverted_at' => now(), 'revert_reason' => $reason, 'rep_seen_at' => null]);
            $invoice = Invoice::whereKey($invoice->id)->first();
            $after = $this->figures($invoice, $rep);
            $this->relinkSnapshot($adjustment);

            ActivityLog::record('invoice.adjustment_reverted', $invoice, [
                'status' => InvoiceAdjustment::APPLIED,
                'amount' => $amountBefore,
                'rep_debt' => $before['debt'],
                'surplus' => $before['surplus'],
            ], [
                'status' => InvoiceAdjustment::REVERTED,
                'amount' => $invoice->effectiveAmount(),
                'rep_debt' => $after['debt'],
                'surplus' => $after['surplus'],
                'reason' => $reason,
            ], ['adjustment_id' => $adjustment->id, 'kind' => $adjustment->kind, 'invoice_number' => $invoice->invoice_number, 'rep_user_id' => $adjustment->rep_user_id]);

            return $adjustment->fresh();
        });
    }

    /** المندوب «اطلعت» على أثر تعديل لاحق على ذمته */
    public function markSeen(InvoiceAdjustment $adjustment, User $rep): void
    {
        if ((int) $adjustment->rep_user_id !== (int) $rep->id) {
            abort(403);
        }
        $adjustment->update(['rep_seen_at' => now()]);
    }

    // =====================================================================

    /**
     * ذمة المندوب على الصيدلية الآن، وزيادة التحصيل على هذه الفاتورة.
     *
     * @return array{debt: ?float, surplus: float}
     */
    public function figures(Invoice $invoice, ?User $rep): array
    {
        if ($rep) {
            $state = $this->receivables->state($rep, [(int) $invoice->customer_id])->get((int) $invoice->customer_id);
            $row = $state ? $state['invoices']->firstWhere('invoice_id', $invoice->id) : null;

            return ['debt' => ($state['remaining_cents'] ?? 0) / 100, 'surplus' => ($row['over_collected_cents'] ?? 0) / 100];
        }
        $row = $this->receivables->debtInvoices(null, [(int) $invoice->customer_id])->first(fn ($r) => $r['invoice']->id === $invoice->id);

        return ['debt' => null, 'surplus' => ($row['clipped_cents'] ?? 0) / 100];
    }

    /**
     * بعد إنشاء التعديل: الذمة بعده والفائض، ولقطة الرصيد، والسجل. في المعاينة يعيد الأرقام فقط.
     */
    private function finish(InvoiceAdjustment $adjustment, Invoice $invoice, ?User $rep, array $before, bool $dry, ?callable $afterApply, array $oldValues, array $newValues): InvoiceAdjustment|array
    {
        $after = $this->figures($invoice, $rep);
        $adjustment->update([
            'rep_debt_before' => $before['debt'],
            'rep_debt_after' => $after['debt'],
            'surplus' => $after['surplus'],
        ]);

        if ($dry) {
            return [
                'rep' => $rep?->name,
                'debt_before' => $before['debt'],
                'debt_after' => $after['debt'],
                'surplus' => $after['surplus'],
                'old' => (float) $adjustment->old_amount,
                'new' => (float) $adjustment->new_amount,
                'delta' => (float) $adjustment->delta,
                'message' => self::impactMessage($rep?->name, $before['debt'], $after['debt'], $after['surplus']),
            ];
        }

        if ($afterApply) {
            $afterApply();
        }
        $this->linkSnapshot($adjustment);
        $cancelled = (float) $adjustment->new_amount === 0.0;

        ActivityLog::record('invoice.adjustment_applied', $invoice, $oldValues + [
            'amount' => (float) $adjustment->old_amount,
            'rep_debt' => $before['debt'],
            'surplus' => $before['surplus'],
        ], $newValues + [
            'amount' => (float) $adjustment->new_amount,
            'delta' => (float) $adjustment->delta,
            'rep_debt' => $after['debt'],
            'surplus' => $after['surplus'],
        ], [
            'adjustment_id' => $adjustment->id,
            'kind' => $adjustment->kind,
            'invoice_number' => $invoice->invoice_number,
            'sheet_id' => $adjustment->sheet_id,
            'customer_id' => $adjustment->customer_id,
            'cost_center' => $adjustment->cost_center_code,
            'rep_user_id' => $rep?->id,
            'rep' => $rep?->name,
            'reason' => $adjustment->reasonLabel(),
            'proposal_id' => $adjustment->proposal_id,
            'balance_snapshot_id' => $adjustment->fresh()->balance_snapshot_id,
            'cancelled_in_accounting' => $cancelled,
            'note' => $cancelled ? 'ألغيت في المحاسبة (المبلغ صار 0؛ لا حذف)' : null,
            'impact' => self::impactMessage($rep?->name, $before['debt'], $after['debt'], $after['surplus'], false),
            'by_role' => $adjustment->applier?->role?->value,
        ]);

        return $adjustment->fresh();
    }

    /**
     * ربط لقطة الرصيد: الممرَّرة صراحة، وإلا لقطة للزبون خلال 30 يوماً بالفرق نفسه أو تذكر هذه الفاتورة بالمبلغ نفسه.
     * ثم تُعاد كتابة تفسيرها فيُحسب التعديل مرة واحدة («تعديل لاحق» لا «مقترح من الملف»).
     */
    private function linkSnapshot(InvoiceAdjustment $adjustment): void
    {
        $snapshot = $adjustment->balance_snapshot_id ? CustomerBalanceSnapshot::find($adjustment->balance_snapshot_id) : null;
        if (! $snapshot || (int) $snapshot->customer_id !== (int) $adjustment->customer_id) {
            $delta = (float) $adjustment->delta;
            $snapshot = CustomerBalanceSnapshot::where('customer_id', $adjustment->customer_id)
                ->where('created_at', '>=', now()->subDays(self::SNAPSHOT_WINDOW_DAYS))
                ->orderByDesc('id')->get()
                ->first(fn ($s) => abs((float) $s->delta - $delta) < 0.01
                    || collect($s->details ?? [])->contains(fn ($d) => ($d['invoice_id'] ?? null) === $adjustment->invoice_id && abs((float) ($d['amount'] ?? 0) - $delta) < 0.01));
        }

        if (! $snapshot) {
            $adjustment->update(['balance_snapshot_id' => null, 'snapshot_note' => InvoiceAdjustment::NO_SNAPSHOT]);

            return;
        }
        $adjustment->update(['balance_snapshot_id' => $snapshot->id, 'snapshot_note' => null]);
        if ($snapshot->balance_import_id) {
            $this->explainer->reexplain($snapshot);
        }
    }

    /** بعد التراجع: تُعاد كتابة تفسير اللقطة المرتبطة (يعود «مقترح من الملف» إن بقي الاقتراح) */
    private function relinkSnapshot(InvoiceAdjustment $adjustment): void
    {
        $snapshot = $adjustment->balance_snapshot_id ? CustomerBalanceSnapshot::find($adjustment->balance_snapshot_id) : null;
        if ($snapshot && $snapshot->balance_import_id) {
            $this->explainer->reexplain($snapshot);
        }
    }

    private function completedSheet(Invoice $invoice): \App\Models\DistributionSheet
    {
        $sheet = $invoice->placement === Invoice::IN_SHEET ? $invoice->distributionSheet : null;
        if (! $sheet || $sheet->status !== 'completed') {
            throw ValidationException::withMessages(['invoice' => 'التعديل اللاحق لفواتير الكشوف المكتملة فقط؛ الكشف المفتوح يُعدَّل من مراجعة الفروق.']);
        }

        return $sheet;
    }

    private function authorizeApply(User $user): void
    {
        if (! self::canApply($user)) {
            abort(403, 'تطبيق التعديلات اللاحقة للمحاسب والمدير فقط.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function header(Invoice $invoice): array
    {
        return [
            'invoice_amount' => (float) $invoice->invoice_amount,
            'delivery_order_no' => $invoice->delivery_order_no,
            'delivery_date' => $invoice->delivery_date?->toDateString(),
            'cost_center_code' => $invoice->cost_center_code,
            'cost_center_name' => $invoice->cost_center_name,
            'placement' => $invoice->placement,
            'distribution_sheet_id' => $invoice->distribution_sheet_id,
            'content_hash' => $invoice->content_hash,
        ];
    }

    /**
     * إعادة ترويسة الفاتورة وأسطرها من نسخة (بلا تغيير مكانها أو كشفها).
     */
    private function restore(Invoice $invoice, InvoiceRevision $revision): void
    {
        $h = $revision->header ?? [];
        $invoice->fill(array_intersect_key($h, array_flip([
            'invoice_number', 'customer_id', 'customer_name', 'invoice_amount', 'invoice_date', 'delivery_date', 'delivery_order_no',
            'cost_center_code', 'cost_center_name', 'entered_by_name', 'invoice_note', 'content_hash', 'net_uncertain',
        ])));
        $invoice->save();

        $invoice->lines()->delete();
        $now = now();
        InvoiceLine::insert(array_map(fn ($line) => $line + ['invoice_id' => $invoice->id, 'created_at' => $now, 'updated_at' => $now], $revision->lines ?? []));
    }

    /**
     * تشغيل ثم تراجع (معاينة الأثر دون كتابة).
     */
    private function dryRun(callable $callback): array
    {
        $result = null;
        try {
            DB::transaction(function () use ($callback, &$result) {
                $result = $callback();

                throw new PreviewRollback;
            });
        } catch (PreviewRollback) {
        }

        return $result;
    }
}

/** @internal استثناء للتراجع عن transaction المعاينة */
final class PreviewRollback extends \RuntimeException
{
}
