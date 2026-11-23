<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StoreVisitRequest;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\InvoiceSeries;
use App\Models\Payment;
use App\Models\PharmacyLock;
use App\Models\PrintEvent;
use App\Models\Setting;
use App\Models\SheetVisit;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\CustomerMergedInvoiceBuilder;
use App\Services\DistributorScope;
use App\Services\NoticeAckService;
use App\Services\PaymentService;
use App\Services\VisitService;
use App\Support\ArabicNormalizer;
use App\Support\InvoiceLinesView;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * واجهة الموزع (المرحلة 3). كل ما يخص فواتير كشف يمر عبر DistributionSheet::invoicesVisibleTo():
 * الموزع يرى كشوفه المسندة فقط، والفواتير غير المؤجلة من مناطقه فقط، والرصيد المستحق فقط (لا الخام ولا المؤجل).
 * الدفعات والزيارات في جدولي payments وsheet_visits؛ أُزيل منطق الكتابة على أول فاتورة وفي ملفات Excel.
 */
class DistributorController extends Controller
{
    public function __construct(
        private DistributorScope $scope,
        private PaymentService $payments,
        private VisitService $visits,
    ) {
    }

    // =====================================================================
    // 1) قائمة الكشوف + مؤشرات اليوم
    // =====================================================================

    public function index(Request $request)
    {
        $user = $request->user();
        $sheets = DistributionSheet::visibleToDistributor($user)->with('assignments')->latest()->latest('id')->get();

        $today = now('Asia/Damascus')->toDateString();
        $todayStats = ['total' => 0, 'visited' => 0, 'syp' => 0.0, 'usd' => 0.0];

        foreach ($sheets as $sheet) {
            $cards = $this->customerCards($sheet, $user);
            $sheet->total_pharmacies_count = $cards->count();
            $sheet->total_invoices_amount = $cards->sum('total');
            $sheet->total_balances_amount = $cards->sum('due');
            $sheet->stats = $this->stats($cards);

            if ($sheet->distribution_date?->toDateString() === $today) {
                $todayStats['total'] += $sheet->stats['total'];
                $todayStats['visited'] += $sheet->stats['visited'];
                $todayStats['syp'] += $sheet->stats['syp'];
                $todayStats['usd'] += $sheet->stats['usd'];
            }
        }
        $todayStats['not_visited'] = $todayStats['total'] - $todayStats['visited'];
        $todayStats['progress'] = $todayStats['total'] ? (int) round($todayStats['visited'] * 100 / $todayStats['total']) : 0;

        return view('distributor.index', compact('sheets', 'todayStats'));
    }

    // =====================================================================
    // 2) صفحة الكشف (بلا أسطر الأصناف؛ تُجلب من invoice-lines)
    // =====================================================================

    public function showSheet(Request $request, $id)
    {
        $sheet = DistributionSheet::findOrFail($id);
        Gate::authorize('view', $sheet);

        $user = $request->user();
        $cards = $this->customerCards($sheet, $user);
        $stats = $this->stats($cards);
        $areas = $cards->pluck('area')->unique()->sort()->values();
        $canRecord = $sheet->isV2() && $user->can('update', $sheet);

        $config = [
            'sheetId' => $sheet->id,
            'canRecord' => $canRecord,
            'canEnterUsd' => $user->canEnterUsd(),
            'usdRateDefault' => (float) Setting::get('usd_rate_default', 139),
            'usdRateMin' => (float) Setting::get('usd_rate_min', 50),
            'usdRateMax' => (float) Setting::get('usd_rate_max', 500),
            'receiptPrefixLength' => (int) Setting::get('receipt_prefix_length', 3),
            'printingEnabled' => (bool) Setting::get('printing_enabled', false),
            'paymentUrl' => route('distributor.payments.store', $sheet),
            'visitUrl' => route('distributor.visits.store', $sheet),
            'linesUrl' => route('distributor.invoice-lines', $sheet),
            // المرحلة 7H: آخر إشعار يعرفه الجهاز (يُرسل مع كل تسجيل)، ومسار «اطلعت» لكل صيدلية
            'lastNoticeId' => (int) app(\App\Services\SheetChangeNotifier::class)->visibleQuery($sheet, $user, true)->max('id'),
            'ackUrl' => route('distributor.notices.ack', [$sheet, '__CUSTOMER__']),
            'userId' => $user->id,
            'pinMaxAccuracy' => (int) Setting::get('location_pin_max_accuracy_m', 50),
            'radius' => (int) Setting::get('location_radius_m', 150),
        ];

        return view('distributor.show', compact('sheet', 'cards', 'stats', 'areas', 'canRecord', 'config'));
    }

