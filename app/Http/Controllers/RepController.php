<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreRepPaymentRequest;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Finance\RepReceivableService;
use App\Services\PaymentImageService;
use App\Services\PaymentService;
use App\Support\ArabicNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * واجهة المندوب (المرحلة 6): ذممي، تحصيل لأي زبون، دفعاتي؛ والتحصيل بصورة إيصال إلزامية.
 * لا يرى المندوب الرصيد الخام ولا فواتير مراكز كلفة أخرى ولا المؤجل. لا طباعة في صفحاته.
 */
class RepController extends Controller
{
    public function __construct(private PaymentService $payments, private RepReceivableService $receivables)
    {
    }

    // =====================================================================
    // الصفحات
    // =====================================================================

    public function index(Request $request)
    {
        $user = $request->user();
        $pharmacies = $user->hasCostCenter() ? $this->receivables->receivablesForRep($user) : collect();
        $today = Payment::active()->where('source', 'rep')->where('entered_by', $user->id)
            ->where('collected_at', '>=', now('Asia/Damascus')->startOfDay()->utc())->get();

        return view('rep.index', [
            'pharmacies' => $pharmacies,
            'areas' => $pharmacies->pluck('area')->unique()->sort()->values(),
            'totals' => [
                'remaining' => $pharmacies->sum('remaining_cents') / 100,
                'today_syp' => round((float) $today->where('currency', 'SYP')->sum('amount'), 2),
                'today_usd' => round((float) $today->where('currency', 'USD')->sum('amount'), 2),
                'pharmacies' => $pharmacies->count(),
            ],
            'config' => $this->config($request),
            // المرحلة 7C: «تعديلات لاحقة على ذمتي» والإنذار حتى «اطلعت»
            'adjustments' => \App\Models\InvoiceAdjustment::with('invoice:id,invoice_number', 'customer:id,name')->where('rep_user_id', $user->id)->orderByDesc('id')->limit(30)->get(),
            'adjustmentAlerts' => \App\Models\InvoiceAdjustment::unseenForRep($user),
        ]);
    }

    public function customers(Request $request)
    {
        $user = $request->user();
        $request->validate(['q' => 'nullable|string|max:100']);
        $q = ArabicNormalizer::normalize((string) $request->input('q'));

        $customers = Customer::visible()
            ->when($q !== '', fn ($query) => $query->where('name_normalized', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%'))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        $state = $user->hasCostCenter() ? $this->receivables->state($user, $customers->pluck('id')->all()) : collect();
        $debts = $state->map(fn ($s) => $s['remaining_cents'] / 100)->all();

        return view('rep.customers', ['customers' => $customers, 'debts' => $debts, 'config' => $this->config($request)]);
    }

    public function payments(Request $request)
    {
        $user = $request->user();
        $payments = Payment::with(['customer:id,name,area', 'image:id,payment_id,created_at', 'voider:id,name'])
            ->where('source', 'rep')
            ->where('entered_by', $user->id)
            ->where('collected_at', '>=', now()->subDays(30))
            ->latest('collected_at')->latest('id')
            ->get();
        $voidable = $payments->mapWithKeys(fn ($p) => [$p->id => $this->payments->canVoid($p, $user)]);

        return view('rep.payments', ['payments' => $payments, 'voidable' => $voidable, 'config' => $this->config($request)]);
    }

    // =====================================================================
    // التحصيل (multipart: الحقول + الصورة في طلب واحد)
    // =====================================================================

    public function storePayment(StoreRepPaymentRequest $request)
    {
        $user = $request->user();
        if (! $user->hasCostCenter()) {
            abort(403, 'لم يُضبط مركز الكلفة لحسابك، راجع الإدارة.');
        }
        $customer = Customer::findOrFail((int) $request->input('customer_id'));
        if ($customer->is_hidden) {
            abort(403, 'هذا الزبون مخفي.');
        }

        $result = $this->payments->recordRep($user, $customer, $request->paymentInput(), $request->file('image'));
        $payment = $result['payment'];

        return response()->json([
            'success' => true,
            'duplicate' => ! $result['created'],
            'payment' => $this->payments->toArray($payment) + ['exceeds_known_debt' => (bool) $payment->exceeds_known_debt],
            // كيف خُصمت من فواتيره (الأقدم أولاً) والمتبقي
            'breakdown' => $this->receivables->paymentBreakdown($payment),
            // لا تحذير «بعيد» للمندوب (العلامة محفوظة للمحاسب)
            'warning' => null,
        ] + $this->payments->locationResponse($result['location']), $result['created'] ? 201 : 200);
    }

    /**
     * استبدال صورة الإيصال قبل التصدير: المندوب صاحب الدفعة أو المحاسب أو الأدمن.
     */
    public function replaceImage(Request $request, Payment $payment, PaymentImageService $images)
    {
        $user = $request->user();
        if (! $payment->isRep()) {
            abort(404);
        }
        $owner = $user->role === Role::Rep && (int) $payment->entered_by === (int) $user->id;
        if (! $owner && ! $user->hasRole(Role::Admin, Role::Accountant)) {
            abort(403, 'لا يمكنك استبدال صورة هذه الدفعة.');
        }
        $request->validate(['image' => ['required', 'file', 'max:3072']], [
            'image.required' => 'اختر صورة الإيصال الجديدة.',
            'image.max' => 'صورة الإيصال يجب ألا تتجاوز 3 ميغابايت.',
        ]);

        $images->replace($payment, $request->file('image'), $user);

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', 'استُبدلت صورة الإيصال.');
    }

    /**
     * خدمة صورة الإيصال بعد التفويض: صاحب الدفعة (المندوب)، المحاسب، الأدمن، المشرف.
     */
    public function image(Request $request, Payment $payment)
    {
        $user = $request->user();
        $owner = (int) $payment->entered_by === (int) $user->id && $user->role === Role::Rep;
        // المرحلة 6B: الصورة جزء من القسم المالي (مشرف المندوبين عرضاً)، ولم تعد لمشرف التوزيع
        if (! $owner && ! $user->hasRole(Role::Admin, Role::Accountant, Role::RepSupervisor)) {
            abort(403, 'لا يمكنك عرض هذه الصورة.');
        }
        // المرحلة 8A: مشرف المندوبين بحصر مفعّل: دفعات مندوبيه على صيدليات نطاقه فقط
        $scope = \App\Services\Reps\RepScope::current($user);
        if ($scope && ! $scope->allowsPayment($payment)) {
            abort(403, 'هذه الدفعة خارج نطاقك.');
        }
        $image = $payment->image;
        if (! $image || ! Storage::disk(PaymentImageService::DISK)->exists($image->path)) {
            abort(404);
        }

        return response(Storage::disk(PaymentImageService::DISK)->get($image->path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="receipt-'.$payment->id.'.jpg"',
        ]);
    }

    private function config(Request $request): array
    {
        $user = $request->user();

        return [
            'paymentUrl' => route('rep.payments.store'),
            'canRecord' => $user->hasCostCenter(),
            'canEnterUsd' => true,
            'usdRateDefault' => (float) Setting::get('usd_rate_default', 139),
            'usdRateMin' => (float) Setting::get('usd_rate_min', 50),
            'usdRateMax' => (float) Setting::get('usd_rate_max', 500),
            'receiptPrefixLength' => (int) Setting::get('receipt_prefix_length', 3),
            'userId' => $user->id,
            'pinMaxAccuracy' => (int) Setting::get('location_pin_max_accuracy_m', 50),
            'radius' => (int) Setting::get('location_radius_m', 150),
            'printingEnabled' => false,
            'maxPending' => 30,
        ];
    }
}
