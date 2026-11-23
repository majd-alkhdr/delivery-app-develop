<?php

namespace App\Services\Finance;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\FinanceExport;
use App\Models\FinanceExportRow;
use App\Models\FinanceSurplusChoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * المالية على مستوى الكشف (المرحلة 5): معاينة التوزيع الحيّة، المانعات، والتصدير للمالية بلقطة ثابتة.
 * التوزيع نفسه في PaymentAllocationEngine وحده.
 */
class FinanceService
{
    public const DEFAULT_TEMPLATE = 'بيد السيد {user} إيصال إيصال مالية كشف رقم {order}يومية ';

    public function __construct(private PaymentAllocationEngine $engine)
    {
    }

    /**
     * قالب بيان المالية كما حُفظ حرفياً (بلا trim).
     */
    public static function template(): string
    {
        $value = Setting::get('finance_statement_template');

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_TEMPLATE;
    }

    /**
     * نص البيان: {user} أسماء المُدخِلين مفصولة بـ « و »، و{order} رقم أمر التسليم (#{id} إن كان فارغاً). لا trim.
     *
     * @param  array<int, string>  $users
     */
    public static function statement(string $template, array $users, DistributionSheet $sheet): string
    {
        $order = $sheet->orderLabel();   // المرحلة 7H: «1580 — ملحق 1» للملحق

        return strtr($template, ['{user}' => implode(' و ', $users), '{order}' => $order]);
    }

    /**
     * سبب منع التوزيع/التصدير على مستوى الكشف، أو null.
     */
    public function sheetBlock(DistributionSheet $sheet): ?string
    {
        return match (true) {
            ! $sheet->isV2() => 'كشف بالصيغة القديمة: للقراءة فقط بلا توزيع ولا تصدير.',
            $sheet->isPreparing() => 'الكشف قيد التجهيز.',
            $sheet->isDraft() => 'الكشف مسودة: لا تصدير للمالية.',
            default => null,
        };
    }

