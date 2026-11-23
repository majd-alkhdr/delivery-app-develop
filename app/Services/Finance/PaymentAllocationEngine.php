<?php

namespace App\Services\Finance;

use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Support\InvoiceFingerprint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * محرك تقسيم الدفعات (المرحلة 5، قرار 1): دفعات الزبون في الكشف تُوزَّع على فواتيره في الكشف نفسه فقط.
 *
 * - الدفعات: غير الملغاة وبحالة pending (أو المجموعة الممرَّرة). دفعة دولار بلا amount_syp ⇒ «ناقصة: بانتظار السعر» ولا توزيع.
 * - الفواتير: placement=in_sheet وقيمتها > 0؛ المتبقي = الإجمالي − ما وُزِّع عليها في دفعات تصدير سابقة.
 * - الترتيب: milk ثم argivit ثم other؛ المجموعة التي لا يكفيها المتاح تُقسَّم بالتناسب بطريقة «أكبر باقي».
 * - كل الحساب بالقروش (int)، والنتيجة تُفحص: (الموزَّع + الفائض) = الدفعات تماماً، ولا فاتورة تتجاوز متبقيها.
 *
 * allocate() يقرأ فقط (لا يكتب)، وcompute() دالة نقية على بيانات جاهزة.
 */
class PaymentAllocationEngine
{
    public const GROUP_ORDER = ['milk', 'argivit', 'other'];

    /** @var array<string, string>|null سلسلة => مجموعة */
    private ?array $groups = null;

    /**
     * ضبط خريطة (السلسلة => المجموعة) مباشرة بدل قراءتها من invoice_series (للاختبارات النقية).
     *
     * @param  array<string, string>  $groups
     */
    public function withGroups(array $groups): static
    {
        $this->groups = $groups;

        return $this;
    }

    public function allocate(DistributionSheet $sheet, int $customerId, ?Collection $payments = null, ?string $surplusCostCenter = null): AllocationResult
    {
        $payments ??= Payment::active()
            ->where('distribution_sheet_id', $sheet->id)
            ->where('customer_id', $customerId)
            ->where('status', 'pending')
            ->with('enteredBy:id,name')
            ->orderBy('id')
            ->get();

        $invoices = $this->sheetInvoices($sheet, [$customerId])->get();

        return $this->compute(
            $customerId,
            $invoices->map(fn ($i) => $this->invoiceInput($i))->all(),
            $payments->map(fn ($p) => $this->paymentInput($p))->all(),
            $this->previousAllocatedCents($invoices->pluck('id')->all()),
            $surplusCostCenter,
        );
    }

    /**
     * فواتير الكشف الموضوعة فيه (لا مؤجلة ولا ملغاة ولا مستبعدة، ولا سجلات الدفعات الخارجية القديمة).
     */
    public function sheetInvoices(DistributionSheet $sheet, ?array $customerIds = null): Builder
    {
        return Invoice::query()
            ->where('distribution_sheet_id', $sheet->id)
            ->where('placement', Invoice::IN_SHEET)
            ->where('is_deferred', false)
            ->whereNotNull('customer_id')
            ->where(fn (Builder $q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds ?: [0]))
            ->withAdjustmentDelta()
            ->orderBy('id');
    }

    /**
     * ما وُزِّع على كل فاتورة في دفعات تصدير سابقة للموزعين (بالقروش).
     * لقطات المندوب (source=rep، المرحلة 6) لا تدخل هنا: هي خصم من دَين المندوب بعد الموزع، لا من توزيع الموزع.
     *
     * @param  array<int, int>  $invoiceIds
     * @return array<int, int>
     */
    public function previousAllocatedCents(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [];
        }

        $sums = [];
        foreach (array_chunk($invoiceIds, 500) as $chunk) {
            PaymentAllocation::query()
                ->whereIn('invoice_id', $chunk)
                ->where('source', PaymentAllocation::SOURCE_DISTRIBUTOR)
                ->selectRaw('invoice_id, SUM(allocated) as s')
                ->groupBy('invoice_id')
                ->get()
                ->each(function ($row) use (&$sums) {
                    $sums[(int) $row->invoice_id] = self::toCents($row->s);
                });
        }

