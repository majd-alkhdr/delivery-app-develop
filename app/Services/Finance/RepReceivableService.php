<?php

namespace App\Services\Finance;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\ArabicNormalizer;
use App\Support\CostCenter;
use App\Support\InvoiceFingerprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ذمم المندوب (المرحلة 6-B)، بلا دفتر ذمم جديد:
 * - الدَين = فواتير كشوف v2 «مكتملة» (in_sheet، > 0) مركز كلفتها بكود المندوب، بمتبقيها بعد الموزع
 *   (UnpaidReportService::liveAllocation: لقطات الموزعين + توزيع دفعاته المعلّقة).
 * - دفعات المندوب تُخصم FIFO (الأقدم تاريخ توزيع ثم السلسلة ثم الرقم) بالقروش:
 *   المُصدَّرة من لقطاتها (payment_allocations.source=rep)، والمعلّقة على المتبقي بعد اللقطات.
 * - الزائد عن الدَين «فائض عن الدَين المعروف» ولا يُحمَّل لفواتير أخرى.
 * - المرحلة 7C: قيمة الفاتورة = effectiveAmount() (تعديلات لاحقة يدوية)، وما حُصِّل على فاتورة خُفّضت فوق قيمتها الجديدة
 *   (لقطات الموزعين المقصوصة + لقطات المندوب فوق المتبقي) يظهر «زيادة تحصيل … يحتاج قراراً» (over_collected) ولا يُصفَّر بصمت.
 */
class RepReceivableService
{
    public const NO_REP = 'بلا مندوب';

    public const AGE_BUCKETS = ['0-7' => [0, 7], '8-30' => [8, 30], '31-60' => [31, 60], '61+' => [61, PHP_INT_MAX]];

    public function __construct(private UnpaidReportService $unpaid)
    {
    }

    // =====================================================================
    // FIFO نقي
    // =====================================================================

    /**
     * @param  array<int, int>  $remaining  invoice_id => متبقٍّ (بالقروش)، بالترتيب المطلوب (الأقدم أولاً)
     * @param  array<int, int>  $payments  payment_id => مبلغ (بالقروش)، بترتيب التحصيل
     * @return array{allocations: array<int, array<int, int>>, remaining: array<int, int>, surplus: array<int, int>}
     */
    public function fifo(array $remaining, array $payments): array
    {
        $allocations = [];
        $surplus = [];
        foreach ($payments as $paymentId => $cents) {
            $left = max(0, (int) $cents);
            foreach ($remaining as $invoiceId => $open) {
                if ($left <= 0) {
                    break;
                }
                if ($open <= 0) {
                    continue;
                }
                $take = min($open, $left);
                $allocations[$paymentId][$invoiceId] = $take;
                $remaining[$invoiceId] = $open - $take;
                $left -= $take;
            }
            if ($left > 0) {
                $surplus[$paymentId] = $left;
            }
        }

        return ['allocations' => $allocations, 'remaining' => $remaining, 'surplus' => $surplus];
    }

    // =====================================================================
    // فواتير الدَين (بعد الموزع)
    // =====================================================================

    /**
     * نطاق فواتير مركز الكلفة (المصدر الوحيد لمطابقة cost_center_code بالتطبيع): كشوف v2 بالحالات المعطاة،
     * in_sheet غير مؤجلة، > 0، ليست سجلات خارجية. يستخدمه دَين المندوب (completed) وكشوف المندوب (المرحلة 6B).
     *
     * @param  array<int, string>|null  $codes  أكواد مطبَّعة (null = كل المراكز)
     * @param  array<int, string>  $statuses
     */
    public function scopedInvoices(?array $codes, ?array $customerIds, array $statuses, ?int $sheetId = null): Collection
    {
        if ($codes === []) {
            return collect();
        }

        $query = Invoice::query()
            ->whereHas('distributionSheet', fn (Builder $q) => $q->where('format', 'v2')->whereIn('status', $statuses))
            ->where('placement', Invoice::IN_SHEET)
            ->where('is_deferred', false)
            ->whereNotNull('customer_id')
            // المرحلة 7C: فاتورة صُفّرت/خُفّضت بتعديل لاحق تبقى ظاهرة (أثر التعديل والفائض)
            ->where(fn (Builder $q) => $q->where('invoice_amount', '>', 0)->orWhereHas('adjustments', fn ($a) => $a->where('status', \App\Models\InvoiceAdjustment::APPLIED)))
            ->where(fn (Builder $q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds ?: [0]))
            ->when($sheetId !== null, fn ($q) => $q->where('distribution_sheet_id', $sheetId))
            ->withAdjustmentDelta();
        if ($codes !== null) {
            $variants = collect($codes)->flatMap(fn ($c) => CostCenter::codeVariants($c))->unique()->values()->all();
            $query->whereIn('cost_center_code', $variants);
        }
        $candidates = $query->orderBy('id')->get();

        return $codes === null ? $candidates : $candidates->filter(fn ($i) => in_array(CostCenter::normalizeCode($i->cost_center_code), $codes, true))->values();
    }

