<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\Request;

/**
 * إلغاء الدفعة (void) وبيانات الإيصال. لا حذف نهائي لأي دفعة.
 */
class PaymentController extends Controller
{
    public function __construct(private PaymentService $payments)
    {
    }

    public function void(Request $request, Payment $payment)
    {
        $this->authorizeAccess($request, $payment);
        $data = $request->validate(['reason' => 'required|string|min:3|max:500'], [
            'reason.required' => 'سبب الإلغاء إلزامي.',
            'reason.min' => 'اكتب سبباً واضحاً (3 أحرف على الأقل).',
        ]);

        $this->payments->void($payment, $request->user(), $data['reason']);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'payment' => $this->payments->toArray($payment->fresh())]);
        }

        return back()->with('success', "أُلغيت الدفعة {$payment->receipt_number}.");
    }

    /**
     * بيانات الإيصال (JSON) للطباعة. is_duplicate = سبقت طباعة ناجحة (print_events)، وprint_count عددها.
     */
    public function receiptData(Request $request, Payment $payment)
    {
        $this->authorizeAccess($request, $payment);

        // المرحلة 4: «نسخة مكررة» = سبقت طباعة ناجحة فعلية (ليست معاينة) لهذه الدفعة، لا مجرد جلب البيانات
        $printCount = \App\Models\PrintEvent::counted()->where('kind', 'receipt')->where('payment_id', $payment->id)->count();

        // سجل اطلاع فقط (لا أثر له على الوسم)
        ActivityLog::record('payment.receipt_viewed', $payment, [], [], [
            'receipt_number' => $payment->receipt_number,
        ]);

        return response()->json($this->payments->receiptData($payment, $printCount > 0) + ['print_count' => $printCount]);
    }

    /**
     * الموزع: دفعاته فقط. المشرف والمحاسب والأدمن: كل الدفعات.
     */
    private function authorizeAccess(Request $request, Payment $payment): void
    {
        $user = $request->user();
        if ($user->hasRole(Role::Admin, Role::Supervisor, Role::Accountant)) {
            return;
        }
        if ($user->hasRole(Role::Distributor, Role::Rep) && (int) $payment->entered_by === (int) $user->id) {
            return;
        }
        abort(403, 'هذه الدفعة ليست لك.');
    }
}
