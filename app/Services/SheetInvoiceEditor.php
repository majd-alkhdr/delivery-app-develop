<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\SheetAssignment;
use App\Models\User;
use App\Services\Import\InvoiceReconciler;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * كل تعديل على مكان الفواتير يمر من هنا (داخل transaction، مع التحقق من manageInvoices):
 * (المرحلة 6B: وكل تعديل على كشف pending/in_progress يُنشئ إشعاراً ويزيد revision في الـtransaction نفسه)
 * وضع فاتورة في كشف، نقلها، إخراجها، استبعادها، إلغاؤها، تغيير منطقتها، وتطبيق اقتراحات التغيير.
 */
class SheetInvoiceEditor
{
    public function __construct(
        private SheetFiguresService $figures,
        private SheetActivityGuard $guard,
        private InvoiceReconciler $reconciler,
        private VisitService $visits,
        private SheetChangeNotifier $notifier,
    ) {
    }

    /**
     * وضع فاتورة في كشف: من "بلا كشف" أو من كشف آخر، مع تغيير منطقتها اختيارياً.
     */
    public function place(Invoice $invoice, DistributionSheet $sheet, User $user, ?string $areaKey = null): void
    {
        if ($invoice->placement === Invoice::IN_SHEET && $invoice->distribution_sheet_id) {
            $this->move($invoice, $sheet, $user);
            if ($areaKey !== null && $areaKey !== '') {
                $this->changeArea($invoice->fresh(), $areaKey, $user);
            }

            return;
        }

        $this->ensureUnplacedEditable($invoice, $user, [Invoice::UNASSIGNED]);
        $this->authorizeSheet($sheet, $user);

        DB::transaction(function () use ($invoice, $sheet, $user, $areaKey) {
            $before = $this->snapshot($invoice);
            $invoice->placement = Invoice::IN_SHEET;
            $invoice->distribution_sheet_id = $sheet->id;
            $invoice->manual_placement = true;
            if ($areaKey !== null && $areaKey !== '') {
                $this->applyArea($invoice, $areaKey);
            }
            $invoice->save();

            $this->figures->refresh($sheet);
            $this->notifier->notify($sheet, 'added', $invoice, $user);
            ActivityLog::record('invoice.placed', $invoice, $before, $this->snapshot($invoice), ['by_role' => $user->role->value]);
        });
    }

    /**
     * نقل فاتورة من كشفها إلى كشف آخر.
     */
    public function move(Invoice $invoice, DistributionSheet $target, User $user): void
    {
        $source = $this->sheetOf($invoice);
        if (! $source || $invoice->placement !== Invoice::IN_SHEET) {
            throw ValidationException::withMessages(['invoice' => 'الفاتورة ليست في كشف.']);
        }
        if ($source->id === $target->id) {
            return;
        }
        $this->authorizeSheet($source, $user);
        $this->authorizeSheet($target, $user);
        $this->guardActivity($invoice, $source, $user);

        DB::transaction(function () use ($invoice, $source, $target, $user) {
            $before = $this->snapshot($invoice);
            $invoice->distribution_sheet_id = $target->id;
            $invoice->manual_placement = true;
            $invoice->save();

            $this->figures->refresh($source);
            $this->figures->refresh($target);
            // لا تتسرب زيارة الزبون في الكشف المصدر إن خرجت منه آخر فواتيره
            if ($invoice->customer_id) {
                $this->visits->detachCustomer($source, $invoice->customer_id, $user, 'نُقلت آخر فواتير الزبون إلى كشف آخر');
            }
            $this->notifier->notify($source, 'moved_out', $invoice, $user);
            $this->notifier->notify($target, 'moved_in', $invoice, $user);
            ActivityLog::record('invoice.moved', $invoice, $before, $this->snapshot($invoice), ['by_role' => $user->role->value]);
        });
    }

