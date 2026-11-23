<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerAlias;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Services\CustomerBalanceService;
use App\Services\Import\SheetImportService;
use App\Services\SheetFiguresService;
use App\Support\ArabicNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * شاشات الزبائن: القائمة (المشرف للقراءة فقط)، ومطابقة الزبائن الجدد (needs_review) وإخفاؤهم
 * لمسؤول الكشوف والمدير.
 */
class CustomerController extends Controller
{
    public function index(Request $request, CustomerBalanceService $balances)
    {
        $query = Customer::query()->orderBy('name');

        if ($request->filled('q')) {
            $query->where('name_normalized', 'like', '%'.ArabicNormalizer::normalize($request->q).'%');
        }
        if ($request->filled('area')) {
            $query->where('area_normalized', ArabicNormalizer::normalize($request->area));
        }
        if ($request->filled('block')) {
            $query->where('block', $request->block);
        }
        if (! $request->boolean('show_hidden')) {
            $query->where('is_hidden', false);
        }

        $customers = $query->paginate(50)->withQueryString();
        // المرحلة 7B: الرصيد المعروض الواحد
        $currentBalances = $balances->currentBalances($customers->getCollection());
        $due = collect($currentBalances)->map(fn ($b) => $b->net)->all();

        $areas = Customer::whereNotNull('area')->distinct()->orderBy('area')->pluck('area');
        $blocks = Customer::whereNotNull('block')->distinct()->orderBy('block')->pluck('block');
        $reviewCount = Customer::where('needs_review', true)->count();

        return view('customers.index', compact('customers', 'due', 'currentBalances', 'areas', 'blocks', 'reviewCount'));
    }

    public function toggleVisibility(Customer $customer)
    {
        $customer->update(['is_hidden' => ! $customer->is_hidden]);

        ActivityLog::record($customer->is_hidden ? 'customer.hidden' : 'customer.unhidden', $customer, [], [
            'name' => $customer->name,
        ]);

        return back()->with('success', $customer->is_hidden ? 'تم إخفاء الزبون.' : 'تم إظهار الزبون.');
    }

    public function review(Request $request, SheetImportService $importer)
    {
        $pending = Customer::where('needs_review', true)
            ->withCount('invoices')
            ->orderBy('name')
            ->get();

        $suggestions = $pending->mapWithKeys(fn (Customer $c) => [$c->id => $importer->suggest($c)]);

        $searchResults = collect();
        $searchFor = $request->integer('for') ?: null;
        if ($request->filled('q')) {
            $searchResults = Customer::where('needs_review', false)
                ->where('name_normalized', 'like', '%'.ArabicNormalizer::normalize($request->q).'%')
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'area']);
        }

        $areas = Customer::whereNotNull('area')->where('needs_review', false)->distinct()->orderBy('area')->pluck('area');
        $blocks = Customer::whereNotNull('block')->distinct()->orderBy('block')->pluck('block');

        return view('customers.review', compact('pending', 'suggestions', 'searchResults', 'searchFor', 'areas', 'blocks'));
    }

    /**
     * ربط زبون مؤقت بزبون موجود: يُنشأ اسم بديل، تُنقل الفواتير، ويُحذف الزبون المؤقت.
     */
    public function link(Request $request, Customer $customer, SheetFiguresService $figures)
    {
        $data = $request->validate([
            'target_id' => 'required|integer|exists:customers,id',
        ], [
            'target_id.required' => 'اختر الزبون الموجود الذي تريد الربط به.',
        ]);

        $target = Customer::findOrFail($data['target_id']);

        if (! $customer->needs_review) {
            return back()->with('error', 'هذا الزبون ليس بحاجة إلى مطابقة.');
        }
        if ($target->id === $customer->id || $target->needs_review) {
            return back()->with('error', 'اختر زبوناً معتمداً مختلفاً.');
        }

        $sheetIds = Invoice::where('customer_id', $customer->id)->distinct()->pluck('distribution_sheet_id');

        DB::transaction(function () use ($customer, $target, $request) {
            CustomerAlias::updateOrCreate(
                ['alias_normalized' => ArabicNormalizer::normalize($customer->name)],
                ['customer_id' => $target->id, 'alias' => $customer->name, 'created_by' => $request->user()->id]
            );

            Invoice::where('customer_id', $customer->id)->update(['customer_id' => $target->id]);

            $customer->delete();
        });

        $figures->syncCustomerArea($target);
        $this->refreshOpenSheets($sheetIds, $figures);

        ActivityLog::record('customer.aliased', $target, [], [
            'alias' => $customer->name,
            'customer' => $target->name,
        ], [
            'temporary_customer_id' => $customer->id,
        ]);

        return back()->with('success', "تم ربط \"{$customer->name}\" بالزبون \"{$target->name}\".");
    }

    /**
     * اعتماد الزبون المؤقت كزبون جديد مع تحديد منطقته.
     */
    public function approve(Request $request, Customer $customer, SheetFiguresService $figures)
    {
        $data = $request->validate([
            'area' => 'required|string|max:255',
            'block' => 'nullable|string|max:255',
        ], [
            'area.required' => 'حدد منطقة الزبون قبل اعتماده.',
        ]);

        if (! $customer->needs_review) {
            return back()->with('error', 'هذا الزبون معتمد مسبقاً.');
        }

        $customer->update([
            'area' => trim($data['area']),
            'block' => isset($data['block']) && trim($data['block']) !== '' ? trim($data['block']) : null,
            'needs_review' => false,
        ]);

        $figures->syncCustomerArea($customer);
        $this->refreshOpenSheets(Invoice::where('customer_id', $customer->id)->distinct()->pluck('distribution_sheet_id'), $figures);

        ActivityLog::record('customer.created_from_review', $customer, [], [
            'name' => $customer->name,
            'area' => $customer->area,
            'block' => $customer->block,
        ]);

        return back()->with('success', "تم اعتماد \"{$customer->name}\" كزبون جديد.");
    }

    private function refreshOpenSheets($sheetIds, SheetFiguresService $figures): void
    {
        DistributionSheet::whereIn('id', collect($sheetIds)->filter())
            ->whereIn('status', DistributionSheet::EDITABLE_STATUSES)
            ->get()
            ->each(fn ($sheet) => $figures->refresh($sheet));
    }
}