    /**
     * فواتير المندوب (مركز كلفته) في الكشوف المنشورة (المرحلة 6B-C: كشوف المندوب).
     *
     * @param  array<int, string>  $statuses
     */
    public function repInvoices(User $rep, array $statuses = ['pending', 'in_progress', 'completed'], ?int $sheetId = null): Collection
    {
        if (! $rep->hasCostCenter()) {
            return collect();
        }

        return $this->scopedInvoices([CostCenter::normalizeCode($rep->cost_center_code)], null, $statuses, $sheetId);
    }

    /**
     * فواتير الدَين المحتملة: كشوف v2 مكتملة، in_sheet غير مؤجلة، > 0، ليست سجلات خارجية.
     *
     * @param  array<int, string>|null  $codes  أكواد مطبَّعة (null = كل الفواتير، لتقرير الإدارة)
     * @return Collection<int, array> صفوف: invoice, sheet, code, customer_id, total_cents, base_cents (المتبقي بعد الموزع)
     */
    public function debtInvoices(?array $codes = null, ?array $customerIds = null): Collection
    {
        if ($codes === []) {
            return collect();
        }

        $candidates = $this->scopedInvoices($codes, $customerIds, ['completed']);
        if ($candidates->isEmpty()) {
            return collect();
        }

        $sheets = DistributionSheet::whereIn('id', $candidates->pluck('distribution_sheet_id')->unique())->get()->keyBy('id');
        $rows = collect();
        foreach ($candidates->groupBy('distribution_sheet_id') as $sheetId => $sheetInvoices) {
            $sheet = $sheets[$sheetId];
            $live = $this->unpaid->liveAllocation($sheet, $sheetInvoices->pluck('customer_id')->unique()->values()->all());
            $unpaidById = [];
            $clippedById = [];
            foreach ($live['results'] as $result) {
                foreach ($result->invoiceRows as $row) {
                    $unpaidById[$row['invoice_id']] = $row['unpaid_cents'];
                }
                foreach ($result->clippedRows as $row) {
                    $clippedById[$row['invoice_id']] = $row['clipped_cents'];
                }
            }
            foreach ($sheetInvoices as $invoice) {
                [$series, $number] = InvoiceFingerprint::split((string) $invoice->invoice_number);
                $rows->push([
                    'invoice' => $invoice,
                    'sheet' => $sheet,
                    'code' => CostCenter::normalizeCode($invoice->cost_center_code),
                    'cost_center' => PaymentAllocationEngine::costCenterLabel($invoice->cost_center_code, $invoice->cost_center_name),
                    'customer_id' => (int) $invoice->customer_id,
                    'series' => $invoice->series ?? $series,
                    'number' => $number,
                    'label' => PaymentAllocationEngine::invoiceLabel((string) $invoice->invoice_number),
                    'date' => $sheet->distribution_date?->toDateString(),
                    'total_cents' => PaymentAllocationEngine::toCents($invoice->effectiveAmount()),
                    'base_cents' => max(0, (int) ($unpaidById[$invoice->id] ?? 0)),
                    // المرحلة 7C: ما وزّعه الموزعون سابقاً فوق القيمة الحالية (allocation_clipped)
                    'clipped_cents' => (int) ($clippedById[$invoice->id] ?? 0),
                ]);
            }
        }

        // الأقدم (تاريخ التوزيع) ثم السلسلة ثم الرقم
        return $rows->sort(fn ($a, $b) => [(string) $a['date'], mb_strtolower((string) $a['series']), self::numberKey($a['number']), $a['invoice']->id]
            <=> [(string) $b['date'], mb_strtolower((string) $b['series']), self::numberKey($b['number']), $b['invoice']->id])->values();
    }