    /**
     * معاينة التوزيع الحيّة للكشف (لا تكتب شيئاً).
     *
     * @return array{customers: Collection, pending: Collection, awaiting_rate: Collection, cost_centers: array, blockers: array, signature: string, totals: array, sheet_block: ?string}
     */
    public function preview(DistributionSheet $sheet): array
    {
        $sheetBlock = $this->sheetBlock($sheet);

        $pending = Payment::active()
            ->where('distribution_sheet_id', $sheet->id)
            ->where('status', 'pending')
            ->with(['enteredBy:id,name', 'customer:id,name,area'])
            ->orderBy('id')
            ->get();

        $invoices = $sheet->isV2() ? $this->engine->sheetInvoices($sheet)->get() : collect();
        $previous = $this->engine->previousAllocatedCents($invoices->pluck('id')->all());
        $choices = FinanceSurplusChoice::where('distribution_sheet_id', $sheet->id)->pluck('cost_center', 'customer_id');

        $customerIds = $pending->pluck('customer_id')->merge($invoices->pluck('customer_id'))->unique()->values();
        $customers = Customer::whereIn('id', $customerIds)->get(['id', 'name', 'area'])->keyBy('id');
        $invoicesByCustomer = $invoices->groupBy('customer_id');
        $paymentsByCustomer = $pending->groupBy('customer_id');

        $rows = $customerIds->map(function ($customerId) use ($customers, $invoicesByCustomer, $paymentsByCustomer, $previous, $choices) {
            $payments = $paymentsByCustomer->get($customerId, collect());
            $result = $this->engine->compute(
                (int) $customerId,
                $invoicesByCustomer->get($customerId, collect())->map(fn ($i) => $this->engine->invoiceInput($i))->all(),
                $payments->map(fn ($p) => $this->engine->paymentInput($p))->all(),
                $previous,
                $choices[$customerId] ?? null,
            );

            return [
                'customer' => $customers->get($customerId),
                'result' => $result,
                'payments' => $payments,
                'users' => $this->userNames($payments),
            ];
        })
            ->sortBy(fn ($row) => [$row['payments']->isEmpty() ? 1 : 0, (string) $row['customer']?->name])
            ->values();

        $awaiting = $pending->filter(fn ($p) => $p->currency === 'USD' && $p->amount_syp === null)->values();
        $costCenters = $invoices->map(fn ($i) => PaymentAllocationEngine::costCenterLabel($i->cost_center_code, $i->cost_center_name))
            ->filter()->unique()->sort()->values()->all();

        $blockers = [];
        if ($sheetBlock !== null) {
            $blockers[] = $sheetBlock;
        }
        if ($pending->isEmpty()) {
            $blockers[] = 'لا دفعات جديدة للتصدير.';
        }
        if ($awaiting->isNotEmpty()) {
            $blockers[] = 'لا يمكن التصدير: '.$awaiting->count().' دفعة دولار بانتظار السعر.';
        }
        $noCenter = $rows->filter(fn ($r) => in_array('surplus_without_cost_center', $r['result']->blockers(), true))->count();
        if ($noCenter > 0) {
            $blockers[] = 'لا يمكن التصدير: فائض بلا مركز كلفة لدى '.$noCenter.' زبون (اختر مركزاً).';
        }
        $noInvoiceCenter = $rows->filter(fn ($r) => in_array('allocation_without_cost_center', $r['result']->blockers(), true))->count();
        if ($noInvoiceCenter > 0) {
            $blockers[] = 'لا يمكن التصدير: مبالغ موزَّعة على فواتير بلا مركز كلفة لدى '.$noInvoiceCenter.' زبون.';
        }

        $paid = $rows->sum(fn ($r) => $r['result']->paidCents);

        // المرحلة 7C (allocation_clipped): مبالغ وُزِّعت سابقاً على فواتير خُفّضت بعد التوزيع؛ يقصّها المحرك وتظهر هنا ولا تختفي
        $clipped = $rows->flatMap(fn ($r) => array_map(fn ($c) => $c + ['customer' => $r['customer']?->name, 'customer_id' => $r['customer']?->id], $r['result']->clippedRows))->values();

        return [
            'customers' => $rows,
            'pending' => $pending,
            'awaiting_rate' => $awaiting,
            'cost_centers' => $costCenters,
            'blockers' => $blockers,
            'clipped' => $clipped,
            'sheet_block' => $sheetBlock,
            'signature' => $this->signature($sheet, $pending, $choices->all(), $invoices),
            'totals' => [
                'paid_syp' => $paid / 100,
                'allocated_syp' => $rows->sum(fn ($r) => $r['result']->allocatedCents()) / 100,
                'surplus_syp' => $rows->sum(fn ($r) => $r['result']->surplusCents) / 100,
                'unpaid_syp' => $rows->sum(fn ($r) => $r['result']->totalUnpaidCents()) / 100,
            ],
        ];
    }

    /**
     * اختيار المحاسب لمركز كلفة فائض زبون (من مراكز هذا الكشف فقط).
     */
    public function setSurplusCostCenter(DistributionSheet $sheet, Customer $customer, string $costCenter, User $user): void
    {
        $centers = $this->preview($sheet)['cost_centers'];
        if (! in_array($costCenter, $centers, true)) {
            throw ValidationException::withMessages(['cost_center' => 'اختر مركز كلفة من مراكز هذا الكشف.']);
        }

        $existing = FinanceSurplusChoice::where('distribution_sheet_id', $sheet->id)->where('customer_id', $customer->id)->first();
        $old = $existing?->cost_center;
        if ($old === $costCenter) {
            return;
        }

        FinanceSurplusChoice::updateOrCreate(
            ['distribution_sheet_id' => $sheet->id, 'customer_id' => $customer->id],
            ['cost_center' => $costCenter, 'chosen_by' => $user->id],
        );

        ActivityLog::record('finance.surplus_cost_center_set', $sheet, ['cost_center' => $old], ['cost_center' => $costCenter], [
            'sheet_id' => $sheet->id,
            'customer_id' => $customer->id,
            'customer' => $customer->name,
        ]);
    }

