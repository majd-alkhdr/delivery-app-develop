<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * دورة حياة الكشف بين مسؤول الكشوف ومشرف التوزيع:
 * الإرسال (preparing ← pending)، السحب، الإغلاق كمسودة، إعادة التفعيل، وتعارضات "زبون في أكثر من كشف".
 */
class SheetDispatchService
{
    public function __construct(
        private SheetFiguresService $figures,
        private VisitService $visits,
    ) {
    }

    // =====================================================================
    // الإرسال
    // =====================================================================

    public function send(DistributionSheet $sheet, User $user): void
    {
        $this->sendMany(collect([$sheet]), $user);
    }

    /**
     * إرسال كل كشوف الدفعة التي ما زالت قيد التجهيز.
     *
     * @return int عدد الكشوف المرسلة
     */
    public function sendBatch(ImportBatch $batch, User $user): int
    {
        $sheets = $this->batchSheets($batch)->where('status', 'preparing')->values();
        if ($sheets->isEmpty()) {
            throw ValidationException::withMessages(['send' => 'لا توجد كشوف قيد التجهيز في هذه الدفعة.']);
        }
        $this->sendMany($sheets, $user);

        return $sheets->count();
    }

    /**
     * @param  Collection<int, DistributionSheet>  $sheets
     */
    private function sendMany(Collection $sheets, User $user): void
    {
        $this->ensureSheetManager($user);

        foreach ($sheets as $sheet) {
            if (! $sheet->isPreparing()) {
                throw ValidationException::withMessages(['send' => "الكشف ({$sheet->title()}) ليس قيد التجهيز."]);
            }
        }

        $blockers = $this->blockers($sheets);
        if ($blockers) {
            throw ValidationException::withMessages(['send' => $blockers]);
        }

        DB::transaction(function () use ($sheets, $user) {
            foreach ($sheets as $sheet) {
                $sheet->update(['status' => 'pending', 'sent_at' => now(), 'sent_by' => $user->id]);
                ActivityLog::record('sheet.sent', $sheet, ['status' => 'preparing'], [
                    'status' => 'pending',
                    'sent_at' => $sheet->sent_at->toDateTimeString(),
                ], [
                    'batch_id' => $sheet->batch_id,
                    'delivery_order_no' => $sheet->delivery_order_no,
                ]);
            }
        });
    }

