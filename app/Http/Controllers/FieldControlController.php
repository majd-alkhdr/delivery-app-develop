<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\PharmacyLock;
use App\Models\SheetVisit;
use App\Models\User;
use App\Services\LocationService;
use App\Services\PharmacyLockService;
use Illuminate\Http\Request;

/**
 * أدوات المشرف والأدمن الميدانية: فك قفل الصيدلية، وزيارات بعيدة عن الموقع وتثبيت الموقع المرجعي.
 */
class FieldControlController extends Controller
{
    public function releaseLock(Request $request, PharmacyLock $lock, PharmacyLockService $locks)
    {
        if (! $lock->isHeld()) {
            return back()->with('error', 'هذا القفل مفكوك مسبقاً.');
        }
        $locks->release($lock, $request->user());

        return back()->with('success', 'تم فك قفل الصيدلية، ويستطيع موزع آخر التسجيل عليها.');
    }

    /**
     * زيارات ودفعات علّمها النظام بعيدة عن موقع الصيدلية أو بلا مرجع مؤكد.
     */
    public function farVisits(Request $request)
    {
        $request->validate(['date' => 'nullable|date', 'user_id' => 'nullable|integer', 'flag' => 'nullable|in:far,no_reference', 'recent_pins' => 'nullable|boolean', 'days' => 'nullable|integer|min:1|max:365']);

        // المرحلة 5: «مراجع ثبّتها الموزعون حديثاً» (location_source=distributor خلال آخر N يوماً)
        if ($request->boolean('recent_pins')) {
            return $this->recentPins($request);
        }
        $flags = $request->filled('flag') ? [$request->flag] : ['far', 'no_reference'];

        $visits = SheetVisit::with(['customer', 'user:id,name', 'sheet:id,delivery_order_no,distribution_date'])
            ->active()
            ->whereIn('location_flag', $flags)
            ->whereNotNull('latitude')
            ->when($request->filled('date'), fn ($q) => $q->whereDate('visited_at', $request->date))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest('visited_at')
            ->paginate(30, ['*'], 'visits_page')
            ->withQueryString();

        $payments = Payment::with(['customer', 'enteredBy:id,name', 'sheet:id,delivery_order_no,distribution_date'])
            ->active()
            ->whereIn('location_flag', $flags)
            ->whereNotNull('latitude')
            // المرحلة 6: دفعات المندوب خارج القائمة (لا يُشترط وجوده عند الصيدلية)؛ المرحلة 8C: دائماً، أُلغي خيار «تضمين المندوبين»
            ->where('source', '!=', 'rep')
            ->when($request->filled('date'), fn ($q) => $q->whereDate('collected_at', $request->date))
            ->when($request->filled('user_id'), fn ($q) => $q->where('entered_by', $request->integer('user_id')))
            ->latest('collected_at')
            ->paginate(30, ['*'], 'payments_page')
            ->withQueryString();

        $distributors = User::where('role', Role::Distributor)->orderBy('name')->get(['id', 'name']);

        return view('supervisor.far-visits', compact('visits', 'payments', 'distributors'));
    }

    /**
     * مراجع ثبّتها الموزعون خلال آخر N يوماً (افتراضياً 7)، مع آخر سجل بموقع لكل صيدلية لزر «إعادة تثبيت».
     */
    private function recentPins(Request $request)
    {
        $days = $request->integer('days') ?: 7;

        $customers = Customer::with('pinnedBy:id,name')
            ->where('location_source', 'distributor')
            ->where('location_pinned_at', '>=', now()->subDays($days))
            ->when($request->filled('user_id'), fn ($q) => $q->where('location_pinned_by', $request->integer('user_id')))
            ->latest('location_pinned_at')
            ->paginate(30)
            ->withQueryString();

        // آخر زيارة/دفعة بموقع لكل صيدلية (مصدر «إعادة التثبيت»)
        $ids = $customers->pluck('id');
        $lastVisits = SheetVisit::active()->whereIn('customer_id', $ids)->whereNotNull('latitude')
            ->orderByDesc('visited_at')->get()->unique('customer_id')->keyBy('customer_id');
        $lastPayments = Payment::active()->whereIn('customer_id', $ids)->whereNotNull('latitude')
            ->orderByDesc('collected_at')->get()->unique('customer_id')->keyBy('customer_id');

        $distributors = User::where('role', Role::Distributor)->orderBy('name')->get(['id', 'name']);

        return view('supervisor.far-visits', [
            'recentPins' => $customers,
            'days' => $days,
            'lastVisits' => $lastVisits,
            'lastPayments' => $lastPayments,
            'distributors' => $distributors,
            'visits' => null,
            'payments' => null,
        ]);
    }

    /**
     * «تثبيت هذا الموقع كمرجع» من زيارة أو دفعة معلَّمة.
     */
    public function pinLocation(Request $request, LocationService $location)
    {
        $data = $request->validate([
            'type' => 'required|in:visit,payment',
            'id' => 'required|integer',
        ]);

        $record = $data['type'] === 'visit' ? SheetVisit::findOrFail($data['id']) : Payment::findOrFail($data['id']);
        if ($record->latitude === null || $record->longitude === null) {
            return back()->with('error', 'لا يوجد موقع مسجّل لهذا السجل.');
        }

        $location->pin($record->customer, (float) $record->latitude, (float) $record->longitude, $request->user(), [
            'source' => $data['type'],
            'source_id' => $record->id,
            'sheet_id' => $record->distribution_sheet_id,
        ]);

        return back()->with('success', 'ثُبّت الموقع كمرجع مؤكد للصيدلية "'.$record->customer->name.'".');
    }
}
