<?php

namespace App\Services\Finance;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\FinanceExport;
use App\Models\FinanceExportRow;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تصدير دفعات المندوبين للمالية (المرحلة 6-G): منفصل عن الكشوف، بالقالب الرباعي نفسه،
 * كل المندوبين المختارين في ملف واحد، والتسلسل «م-{n}» بقفل صف النطاق.
 * - المشمول افتراضياً: الدفعات المعلّقة غير الملغاة وغير «بحاجة مراجعة»؛ «بحاجة مراجعة» تُشمل يدوياً بسبب إلزامي.
 * - مركز الكلفة في الملف = cost_center_label للمندوب، وكل المبلغ لمركزه (لا فائض ولا مراكز أخرى).
 */
class RepFinanceService
{
    public const DEFAULT_TEMPLATE = 'بيد السيد {user} إيصال إيصال مالية كشف رقم {order}يومية ';

    public function __construct(private RepReceivableService $receivables)
    {
    }

    /**
     * قالب بيان المندوبين كما حُفظ حرفياً (بلا trim).
     */
    public static function template(): string
    {
        $value = Setting::get('finance_rep_statement_template');

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_TEMPLATE;
    }

    /**
     * {user} = cost_center_label للمندوب، {order} = م-{seq}. لا trim.
     */
    public static function statement(string $template, string $repLabel, int $sequence): string
    {
        return strtr($template, ['{user}' => $repLabel, '{order}' => 'م-'.$sequence]);
    }

    /**
     * المندوبون بمركز كلفة مضبوط.
     */
    public function reps(): Collection
    {
        return User::where('role', Role::Rep)->orderBy('name')->get()->filter->hasCostCenter()->values();
    }