    /**
     * أسباب منع الإرسال (مصفوفة فارغة = مسموح):
     * فواتير "بلا كشف" أو اقتراحات تغيير معلّقة تخص الدفعة، أو تعارض زبون غير مُقَرّ به.
     *
     * @param  Collection<int, DistributionSheet>  $sheets
     * @return array<int, string>
     */
    public function blockers(Collection $sheets): array
    {
        if ($sheets->isEmpty()) {
            return [];
        }

        $sheetIds = $sheets->pluck('id')->all();
        $batchIds = $this->relatedBatchIds($sheets);
        $messages = [];

        // فواتير جديدة تنتظر قرار إضافتها لكشف منشور ليست «بلا كشف» فعلاً (لها عنصر معلّق خاص بكشفها)
        $awaiting = ImportBatchItem::where('kind', ImportBatchItem::CREATED)->where('status', ImportBatchItem::PENDING)->pluck('invoice_id');
        $unassigned = $batchIds ? Invoice::where('placement', Invoice::UNASSIGNED)->whereIn('last_seen_batch_id', $batchIds)
            ->whereNotIn('id', $awaiting->all() ?: [0])->count() : 0;
        if ($unassigned > 0) {
            $messages[] = "توجد {$unassigned} فاتورة \"بلا كشف\" تخص الدفعة: ضعها في كشف أو استبعدها أولاً.";
        }

        // المرحلة 7A (E6): عناصر دفعة معلّقة تخص هذه الكشوف (فواتيرها أو هدفها)
        $pendingItems = ImportBatchItem::where('status', ImportBatchItem::PENDING)
            ->where(fn (Builder $q) => $q->whereIn('sheet_id', $sheetIds)->orWhereIn('target_sheet_id', $sheetIds)
                ->orWhereIn('invoice_id', Invoice::whereIn('distribution_sheet_id', $sheetIds)->where('placement', Invoice::IN_SHEET)->select('id')))
            ->count();
        if ($pendingItems > 0) {
            $messages[] = "توجد {$pendingItems} عناصر في «مراجعة الفروق» تخص هذا الكشف تنتظر قرارك (تطبيق أو تجاهل): افتح شاشة الدفعة واختر «بانتظار قرارك».";
        }

        // اقتراحات قديمة (قبل 7A) بلا عنصر دفعة
        $proposals = $batchIds ? InvoiceChangeProposal::where('status', InvoiceChangeProposal::PENDING)->whereIn('batch_id', $batchIds)
            ->whereNotIn('id', ImportBatchItem::whereNotNull('proposal_id')->select('proposal_id'))->count() : 0;
        if ($proposals > 0) {
            $messages[] = "توجد {$proposals} تغييرات على فواتير تنتظر قرارك (تطبيق أو رفض).";
        }

        $acknowledged = $sheets->every(fn ($s) => $s->batch_id && ImportBatch::whereKey($s->batch_id)->whereNotNull('conflicts_acknowledged_at')->exists());
        if (! $acknowledged) {
            $conflicts = [];
            foreach ($sheets->groupBy(fn ($s) => $s->distribution_date?->toDateString()) as $date => $group) {
                if ($date) {
                    $conflicts = array_merge($conflicts, $this->conflicts($date, $group->pluck('id')->all()));
                }
            }
            if ($conflicts) {
                $messages[] = count($conflicts).' زبون له فواتير في أكثر من كشف في اليوم نفسه: انقل فواتيره أو أقرّ بالتعارض.';
            }
        }

        return $messages;
    }

    /**
     * دفعات الرفع المرتبطة بمجموعة كشوف: دفعة إنشاء كل كشف + آخر دفعة ظهرت فيها فواتيره.
     *
     * @return array<int, string>
     */
    public function relatedBatchIds(Collection $sheets): array
    {
        $ids = $sheets->pluck('batch_id')->filter();
        $ids = $ids->merge(
            Invoice::whereIn('distribution_sheet_id', $sheets->pluck('id'))->whereNotNull('last_seen_batch_id')->distinct()->pluck('last_seen_batch_id')
        );

        return $ids->unique()->values()->all();
    }

    /**
     * كشوف الدفعة: التي أُنشئت فيها والتي أضيفت إليها فواتير منها.
     *
     * @return Collection<int, DistributionSheet>
     */
    public function batchSheets(ImportBatch $batch): Collection
    {
        $touched = (array) $batch->stat('touched_sheet_ids', []);

        return DistributionSheet::with(['assignments.distributor', 'distributor'])
            ->where(fn (Builder $q) => $q->where('batch_id', $batch->id)->orWhereIn('id', $touched))
            ->orderBy('delivery_order_no')
            ->orderBy('id')
            ->get();
    }

    // =====================================================================
    // تعارض "زبون في أكثر من كشف"
    // =====================================================================