    /**
     * إخراج فاتورة من كشفها إلى "بلا كشف" (السبب إلزامي).
     */
    public function remove(Invoice $invoice, string $reason, User $user): void
    {
        $this->requireReason($reason);
        $source = $this->sheetOf($invoice);
        if (! $source || $invoice->placement !== Invoice::IN_SHEET) {
            throw ValidationException::withMessages(['invoice' => 'الفاتورة ليست في كشف.']);
        }
        $this->authorizeSheet($source, $user);
        $this->guardActivity($invoice, $source, $user);

        DB::transaction(function () use ($invoice, $source, $reason, $user) {
            $before = $this->snapshot($invoice);
            $invoice->placement = Invoice::UNASSIGNED;
            $invoice->distribution_sheet_id = null;
            $invoice->manual_placement = true;
            $invoice->save();

            $this->figures->refresh($source);
            if ($invoice->customer_id) {
                $this->visits->detachCustomer($source, $invoice->customer_id, $user, 'أُخرجت آخر فواتير الزبون من الكشف');
            }
            $this->notifier->notify($source, 'removed', $invoice, $user);
            ActivityLog::record('invoice.removed', $invoice, $before, $this->snapshot($invoice) + ['reason' => trim($reason)]);
        });
    }

    /**
     * استبعاد فاتورة "بلا كشف" (السبب إلزامي).
     */
    public function exclude(Invoice $invoice, string $reason, User $user): void
    {
        $this->requireReason($reason);
        $this->ensureUnplacedEditable($invoice, $user, [Invoice::UNASSIGNED]);

        DB::transaction(function () use ($invoice, $reason) {
            $before = $this->snapshot($invoice);
            $invoice->placement = Invoice::EXCLUDED;
            $invoice->exclusion_reason = trim($reason);
            $invoice->save();
            ActivityLog::record('invoice.excluded', $invoice, $before, $this->snapshot($invoice) + ['reason' => $invoice->exclusion_reason]);
        });
    }

    // =====================================================================
    // المرحلة 7F: الإجراءات الجماعية بالقواعد نفسها للإجراء الفردي (الدور، الحالة المسموحة، السبب)
    // وسجل لكل فاتورة (قبل/بعد + السبب + bulk_operation_id)، بعدد استعلامات ثابت لكل 500 فاتورة
    // =====================================================================

    /** استبعاد جماعي: كما exclude() (من «بلا كشف» فقط، سبب إلزامي) */
    public function excludeMany(Collection $invoices, string $reason, User $user, string $operationId): int
    {
        $this->requireReason($reason);

        return $this->bulkPlacement($invoices, $user, Invoice::UNASSIGNED, Invoice::EXCLUDED, 'invoice.excluded', $operationId,
            ['exclusion_reason' => trim($reason)], ['reason' => trim($reason)]);
    }

    /** إلغاء جماعي لطلبيات مؤجلة: كما cancel() (من «مؤجلة» فقط، سبب إلزامي) */
    public function cancelMany(Collection $invoices, string $reason, User $user, string $operationId): int
    {
        $this->requireReason($reason);
        $count = $this->bulkPlacement($invoices, $user, Invoice::DEFERRED, Invoice::CANCELLED, 'invoice.cancelled', $operationId,
            ['cancelled_reason' => trim($reason)], ['reason' => trim($reason)]);
        $this->refreshCustomersSheets($invoices->pluck('customer_id')->all());

        return $count;
    }

    /** إعادة إدراج جماعية: كما restore() (من «مستبعدة» إلى «بلا كشف») */
    public function restoreMany(Collection $invoices, User $user, string $operationId): int
    {
        return $this->bulkPlacement($invoices, $user, Invoice::EXCLUDED, Invoice::UNASSIGNED, 'invoice.restored', $operationId,
            ['exclusion_reason' => null], [], 'exclusion_reason');
    }

    /** إعادة طلبيات ملغاة إلى «مؤجلة» (عكس cancelMany؛ لا إجراء فردي مماثل قبل 7F) */
    public function uncancelMany(Collection $invoices, User $user, string $operationId): int
    {
        $count = $this->bulkPlacement($invoices, $user, Invoice::CANCELLED, Invoice::DEFERRED, 'invoice.uncancelled', $operationId,
            ['cancelled_reason' => null], [], 'cancelled_reason');
        $this->refreshCustomersSheets($invoices->pluck('customer_id')->all());

        return $count;
    }