    /**
     * بطاقات الزبائن في الكشف كما يراها الموزع.
     *
     * @return Collection<int, array>
     */
    private function customerCards(DistributionSheet $sheet, User $user): Collection
    {
        $invoices = $sheet->invoicesVisibleTo($user)
            ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
            ->orderBy('id')
            ->get(['id', 'customer_id', 'customer_name', 'area', 'area_key', 'invoice_number', 'series', 'invoice_amount', 'invoice_date', 'is_zero_value', 'final_balance', 'cost_center_code', 'cost_center_name', 'invoice_note', 'added_after_send_at']);

        $customerIds = $invoices->pluck('customer_id')->filter()->unique()->values();

        $visits = SheetVisit::active()->with('user:id,name')->where('distribution_sheet_id', $sheet->id)->whereIn('customer_id', $customerIds)->get()->keyBy('customer_id');
        $payments = Payment::active()->where('distribution_sheet_id', $sheet->id)->whereIn('customer_id', $customerIds)->orderBy('id')->get()->groupBy('customer_id');
        // حالة الموقع المرجعي لكل زبون (بلا إحداثيات خام للواجهة)
        $refStates = Customer::whereIn('id', $customerIds)->get(['id', 'latitude', 'longitude', 'location_confirmed'])
            ->mapWithKeys(fn ($c) => [$c->id => $c->referenceState()]);
        // المرحلة 7B: الرصيد المعروض الواحد (currentBalance) وتنبيه «تغيّر الرصيد» غير المقروء لكل صيدلية
        $balances = app(CustomerBalanceService::class)->currentBalances(Customer::whereIn('id', $customerIds)->get(), $sheet->distribution_date);
        $balanceChanges = app(\App\Services\SheetChangeNotifier::class)->unseenBalanceChanges($sheet, $user);
        // المرحلة 7H: إشعارات إلزامية لم يطّلع عليها الموزع لكل صيدلية (استعلام واحد)
        $pendingAcks = app(NoticeAckService::class)->pendingByCustomer($sheet, $user);
        $locks = $sheet->distribution_date
            ? PharmacyLock::with('holder:id,name')->whereIn('customer_id', $customerIds)->whereDate('distribution_date', $sheet->distribution_date)->whereNotNull('user_id')->get()->keyBy('customer_id')
            : collect();

        return $invoices
            ->groupBy(fn ($i) => $i->customer_id ? 'c'.$i->customer_id : 'n'.$i->customer_name)
            ->map(function (Collection $rows) use ($visits, $payments, $locks, $user, $refStates, $balances, $balanceChanges, $pendingAcks) {
                $first = $rows->first();
                $customerId = $first->customer_id;
                $visit = $customerId ? $visits->get($customerId) : null;
                $customerPayments = $customerId ? $payments->get($customerId, collect()) : collect();
                $lock = $customerId ? $locks->get($customerId) : null;

                // المرحلة 7B: رصيد المحاسبة − المؤجل − الدفعات التي لم تنعكس (ومنها دفعات هذا الكشف) — رقم واحد
                $balance = $customerId ? ($balances[$customerId] ?? null) : null;
                $due = $balance ? $balance->net : round((float) $first->final_balance, 2);
                $syp = round((float) $customerPayments->where('currency', 'SYP')->sum('amount'), 2);
                $usd = round((float) $customerPayments->where('currency', 'USD')->sum('amount'), 2);

                $status = $customerPayments->isNotEmpty() ? 'paid' : ($visit && $visit->status === 'visited' ? 'visited' : 'not_visited');

                return [
                    'key' => $customerId ? 'c'.$customerId : 'n'.md5($first->customer_name),
                    'customer_id' => $customerId,
                    'name' => $first->customer_name,
                    'search' => ArabicNormalizer::normalize($first->customer_name),
                    'area' => $first->area ?: 'بلا منطقة',
                    'invoices' => $rows->map(fn ($i) => [
                        'id' => $i->id,
                        'number' => $i->invoice_number,
                        'series' => $i->series,
                        'amount' => (float) $i->invoice_amount,
                        'date' => $i->invoice_date?->format('Y-m-d'),
                        'is_zero_value' => (bool) $i->is_zero_value,
                        // المرحلة 7H
                        'added_after_send' => $i->added_after_send_at !== null,
                        // المرحلة 6B-G: مركز الكلفة والبيان بجانب كل فاتورة
                        'cost_center' => InvoiceLinesView::costCenter($i),
                        'note' => InvoiceLinesView::note($i->invoice_note),
                    ])->values(),
                    'total' => round((float) $rows->sum('invoice_amount'), 2),
                    'due' => $due,
                    'syp' => $syp,
                    'usd' => $usd,
                    // «المتبقي» = الرصيد المعروض نفسه (الدفعات المسجّلة مطروحة منه ما دامت لم تنعكس)
                    'remaining' => $due,
                    'balance' => $balance,
                    'balance_change' => $customerId ? $balanceChanges->get($customerId) : null,
                    'pending_acks' => $customerId ? $pendingAcks->get($customerId, collect()) : collect(),
                    'visit' => $visit,
                    'payments' => $customerPayments,
                    'status' => $status,
                    'locked_by_other' => $lock && (int) $lock->user_id !== (int) $user->id ? $lock->holder?->name : null,
                    'ref_state' => $customerId ? ($refStates[$customerId] ?? 'none') : null,
                ];
            })
            ->sortBy(fn ($c) => [$c['area'], $c['name']])
            ->values();
    }