    /**
     * زبائن لهم فواتير غير مؤجلة (in_sheet) في كشفين أو أكثر لنفس تاريخ التوزيع.
     *
     * @param  array<int, int>|null  $involvingSheetIds  إظهار التعارضات التي تشمل هذه الكشوف فقط
     * @return array<int, array{customer_id: int, customer_name: string, sheets: array<int, array>}>
     */
    public function conflicts(string $date, ?array $involvingSheetIds = null): array
    {
        $rows = Invoice::query()
            ->join('distribution_sheets as s', 's.id', '=', 'invoices.distribution_sheet_id')
            ->where('invoices.placement', Invoice::IN_SHEET)
            ->whereNotNull('invoices.customer_id')
            ->where('s.format', 'v2')
            ->where('s.status', '!=', 'draft')
            ->whereDate('s.distribution_date', $date)
            ->get([
                'invoices.id', 'invoices.customer_id', 'invoices.customer_name', 'invoices.invoice_number', 'invoices.invoice_amount',
                's.id as sheet_id', 's.delivery_order_no as sheet_order', 's.status as sheet_status', 's.parent_sheet_id', 's.supplement_no',
            ]);

        $result = [];
        foreach ($rows->groupBy('customer_id') as $customerId => $invoices) {
            $bySheet = $invoices->groupBy('sheet_id');
            if ($bySheet->count() < 2) {
                continue;
            }
            // المرحلة 7H: الأصل وملاحقه أسرة واحدة (فواتير الأمر نفسه بعد اكتماله): ليس تعارضاً
            if ($invoices->map(fn ($i) => $i->parent_sheet_id ?: $i->sheet_id)->unique()->count() < 2) {
                continue;
            }
            if ($involvingSheetIds !== null && $bySheet->keys()->intersect($involvingSheetIds)->isEmpty()) {
                continue;
            }
            $result[] = [
                'customer_id' => (int) $customerId,
                'customer_name' => $invoices->first()->customer_name,
                'sheets' => $bySheet->map(fn ($group, $sheetId) => [
                    'sheet_id' => (int) $sheetId,
                    'order' => $group->first()->sheet_order.($group->first()->supplement_no !== null ? ' — ملحق '.$group->first()->supplement_no : ''),
                    'status' => $group->first()->sheet_status,
                    'invoices' => $group->map(fn ($i) => ['id' => $i->id, 'number' => $i->invoice_number, 'amount' => (float) $i->invoice_amount])->values()->all(),
                ])->values()->all(),
            ];
        }

        return $result;
    }

    public function acknowledgeConflicts(ImportBatch $batch, User $user): void
    {
        $this->ensureSheetManager($user);
        $conflicts = $batch->distribution_date
            ? $this->conflicts($batch->distribution_date->toDateString(), $this->batchSheets($batch)->pluck('id')->all())
            : [];

        $batch->update(['conflicts_acknowledged_by' => $user->id, 'conflicts_acknowledged_at' => now()]);

        ActivityLog::record('batch.conflicts_acknowledged', null, [], [
            'conflicts_acknowledged_at' => $batch->conflicts_acknowledged_at->toDateTimeString(),
        ], [
            'batch_id' => $batch->id,
            'customers' => collect($conflicts)->pluck('customer_name')->all(),
        ]);
    }

    // =====================================================================
    // السحب من المشرف
    // =====================================================================

    /**
     * سحب كشف مُرسل إلى "قيد التجهيز": لمسؤول الكشوف إن لم يكن له إسناد ولا نشاط، وإلا للمدير فقط.
     */
    public function recall(DistributionSheet $sheet, User $user): void
    {
        $this->ensureSheetManager($user);

        if (! in_array($sheet->status, ['pending', 'in_progress'], true)) {
            throw ValidationException::withMessages(['recall' => 'لا يمكن سحب كشف في حالته الحالية.']);
        }

        $hasAssignments = $sheet->assignments()->exists();
        $hasActivity = $sheet->hasActivity();
        if (! $this->canRecall($sheet, $user)) {
            throw ValidationException::withMessages(['recall' => 'للكشف إسناد أو نشاط مسجّل (زيارة/دفعة)، فسحبه للمدير فقط.']);
        }

        $old = ['status' => $sheet->status, 'sent_at' => $sheet->sent_at?->toDateTimeString()];
        $sheet->update(['status' => 'preparing', 'sent_at' => null, 'sent_by' => null]);

        ActivityLog::record('sheet.recalled', $sheet, $old, ['status' => 'preparing'], [
            'had_assignments' => $hasAssignments,
            'had_activity' => $hasActivity,
        ]);
    }

