<?php

namespace App\Services\Reps;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\Finance\PaymentAllocationEngine;
use App\Services\Finance\RepReceivableService;
use App\Services\Finance\UnpaidReportService;
use App\Support\ArabicNormalizer;
use App\Support\CostCenter;
use App\Support\InvoiceFingerprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * المرحلة 8D: «كشف الصيدلية الكامل» — عرض فقط، بلا أي حساب دَين جديد:
 * - الرصيد الكامل = CustomerBalanceService::currentBalance (الخام − المؤجل − دفعات لم تنعكس).
 * - حصة المندوب (ذممه) = RepReceivableService::state($rep, [$customer]) حرفياً (نفس رقم «ذممي»).
 * - حصة المندوب من دفعة موزع = ما وزّعه محرك التوزيع على فواتيره: لقطات payment_allocations (المصدَّرة)
 *   أو liveAllocation (المعلّقة) — لا توزيع جديد.
 * - من يرى حصة من: المندوب نفسه؛ مشرف المندوبين لمندوبيه المسنَدين على صيدليات نطاقه (RepScope::forSupervisor)؛ الأدمن للكل.
 * - لا يحمل أي صف لفاتورة/دفعة خارج «مندوبي الحصص» متبقياً أو مسدَّداً أو حصة (لا تصل إلى القالب أصلاً).
 */
class PharmacyStatementService
{
    public const SETTING = 'rep_full_statement';

    /** الفترة الافتراضية: فواتير آخر N يوماً + كل ما لم يُسدَّد من فواتير الحصص + كل ما هو قيد التسليم */
    public const DEFAULT_DAYS = 90;

    /** الكشوف المرسلة: قيد التسليم (pending/in_progress) والمكتملة */
    public const SHEET_STATUSES = ['pending', 'in_progress', 'completed'];

    public const PER_PAGE = 25;

    public const NO_REP = 'غير منسوب لمندوب';

    /** @var array<string, User>|null كود مطبَّع => المندوب */
    private ?array $repsByCode = null;

    public function __construct(
        private RepReceivableService $receivables,
        private CustomerBalanceService $balances,
        private RepScope $repScope,
        private UnpaidReportService $unpaid,
        private PaymentAllocationEngine $engine,
    ) {
    }

    /**
     * مفتاح الأمان (الافتراضي مفعّل): عند إيقافه يرى المندوب (ومشرف المندوبين) فواتير/دفعات مندوبي حصصه فقط.
     */
    public static function fullEnabled(): bool
    {
        return (bool) Setting::get(self::SETTING, true);
    }

    // =====================================================================
    // المندوبون ومراكز الكلفة
    // =====================================================================

    /**
     * كل المندوبين بمراكز كلفتهم (استعلام واحد يُحفظ). عند تكرار الكود يُعتمد الأقدم (كما في RepViewerScope).
     *
     * @return array<string, User>
     */
    public function repsByCode(): array
    {
        if ($this->repsByCode !== null) {
            return $this->repsByCode;
        }
        $map = [];
        foreach (User::where('role', Role::Rep)->whereNotNull('cost_center_code')->orderBy('id')->get(['id', 'name', 'role', 'cost_center_code', 'cost_center_label']) as $rep) {
            $code = $rep->hasCostCenter() ? CostCenter::normalizeCode($rep->cost_center_code) : null;
            if ($code !== null) {
                $map[$code] ??= $rep;
            }
        }

        return $this->repsByCode = $map;
    }

    public function repForCode(?string $rawCode): ?User
    {
        $code = CostCenter::normalizeCode($rawCode);

        return $code !== null ? ($this->repsByCode()[$code] ?? null) : null;
    }

