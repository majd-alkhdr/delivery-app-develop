<?php

namespace App\Http\Controllers;

use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\SheetAssignment;
use App\Models\Customer;
use App\Support\ArabicNormalizer;
use Illuminate\Http\Request;

/**
 * قوائم الفواتير خارج الكشوف: الطلبيات المؤجلة، و"بلا كشف".
 */
class InvoiceListController extends Controller
{
    /**
     * كل الطلبيات المؤجلة (مسؤول الكشوف والمدير، والمشرف والمحاسب للقراءة).
     */
    public function deferred(Request $request)
    {
        $request->validate([
            'customer' => 'nullable|string|max:255',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = Invoice::where('placement', Invoice::DEFERRED)
            ->with('lines')
            ->orderBy('delivery_date')
            ->orderBy('customer_name');

        if ($request->filled('customer')) {
            $normalized = ArabicNormalizer::normalize($request->customer);
            $query->where(fn ($q) => $q->where('customer_name', 'like', '%'.trim($request->customer).'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name_normalized', 'like', "%{$normalized}%")));
        }
        if ($request->filled('from')) {
            $query->whereDate('delivery_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->whereDate('delivery_date', '<=', $request->to);
        }

        $total = (clone $query)->sum('invoice_amount');
        $invoices = $query->paginate(50)->withQueryString();
        $canEdit = $request->user()->hasRole('admin', 'sheet_manager');

        return view('invoices.deferred', compact('invoices', 'total', 'canEdit'));
    }

    /**
     * كل الفواتير "بلا كشف" عبر الدفعات (مسؤول الكشوف والمدير فقط)، والمستبعدة للعرض.
     */
    public function unassigned(Request $request)
    {
        $showExcluded = $request->boolean('excluded');

        $invoices = Invoice::where('placement', $showExcluded ? Invoice::EXCLUDED : Invoice::UNASSIGNED)
            // الدفعات الخارجية القديمة المرحَّلة إلى payments ليست فواتير
            ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
            ->orderByDesc('invoice_date')
            ->orderBy('customer_name')
            ->paginate(50)
            ->withQueryString();

        // الكشوف غير المكتملة لكل تاريخ توزيع (هدف الوضع)
        $targetSheets = DistributionSheet::where('format', 'v2')
            ->whereIn('status', DistributionSheet::EDITABLE_STATUSES)
            ->orderByDesc('distribution_date')
            ->orderBy('delivery_order_no')
            ->limit(200)
            ->get(['id', 'delivery_order_no', 'status', 'distribution_date', 'format', 'original_name', 'file_name']);

        $areaOptions = [SheetAssignment::NO_AREA => 'بلا منطقة'] + Customer::whereNotNull('area_normalized')
            ->select('area_normalized', 'area')->distinct()->orderBy('area')->get()
            ->unique('area_normalized')->mapWithKeys(fn ($c) => [$c->area_normalized => $c->area])->all();

        return view('invoices.unassigned', compact('invoices', 'targetSheets', 'areaOptions', 'showExcluded'));
    }
}
