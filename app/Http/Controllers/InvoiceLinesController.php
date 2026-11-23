<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * أسطر فاتورة للإدارة (قراءة فقط): admin وsheet_manager كل الفواتير؛ supervisor وaccountant
 * فواتير الكشوف غير «قيد التجهيز» أو المؤجلة. الموزع ممنوع (من مجموعة المسار).
 * لا تعديل لأسطر الفاتورة من أي دور.
 */
class InvoiceLinesController extends Controller
{
    public function show(Request $request, Invoice $invoice)
    {
        $user = $request->user();

        // مشرف المندوبين (6B): أسطر فواتير الكشوف المنشورة فقط، لا المؤجلة
        if ($user->role === Role::RepSupervisor) {
            $sheet = $invoice->distributionSheet;
            if (! $sheet || $invoice->placement !== Invoice::IN_SHEET || ! in_array($sheet->status, ['pending', 'in_progress', 'completed'], true)) {
                abort(404);
            }
            // المرحلة 8A: عند تفعيل الحصر، فواتير مندوبيه في نطاقه فقط
            $scope = \App\Services\Reps\RepScope::current($user);
            if ($scope && ! $scope->allowsInvoiceId($invoice->id)) {
                abort(403, 'هذه الفاتورة خارج نطاقك.');
            }
        }

        if ($user->hasRole(Role::Supervisor, Role::Accountant)) {
            $sheet = $invoice->distributionSheet;
            $allowed = ($sheet && ! $sheet->isPreparing() && $invoice->placement === Invoice::IN_SHEET)
                || $invoice->placement === Invoice::DEFERRED;
            if (! $allowed) {
                abort(404);
            }
        }

        $invoice->load('lines');

        if ($request->wantsJson()) {
            $view = \App\Support\InvoiceLinesView::summarize($invoice);

            return response()->json([
                'id' => $invoice->id,
                'number' => $invoice->invoice_number,
                'total' => (float) $invoice->invoice_amount,
                'lines' => $view['lines'],
                // المرحلة 6B: المجموع قبل الحسم، حسم الأقلام، الصافي
                'gross_total' => $view['gross_total'],
                'discount_total' => $view['discount_total'],
                'net_total' => $view['net_total'],
                'has_discount' => $view['has_discount'],
                'net_uncertain' => (bool) $invoice->net_uncertain,
            ]);
        }

        return view('partials.invoice-lines', ['invoice' => $invoice]);
    }
}