        return $sums;
    }

    /**
     * @return array{id: int, invoice_key: ?string, invoice_number: string, series: ?string, cost_center: ?string, total_cents: int}
     */
    public function invoiceInput(Invoice $invoice): array
    {
        return [
            'id' => (int) $invoice->id,
            'invoice_key' => $invoice->invoice_key,
            'invoice_number' => (string) $invoice->invoice_number,
            'series' => $invoice->series ?? InvoiceFingerprint::split((string) $invoice->invoice_number)[0],
            'cost_center' => self::costCenterLabel($invoice->cost_center_code, $invoice->cost_center_name),
            // المرحلة 7C: القيمة الفعالة (invoice_amount + تعديلات لاحقة يدوية مطبَّقة)
            'total_cents' => self::toCents($invoice->effectiveAmount()),
        ];
    }

    /**
     * @return array{id: int, currency: string, amount_syp_cents: ?int, entered_by: ?int, entered_by_name: ?string}
     */
    public function paymentInput(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'currency' => (string) $payment->currency,
            'amount_syp_cents' => $payment->amount_syp !== null ? self::toCents($payment->amount_syp) : null,
            'entered_by' => $payment->entered_by !== null ? (int) $payment->entered_by : null,
            'entered_by_name' => $payment->relationLoaded('enteredBy') ? $payment->enteredBy?->name : null,
        ];
    }

    /**
     * الحساب النقي.
     *
     * @param  array<int, array>  $invoices  كل فواتير الزبون في الكشف (invoiceInput)، بما فيها الصفرية
     * @param  array<int, array>  $payments  paymentInput
     * @param  array<int, int>  $previousCents  invoice_id => ما وُزِّع سابقاً
     */
    public function compute(int $customerId, array $invoices, array $payments, array $previousCents = [], ?string $surplusChoice = null): AllocationResult
    {
        $awaiting = count(array_filter($payments, fn ($p) => $p['amount_syp_cents'] === null));
        $paidCents = array_sum(array_map(fn ($p) => (int) ($p['amount_syp_cents'] ?? 0), $payments));
        $incomplete = $awaiting > 0 ? AllocationResult::INCOMPLETE_AWAITING_RATE : null;

        // الفواتير الداخلة في التوزيع (> 0) والخارجة (صفرية/سالبة)
        $rows = [];
        $excluded = [];
        $clipped = [];
        foreach ($invoices as $invoice) {
            [, $number] = InvoiceFingerprint::split($invoice['invoice_number']);
            $base = [
                'invoice_id' => $invoice['id'],
                'invoice_key' => $invoice['invoice_key'],
                'invoice_number' => $invoice['invoice_number'],
                'label' => self::invoiceLabel($invoice['invoice_number']),
                'series' => $invoice['series'],
                'number' => $number,
                'cost_center' => $invoice['cost_center'],
                'group' => $this->groupFor($invoice['series']),
            ];
            $base['group_label'] = InvoiceSeries::ALLOCATION_GROUPS[$base['group']] ?? $base['group'];

            // المرحلة 7C: ما وُزِّع سابقاً أكبر من قيمة الفاتورة الحالية (خُفّضت بعد التوزيع) ⇒ يُقصّ كما كان، لكن ظاهراً لا بصمت
            $previousRaw = max(0, (int) ($previousCents[$invoice['id']] ?? 0));
            $cap = max(0, $invoice['total_cents']);
            if ($previousRaw > $cap) {
                $clipped[] = [
                    'invoice_id' => $invoice['id'], 'invoice_number' => $invoice['invoice_number'], 'label' => $base['label'],
                    'cost_center' => $invoice['cost_center'], 'total_cents' => $invoice['total_cents'],
                    'previous_cents' => $previousRaw, 'clipped_cents' => $previousRaw - $cap, 'clipped' => ($previousRaw - $cap) / 100,
                ];
            }

            if ($invoice['total_cents'] <= 0) {
                $excluded[] = $base + ['total' => $invoice['total_cents'] / 100, 'total_cents' => $invoice['total_cents']];

                continue;
            }

            $previous = min($invoice['total_cents'], $previousRaw);
            $rows[] = $base + [
                'total_cents' => $invoice['total_cents'],
                'previous_cents' => $previous,
                'remaining_cents' => $invoice['total_cents'] - $previous,
                'allocated_cents' => 0,
            ];
        }

        // ترتيب: المجموعة ثم (السلسلة، الرقم)
        usort($rows, fn ($a, $b) => [array_search($a['group'], self::GROUP_ORDER, true), self::sortKey($a)]
            <=> [array_search($b['group'], self::GROUP_ORDER, true), self::sortKey($b)]);

        $available = $incomplete === null ? $paidCents : 0;
        foreach (self::GROUP_ORDER as $group) {
            $indexes = array_keys(array_filter($rows, fn ($r) => $r['group'] === $group && $r['remaining_cents'] > 0));
            if ($indexes === [] || $available <= 0) {
                continue;
            }
            $groupTotal = array_sum(array_map(fn ($i) => $rows[$i]['remaining_cents'], $indexes));

            if ($available >= $groupTotal) {
                foreach ($indexes as $i) {
                    $rows[$i]['allocated_cents'] = $rows[$i]['remaining_cents'];
                }
                $available -= $groupTotal;

                continue;
            }

            foreach ($this->largestRemainder($available, array_map(fn ($i) => $rows[$i]['remaining_cents'], $indexes)) as $k => $share) {
                $rows[$indexes[$k]]['allocated_cents'] = $share;
            }
            $available = 0;
        }
        $surplus = $incomplete === null ? $available : 0;

        // اختبارا الاتساق
        $allocated = array_sum(array_column($rows, 'allocated_cents'));
        if ($incomplete === null && $allocated + $surplus !== $paidCents) {
            throw new LogicException("عدم اتساق التوزيع للزبون {$customerId}: {$allocated} + {$surplus} ≠ {$paidCents}");
        }
        foreach ($rows as $row) {
            if ($row['allocated_cents'] < 0 || $row['allocated_cents'] > $row['remaining_cents']) {
                throw new LogicException("الموزَّع على الفاتورة {$row['invoice_id']} يتجاوز متبقيها.");
            }
        }

        $rows = array_map(function ($row) {
            $row['unpaid_cents'] = $row['remaining_cents'] - $row['allocated_cents'];
            $row['status'] = match (true) {
                $row['unpaid_cents'] === 0 => 'paid',
                $row['allocated_cents'] + $row['previous_cents'] > 0 => 'partial',
                default => 'unpaid',
            };
            foreach (['total', 'previous', 'remaining', 'allocated', 'unpaid'] as $f) {
                $row[$f] = $row[$f.'_cents'] / 100;
            }

            return $row;
        }, $rows);

        // التجميع حسب مركز الكلفة
        $byCenter = [];
        foreach ($rows as $row) {
            if ($row['allocated_cents'] > 0) {
                $key = $row['cost_center'] ?? '';
                $byCenter[$key] = ($byCenter[$key] ?? 0) + $row['allocated_cents'];
            }
        }
        ksort($byCenter, SORT_STRING);
        $centerRows = [];
        foreach ($byCenter as $center => $cents) {
            $centerRows[] = ['cost_center' => $center === '' ? null : (string) $center, 'amount' => $cents / 100, 'amount_cents' => $cents];
        }

        // مركز الفائض: اختيار صريح، وإلا مركز أعلى فاتورة رقماً للزبون في الكشف (مقترح)، وإلا بلا مركز
        $suggested = $this->suggestedCostCenter(array_merge($rows, $excluded));
        $choice = $surplusChoice !== null && trim($surplusChoice) !== '' ? $surplusChoice : null;
        [$surplusCenter, $source] = match (true) {
            $choice !== null => [$choice, 'chosen'],
            $suggested !== null => [$suggested, 'suggested'],
            default => [null, 'none'],
        };

        return new AllocationResult(
            customerId: $customerId,
            invoiceRows: $rows,
            excludedRows: $excluded,
            costCenterRows: $centerRows,
            paidCents: $paidCents,
            surplusCents: $surplus,
            surplusCostCenter: $surplusCenter,
            surplusCostCenterSource: $source,
            suggestedCostCenter: $suggested,
            incompleteReason: $incomplete,
            awaitingRateCount: $awaiting,
            paymentIds: array_map(fn ($p) => $p['id'], $payments),
            clippedRows: $clipped,
        );
    }

    /**
     * تقسيم $amount على $weights بالتناسب، بالقروش، بطريقة أكبر باقي (largest remainder).
     * الحصة = floor(amount × w / Σw)، ثم القروش الباقية واحداً واحداً لأكبر الكسور، وعند التساوي بالترتيب المعطى.
     * يفترض amount < Σw، فلا تتجاوز أي حصة وزنها.
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    public function largestRemainder(int $amount, array $weights): array
    {
        $weights = array_values($weights);
        $total = array_sum($weights);
        if ($total <= 0 || $amount <= 0) {
            return array_fill(0, count($weights), 0);
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $i => $w) {
            [$q, $r] = self::mulDiv($amount, $w, $total);
            $shares[$i] = $q;
            $remainders[$i] = $r;
        }

        $left = $amount - array_sum($shares);
        $order = array_keys($weights);
        usort($order, fn ($a, $b) => self::compareRemainders($remainders[$b], $remainders[$a]) ?: $a <=> $b);
        foreach ($order as $i) {
            if ($left <= 0) {
                break;
            }
            if ($shares[$i] < $weights[$i]) {
                $shares[$i]++;
                $left--;
            }
        }

        return $shares;
    }

    // =====================================================================

    private function groupFor(?string $series): string
    {
        $this->groups ??= InvoiceSeries::query()->pluck('allocation_group', 'key')->all();
        $key = InvoiceSeries::keyFor($series);
        $group = $key !== null ? ($this->groups[$key] ?? 'other') : 'other';

        return in_array($group, self::GROUP_ORDER, true) ? $group : 'other';
    }

    /**
     * مركز كلفة أعلى فاتورة رقماً (عند التساوي: السلسلة الأكبر)، أو null.
     */
    private function suggestedCostCenter(array $rows): ?string
    {
        $best = null;
        foreach ($rows as $row) {
            if ($row['cost_center'] === null) {
                continue;
            }
            if ($best === null || self::compareNumbers($row['number'], $best['number']) > 0
                || (self::compareNumbers($row['number'], $best['number']) === 0 && strcmp((string) $row['series'], (string) $best['series']) > 0)) {
                $best = $row;
            }
        }

        return $best['cost_center'] ?? null;
    }

    private static function sortKey(array $row): array
    {
        $number = (string) $row['number'];

        return [mb_strtolower((string) $row['series']), ctype_digit($number) ? str_pad($number, 20, '0', STR_PAD_LEFT) : $number];
    }

    private static function compareNumbers(?string $a, ?string $b): int
    {
        $a = (string) $a;
        $b = (string) $b;
        if (ctype_digit($a) && ctype_digit($b)) {
            return str_pad($a, 20, '0', STR_PAD_LEFT) <=> str_pad($b, 20, '0', STR_PAD_LEFT);
        }

        return strnatcmp($a, $b);
    }

    /**
     * floor(a×b/c) وباقيها بدقة كاملة (bcmath إن وُجد؛ وإلا float والقروش الباقية تُصحَّح بأكبر باقي).
     *
     * @return array{0: int, 1: int|float|string}
     */
    private static function mulDiv(int $a, int $b, int $c): array
    {
        if (function_exists('bcmul')) {
            $product = bcmul((string) $a, (string) $b, 0);

            return [(int) bcdiv($product, (string) $c, 0), bcmod($product, (string) $c, 0)];
        }

        $exact = $a * ($b / $c);
        $q = (int) floor($exact);

        return [$q, $exact - $q];
    }

    private static function compareRemainders(int|float|string $a, int|float|string $b): int
    {
        if (is_string($a) && is_string($b) && function_exists('bccomp')) {
            return bccomp($a, $b, 0);
        }

        return (float) $a <=> (float) $b;
    }

    // =====================================================================
    // أدوات عامة
    // =====================================================================

    /**
     * قيمة عشرية (نص أو رقم) إلى قروش صحيحة.
     */
    public static function toCents(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $text = is_string($value) ? trim($value) : sprintf('%.4F', (float) $value);
        if (preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $text, $m)) {
            $fraction = str_pad(substr($m[3] ?? '', 0, 3), 3, '0');
            $cents = (int) ($m[2] === '' ? '0' : $m[2]) * 100 + intdiv((int) $fraction + 5, 10);

            return ($m[1] ?? '') === '-' ? -$cents : $cents;
        }

        return (int) round((float) $value * 100);
    }

    /**
     * مركز الكلفة بصيغة «كود-اسم» كما في ملف الحركة.
     */
    public static function costCenterLabel(?string $code, ?string $name): ?string
    {
        $code = trim((string) $code);
        $name = trim((string) $name);
        if ($code === '' && $name === '') {
            return null;
        }

        return $code !== '' && $name !== '' ? $code.'-'.$name : ($code !== '' ? $code : $name);
    }

    /**
     * «G: 12830» من «ع G: 12830».
     */
    public static function invoiceLabel(string $invoiceNumber): string
    {
        [$series, $number] = InvoiceFingerprint::split($invoiceNumber);

        return $series !== null ? "{$series}: {$number}" : (string) $number;
    }
}