    // =====================================================================
    // حالة ذمم مندوب
    // =====================================================================

    /**
     * حالة ذمم المندوب لكل صيدلية (لها دَين أو دفعات منه).
     *
     * @param  Collection|null  $debt  فواتير مُحمَّلة مسبقاً (debtInvoices) لتفادي إعادة الحساب
     * @return Collection<int, array> مفتاحها customer_id
     */
    public function state(User $rep, ?array $customerIds = null, ?Collection $debt = null): Collection
    {
        if (! $rep->hasCostCenter()) {
            return collect();
        }
        $code = CostCenter::normalizeCode($rep->cost_center_code);
        $debt ??= $this->debtInvoices([$code], $customerIds);
        $debt = $debt->where('code', $code)->when($customerIds !== null, fn ($c) => $c->whereIn('customer_id', $customerIds));

        $payments = Payment::active()
            ->where('source', 'rep')
            ->where('entered_by', $rep->id)
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds ?: [0]))
            ->orderBy('collected_at')->orderBy('id')
            ->get();

        $snapshots = PaymentAllocation::query()
            ->where('source', PaymentAllocation::SOURCE_REP)
            ->whereIn('payment_id', $payments->pluck('id')->all() ?: [0])
            ->get();

        $customerIdsAll = $debt->pluck('customer_id')->merge($payments->pluck('customer_id'))->unique()->values();
        $customers = Customer::whereIn('id', $customerIdsAll)->get(['id', 'name', 'area', 'area_normalized'])->keyBy('id');
        $today = now('Asia/Damascus')->startOfDay();

        return $customerIdsAll->mapWithKeys(function ($customerId) use ($debt, $payments, $snapshots, $customers, $today) {
            $invoices = $debt->where('customer_id', $customerId)->values();
            $customerPayments = $payments->where('customer_id', $customerId)->values();
            $exported = $customerPayments->filter(fn ($p) => $p->status !== 'pending');
            $pending = $customerPayments->where('status', 'pending')->values();
            $snap = $snapshots->whereIn('payment_id', $customerPayments->pluck('id'));

            // المتبقي بعد لقطات المندوب السابقة
            $remaining = [];
            $over = [];
            foreach ($invoices as $row) {
                $taken = PaymentAllocationEngine::toCents($snap->where('invoice_id', $row['invoice']->id)->sum('allocated'));
                $remaining[$row['invoice']->id] = max(0, $row['base_cents'] - $taken);
                // المرحلة 7C: زيادة تحصيل بعد تخفيض الفاتورة (لا تُصفَّر بصمت)
                $over[$row['invoice']->id] = ($row['clipped_cents'] ?? 0) + max(0, $taken - $row['base_cents']);
            }
            $fifo = $this->fifo($remaining, $pending->mapWithKeys(fn ($p) => [$p->id => PaymentAllocationEngine::toCents($p->amount_syp)])->all());

            $paymentsById = $customerPayments->keyBy('id');
            $rows = $invoices->map(function ($row) use ($fifo, $snap, $paymentsById, $today, $remaining, $over) {
                $id = $row['invoice']->id;
                $paidBy = [];
                foreach ($snap->where('invoice_id', $id) as $s) {
                    $paidBy[] = $this->paidByRow($paymentsById[$s->payment_id] ?? null, PaymentAllocationEngine::toCents($s->allocated), true);
                }
                foreach ($fifo['allocations'] as $paymentId => $byInvoice) {
                    if (isset($byInvoice[$id])) {
                        $paidBy[] = $this->paidByRow($paymentsById[$paymentId] ?? null, $byInvoice[$id], false);
                    }
                }
                $left = $fifo['remaining'][$id];

                return [
                    'invoice_id' => $id,
                    'label' => $row['label'],
                    'series' => $row['series'],
                    'number' => $row['number'],
                    'date' => $row['date'],
                    'sheet_id' => $row['sheet']->id,
                    'order' => $row['sheet']->orderLabel(),
                    'cost_center' => $row['cost_center'],
                    'age_days' => $row['date'] ? (int) \Carbon\Carbon::parse($row['date'], 'Asia/Damascus')->diffInDays($today) : 0,
                    'total_cents' => $row['total_cents'],
                    'base_cents' => $row['base_cents'],
                    // المتبقي بعد لقطات المندوب السابقة وقبل دفعاته المعلّقة (أساس التصدير المتأخر)
                    'open_cents' => $remaining[$id],
                    'rep_paid_cents' => $row['base_cents'] - $left,
                    'remaining_cents' => $left,
                    'over_collected_cents' => $over[$id] ?? 0,
                    'status' => match (true) {
                        $left === 0 => 'paid',
                        $left < $row['total_cents'] => 'partial',
                        default => 'unpaid',
                    },
                    'paid_by' => $paidBy,
                ];
            })->values();

            $exportedCents = $exported->sum(fn ($p) => PaymentAllocationEngine::toCents($p->amount_syp));
            $exportedAllocated = PaymentAllocationEngine::toCents($snap->sum('allocated'));
            $open = $rows->where('remaining_cents', '>', 0);
            $customer = $customers[$customerId] ?? null;

            return [(int) $customerId => [
                'customer_id' => (int) $customerId,
                'customer' => $customer,
                'name' => $customer?->name,
                'area' => $customer?->area ?: 'بلا منطقة',
                'invoices' => $rows,
                'arising_cents' => $rows->sum('base_cents'),
                'remaining_cents' => $rows->sum('remaining_cents'),
                'exported_cents' => $exportedCents,
                'pending_cents' => $pending->sum(fn ($p) => PaymentAllocationEngine::toCents($p->amount_syp)),
                'surplus_cents' => array_sum($fifo['surplus']) + max(0, $exportedCents - $exportedAllocated),
                'over_collected_cents' => $rows->sum('over_collected_cents'),
                'over_collected' => self::overCollectedLines($rows),
                'awaiting_rate' => $customerPayments->filter(fn ($p) => $p->currency === 'USD' && $p->amount_syp === null)->count(),
                'oldest_days' => $open->max('age_days'),
                'last_collected_at' => $customerPayments->max('collected_at'),
                'live' => $fifo,
            ]];
        });
    }

    /**
     * «ذممي» للمندوب: الصيدليات التي عليها دَين متبقٍّ لمركزه.
     */
    public function receivablesForRep(User $rep): Collection
    {
        return $this->state($rep)->filter(fn ($s) => $s['remaining_cents'] > 0 || $s['over_collected_cents'] > 0)->values();
    }

    /**
     * الدَين المعروف المتبقي لمندوب على صيدلية (بالقروش).
     */
    public function knownDebtCents(User $rep, int $customerId): int
    {
        return (int) ($this->state($rep, [$customerId])->get($customerId)['remaining_cents'] ?? 0);
    }

    /**
     * كيف تُخصم دفعة (معلّقة) من فواتير المندوب الآن: [[label, allocated, remaining], ...] والمتبقي الكلي.
     *
     * @return array{rows: array<int, array>, remaining: float, surplus: float}
     */
    public function paymentBreakdown(Payment $payment): array
    {
        $rep = $payment->enteredBy;
        $state = $rep ? $this->state($rep, [$payment->customer_id])->get($payment->customer_id) : null;
        if (! $state) {
            return ['rows' => [], 'remaining' => 0.0, 'surplus' => $payment->amount_syp !== null ? (float) $payment->amount_syp : 0.0];
        }
        $byInvoice = $state['live']['allocations'][$payment->id] ?? [];
        $rows = $state['invoices']->filter(fn ($i) => isset($byInvoice[$i['invoice_id']]))->map(fn ($i) => [
            'invoice' => $i['label'],
            'allocated' => $byInvoice[$i['invoice_id']] / 100,
            'remaining' => $i['remaining_cents'] / 100,
        ])->values()->all();

        return ['rows' => $rows, 'remaining' => $state['remaining_cents'] / 100, 'surplus' => ($state['live']['surplus'][$payment->id] ?? 0) / 100];
    }

    // =====================================================================
    // تقرير الإدارة
    // =====================================================================

    /**
     * تجميع بالمندوب ثم الصيدلية، ومراكز بلا مندوب تحت «بلا مندوب».
     *
     * @param  array{rep_id?: ?string, area?: ?string, q?: ?string, age?: ?string, status?: ?string}  $filters
     * @return array{groups: Collection, totals: array}
     */
    public function receivablesForStaff(array $filters = [], ?\App\Services\Reps\RepViewerScope $scope = null): array
    {
        $reps = User::where('role', Role::Rep)->whereNotNull('cost_center_code')->orderBy('name')->get()->filter->hasCostCenter();
        // المرحلة 8A: مشرف المندوبين بحصر مفعّل: مندوبوه فقط، وذمة كل منهم على صيدليات نطاقه (state بنطاق زبائن، المنطق نفسه)
        if ($scope !== null) {
            $reps = $reps->filter(fn ($r) => $scope->allowsRep($r))->values();
        }
        $repByCode = $reps->mapWithKeys(fn ($r) => [CostCenter::normalizeCode($r->cost_center_code) => $r]);
        // مفاتيح المصفوفة الرقمية تصير أعداداً: تُعاد نصوصاً للمطابقة الصارمة في scopedInvoices
        $debt = $this->debtInvoices($scope !== null ? $repByCode->keys()->filter()->map(fn ($c) => (string) $c)->values()->all() : null);

        $groups = collect();
        foreach ($reps as $rep) {
            if (! empty($filters['rep_id']) && (string) $filters['rep_id'] !== (string) $rep->id) {
                continue;
            }
            $groups->push($this->group($rep->id, $rep->name, $rep->cost_center_label, $this->state($rep, $scope?->customerIdsFor($rep->id), $debt), $filters));
        }

        // مراكز بلا مندوب: المتبقي بعد الموزع فقط (لا دفعات مندوب) — للأدمن والمحاسب، لا للعرض المقيَّد
        if ($scope === null && (empty($filters['rep_id']) || $filters['rep_id'] === 'none')) {
            $orphan = $debt->filter(fn ($r) => $r['code'] === null || ! $repByCode->has($r['code']));
            $customers = Customer::whereIn('id', $orphan->pluck('customer_id')->unique())->get(['id', 'name', 'area'])->keyBy('id');
            $today = now('Asia/Damascus')->startOfDay();
            $state = $orphan->groupBy('customer_id')->map(function ($rows, $customerId) use ($customers, $today) {
                $invoices = $rows->map(fn ($r) => [
                    'invoice_id' => $r['invoice']->id, 'label' => $r['label'], 'series' => $r['series'], 'number' => $r['number'],
                    'date' => $r['date'], 'order' => $r['sheet']->orderLabel(), 'cost_center' => $r['cost_center'],
                    'age_days' => $r['date'] ? (int) \Carbon\Carbon::parse($r['date'], 'Asia/Damascus')->diffInDays($today) : 0,
                    'total_cents' => $r['total_cents'], 'base_cents' => $r['base_cents'], 'rep_paid_cents' => 0,
                    'remaining_cents' => $r['base_cents'], 'over_collected_cents' => $r['clipped_cents'] ?? 0,
                    'status' => $r['base_cents'] === 0 ? 'paid' : ($r['base_cents'] < $r['total_cents'] ? 'partial' : 'unpaid'),
                    'paid_by' => [],
                ])->values();
                $customer = $customers[$customerId] ?? null;

                return [
                    'customer_id' => (int) $customerId, 'customer' => $customer, 'name' => $customer?->name, 'area' => $customer?->area ?: 'بلا منطقة',
                    'invoices' => $invoices, 'arising_cents' => $invoices->sum('base_cents'), 'remaining_cents' => $invoices->sum('remaining_cents'),
                    'exported_cents' => 0, 'pending_cents' => 0, 'surplus_cents' => 0, 'awaiting_rate' => 0,
                    'over_collected_cents' => $invoices->sum('over_collected_cents'), 'over_collected' => self::overCollectedLines($invoices),
                    'oldest_days' => $invoices->where('remaining_cents', '>', 0)->max('age_days'), 'last_collected_at' => null,
                ];
            });
            $group = $this->group(null, self::NO_REP, null, $state, $filters);
            if ($group['pharmacies']->isNotEmpty()) {
                $groups->push($group);
            }
        }

        return [
            'groups' => $groups,
            'scope_label' => $scope?->label(),
            'totals' => [
                'arising' => $groups->sum('arising_cents') / 100,
                'exported' => $groups->sum('exported_cents') / 100,
                'pending' => $groups->sum('pending_cents') / 100,
                'remaining' => $groups->sum('remaining_cents') / 100,
                'surplus' => $groups->sum('surplus_cents') / 100,
                'over_collected' => $groups->sum('over_collected_cents') / 100,
                'pharmacies' => $groups->sum(fn ($g) => $g['pharmacies']->count()),
            ],
        ];
    }

    /**
     * مجموعة مندوب: الصيدليات بعد الفلاتر والملخص.
     */
    private function group(?int $repId, string $name, ?string $label, Collection $state, array $filters): array
    {
        $q = ! empty($filters['q']) ? ArabicNormalizer::normalize((string) $filters['q']) : '';
        $area = ! empty($filters['area']) ? ArabicNormalizer::normalize((string) $filters['area']) : '';
        $bucket = self::AGE_BUCKETS[$filters['age'] ?? ''] ?? null;
        $status = $filters['status'] ?? null;

        $pharmacies = $state
            ->filter(fn ($s) => $s['invoices']->isNotEmpty() || $s['surplus_cents'] > 0 || $s['pending_cents'] > 0 || $s['exported_cents'] > 0 || ($s['over_collected_cents'] ?? 0) > 0)
            ->when($q !== '', fn ($c) => $c->filter(fn ($s) => str_contains(ArabicNormalizer::normalize((string) $s['name']), $q)))
            ->when($area !== '', fn ($c) => $c->filter(fn ($s) => ArabicNormalizer::normalize((string) $s['area']) === $area))
            ->when($bucket !== null, fn ($c) => $c->filter(fn ($s) => $s['oldest_days'] !== null && $s['oldest_days'] >= $bucket[0] && $s['oldest_days'] <= $bucket[1]))
            ->map(function ($s) use ($status) {
                if ($status) {
                    $s['invoices'] = $s['invoices']->where('status', $status)->values();
                }

                return $s;
            })
            ->when($status !== null && $status !== '', fn ($c) => $c->filter(fn ($s) => $s['invoices']->isNotEmpty()))
            ->sortBy('name', SORT_NATURAL)
            ->values();

        return [
            'rep_id' => $repId,
            'name' => $name,
            'label' => $label,
            'pharmacies' => $pharmacies,
            'arising_cents' => $pharmacies->sum('arising_cents'),
            'exported_cents' => $pharmacies->sum('exported_cents'),
            'pending_cents' => $pharmacies->sum('pending_cents'),
            'remaining_cents' => $pharmacies->sum('remaining_cents'),
            'surplus_cents' => $pharmacies->sum('surplus_cents'),
            'over_collected_cents' => $pharmacies->sum('over_collected_cents'),
            'open_pharmacies' => $pharmacies->where('remaining_cents', '>', 0)->count(),
            'oldest_days' => $pharmacies->max('oldest_days'),
        ];
    }

    /**
     * المرحلة 7C: «زيادة تحصيل على الفاتورة G: 1 بمبلغ 100 بعد التعديل: يحتاج قراراً» لكل فاتورة فيها زيادة.
     *
     * @return array<int, array{invoice_id: int, label: string, cents: int, text: string}>
     */
    public static function overCollectedLines(Collection $rows): array
    {
        return $rows->filter(fn ($r) => ($r['over_collected_cents'] ?? 0) > 0)->map(fn ($r) => [
            'invoice_id' => $r['invoice_id'],
            'label' => $r['label'],
            'cents' => $r['over_collected_cents'],
            'text' => 'زيادة تحصيل على الفاتورة '.$r['label'].' بمبلغ '.\App\Services\Balances\BalanceDeltaExplainer::money($r['over_collected_cents'] / 100).' بعد التعديل: يحتاج قراراً',
        ])->values()->all();
    }

    private function paidByRow(?Payment $payment, int $cents, bool $exported): array
    {
        return [
            'payment_id' => $payment?->id,
            'receipt_number' => $payment?->receipt_number,
            'book_receipt_no' => $payment?->book_receipt_no,
            'date' => $payment?->collected_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d'),
            'cents' => $cents,
            'exported' => $exported,
        ];
    }

    private static function numberKey(?string $number): string
    {
        $number = (string) $number;

        return ctype_digit($number) ? str_pad($number, 20, '0', STR_PAD_LEFT) : $number;
    }
}