    /**
     * المعاينة الحيّة (لا تكتب شيئاً).
     *
     * @param  array<int, int>|null  $repIds  null = كل المندوبين
     * @param  array<int, string>  $overrides  payment_id => سبب شمول دفعة «بحاجة مراجعة»
     */
    public function preview(?array $repIds, array $overrides = []): array
    {
        $reps = $this->reps()->when($repIds !== null, fn ($c) => $c->whereIn('id', $repIds))->keyBy('id');

        $pending = Payment::active()
            ->where('source', 'rep')
            ->where('status', 'pending')
            ->whereIn('entered_by', $reps->keys()->all() ?: [0])
            ->with(['customer:id,name,area', 'enteredBy:id,name,cost_center_code,cost_center_label', 'image'])
            ->orderBy('collected_at')->orderBy('id')
            ->get();

        $overrides = array_filter($overrides, fn ($r) => trim((string) $r) !== '');
        $included = $pending->filter(fn ($p) => $p->review_status !== 'needs_review' || isset($overrides[$p->id]))->values();
        $needsReview = $pending->where('review_status', 'needs_review')->values();
        $awaiting = $included->filter(fn ($p) => $p->currency === 'USD' && $p->amount_syp === null)->values();

        $blockers = [];
        if ($included->isEmpty()) {
            $blockers[] = 'لا دفعات جديدة للتصدير.';
        }
        if ($awaiting->isNotEmpty()) {
            $blockers[] = 'لا يمكن التصدير: '.$awaiting->count().' دفعة دولار بانتظار السعر.';
        }

        $groups = $included->groupBy(fn ($p) => $p->entered_by.'|'.$p->customer_id)
            ->map(function (Collection $payments) use ($reps) {
                $first = $payments->first();
                $rep = $reps[$first->entered_by];

                return [
                    'rep' => $rep,
                    'customer' => $first->customer,
                    'payments' => $payments->values(),
                    'cents' => $payments->sum(fn ($p) => PaymentAllocationEngine::toCents($p->amount_syp)),
                ];
            })
            ->sortBy(fn ($g) => [(string) $g['rep']->name, (string) $g['customer']?->name])
            ->values();

        return [
            'reps' => $reps->values(),
            'pending' => $pending,
            'included' => $included,
            'needs_review' => $needsReview,
            'awaiting_rate' => $awaiting,
            'overrides' => $overrides,
            'groups' => $groups,
            'blockers' => $blockers,
            'total_syp' => $included->sum(fn ($p) => PaymentAllocationEngine::toCents($p->amount_syp)) / 100,
            'signature' => hash('sha256', json_encode([
                $reps->keys()->sort()->values()->all(),
                $included->map(fn ($p) => [$p->id, (string) $p->amount_syp, $p->review_status])->values()->all(),
                $overrides,
                self::template(),
                $reps->map(fn ($r) => $r->cost_center_label)->all(),
            ], JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * التصدير: transaction واحد بقفل صف نطاق المندوبين؛ لقطة ثابتة، والدفعات المشمولة exported.
     */
    public function export(User $by, ?array $repIds, array $overrides, ?string $signature = null): FinanceExport
    {
        return DB::transaction(function () use ($by, $repIds, $overrides, $signature) {
            // قفل النطاق: نقرتان متزامنتان لا تأخذان الرقم نفسه ولا تصدّران الدفعات مرتين
            Setting::where('key', 'finance_rep_export_lock')->lockForUpdate()->first();

            $preview = $this->preview($repIds, $overrides);
            if ($preview['included']->isEmpty()) {
                throw ValidationException::withMessages(['export' => 'لا دفعات جديدة للتصدير.']);
            }
            if ($preview['blockers'] !== []) {
                throw ValidationException::withMessages(['export' => $preview['blockers']]);
            }
            if ($signature !== null && ! hash_equals($preview['signature'], $signature)) {
                throw ValidationException::withMessages(['export' => 'تغيّرت الدفعات منذ المعاينة. راجع المعاينة ثم أكّد من جديد.']);
            }

            $template = self::template();
            $included = $preview['included'];
            $sequence = (int) FinanceExport::where('scope_key', FinanceExport::REP_SCOPE)->max('sequence') + 1;
            $now = now();
            $usedOverrides = array_intersect_key($preview['overrides'], array_flip($included->pluck('id')->all()));

            $export = FinanceExport::create([
                'kind' => FinanceExport::KIND_REP,
                'scope_key' => FinanceExport::REP_SCOPE,
                'distribution_sheet_id' => null,
                'sequence' => $sequence,
                'exported_by' => $by->id,
                'exported_at' => $now,
                'payments_count' => $included->count(),
                'total_syp' => $preview['total_syp'],
                'total_usd' => round($included->where('currency', 'USD')->sum(fn ($p) => (float) $p->amount), 2),
                'surplus_syp' => 0,
                'statement_template' => $template,
                'template_signature' => hash('sha256', $template),
                'meta' => [
                    'order' => 'م-'.$sequence,
                    'rep_ids' => $preview['reps']->pluck('id')->values()->all(),
                    'rep_labels' => $preview['reps']->mapWithKeys(fn ($r) => [$r->id => $r->cost_center_label])->all(),
                    'rep_names' => $preview['reps']->mapWithKeys(fn ($r) => [$r->id => $r->name])->all(),
                    'overrides' => $usedOverrides,
                ],
            ]);

            $sort = 0;
            $receiptSort = 0;
            $importCents = 0;
            $surplusCents = 0;
            foreach ($preview['groups'] as $group) {
                $rep = $group['rep'];
                $statement = self::statement($template, (string) $rep->cost_center_label, $sequence);
                FinanceExportRow::create([
                    'finance_export_id' => $export->id, 'row_type' => 'allocation', 'sort' => ++$sort,
                    'customer_id' => $group['customer']->id, 'customer_name' => (string) $group['customer']->name,
                    'statement' => $statement, 'cost_center' => $rep->cost_center_label, 'amount' => $group['cents'] / 100,
                    'rep_user_id' => $rep->id,
                ]);
                $importCents += $group['cents'];

                foreach ($group['payments'] as $payment) {
                    FinanceExportRow::create([
                        'finance_export_id' => $export->id, 'row_type' => 'receipt', 'sort' => ++$receiptSort,
                        'customer_id' => $group['customer']->id, 'customer_name' => (string) $group['customer']->name,
                        'statement' => $statement, 'cost_center' => $rep->cost_center_label, 'amount' => (float) $payment->amount_syp,
                        'payment_id' => $payment->id, 'rep_user_id' => $rep->id, 'currency' => $payment->currency,
                        'amount_original' => (float) $payment->amount,
                        'exchange_rate' => $payment->exchange_rate !== null ? (float) $payment->exchange_rate : null,
                        'receipt_number' => $payment->receipt_number,
                    ]);
                }

                // لقطة الخصم (الأقدم أولاً) على المتبقي بعد اللقطات السابقة، للدفعات المشمولة وحدها
                $state = $this->receivables->state($rep, [$group['customer']->id])->get($group['customer']->id);
                $open = $state ? $state['invoices']->mapWithKeys(fn ($i) => [$i['invoice_id'] => $i['open_cents']])->all() : [];
                $fifo = $this->receivables->fifo($open, $group['payments']->mapWithKeys(fn ($p) => [$p->id => PaymentAllocationEngine::toCents($p->amount_syp)])->all());
                $surplusCents += array_sum($fifo['surplus']);
                $rows = $state ? $state['invoices']->keyBy('invoice_id') : collect();
                $invoices = Invoice::whereIn('id', array_keys($open) ?: [0])->get()->keyBy('id');
                foreach ($fifo['allocations'] as $paymentId => $byInvoice) {
                    foreach ($byInvoice as $invoiceId => $cents) {
                        $row = $rows[$invoiceId];
                        $invoice = $invoices[$invoiceId];
                        PaymentAllocation::create([
                            'source' => PaymentAllocation::SOURCE_REP,
                            'payment_id' => $paymentId,
                            'finance_export_id' => $export->id,
                            'distribution_sheet_id' => $row['sheet_id'],
                            'customer_id' => $group['customer']->id,
                            'invoice_id' => $invoiceId,
                            'invoice_key' => $invoice->invoice_key,
                            'invoice_number' => $invoice->invoice_number,
                            'series' => $row['series'],
                            'cost_center' => $row['cost_center'],
                            'allocation_group' => 'rep',
                            'invoice_total' => $row['total_cents'] / 100,
                            'remaining_before' => $open[$invoiceId] / 100,
                            'allocated' => $cents / 100,
                            'unpaid' => ($open[$invoiceId] - $cents) / 100,
                            'status' => $open[$invoiceId] - $cents === 0 ? 'paid' : 'partial',
                        ]);
                        $open[$invoiceId] -= $cents;
                    }
                }
            }

            // اتساق: مجموع ملف الاستيراد = مجموع الدفعات المشمولة بالقرش
            $paymentCents = $included->sum(fn ($p) => PaymentAllocationEngine::toCents($p->amount_syp));
            if ($importCents !== $paymentCents) {
                throw new \LogicException("مجموع ملف استيراد المندوبين ({$importCents}) لا يطابق الدفعات ({$paymentCents}).");
            }
            $export->update(['surplus_syp' => $surplusCents / 100]);

            $updated = Payment::whereIn('id', $included->pluck('id'))
                ->where('status', 'pending')->whereNull('voided_at')
                ->update(['status' => 'exported', 'exported_at' => $now, 'finance_export_id' => $export->id]);
            if ($updated !== $included->count()) {
                throw ValidationException::withMessages(['export' => 'تغيّرت الدفعات أثناء التصدير. أعد المحاولة.']);
            }

            foreach ($usedOverrides as $paymentId => $reason) {
                ActivityLog::record('finance.rep_export_override', $included->firstWhere('id', $paymentId), [], ['reason' => $reason], [
                    'finance_export_id' => $export->id,
                    'order' => 'م-'.$sequence,
                ]);
            }
            ActivityLog::record('finance.rep_exported', $export, [], [
                'order' => 'م-'.$sequence,
                'payments_count' => $export->payments_count,
                'total_syp' => (float) $export->total_syp,
                'total_usd' => (float) $export->total_usd,
            ], ['rep_ids' => $export->meta['rep_ids'], 'overrides' => count($usedOverrides)]);

            return $export;
        });
    }
}