    /**
     * «صيدليات المندوب» لهذه الشاشة: زبائن له فيها فاتورة واحدة على الأقل بمركز كلفته، غير مستبعدة،
     * بأي موضع أو حالة كشف (مؤجلة، ملغاة، بلا كشف، في كشف…)، ليست سجلاً خارجياً.
     *
     * @param  array<int, int>|null  $customerIds
     * @return array<int, int>
     */
    public function soldCustomerIds(User $rep, ?array $customerIds = null): array
    {
        if (! $rep->hasCostCenter() || $customerIds === []) {
            return [];
        }
        $code = CostCenter::normalizeCode($rep->cost_center_code);

        return Invoice::query()
            ->whereIn('cost_center_code', CostCenter::codeVariants($code))
            ->whereNotNull('customer_id')
            ->where('placement', '!=', Invoice::EXCLUDED)
            ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
            ->when($customerIds !== null, fn ($q) => $q->whereIn('customer_id', $customerIds))
            ->select(['customer_id', 'cost_center_code'])->distinct()->toBase()->get()
            ->filter(fn ($r) => CostCenter::normalizeCode($r->cost_center_code) === $code)
            ->pluck('customer_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * أكواد المندوبين الذين باعوا الصيدلية (فواتير غير مستبعدة).
     *
     * @return array<int, string>
     */
    private function sellerCodes(int $customerId): array
    {
        return Invoice::query()->where('customer_id', $customerId)->whereNotNull('cost_center_code')
            ->where('placement', '!=', Invoice::EXCLUDED)
            ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
            ->distinct()->pluck('cost_center_code')
            ->map(fn ($c) => CostCenter::normalizeCode($c))->filter()->unique()->values()->all();
    }

    // =====================================================================
    // من يرى ماذا
    // =====================================================================

    /**
     * مندوبو الحصص للمشاهِد على هذه الصيدلية (مفتاحها rep_id)، أو null = لا وصول (403).
     * - rep: نفسه إن باعها (≥ فاتورة غير مستبعدة بمركزه).
     * - rep_supervisor: مندوبوه المسنَدون له إسناداً فعّالاً يغطي هذه الصيدلية (RepScope::forSupervisor، مستقل عن إعداد الحصر)،
     *   والوصول مشروط بأن يكون أحدهم قد باعها.
     * - admin: كل مندوب باعها أو سجّل عليها تحصيلاً.
     *
     * @return Collection<int, User>|null
     */
    public function shareReps(User $viewer, Customer $customer): ?Collection
    {
        $cid = (int) $customer->id;
        if ($viewer->role === Role::Rep) {
            return $this->soldCustomerIds($viewer, [$cid]) !== [] ? collect([$viewer->id => $viewer]) : null;
        }
        if (! $viewer->hasRole(Role::Admin, Role::RepSupervisor)) {
            return null;
        }

        $byCode = $this->repsByCode();
        $sellers = collect($this->sellerCodes($cid))->map(fn ($c) => $byCode[$c] ?? null)->filter()->keyBy('id');
        $collectors = Payment::active()->where('source', 'rep')->where('customer_id', $cid)->distinct()->pluck('entered_by')->map(fn ($id) => (int) $id)->all();
        $candidates = $sellers->union(collect($byCode)->filter(fn ($r) => in_array($r->id, $collectors, true))->keyBy('id'))->sortBy('name', SORT_NATURAL);

        if ($viewer->role === Role::Admin) {
            return $candidates;
        }

        $scope = $this->repScope->forSupervisor($viewer);
        if (! $sellers->contains(fn ($r) => $scope->allowsCustomerForRep($r->id, $cid))) {
            return null;
        }

        return $candidates->filter(fn ($r) => $scope->allowsCustomerForRep($r->id, $cid));
    }

    /**
     * الصيدليات التي يحق للمشاهِد فتح كشفها (null = كلها: الأدمن) — لعرض الروابط دون 403 (يُحفظ على الطلب).
     *
     * @return array<int, true>|null
     */
    public function allowedCustomerSet(User $viewer): ?array
    {
        if ($viewer->role === Role::Admin) {
            return null;
        }
        $key = 'pharmacy_statement_allowed:'.$viewer->id;
        $request = request();
        if ($request && $request->attributes->has($key)) {
            return $request->attributes->get($key);
        }
        $set = match ($viewer->role) {
            Role::Rep => array_fill_keys($this->soldCustomerIds($viewer), true),
            Role::RepSupervisor => $this->repScope->forSupervisor($viewer)->invoiceRows()
                ->where('placement', '!=', Invoice::EXCLUDED)
                ->mapWithKeys(fn ($r) => [(int) $r->customer_id => true])->all(),
            default => [],
        };
        $request?->attributes->set($key, $set);

        return $set;
    }

    /**
     * رابط كشف الصيدلية للمشاهِد، أو null إن لم يكن له حق فتحه.
     */
    public static function linkFor(?User $viewer, ?int $customerId): ?string
    {
        if (! $viewer || ! $customerId || ! $viewer->hasRole(Role::Admin, Role::RepSupervisor, Role::Rep)) {
            return null;
        }
        $set = app(self::class)->allowedCustomerSet($viewer);
        if ($set !== null && ! isset($set[$customerId])) {
            return null;
        }

        return $viewer->role === Role::Rep
            ? route('rep.pharmacies.show', $customerId)
            : route('rep-supervisor.pharmacies.show', $customerId);
    }

    // =====================================================================
    // «صيدلياتي» للمندوب
    // =====================================================================

    /**
     * @return array{rows: LengthAwarePaginator, total: int}
     */
    public function pharmaciesForRep(User $rep, ?string $q, int $page = 1): array
    {
        $ids = $this->soldCustomerIds($rep);
        $customers = Customer::whereIn('id', $ids ?: [0])->get(['id', 'name', 'area', 'block', 'balance', 'balance_as_of']);
        $balances = $customers->isNotEmpty() ? $this->balances->currentBalances($customers) : [];
        // الرقم نفسه في «ذممي» (receivablesForRep = state($rep))
        $state = $this->receivables->state($rep);

        $needle = ArabicNormalizer::normalize((string) $q);
        $rows = $customers->map(fn (Customer $c) => [
            'customer' => $c,
            'balance' => $balances[$c->id] ?? null,
            'balance_cents' => PaymentAllocationEngine::toCents($balances[$c->id]?->net ?? 0),
            'share_cents' => (int) ($state->get($c->id)['remaining_cents'] ?? 0),
        ])
            ->when($needle !== '', fn ($c) => $c->filter(fn ($r) => str_contains(ArabicNormalizer::normalize((string) $r['customer']->name), $needle)
                || str_contains(ArabicNormalizer::normalize((string) $r['customer']->area), $needle)))
            ->sort(fn ($a, $b) => [$b['share_cents'], $a['customer']->name] <=> [$a['share_cents'], $b['customer']->name])
            ->values();

        $perPage = 30;
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page, [
            'path' => request()->url(), 'query' => request()->query(),
        ]);

        return ['rows' => $paginator, 'total' => count($ids)];
    }

    // =====================================================================
    // الكشف
    // =====================================================================

    /**
     * @param  Collection<int, User>  $shareReps  من shareReps()
     * @param  array{q?: ?string, who?: ?string, period?: ?string, from?: ?string, to?: ?string, page?: int, pp?: int}  $filters
     */
    public function build(User $viewer, Customer $customer, Collection $shareReps, array $filters = []): array
    {
        $cid = (int) $customer->id;
        $full = $viewer->role === Role::Admin || self::fullEnabled();
        $shareCodes = $shareReps->mapWithKeys(fn (User $r) => [CostCenter::normalizeCode($r->cost_center_code) => $r->id])->all();

        // 1) الرصيد الكامل والحصص (state لكل مندوب حصة، بفواتير دَين محمَّلة مرة واحدة)
        $balance = $this->balances->currentBalance($customer);
        $debt = $shareCodes !== [] ? $this->receivables->debtInvoices(array_map('strval', array_keys($shareCodes)), [$cid]) : collect();
        $states = $shareReps->mapWithKeys(fn (User $r) => [$r->id => $this->receivables->state($r, [$cid], $debt)->get($cid)]);

        // 2) الفواتير: كشوف مرسلة (قيد التسليم/مكتملة)، in_sheet غير مؤجلة، ليست خارجية (نطاق الذمم نفسه)
        $invoices = $this->receivables->scopedInvoices(null, [$cid], self::SHEET_STATUSES);
        $sheets = DistributionSheet::whereIn('id', $invoices->pluck('distribution_sheet_id')->unique()->all() ?: [0])
            ->get(['id', 'status', 'distribution_date', 'delivery_order_no'])->keyBy('id');
        // invoice_id => صف state (بمفاتيح محفوظة؛ flatMap يعيد الترقيم)
        $stateRows = [];
        foreach ($states as $s) {
            foreach ($s['invoices'] ?? [] as $row) {
                $stateRows[$row['invoice_id']] = $row;
            }
        }
        $since = now('Asia/Damascus')->subDays(self::DEFAULT_DAYS)->toDateString();

        $invoiceRows = $invoices->map(function (Invoice $i) use ($sheets, $shareCodes, $stateRows) {
            $sheet = $sheets[$i->distribution_sheet_id] ?? null;
            $code = CostCenter::normalizeCode($i->cost_center_code);
            $rep = $code !== null ? ($this->repsByCode()[$code] ?? null) : null;
            $shareRepId = $code !== null ? ($shareCodes[$code] ?? null) : null;
            $delivered = $sheet?->status === 'completed';
            [$series, $number] = InvoiceFingerprint::split((string) $i->invoice_number);
            $row = [
                'invoice_id' => $i->id,
                'label' => PaymentAllocationEngine::invoiceLabel((string) $i->invoice_number),
                'series' => $i->series ?? $series,
                'number' => $number,
                'date' => $sheet?->distribution_date?->toDateString(),
                'amount_cents' => PaymentAllocationEngine::toCents($i->effectiveAmount()),
                'rep_id' => $rep?->id,
                'rep_name' => $rep?->name ?? self::NO_REP,
                'order' => $sheet?->orderLabel(),
                'delivered' => $delivered,
                'status_label' => $delivered ? 'مسلَّمة' : 'قيد التسليم',
                'mine' => $shareRepId !== null,
                'share_rep_id' => $shareRepId,
            ];
            // المتبقي والمسدَّد لفواتير مندوبي الحصص فقط (ولا مفتاح لهما في غيرها)
            if ($shareRepId !== null) {
                $s = $delivered ? ($stateRows[$i->id] ?? null) : null;
                $row['remaining_cents'] = $s['remaining_cents'] ?? null;
                $row['paid_cents'] = $s !== null ? max(0, $s['total_cents'] - $s['remaining_cents']) : null;
            }

            return $row;
        });
        if (! $full) {
            $invoiceRows = $invoiceRows->where('mine', true);
        }

        // 3) الدفعات وحصصها
        $payments = $this->paymentRows($cid, $shareReps, $full);

        // 4) الملخص
        $netCents = PaymentAllocationEngine::toCents($balance->net);
        $repCards = $shareReps->map(function (User $r) use ($states, $payments, $invoiceRows) {
            $s = $states[$r->id] ?? null;

            return [
                'rep' => $r,
                'remaining_cents' => (int) ($s['remaining_cents'] ?? 0),
                'over_collected' => $s['over_collected'] ?? [],
                'payments_share_cents' => $payments->sum(fn ($p) => $p['shares'][$r->id] ?? 0),
                'open_invoices' => $invoiceRows->where('share_rep_id', $r->id)->filter(fn ($i) => ($i['remaining_cents'] ?? 0) > 0)->sortBy('date')->values(),
            ];
        })->values();
        $sharesCents = $repCards->sum('remaining_cents');

        // 5) الفلاتر والترقيم
        $filtered = $this->filterInvoices($invoiceRows, $filters, $since);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $ppage = max(1, (int) ($filters['pp'] ?? 1));
        $query = request()->query();

        return [
            'customer' => $customer,
            'full' => $full,
            'balance' => $balance,
            'balance_cents' => $netCents,
            'shares_cents' => $sharesCents,
            'rest_cents' => $netCents - $sharesCents,
            'rep_cards' => $repCards,
            'since' => $since,
            'invoice_count' => $invoiceRows->count(),
            'invoices' => new LengthAwarePaginator($filtered->forPage($page, self::PER_PAGE)->values(), $filtered->count(), self::PER_PAGE, $page, [
                'path' => request()->url(), 'query' => $query, 'pageName' => 'page',
            ]),
            'payments' => new LengthAwarePaginator($payments->forPage($ppage, self::PER_PAGE)->values(), $payments->count(), self::PER_PAGE, $ppage, [
                'path' => request()->url(), 'query' => $query, 'pageName' => 'pp',
            ]),
            'payments_total_cents' => $payments->sum('amount_cents'),
        ];
    }

    /**
     * الافتراضي: آخر DEFAULT_DAYS يوماً + كل فواتير الحصص غير المسدَّدة (أي عمر) + كل ما هو قيد التسليم.
     * (فواتير غير المندوب الأقدم لا تظهر إلا بـ«عرض الكل» أو تاريخ: لا يُستدل من ظهورها على أنها غير مسدَّدة.)
     */
    private function filterInvoices(Collection $rows, array $filters, string $since): Collection
    {
        $q = preg_replace('/\s+/u', '', (string) ($filters['q'] ?? ''));
        $period = $filters['period'] ?? 'default';

        return $rows
            ->when(($filters['who'] ?? 'all') === 'mine', fn ($c) => $c->where('mine', true))
            ->when($q !== '', fn ($c) => $c->filter(fn ($r) => str_contains(preg_replace('/\s+/u', '', mb_strtolower($r['label'].' '.$r['number'])), mb_strtolower($q))))
            ->when($period === 'default', fn ($c) => $c->filter(fn ($r) => ! $r['delivered'] || (string) $r['date'] >= $since || ($r['remaining_cents'] ?? 0) > 0))
            ->when($period === 'range', fn ($c) => $c->filter(fn ($r) => (empty($filters['from']) || (string) $r['date'] >= $filters['from']) && (empty($filters['to']) || (string) $r['date'] <= $filters['to'])))
            ->sort(fn ($a, $b) => [(string) $b['date'], $b['invoice_id']] <=> [(string) $a['date'], $a['invoice_id']])
            ->values();
    }

    /**
     * كل دفعات الصيدلية غير الملغاة، ولكل منها حصص مندوبي الحصص فقط (rep_id => قروش).
     * - دفعة مندوب: كاملة لصاحبها إن كان من مندوبي الحصص.
     * - دفعة موزع مصدَّرة: لقطات payment_allocations (source=distributor) لتصديرها وكشفها، على فواتير المندوب.
     * - دفعة موزع معلّقة: liveAllocation للكشف (المحرك نفسه).
     * التوزيع يتم على مجموع دفعات الصيدلية في الكشف/التصدير؛ إن كانت أكثر من دفعة فالحصة تُقسَم بينها بالتناسب (أكبر باقي).
     */
    private function paymentRows(int $cid, Collection $shareReps, bool $full): Collection
    {
        $shareIds = $shareReps->keys()->map(fn ($id) => (int) $id)->all();
        $payments = Payment::active()->where('customer_id', $cid)
            ->with('enteredBy:id,name')
            ->when(! $full, fn ($q) => $q->where('source', 'rep')->whereIn('entered_by', $shareIds ?: [0]))
            ->orderByDesc('collected_at')->orderByDesc('id')
            ->get();

        $dist = $payments->filter(fn (Payment $p) => $p->source !== 'rep' && $p->distribution_sheet_id !== null);
        $exported = $dist->filter(fn (Payment $p) => $p->status !== 'pending' && $p->finance_export_id !== null);
        $pending = $dist->filter(fn (Payment $p) => $p->status === 'pending');

        // ما وُزِّع على كل فاتورة لكل مجموعة (تصدير:كشف أو معلّق:كشف)
        $groupAlloc = [];
        if ($shareIds !== [] && $exported->isNotEmpty()) {
            PaymentAllocation::query()->where('source', PaymentAllocation::SOURCE_DISTRIBUTOR)->where('customer_id', $cid)
                ->whereIn('finance_export_id', $exported->pluck('finance_export_id')->unique()->all())
                ->get(['invoice_id', 'finance_export_id', 'distribution_sheet_id', 'allocated'])
                ->each(function ($a) use (&$groupAlloc) {
                    $key = 'x'.$a->finance_export_id.':'.$a->distribution_sheet_id;
                    $groupAlloc[$key][(int) $a->invoice_id] = ($groupAlloc[$key][(int) $a->invoice_id] ?? 0) + PaymentAllocationEngine::toCents($a->allocated);
                });
        }
        if ($shareIds !== []) {
            foreach (DistributionSheet::whereIn('id', $pending->pluck('distribution_sheet_id')->unique()->all() ?: [0])->get() as $sheet) {
                $result = $this->unpaid->liveAllocation($sheet, [$cid])['results'][$cid] ?? null;
                foreach ($result?->invoiceRows ?? [] as $row) {
                    if ($row['allocated_cents'] > 0) {
                        $groupAlloc['p'.$sheet->id][(int) $row['invoice_id']] = $row['allocated_cents'];
                    }
                }
            }
        }

        // كود كل فاتورة موزَّع عليها ⇒ مندوب حصة
        $invoiceIds = collect($groupAlloc)->flatMap(fn ($byInvoice) => array_keys($byInvoice))->unique()->values()->all();
        $codeToShare = $shareReps->mapWithKeys(fn (User $r) => [CostCenter::normalizeCode($r->cost_center_code) => $r->id])->all();
        $invoiceRep = $invoiceIds === [] ? [] : Invoice::whereIn('id', $invoiceIds)->pluck('cost_center_code', 'id')
            ->map(fn ($c) => $codeToShare[CostCenter::normalizeCode($c)] ?? null)->filter()->all();

        // حصة كل مندوب في كل مجموعة، ثم تقسيمها على دفعات المجموعة
        $shares = [];
        $groups = $exported->groupBy(fn ($p) => 'x'.$p->finance_export_id.':'.$p->distribution_sheet_id)
            ->union($pending->groupBy(fn ($p) => 'p'.$p->distribution_sheet_id));
        foreach ($groups as $key => $group) {
            $byRep = [];
            foreach ($groupAlloc[$key] ?? [] as $invoiceId => $cents) {
                if (isset($invoiceRep[$invoiceId])) {
                    $byRep[$invoiceRep[$invoiceId]] = ($byRep[$invoiceRep[$invoiceId]] ?? 0) + $cents;
                }
            }
            $group = $group->filter(fn ($p) => $p->amount_syp !== null)->sortBy('id')->values();
            $weights = $group->map(fn ($p) => PaymentAllocationEngine::toCents($p->amount_syp))->all();
            foreach ($byRep as $repId => $cents) {
                $split = $cents >= array_sum($weights) ? $weights : $this->engine->largestRemainder($cents, $weights);
                foreach ($group as $k => $p) {
                    if (($split[$k] ?? 0) > 0) {
                        $shares[$p->id][$repId] = $split[$k];
                    }
                }
            }
        }

        return $payments->map(function (Payment $p) use ($shares, $shareIds) {
            $isRep = $p->source === 'rep';
            $cents = $p->amount_syp !== null ? PaymentAllocationEngine::toCents($p->amount_syp) : 0;
            $rowShares = $isRep
                ? (in_array((int) $p->entered_by, $shareIds, true) ? [(int) $p->entered_by => $cents] : [])
                : ($shares[$p->id] ?? []);

            return [
                'payment_id' => $p->id,
                'date' => $p->collected_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d'),
                'collector' => $p->enteredBy?->name,
                'type' => match ($p->source) {
                    'rep' => 'تحصيل مندوب',
                    'sheet' => 'تحصيل موزع',
                    default => 'دفعة خارجية',
                },
                'is_rep' => $isRep,
                'currency' => $p->currency,
                'amount' => (float) $p->amount,
                'amount_cents' => $cents,
                'awaiting_rate' => $p->amount_syp === null,
                'status_label' => $p->status === 'pending' ? 'معلّقة' : 'مصدَّرة',
                'pending' => $p->status === 'pending',
                'shares' => array_intersect_key($rowShares, array_flip($shareIds)),
            ];
        })->values();
    }
}