    private function bulkPlacement(Collection $invoices, User $user, string $from, string $to, string $action, string $operationId,
        array $set, array $afterExtra, ?string $beforeReasonColumn = null): int
    {
        if (! $user->hasRole(Role::Admin, Role::SheetManager)) {
            abort(403, 'غير مصرح لك بتعديل الفواتير.');
        }
        $wrong = $invoices->reject(fn ($i) => $i->placement === $from);
        if ($wrong->isNotEmpty()) {
            throw ValidationException::withMessages(['invoice' => 'هذا الإجراء غير متاح لفواتير في حالة أخرى: '.$wrong->take(5)->pluck('invoice_number')->implode('، ')]);
        }

        return DB::transaction(function () use ($invoices, $to, $action, $operationId, $set, $afterExtra, $beforeReasonColumn) {
            $entries = [];
            foreach ($invoices as $invoice) {
                $before = $this->snapshot($invoice) + ($beforeReasonColumn ? ['reason' => $invoice->{$beforeReasonColumn}] : []);
                $invoice->placement = $to;
                $after = $this->snapshot($invoice) + $afterExtra;
                $entries[] = [$action, $invoice, $before, $after, ['bulk_operation_id' => $operationId]];
            }
            foreach (array_chunk($invoices->pluck('id')->all(), \App\Support\BulkWrite::CHUNK) as $chunk) {
                // is_deferred متسق مع placement كما يفعل حدث saving في Invoice
                Invoice::whereIn('id', $chunk)->update($set + ['placement' => $to, 'is_deferred' => $to === Invoice::DEFERRED, 'updated_at' => now()]);
            }
            foreach (array_chunk($entries, \App\Support\BulkWrite::CHUNK) as $chunk) {
                ActivityLog::recordMany($chunk);
            }

            return count($entries);
        });
    }

    private function refreshCustomersSheets(array $customerIds): void
    {
        $customerIds = array_values(array_unique(array_filter($customerIds)));
        foreach (array_chunk($customerIds, \App\Support\BulkWrite::CHUNK) as $chunk) {
            $this->figures->refreshMany(
                Invoice::whereIn('customer_id', $chunk)->whereNotNull('distribution_sheet_id')
                    ->whereHas('distributionSheet', fn ($q) => $q->whereIn('status', DistributionSheet::EDITABLE_STATUSES))
                    ->distinct()->pluck('distribution_sheet_id')
            );
        }
    }

    /**
     * إعادة فاتورة مستبعدة إلى "بلا كشف".
     */
    public function restore(Invoice $invoice, User $user): void
    {
        $this->ensureUnplacedEditable($invoice, $user, [Invoice::EXCLUDED]);

        DB::transaction(function () use ($invoice) {
            $before = $this->snapshot($invoice) + ['reason' => $invoice->exclusion_reason];
            $invoice->placement = Invoice::UNASSIGNED;
            $invoice->exclusion_reason = null;
            $invoice->save();
            ActivityLog::record('invoice.restored', $invoice, $before, $this->snapshot($invoice));
        });
    }

    /**
     * اعتبار طلبية مؤجلة ملغاة (لم تعد في ملف المحاسبة). تخرج من حساب الرصيد المستحق.
     */
    public function cancel(Invoice $invoice, string $reason, User $user): void
    {
        $this->requireReason($reason);
        $this->ensureUnplacedEditable($invoice, $user, [Invoice::DEFERRED]);

        DB::transaction(function () use ($invoice, $reason) {
            $before = $this->snapshot($invoice);
            $invoice->placement = Invoice::CANCELLED;
            $invoice->cancelled_reason = trim($reason);
            $invoice->save();
            ActivityLog::record('invoice.cancelled', $invoice, $before, $this->snapshot($invoice) + ['reason' => $invoice->cancelled_reason]);
        });

        $this->refreshCustomerSheets($invoice->customer_id);
    }

