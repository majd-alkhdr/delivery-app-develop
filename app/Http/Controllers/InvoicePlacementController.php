<?php

namespace App\Http\Controllers;

use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Services\SheetInvoiceEditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * إجراءات مسؤول الكشوف على الفواتير. كل التنفيذ عبر SheetInvoiceEditor.
 */
class InvoicePlacementController extends Controller
{
    public function __construct(private SheetInvoiceEditor $editor)
    {
    }

    /**
     * وضع فاتورة في كشف (من "بلا كشف" أو من كشف آخر) مع تغيير منطقتها اختيارياً.
     */
    public function place(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'sheet_id' => 'required|integer|exists:distribution_sheets,id',
            'area_key' => 'nullable|string|max:255',
        ], ['sheet_id.required' => 'اختر الكشف.']);

        $this->editor->place($invoice, DistributionSheet::findOrFail($data['sheet_id']), $request->user(), $data['area_key'] ?? null);

        return back()->with('success', "وُضعت الفاتورة {$invoice->invoice_number} في الكشف.");
    }

    /**
     * نقل فاتورة أو عدة فواتير محددة إلى كشف آخر.
     */
    public function move(Request $request)
    {
        $data = $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'integer',
            'sheet_id' => 'required|integer|exists:distribution_sheets,id',
        ], [
            'invoice_ids.required' => 'حدد فاتورة واحدة على الأقل.',
            'sheet_id.required' => 'اختر الكشف الهدف.',
        ]);

        $target = DistributionSheet::findOrFail($data['sheet_id']);
        $invoices = Invoice::whereIn('id', $data['invoice_ids'])->get();
        DB::transaction(fn () => $invoices->each(fn ($invoice) => $this->editor->move($invoice, $target, $request->user())));

        return back()->with('success', 'نُقلت '.$invoices->count().' فاتورة إلى '.$target->title().'.');
    }

    /**
     * إخراج فاتورة أو عدة فواتير من الكشف إلى "بلا كشف" (السبب إلزامي).
     */
    public function remove(Request $request)
    {
        $data = $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'integer',
            'reason' => 'required|string|min:3|max:1000',
        ], [
            'invoice_ids.required' => 'حدد فاتورة واحدة على الأقل.',
            'reason.required' => 'سبب الإخراج إلزامي.',
            'reason.min' => 'اكتب سبباً واضحاً (3 أحرف على الأقل).',
        ]);

        $invoices = Invoice::whereIn('id', $data['invoice_ids'])->get();
        DB::transaction(fn () => $invoices->each(fn ($invoice) => $this->editor->remove($invoice, $data['reason'], $request->user())));

        return back()->with('success', 'أُخرجت '.$invoices->count().' فاتورة إلى "بلا كشف".');
    }

    public function exclude(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000'], [
            'reason.required' => 'سبب الاستبعاد إلزامي.',
            'reason.min' => 'اكتب سبباً واضحاً (3 أحرف على الأقل).',
        ]);
        $this->editor->exclude($invoice, $data['reason'], $request->user());

        return back()->with('success', "استُبعدت الفاتورة {$invoice->invoice_number}.");
    }

    public function restore(Request $request, Invoice $invoice)
    {
        $this->editor->restore($invoice, $request->user());

        return back()->with('success', "أُعيدت الفاتورة {$invoice->invoice_number} إلى \"بلا كشف\".");
    }

    public function cancel(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000'], [
            'reason.required' => 'سبب الإلغاء إلزامي.',
            'reason.min' => 'اكتب سبباً واضحاً (3 أحرف على الأقل).',
        ]);
        $this->editor->cancel($invoice, $data['reason'], $request->user());

        return back()->with('success', "اعتُبرت الطلبية {$invoice->invoice_number} ملغاة.");
    }

    public function keep(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['batch_id' => 'required|string|exists:import_batches,id']);
        $this->editor->keepDeferred($invoice, $data['batch_id'], $request->user());

        return back()->with('success', "أُبقيت الطلبية {$invoice->invoice_number} مؤجلة.");
    }

    public function area(Request $request, Invoice $invoice)
    {
        $data = $request->validate(['area_key' => 'required|string|max:255'], ['area_key.required' => 'اختر المنطقة.']);
        $this->editor->changeArea($invoice, $data['area_key'], $request->user());

        return back()->with('success', "تم تغيير منطقة الفاتورة {$invoice->invoice_number}.");
    }

    public function applyProposal(Request $request, InvoiceChangeProposal $proposal)
    {
        // المرحلة 7A: اقتراح له عنصر دفعة ⇒ القرار عبر ImportReviewService (إعادة التصنيف، النسخة القديمة، الإشعارات)
        if ($item = \App\Models\ImportBatchItem::where('proposal_id', $proposal->id)->latest('id')->first()) {
            $result = app(\App\Services\Import\ImportReviewService::class)->apply($item, $request->user(), $request->boolean('confirm_printed'));

            return app(ImportReviewController::class)->respond($result, 'invoice');
        }

        // المرحلة 6B-D5: فاتورة طُبعت سابقاً للصيدلية ⇒ تأكيد إضافي صريح، ويُسجَّل
        $printed = \App\Models\PrintEvent::invoicePrinted($proposal->invoice);
        if ($printed && ! $request->boolean('confirm_printed')) {
            return back()->with('warning', 'طُبعت هذه الفاتورة سابقاً للصيدلية ('.$proposal->invoice->invoice_number.'). أكّد التطبيق من جديد.');
        }

        $this->editor->applyProposal($proposal, $request->user());
        if ($printed) {
            \App\Models\ActivityLog::record('invoice.change_applied_after_print', $proposal->invoice, [], [], [
                'proposal_id' => $proposal->id,
                'invoice_number' => $proposal->invoice->invoice_number,
            ]);
        }

        return back()->with('success', 'تم تطبيق التحديث على الفاتورة '.$proposal->invoice->invoice_number.'.');
    }

    public function rejectProposal(Request $request, InvoiceChangeProposal $proposal)
    {
        if ($item = \App\Models\ImportBatchItem::where('proposal_id', $proposal->id)->latest('id')->first()) {
            app(\App\Services\Import\ImportReviewService::class)->reject($item, $request->user(), $request->input('note'));

            return back()->with('success', 'تم رفض التحديث.');
        }

        $this->editor->rejectProposal($proposal, $request->user());

        return back()->with('success', 'تم رفض التحديث.');
    }
}