    /**
     * مؤشرات الموزع (من السجلات غير الملغاة فقط).
     */
    private function stats(Collection $cards): array
    {
        $total = $cards->count();
        $visited = $cards->whereIn('status', ['visited', 'paid'])->count();

        return [
            'total' => $total,
            'visited' => $visited,
            'not_visited' => $total - $visited,
            'syp' => round((float) $cards->sum('syp'), 2),
            'usd' => round((float) $cards->sum('usd'), 2),
            'progress' => $total ? (int) round($visited * 100 / $total) : 0,
        ];
    }

    // =====================================================================
    // 3) تسجيل دفعة وزيارة على كشف
    // =====================================================================

    public function storePayment(StorePaymentRequest $request, DistributionSheet $sheet)
    {
        $user = $request->user();
        $this->authorizeRecording($sheet, $user);
        [$customer, $outside] = $this->recordingCustomer($sheet, $user, (int) $request->input('customer_id'));
        $ack = Payment::where('client_uuid', strtolower((string) $request->input('client_uuid')))->exists() ? null : $this->ackGate($request, $sheet, $user, $customer);

        $result = $this->payments->record($user, $sheet, $customer, 'sheet', $request->paymentInput() + ['outside_sheet' => $outside, 'ack_context' => $ack]);

        return $this->paymentResponse($result);
    }