    /**
     * إبقاء طلبية مؤجلة لم تظهر في ملف الدفعة (تُعتبر "مرئية" في هذه الدفعة).
     */
    public function keepDeferred(Invoice $invoice, string $batchId, User $user): void
    {
        $this->ensureUnplacedEditable($invoice, $user, [Invoice::DEFERRED]);
        $invoice->update(['last_seen_batch_id' => $batchId]);
        ActivityLog::record('invoice.kept', $invoice, [], ['placement' => $invoice->placement], ['batch_id' => $batchId]);
    }

    /**
     * تغيير منطقة هذه الفاتورة وحدها (تُحفظ area_overridden).
     */
    public function changeArea(Invoice $invoice, string $areaKey, User $user): void
    {
        $sheet = $this->sheetOf($invoice);
        if ($sheet) {
            $this->authorizeSheet($sheet, $user);
            $this->guardActivity($invoice, $sheet, $user);
        } else {
            $this->ensureUnplacedEditable($invoice, $user, [Invoice::UNASSIGNED, Invoice::DEFERRED]);
        }

        DB::transaction(function () use ($invoice, $areaKey, $sheet, $user) {
            $before = $this->snapshot($invoice);
            [$oldKey, $oldLabel] = [$invoice->area_key, $invoice->area ?: 'بلا منطقة'];
            $this->applyArea($invoice, $areaKey);
            $invoice->save();

            if ($sheet) {
                $this->figures->refresh($sheet);
                // إشعار لموزع المنطقة القديمة والجديدة
                $this->notifier->notify($sheet, 'area_changed', $invoice, $user, $oldKey, $oldLabel);
                if ($oldKey !== $invoice->area_key) {
                    $this->notifier->notify($sheet, 'area_changed', $invoice, $user);
                }
            }
            ActivityLog::record('invoice.area_changed', $invoice, $before, $this->snapshot($invoice));
        });
    }

    /**
     * تطبيق اقتراح تغيير: يستبدل بيانات الفاتورة وأسطرها. ممنوع إن كان على زبونها نشاط (إلا للأدمن).
     */
    public function applyProposal(InvoiceChangeProposal $proposal, User $user): void
    {
        $this->ensurePendingProposal($proposal);
        $invoice = $proposal->invoice;
        $sheet = $this->sheetOf($invoice);
        if ($sheet) {
            $this->authorizeSheet($sheet, $user);
            $this->guardActivity($invoice, $sheet, $user);
        } elseif (! $user->hasRole(Role::Admin, Role::SheetManager)) {
            abort(403);
        }

        DB::transaction(function () use ($proposal, $invoice, $sheet, $user) {
            $before = $this->snapshot($invoice) + ['amount' => (float) $invoice->invoice_amount, 'delivery_order_no' => $invoice->delivery_order_no, 'content_hash' => $invoice->content_hash];
            $this->reconciler->applyPayload($invoice, $proposal->payload);
            $invoice->refresh();

            $proposal->update(['status' => InvoiceChangeProposal::APPLIED, 'decided_by' => $user->id, 'decided_at' => now()]);

            if ($sheet) {
                $this->figures->refresh($sheet);
                $this->notifier->notify($sheet, 'updated', $invoice, $user);
            }
            ActivityLog::record('invoice.change_applied', $invoice, $before,
                $this->snapshot($invoice) + ['amount' => (float) $invoice->invoice_amount, 'delivery_order_no' => $invoice->delivery_order_no, 'content_hash' => $invoice->content_hash],
                ['proposal_id' => $proposal->id, 'batch_id' => $proposal->batch_id]);
        });
    }

