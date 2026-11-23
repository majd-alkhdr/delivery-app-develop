<?php

namespace App\Http\Controllers;

use App\Models\DistributionSheet;
use Illuminate\Http\Request;

class AccountantController extends Controller
{
    public function index(Request $request)
    {
        // المحاسب لا يرى الكشوف قيد التجهيز
        $query = DistributionSheet::visibleToStaff()
            ->with(['distributor', 'assignments.distributor'])
            ->withSum('invoices', 'invoice_amount')
            ->latest();

        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $sheets = $query->paginate(10);

        // المرحلة 7C: عدّاد «تعديلات بانتظار القرار»
        $pendingAdjustments = app(\App\Services\Finance\PostCloseAdjustmentService::class)->pendingCount();

        return view('accountant.index', compact('sheets', 'pendingAdjustments'));
    }
}