    public function storeVisit(StoreVisitRequest $request, DistributionSheet $sheet)
    {
        $user = $request->user();
        $this->authorizeRecording($sheet, $user);
        [$customer, $outside] = $this->recordingCustomer($sheet, $user, (int) $request->input('customer_id'));
        $ack = SheetVisit::where('client_uuid', strtolower((string) $request->input('client_uuid')))->exists() ? null : $this->ackGate($request, $sheet, $user, $customer);

        $result = $this->visits->record(
            $user, $sheet, $customer,
            $request->input('status'), $request->reasonText(),
            strtolower($request->input('client_uuid')),
            $request->filled('latitude') ? (float) $request->input('latitude') : null,
            $request->filled('longitude') ? (float) $request->input('longitude') : null,
            $request->pinInput(),
            $outside,
            $ack,
        );
        $visit = $result['visit'];

        return response()->json([
            'success' => true,
            'duplicate' => ! $result['created'],
            'visit' => [
                'id' => $visit->id,
                'client_uuid' => $visit->client_uuid,
                'customer_id' => $visit->customer_id,
                'status' => $visit->status,
                'reason' => $visit->reason,
                'location_flag' => $visit->location_flag,
                'distance_m' => $visit->distance_m,
            ],
            'warning' => $visit->location_flag === 'far' ? 'أنت بعيد عن موقع الصيدلية.' : null,
        ] + $this->locationResponse($result['location']), $result['created'] ? 201 : 200);
    }

    /**
     * المرحلة 7B: «اطلعت» على تغيّر رصيد صيدلية في هذا الكشف.
     */
    public function balanceSeen(Request $request, DistributionSheet $sheet, Customer $customer)
    {
        Gate::authorize('view', $sheet);
        app(\App\Services\SheetChangeNotifier::class)->markBalanceSeen($sheet, $request->user(), $customer->id);
        // المرحلة 7H: «اطلعت» على تغيّر الرصيد هو اطلاع على إشعاراته الإلزامية أيضاً
        app(NoticeAckService::class)->ackBalance($sheet, $request->user(), $customer->id);

        return $request->expectsJson() ? response()->json(['success' => true]) : back()->with('success', 'تم.');
    }

    /**
     * المرحلة 7H: «اطلعت» على تعديلات صيدلية في الكشف (يفك منع التسجيل عليها). الموزع لكشوفه فقط (Policy::view).
     */
    public function ackCustomer(Request $request, DistributionSheet $sheet, Customer $customer)
    {
        Gate::authorize('view', $sheet);
        $count = app(NoticeAckService::class)->ackCustomer($sheet, $request->user(), $customer->id);

        return $request->expectsJson()
            ? response()->json(['success' => true, 'acked' => $count, 'customer_id' => $customer->id])
            : back()->with('success', 'تم الاطلاع على تعديلات '.$customer->name.'.');
    }