    /**
     * المرحلة 7A: تطبيق عنصر دفعة رفع على فاتورة (القرار والصلاحية والحارس في ImportReviewService).
     * - payload: حمولة الملف الجديدة (null = بلا تغيير في البيانات). تُحفظ نسخة قديمة في invoice_revisions قبل الكتابة.
     * - placement: الموقع الجديد (null = تبقى في مكانها) مع sheet وmanual.
     * يعيد كشوف الأرقام والإشعارات (الموزع يُنبَّه في الكشوف المنشورة) ويعيد القيم قبل وبعد.
     *
     * @param  array{payload?: ?array, placement?: ?string, sheet?: ?DistributionSheet, manual?: bool, clear_cancel?: bool, batch_id?: ?string, item_id?: ?int, detach_reason?: string}  $plan
     * @return array{before: array, after: array}
     */
    public function applyImported(Invoice $invoice, array $plan, User $user): array
    {
        $source = $this->sheetOf($invoice);
        $before = $this->importSnapshot($invoice);

        if (! empty($plan['payload'])) {
            \App\Models\InvoiceRevision::snapshot($invoice, $plan['batch_id'] ?? null, $plan['item_id'] ?? null, $user->id);
            $this->reconciler->applyPayload($invoice, $plan['payload']);
            $invoice->refresh();
        }

        $moved = false;
        if (($plan['placement'] ?? null) !== null) {
            $sheetId = $plan['placement'] === Invoice::IN_SHEET ? $plan['sheet']?->id : null;
            $moved = $plan['placement'] !== $invoice->placement || (int) $sheetId !== (int) $invoice->distribution_sheet_id;
            $invoice->placement = $plan['placement'];
            $invoice->distribution_sheet_id = $sheetId;
            $invoice->manual_placement = (bool) ($plan['manual'] ?? false);
            if (! empty($plan['clear_cancel'])) {
                $invoice->cancelled_reason = null;
            }
            // المرحلة 7H: دخلت كشفاً مُرسلاً (pending/in_progress) من خارج أي كشف ⇒ «أُضيفت بعد الإرسال»
            if ($moved && ! $source && $sheetId && in_array($plan['sheet']->status, SheetChangeNotifier::NOTIFY_STATUSES, true)) {
                $invoice->added_after_send_at = now();
                $invoice->added_after_send_batch_id = $plan['batch_id'] ?? null;
            }
            $invoice->save();
        }

        $target = $this->sheetOf($invoice);
        foreach (collect([$source, $target])->filter()->unique('id') as $sheet) {
            $this->figures->refresh($sheet);
        }

        if ($moved) {
            if ($source && $invoice->customer_id) {
                $this->visits->detachCustomer($source, $invoice->customer_id, $user, $plan['detach_reason'] ?? 'خرجت آخر فواتير الزبون من الكشف بتطبيق تغيير من ملف');
            }
            if ($source) {
                $this->notifier->notify($source, $target ? 'moved_out' : 'removed', $invoice, $user);
            }
            if ($target) {
                $this->notifier->notify($target, $source ? 'moved_in' : 'added', $invoice, $user);
            }
        } elseif (! empty($plan['payload']) && $target) {
            $this->notifier->notify($target, 'updated', $invoice, $user);
        }

        $after = $this->importSnapshot($invoice->fresh());
        if ($invoice->added_after_send_at !== null && $invoice->wasChanged('added_after_send_at')) {
            $after['added_after_send'] = true;
        }

        return ['before' => $before, 'after' => $after];
    }

    /**
     * @return array<string, mixed> القيم قبل/بعد لسجل تطبيق عنصر الدفعة (عدد الأسطر يُمرَّر إن كان معروفاً، 7E2)
     */
    public function importSnapshot(Invoice $invoice, ?int $linesCount = null): array
    {
        return $this->snapshot($invoice) + [
            'amount' => (float) $invoice->invoice_amount,
            'delivery_order_no' => $invoice->delivery_order_no,
            'delivery_date' => $invoice->delivery_date?->toDateString(),
            'cost_center' => trim(($invoice->cost_center_code ?? '').'-'.($invoice->cost_center_name ?? ''), '-') ?: null,
            'lines_count' => $linesCount ?? $invoice->lines()->count(),
            'content_hash' => $invoice->content_hash,
        ];
    }

    /**
     * هل للزبون نشاط (زيارة أو دفعة) في هذا الكشف؟ (الحارس نفسه دون تغيير في قاعدته)
     */
    public function customerHasActivity(?DistributionSheet $sheet, ?int $customerId): bool
    {
        return $this->guard->customerHasActivity($sheet, $customerId);
    }