    public function canRecall(DistributionSheet $sheet, User $user): bool
    {
        if (! in_array($sheet->status, ['pending', 'in_progress'], true) || ! $sheet->isV2()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return $user->role === Role::SheetManager
            && ! $sheet->assignments()->exists()
            && ! $sheet->hasActivity();
    }

    // =====================================================================
    // الإغلاق كمسودة وإعادة التفعيل
    // =====================================================================

    /**
     * حالات الكشف التي يجوز للمستخدم إغلاقها كمسودة:
     * مسؤول الكشوف: قيد التجهيز فقط. المشرف: بعد الإرسال. المدير: الاثنتان.
     *
     * @return array<int, string>
     */
    public function draftableStatuses(User $user): array
    {
        return match (true) {
            $user->isAdmin() => ['preparing', 'pending', 'in_progress'],
            $user->role === Role::SheetManager => ['preparing'],
            $user->role === Role::Supervisor => ['pending', 'in_progress'],
            default => [],
        };
    }

    /**
     * إغلاق كمسودة: تُفرَّغ فواتير الكشف إلى "بلا كشف" لإعادة توزيعها، وتُحفظ الإسنادات ولا تُحذف.
     */
    public function draft(DistributionSheet $sheet, string $reason, User $user): int
    {
        if (! in_array($sheet->status, $this->draftableStatuses($user), true)) {
            throw ValidationException::withMessages(['reason' => 'لا يمكنك إغلاق هذا الكشف كمسودة في حالته الحالية.']);
        }
        // المرحلة 3: كشف عليه نشاط (زيارة أو دفعة غير ملغاة) لا يُغلق كمسودة إلا من الأدمن
        if (! $user->isAdmin() && $sheet->hasActivity()) {
            throw ValidationException::withMessages(['reason' => 'سُجّلت على هذا الكشف زيارات أو دفعات، فلا يُغلق كمسودة إلا من المدير.']);
        }

        return DB::transaction(function () use ($sheet, $reason, $user) {
            $oldStatus = $sheet->status;

            // الدفعات الخارجية (سجلات تحصيل الموزع) تبقى مع الكشف
            $released = Invoice::where('distribution_sheet_id', $sheet->id)
                ->where(fn (Builder $q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
                ->get(['id', 'invoice_number']);

            Invoice::whereIn('id', $released->pluck('id'))->update([
                'distribution_sheet_id' => null,
                'placement' => Invoice::UNASSIGNED,
                'is_deferred' => false,
                'manual_placement' => false,
            ]);

            $sheet->update([
                'status' => 'draft',
                'status_reason' => trim($reason),
                'status_changed_by' => $user->id,
                'status_changed_at' => now(),
            ]);
            $this->figures->refresh($sheet);

            ActivityLog::record('sheet.drafted', $sheet, ['status' => $oldStatus], [
                'status' => 'draft',
                'reason' => $sheet->status_reason,
            ], [
                'released_invoices' => $released->count(),
            ]);

            // زيارات الكشف لا تتسرب إلى كشف جديد تُوضع فيه الفواتير المُفرَّغة
            foreach (\App\Models\SheetVisit::active()->where('distribution_sheet_id', $sheet->id)->distinct()->pluck('customer_id') as $customerId) {
                $this->visits->detachCustomer($sheet, (int) $customerId, $user, 'أُغلق الكشف كمسودة');
            }

            return $released->count();
        });
    }

    public function reopen(DistributionSheet $sheet, User $user): void
    {
        $oldReason = $sheet->status_reason;
        $sheet->update([
            'status' => 'pending',
            'status_reason' => null,
            'status_changed_by' => $user->id,
            'status_changed_at' => now(),
        ]);

        ActivityLog::record('sheet.reopened', $sheet, ['status' => 'draft', 'reason' => $oldReason], ['status' => 'pending']);
    }

    private function ensureSheetManager(User $user): void
    {
        if (! $user->hasRole(Role::Admin, Role::SheetManager)) {
            abort(403, 'هذا الإجراء لمسؤول الكشوف فقط.');
        }
    }
}