    /**
     * المرحلة 7H: منع التسجيل على صيدلية لها إشعار إلزامي لم يطّلع عليه الموزع.
     * الطلب الحي ⇒ 409 برسالة عربية (التطبيق يُبقي العنصر في الطابور حتى «اطلعت»، فلا يضيع).
     * القادم من الطابور دون اتصال (offline=1) ⇒ يُقبل دائماً، ويُعاد سياقه ليُوسَم السجل «سُجّل قبل اطلاع الموزع على تعديل».
     *
     * @return array<string, mixed>|null سياق الوسم، أو null إن لم يوجد ما يمنع
     */
    private function ackGate(Request $request, DistributionSheet $sheet, User $user, Customer $customer): ?array
    {
        $pending = app(NoticeAckService::class)->pendingForCustomer($sheet, $user, $customer->id);
        if ($pending->isEmpty()) {
            return null;
        }

        if (! $request->boolean('offline')) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => NoticeAckService::message($pending),
                'ack_required' => true,
                'customer_id' => $customer->id,
                'ack_url' => route('distributor.notices.ack', [$sheet, $customer]),
                'notices' => $pending->map(fn ($n) => ['id' => $n->id, 'type' => $n->type, 'text' => NoticeAckService::detail($n)])->values(),
            ], 409));
        }

        return NoticeAckService::context($pending, $request->input('recorded_at'), $request->filled('known_notice_id') ? (int) $request->input('known_notice_id') : null);
    }

    // =====================================================================
    // 4) تبويب العملاء والدفعة الخارجية
    // =====================================================================

    public function customers(Request $request, CustomerBalanceService $balances)
    {
        $user = $request->user();
        $query = $this->scope->customersQuery($user)->orderBy('name');

        $areas = (clone $query)->whereNotNull('area')->distinct()->orderBy('area')->pluck('area');

        if ($request->filled('q')) {
            $query->where('name_normalized', 'like', '%'.ArabicNormalizer::normalize($request->q).'%');
        }
        if ($request->filled('area')) {
            $request->area === '__none__'
                ? $query->whereNull('area_normalized')
                : $query->where('area_normalized', ArabicNormalizer::normalize($request->area));
        }

        $customers = $query->paginate(50)->withQueryString();
        $due = collect($balances->currentBalances($customers->getCollection()))->map(fn ($b) => $b->net)->all();
        $hasOpenSheet = $this->scope->openSheets($user)->exists();

        $config = [
            'externalUrl' => route('distributor.payments.external'),
            'canRecord' => $hasOpenSheet,
            'canEnterUsd' => $user->canEnterUsd(),
            'usdRateDefault' => (float) Setting::get('usd_rate_default', 139),
            'usdRateMin' => (float) Setting::get('usd_rate_min', 50),
            'usdRateMax' => (float) Setting::get('usd_rate_max', 500),
            'receiptPrefixLength' => (int) Setting::get('receipt_prefix_length', 3),
            'userId' => $user->id,
            'pinMaxAccuracy' => (int) Setting::get('location_pin_max_accuracy_m', 50),
            'radius' => (int) Setting::get('location_radius_m', 150),
            'printingEnabled' => (bool) Setting::get('printing_enabled', false),
        ];

        return view('distributor.customers', compact('customers', 'due', 'areas', 'hasOpenSheet', 'config'));
    }

    public function storeExternalPayment(StorePaymentRequest $request)
    {
        $user = $request->user();
        $customer = Customer::findOrFail((int) $request->input('customer_id'));

        // الكشف: مفتوح (pending/in_progress) للموزع ويغطي إسناده منطقة الزبون، والأحدث رفعاً
        $sheet = $this->scope->openSheetFor($user, $customer);
        if (! $sheet) {
            if (! $this->scope->openSheets($user)->exists()) {
                return response()->json(['message' => 'لا يوجد لديك كشف مفتوح اليوم، فلا يمكن تسجيل دفعة خارجية. تواصل مع مشرف التوزيع.'], 422);
            }
            abort(403, 'هذا الزبون خارج مناطقك المسندة.');
        }
        if ($customer->is_hidden) {
            abort(403, 'هذا الزبون مخفي.');
        }
        $ack = Payment::where('client_uuid', strtolower((string) $request->input('client_uuid')))->exists() ? null : $this->ackGate($request, $sheet, $user, $customer);

        $result = $this->payments->record($user, $sheet, $customer, 'external', $request->paymentInput() + ['ack_context' => $ack]);

        return $this->paymentResponse($result);
    }

    // =====================================================================
    // 5) أسطر الفواتير للعمل دون اتصال (J)
    // =====================================================================

    public function invoiceLines(Request $request, DistributionSheet $sheet, CustomerMergedInvoiceBuilder $merged, CustomerBalanceService $balances)
    {
        Gate::authorize('view', $sheet);
        $user = $request->user();

        $visible = $sheet->invoicesVisibleTo($user)
            ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'));

        // ETag من أحدث updated_at للفواتير المرئية وعددها (والرصيد المستحق المعروض في الفاتورة المجمّعة)
        $meta = (clone $visible)->selectRaw('COUNT(*) as c, MAX(updated_at) as u')->first();
        $invoices = (clone $visible)->with(['lines', 'customer'])->orderBy('id')->get();
        $customers = $invoices->pluck('customer')->filter()->unique('id')->values();
        // المرحلة 7B: الرصيد المعروض الواحد (net) في الفاتورة المجمّعة والإيصال دون اتصال
        $dues = collect($balances->currentBalances($customers, $sheet->distribution_date))->map(fn ($b) => $b->net)->all();
        // المرحلة 5: دفعات الزبون غير الملغاة في يوم التوزيع نفسه عبر كل الكشوف (لـ«الرصيد قبل» في الإيصال دون اتصال)
        $paidToday = $this->paidToday($sheet, $customers->pluck('id')->all());

        // عدد الطباعات الفعلية السابقة لكل فاتورة ولكل فاتورة مجمّعة (لوسم «نسخة مكررة» دون اتصال)
        $printCounts = [
            'invoices' => PrintEvent::counted()->where('kind', 'invoice')->whereIn('invoice_id', $invoices->pluck('id'))
                ->selectRaw('invoice_id, COUNT(*) as c')->groupBy('invoice_id')->pluck('c', 'invoice_id')->map(fn ($c) => (int) $c),
            'merged' => PrintEvent::counted()->where('kind', 'merged')->where('distribution_sheet_id', $sheet->id)
                ->selectRaw('customer_id, COUNT(*) as c')->groupBy('customer_id')->pluck('c', 'customer_id')->map(fn ($c) => (int) $c),
        ];

        // المرحلة 7H: الإشعارات الإلزامية غير المُطَّلَع عليها لكل صيدلية (تصل الجهاز عند أول اتصال فيظهر الإلزام دون اتصال)
        $pendingAcks = app(NoticeAckService::class)->pendingByCustomer($sheet, $user)
            ->map(fn ($notices) => $notices->map(fn ($n) => ['id' => $n->id, 'type' => $n->type, 'text' => NoticeAckService::detail($n)])->values()->all());
        $lastNoticeId = (int) app(\App\Services\SheetChangeNotifier::class)->visibleQuery($sheet, $user, true)->max('id');

        $etag = '"'.sha1($sheet->id.'|'.$meta->c.'|'.$meta->u.'|'.json_encode($dues).'|'.json_encode($printCounts).'|'.json_encode($paidToday).'|s'.InvoiceLinesView::SCHEMA.'|r'.(int) $sheet->revision
            .'|a'.json_encode($pendingAcks).'|n'.$lastNoticeId).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'private, no-cache'];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        $payload = [
            'sheet_id' => $sheet->id,
            // نسخة مخطط JSON (تتحدث بها النسخ المخزّنة على الأجهزة)
            'schema' => InvoiceLinesView::SCHEMA,
            // المرحلة 6B-D: رقم مراجعة الكشف (يتغير بكل تعديل بعد النشر)
            'revision' => (int) $sheet->revision,
            // المرحلة 7H
            'last_notice_id' => $lastNoticeId,
            'pending_acks' => (object) $pendingAcks->all(),
            'ack_url' => route('distributor.notices.ack', [$sheet, '__CUSTOMER__']),
            'delivery_order_no' => $sheet->delivery_order_no,
            'distribution_date' => $sheet->distribution_date?->toDateString(),
            'etag' => $etag,
            'print_counts' => $printCounts,
            'invoices' => $invoices->map(fn ($invoice) => $this->invoicePayload($invoice))->values(),
            'merged' => $invoices->whereNotNull('customer_id')->groupBy('customer_id')
                ->map(fn ($rows, $customerId) => $merged->fromInvoices($sheet, (int) $customerId, $rows->values(), (float) ($dues[$customerId] ?? 0)) + [
                    'paid_today_syp' => $paidToday[$customerId]['syp'] ?? 0.0,
                    'paid_today_uuids' => $paidToday[$customerId]['uuids'] ?? [],
                ])
                ->values(),
        ];

        return response()->json($payload, 200, $headers);
    }

    /**
     * لكل زبون: مجموع amount_syp لدفعاته غير الملغاة في يوم توزيع هذا الكشف (كل الكشوف)، ومعرّفاتها.
     * دفعة الدولار بلا سعر لا تدخل المجموع (لا قيمة لها بالليرة بعد).
     *
     * @return array<int, array{syp: float, uuids: array<int, string>}>
     */
    private function paidToday(DistributionSheet $sheet, array $customerIds): array
    {
        if (! $sheet->distribution_date || $customerIds === []) {
            return [];
        }

        $result = [];
        Payment::active()
            ->whereIn('customer_id', $customerIds)
            ->whereHas('sheet', fn ($q) => $q->whereDate('distribution_date', $sheet->distribution_date))
            ->orderBy('id')
            ->get(['id', 'customer_id', 'client_uuid', 'amount_syp'])
            ->each(function ($p) use (&$result) {
                $row = $result[$p->customer_id] ?? ['syp' => 0.0, 'uuids' => []];
                $row['syp'] = round($row['syp'] + (float) $p->amount_syp, 2);
                $row['uuids'][] = $p->client_uuid;
                $result[$p->customer_id] = $row;
            });

        return $result;
    }

    private function invoicePayload($invoice): array
    {
        // المرحلة 6B: صيغة واحدة للموزع وكشوف المندوب (InvoiceLinesView::payload)
        return InvoiceLinesView::payload($invoice);
    }

    // =====================================================================

    private function authorizeRecording(DistributionSheet $sheet, User $user): void
    {
        Gate::forUser($user)->authorize('view', $sheet);
        if (! $sheet->isV2()) {
            abort(403, 'كشوف الصيغة القديمة للقراءة فقط.');
        }
        if ($sheet->isLocked()) {
            abort(403, $sheet->isDraft() ? 'هذا الكشف أُغلق كمسودة ولا يقبل أي تسجيل.' : 'هذا الكشف مغلق ولا يقبل أي تسجيل.');
        }
        Gate::forUser($user)->authorize('update', $sheet);
    }

    /**
     * الزبون يجب أن يكون ضمن ما يراه الموزع في هذا الكشف (مناطقه، فواتير غير مؤجلة).
     */
    /**
     * زبون التسجيل في الكشف، مع حماية الطابور دون اتصال (المرحلة 6B-D4):
     * إن لم يعد الزبون ظاهراً في الكشف لأن فواتيره أُخرجت/نُقلت/تغيّرت منطقتها بعد النشر (إشعار تعديل)،
     * تُحفظ الدفعة/الزيارة كما هي بعلامة «صيدلية خارج الكشف» بدل رفضها (كان الرفض 403 يُسقطها من الطابور).
     *
     * @return array{0: Customer, 1: bool}
     */
    private function recordingCustomer(DistributionSheet $sheet, User $user, int $customerId): array
    {
        if ($this->scope->customerVisibleInSheet($sheet, $user, $customerId)) {
            return [Customer::findOrFail($customerId), false];
        }
        if (app(\App\Services\SheetChangeNotifier::class)->customerLeftSheet($sheet, $customerId)) {
            return [Customer::findOrFail($customerId), true];
        }

        return [$this->sheetCustomer($sheet, $user, $customerId), false];
    }

    private function sheetCustomer(DistributionSheet $sheet, User $user, int $customerId): Customer
    {
        if (! $this->scope->customerVisibleInSheet($sheet, $user, $customerId)) {
            abort(403, 'هذا الزبون ليس ضمن مناطقك في هذا الكشف.');
        }

        return Customer::findOrFail($customerId);
    }

    private function paymentResponse(array $result)
    {
        $payment = $result['payment'];

        return response()->json([
            'success' => true,
            'duplicate' => ! $result['created'],
            'payment' => $this->payments->toArray($payment),
            'warning' => $payment->location_flag === 'far' ? 'أنت بعيد عن موقع الصيدلية.' : null,
        ] + $this->locationResponse($result['location']), $result['created'] ? 201 : 200);
    }

    /**
     * قرار سؤال تثبيت الموقع يأتي من الخادم (لا تقرره الواجهة وحدها). لا إحداثيات خام، فقط المسافة.
     */
    private function locationResponse(array $location): array
    {
        return [
            'reference_state' => $location['reference_state'],
            'distance_m' => $location['distance_m'],
            'can_pin' => $location['can_pin'],
            'pin_result' => $location['pin_result'],
            'pin_message' => $location['pin_message'],
        ];
    }
}