    /**
     * التصدير للمالية: transaction واحد بقفل الكشف؛ لقطة ثابتة، والدفعات تصبح exported.
     * $signature: توقيع المعاينة التي أكدها المحاسب (إن تغيّرت البيانات بعدها يُرفض).
     */
    public function export(DistributionSheet $sheet, User $user, ?string $signature = null): FinanceExport
    {
        return DB::transaction(function () use ($sheet, $user, $signature) {
            $locked = DistributionSheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            $preview = $this->preview($locked);

            if ($preview['pending']->isEmpty()) {
                throw ValidationException::withMessages(['export' => 'لا دفعات جديدة للتصدير.']);
            }
            if ($preview['blockers'] !== []) {
                throw ValidationException::withMessages(['export' => $preview['blockers']]);
            }
            if ($signature !== null && ! hash_equals($preview['signature'], $signature)) {
                throw ValidationException::withMessages(['export' => 'تغيّرت الدفعات أو الفواتير منذ المعاينة. راجع المعاينة ثم أكّد من جديد.']);
            }

            $template = self::template();
            $pending = $preview['pending'];
            $now = now();

            $export = FinanceExport::create([
                'kind' => FinanceExport::KIND_SHEET,
                'scope_key' => FinanceExport::sheetScope($locked->id),
                'distribution_sheet_id' => $locked->id,
                'sequence' => (int) FinanceExport::where('scope_key', FinanceExport::sheetScope($locked->id))->max('sequence') + 1,
                'exported_by' => $user->id,
                'exported_at' => $now,
                'payments_count' => $pending->count(),
                'total_syp' => round($pending->sum(fn ($p) => (float) $p->amount_syp), 2),
                'total_usd' => round($pending->where('currency', 'USD')->sum(fn ($p) => (float) $p->amount), 2),
                'surplus_syp' => $preview['totals']['surplus_syp'],
                'statement_template' => $template,
                'template_signature' => hash('sha256', $template),
            ]);

            $importRows = [];
            $receiptRows = [];
            foreach ($preview['customers'] as $row) {
                /** @var AllocationResult $result */
                $result = $row['result'];
                if (! $result->hasPayments()) {
                    continue;
                }
                $name = (string) $row['customer']->name;
                $statement = self::statement($template, $row['users'], $locked);

                foreach ($result->costCenterRows as $center) {
                    $importRows[] = ['allocation', $row['customer']->id, $name, $statement, $center['cost_center'], $center['amount']];
                }
                if ($result->surplusCents > 0) {
                    $importRows[] = ['surplus', $row['customer']->id, $name, $statement, $result->surplusCostCenter, $result->surplus()];
                }

                foreach ($result->invoiceRows as $invoice) {
                    PaymentAllocation::create([
                        'source' => PaymentAllocation::SOURCE_DISTRIBUTOR,
                        'finance_export_id' => $export->id,
                        'distribution_sheet_id' => $locked->id,
                        'customer_id' => $row['customer']->id,
                        'invoice_id' => $invoice['invoice_id'],
                        'invoice_key' => $invoice['invoice_key'],
                        'invoice_number' => $invoice['invoice_number'],
                        'series' => $invoice['series'],
                        'cost_center' => $invoice['cost_center'],
                        'allocation_group' => $invoice['group'],
                        'invoice_total' => $invoice['total'],
                        'remaining_before' => $invoice['remaining'],
                        'allocated' => $invoice['allocated'],
                        'unpaid' => $invoice['unpaid'],
                        'status' => $invoice['status'],
                    ]);
                }

                foreach ($row['payments'] as $payment) {
                    $receiptRows[] = [
                        'customer_id' => $row['customer']->id,
                        'customer_name' => $name,
                        'statement' => self::statement($template, array_filter([$payment->enteredBy?->name]), $locked),
                        'amount' => (float) $payment->amount_syp,
                        'payment_id' => $payment->id,
                        'currency' => $payment->currency,
                        'amount_original' => (float) $payment->amount,
                        'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
                        'receipt_number' => $payment->receipt_number,
                    ];
                }
            }

            // ملف الاستيراد: بترتيب اسم الزبون ثم مركز الكلفة (والفائض بعد صفوف التوزيع)
            usort($importRows, fn ($a, $b) => [$a[2], (string) $a[4], $a[0] === 'surplus'] <=> [$b[2], (string) $b[4], $b[0] === 'surplus']);
            foreach ($importRows as $i => [$type, $customerId, $name, $statement, $center, $amount]) {
                if ($center === null) {
                    throw ValidationException::withMessages(['export' => 'مبلغ بلا مركز كلفة.']);
                }
                FinanceExportRow::create([
                    'finance_export_id' => $export->id, 'row_type' => $type, 'sort' => $i + 1, 'customer_id' => $customerId,
                    'customer_name' => $name, 'statement' => $statement, 'cost_center' => $center, 'amount' => $amount,
                ]);
            }
            usort($receiptRows, fn ($a, $b) => [$a['customer_name'], $a['payment_id']] <=> [$b['customer_name'], $b['payment_id']]);
            foreach ($receiptRows as $i => $receipt) {
                FinanceExportRow::create($receipt + ['finance_export_id' => $export->id, 'row_type' => 'receipt', 'sort' => $i + 1]);
            }

            // اتساق: مجموع الملف الرباعي = مجموع الدفعات
            $importCents = array_sum(array_map(fn ($r) => PaymentAllocationEngine::toCents($r[5]), $importRows));
            $paymentCents = $pending->sum(fn ($p) => PaymentAllocationEngine::toCents($p->amount_syp));
            if ($importCents !== $paymentCents) {
                throw new \LogicException("مجموع ملف الاستيراد ({$importCents}) لا يطابق الدفعات ({$paymentCents}).");
            }

            $updated = Payment::whereIn('id', $pending->pluck('id'))
                ->where('status', 'pending')
                ->whereNull('voided_at')
                ->update(['status' => 'exported', 'exported_at' => $now, 'finance_export_id' => $export->id]);
            if ($updated !== $pending->count()) {
                throw ValidationException::withMessages(['export' => 'تغيّرت الدفعات أثناء التصدير. أعد المحاولة.']);
            }

            if ($preview['clipped']->isNotEmpty()) {
                ActivityLog::record('finance.allocation_clipped', $export, [
                    'previous_allocated' => $preview['clipped']->mapWithKeys(fn ($c) => [$c['invoice_number'] => $c['previous_cents'] / 100])->all(),
                ], [
                    'invoice_total' => $preview['clipped']->mapWithKeys(fn ($c) => [$c['invoice_number'] => $c['total_cents'] / 100])->all(),
                    'clipped' => $preview['clipped']->mapWithKeys(fn ($c) => [$c['invoice_number'] => $c['clipped']])->all(),
                ], [
                    'sheet_id' => $locked->id,
                    'allocation_clipped' => true,
                    'total_clipped' => $preview['clipped']->sum('clipped_cents') / 100,
                    'note' => 'وُزِّع سابقاً على هذه الفواتير أكثر من قيمتها الحالية بعد تعديل لاحق؛ الزائد لا يُخصم من الدفعات الجديدة ويظهر فائضاً في ذمم المندوب.',
                ]);
            }

            ActivityLog::record('finance.exported', $export, [], [
                'sequence' => $export->sequence,
                'payments_count' => $export->payments_count,
                'total_syp' => (float) $export->total_syp,
                'total_usd' => (float) $export->total_usd,
                'surplus_syp' => (float) $export->surplus_syp,
            ], [
                'sheet_id' => $locked->id,
                'delivery_order_no' => $locked->delivery_order_no,
            ]);

            return $export;
        });
    }

    /**
     * أسماء من أدخلوا دفعات الزبون، بترتيب أول دفعة، بلا تكرار.
     *
     * @return array<int, string>
     */
    private function userNames(Collection $payments): array
    {
        return $payments->sortBy('id')->map(fn ($p) => $p->enteredBy?->name)->filter()->unique()->values()->all();
    }

    private function signature(DistributionSheet $sheet, Collection $pending, array $choices, Collection $invoices): string
    {
        ksort($choices);

        return hash('sha256', json_encode([
            $sheet->id,
            $pending->map(fn ($p) => [$p->id, (string) $p->amount_syp])->values()->all(),
            $choices,
            // المرحلة 7C: القيمة الفعالة (تعديل لاحق يدوي يغيّر التوقيع)
            $invoices->map(fn ($i) => [$i->id, (string) $i->effectiveAmount(), (string) $i->cost_center_code, (string) $i->cost_center_name])->values()->all(),
            self::template(),
        ], JSON_UNESCAPED_UNICODE));
    }
}