    public function rejectProposal(InvoiceChangeProposal $proposal, User $user): void
    {
        $this->ensurePendingProposal($proposal);
        if (! $user->hasRole(Role::Admin, Role::SheetManager)) {
            abort(403);
        }

        $proposal->update(['status' => InvoiceChangeProposal::REJECTED, 'decided_by' => $user->id, 'decided_at' => now()]);
        ActivityLog::record('invoice.change_rejected', $proposal->invoice, ['status' => InvoiceChangeProposal::PENDING], ['status' => InvoiceChangeProposal::REJECTED], [
            'proposal_id' => $proposal->id,
            'batch_id' => $proposal->batch_id,
        ]);
    }

    // =====================================================================

    /**
     * @return array<string, mixed> قيم قبل/بعد للسجل: الكشف والمنطقة والحالة
     */
    private function snapshot(Invoice $invoice): array
    {
        return [
            'invoice_number' => $invoice->invoice_number,
            'sheet_id' => $invoice->distribution_sheet_id,
            'placement' => $invoice->placement,
            'area_key' => $invoice->area_key,
        ];
    }

    private function sheetOf(Invoice $invoice): ?DistributionSheet
    {
        return $invoice->distribution_sheet_id ? DistributionSheet::find($invoice->distribution_sheet_id) : null;
    }

    private function authorizeSheet(DistributionSheet $sheet, User $user): void
    {
        if (! Gate::forUser($user)->allows('manageInvoices', $sheet)) {
            throw ValidationException::withMessages(['invoice' => 'لا يمكن تعديل فواتير هذا الكشف في حالته الحالية ('.$sheet->title().').']);
        }
    }

    private function ensureUnplacedEditable(Invoice $invoice, User $user, array $placements): void
    {
        if (! $user->hasRole(Role::Admin, Role::SheetManager)) {
            abort(403, 'غير مصرح لك بتعديل الفواتير.');
        }
        if (! in_array($invoice->placement, $placements, true)) {
            throw ValidationException::withMessages(['invoice' => 'هذا الإجراء غير متاح للفاتورة في حالتها الحالية ('.$invoice->placementLabel().').']);
        }
    }

    private function guardActivity(Invoice $invoice, DistributionSheet $sheet, User $user): void
    {
        if (! $user->isAdmin() && $this->guard->customerHasActivity($sheet, $invoice->customer_id, $invoice->customer_name)) {
            throw ValidationException::withMessages(['invoice' => "سُجّلت زيارة أو دفعة للزبون \"{$invoice->customer_name}\" في هذا الكشف، فلا يمكن تعديل فاتورته إلا من المدير."]);
        }
    }

    private function requireReason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'السبب إلزامي (3 أحرف على الأقل).']);
        }
    }

    private function ensurePendingProposal(InvoiceChangeProposal $proposal): void
    {
        if (! $proposal->isPending()) {
            throw ValidationException::withMessages(['proposal' => 'تم البت في هذا الاقتراح مسبقاً.']);
        }
    }

    private function applyArea(Invoice $invoice, string $areaKey): void
    {
        if ($areaKey === SheetAssignment::NO_AREA) {
            $invoice->area = null;
        } else {
            $label = Customer::where('area_normalized', $areaKey)->value('area');
            if ($label === null) {
                throw ValidationException::withMessages(['area_key' => 'المنطقة المختارة غير معروفة.']);
            }
            $invoice->area = $label;
        }
        $invoice->area_key = $areaKey;
        $invoice->area_overridden = true;
    }

    private function refreshCustomerSheets(?int $customerId): void
    {
        if (! $customerId) {
            return;
        }
        $this->figures->refreshMany(
            Invoice::where('customer_id', $customerId)
                ->whereNotNull('distribution_sheet_id')
                ->whereHas('distributionSheet', fn ($q) => $q->whereIn('status', DistributionSheet::EDITABLE_STATUSES))
                ->distinct()
                ->pluck('distribution_sheet_id')
        );
    }
}
