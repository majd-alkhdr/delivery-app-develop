<?php

namespace App\Services\Reps;

use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\CostCenter;
use Illuminate\Support\Collection;

/**
 * المرحلة 8A: ما يراه مشرف مندوبين واحد عند تفعيل الحصر (تُنشئه RepScope::forSupervisor فقط).
 * الفاتورة ضمن النطاق = مركز كلفتها لمندوب من مندوبيه، وزبونها ضمن نطاق ذلك المندوب عنده (أو نطاقه all).
 */
class RepViewerScope
{
    /** @var array<string, int> كود مطبَّع => rep_id */
    private array $codeToRep = [];

    private ?Collection $invoiceRows = null;

    /** @var array<int, true>|null */
    private ?array $invoiceIdSet = null;

    /**
     * @param  array<int, array{rep: User, code: ?string, all: bool, customers: array<int, true>, labels: array<int, string>, count: int}>  $reps
     */
    public function __construct(public readonly User $supervisor, private array $reps)
    {
        foreach ($reps as $repId => $r) {
            if ($r['code'] !== null) {
                $this->codeToRep[$r['code']] ??= $repId;
            }
        }
    }

    /**
     * @return array<int, int>
     */
    public function repIds(): array
    {
        return array_keys($this->reps);
    }

    public function reps(): Collection
    {
        return collect($this->reps)->map(fn ($r) => $r['rep'])->sortBy('name', SORT_NATURAL)->values();
    }

    public function allowsRep(User|int $rep): bool
    {
        return isset($this->reps[$rep instanceof User ? $rep->id : $rep]);
    }

    public function isAll(int $repId): bool
    {
        return (bool) ($this->reps[$repId]['all'] ?? false);
    }

    /**
     * زبائن المندوب ضمن النطاق: null = كل صيدلياته، [] = لا شيء.
     *
     * @return array<int, int>|null
     */
    public function customerIdsFor(int $repId): ?array
    {
        if (! isset($this->reps[$repId])) {
            return [];
        }

        return $this->reps[$repId]['all'] ? null : array_keys($this->reps[$repId]['customers']);
    }

    public function allowsCustomerForRep(int $repId, ?int $customerId): bool
    {
        if (! isset($this->reps[$repId]) || $customerId === null) {
            return false;
        }

        return $this->reps[$repId]['all'] || isset($this->reps[$repId]['customers'][$customerId]);
    }

    /**
     * فاتورة (بمركز كلفتها الخام وزبونها) ضمن النطاق؟
     */
    public function allowsInvoice(?string $costCenterCode, ?int $customerId): bool
    {
        $code = CostCenter::normalizeCode($costCenterCode);
        $repId = $code !== null ? ($this->codeToRep[$code] ?? null) : null;

        return $repId !== null && $this->allowsCustomerForRep($repId, $customerId);
    }

    /**
     * دفعة مندوب ضمن النطاق: سجّلها أحد مندوبيه على صيدلية من نطاقه.
     */
    public function allowsPayment(Payment $payment): bool
    {
        return $payment->source === 'rep' && $this->allowsCustomerForRep((int) $payment->entered_by, $payment->customer_id !== null ? (int) $payment->customer_id : null);
    }

    /**
     * فواتير مندوبيه ضمن النطاق (كل الحالات): id، الكشف، الزبون، الموضع. استعلام واحد يُحفظ.
     */
    public function invoiceRows(): Collection
    {
        if ($this->invoiceRows !== null) {
            return $this->invoiceRows;
        }
        if ($this->codeToRep === []) {
            return $this->invoiceRows = collect();
        }
        $variants = collect(array_keys($this->codeToRep))->flatMap(fn ($c) => CostCenter::codeVariants($c))->unique()->values()->all();

        return $this->invoiceRows = Invoice::query()->whereIn('cost_center_code', $variants)->whereNotNull('customer_id')
            ->toBase()->get(['id', 'distribution_sheet_id', 'customer_id', 'cost_center_code', 'placement'])
            ->filter(fn ($i) => $this->allowsInvoice($i->cost_center_code, (int) $i->customer_id))
            ->values();
    }

    /**
     * @return array<int, int>
     */
    public function invoiceIds(): array
    {
        return $this->invoiceRows()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function allowsInvoiceId(int $invoiceId): bool
    {
        $this->invoiceIdSet ??= array_fill_keys($this->invoiceIds(), true);

        return isset($this->invoiceIdSet[$invoiceId]);
    }

    /**
     * الكشوف التي فيها فواتير ضمن النطاق (موضوعة في الكشف).
     *
     * @return array<int, int>
     */
    public function sheetIds(): array
    {
        return $this->invoiceRows()->where('placement', Invoice::IN_SHEET)->pluck('distribution_sheet_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    public function allowsSheet(DistributionSheet|int $sheet): bool
    {
        return in_array($sheet instanceof DistributionSheet ? $sheet->id : $sheet, $this->sheetIds(), true);
    }

    /**
     * الزبائن الذين لهم فواتير ضمن النطاق.
     *
     * @return array<int, int>
     */
    public function customerIds(): array
    {
        return $this->invoiceRows()->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * «النطاق: نهر عيشة، المزة (12 صيدلية)» — لكل المندوبين أو لمندوب واحد.
     */
    public function label(?int $repId = null): string
    {
        $reps = $repId !== null ? array_intersect_key($this->reps, [$repId => true]) : $this->reps;
        if ($reps === []) {
            return 'النطاق: لا مندوبين مُسنَدين لك بعد';
        }
        $labels = collect($reps)->flatMap(fn ($r) => $r['labels'])->unique()->values();
        $count = collect($reps)->sum('count');

        return 'النطاق: '.$labels->implode('، ').' ('.$count.' صيدلية)';
    }
}
