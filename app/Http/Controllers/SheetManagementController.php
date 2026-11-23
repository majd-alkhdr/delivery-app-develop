<?php

namespace App\Http\Controllers;

use App\Models\DistributionSheet;
use App\Services\SheetAssignmentService;
use App\Services\SheetDispatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * إدارة حالة الكشف وإسناده (بديل الحذف): إعادة الإسناد، الإغلاق كمسودة، إعادة التفعيل.
 */
class SheetManagementController extends Controller
{
    /**
     * حفظ إسناد المناطق (يُستخدم للإسناد الأول ولإعادة الإسناد / تغيير الموزع).
     */
    public function assign(Request $request, DistributionSheet $sheet, SheetAssignmentService $service)
    {
        Gate::authorize('view', $sheet);
        Gate::authorize('assign', $sheet);

        $data = $request->validate([
            'all_distributor_id' => 'nullable|integer',
            'rows' => 'nullable|array',
            'rows.*.area_key' => 'nullable|string|max:255',
            'rows.*.distributor_id' => 'nullable|integer',
        ]);

        $service->sync(
            $sheet,
            $data['rows'] ?? [],
            ! empty($data['all_distributor_id']) ? (int) $data['all_distributor_id'] : null,
            $request->user()
        );

        $sheet->unsetRelation('assignments');
        $unassigned = $sheet->unassignedAreaKeys();

        return redirect()->route('supervisor.sheet.show', $sheet->id)->with(
            $unassigned ? 'warning' : 'success',
            $unassigned
                ? 'تم حفظ الإسناد، لكن بقيت '.count($unassigned).' منطقة بدون موزع.'
                : 'تم حفظ إسناد المناطق.'
        );
    }

    /**
     * إغلاق كمسودة بدون توزيع: يتطلب سبباً، وتُفرَّغ فواتير الكشف إلى "بلا كشف".
     * مسؤول الكشوف: قيد التجهيز فقط. المشرف: بعد الإرسال. المدير: الحالتان.
     */
    public function draft(Request $request, DistributionSheet $sheet, SheetDispatchService $dispatch)
    {
        Gate::authorize('view', $sheet);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ], [
            'reason.required' => 'سبب الإغلاق كمسودة إلزامي.',
            'reason.min' => 'اكتب سبباً واضحاً (3 أحرف على الأقل).',
        ]);

        if (! in_array($sheet->status, $dispatch->draftableStatuses($request->user()), true)) {
            return back()->with('error', 'لا يمكنك تحويل هذا الكشف إلى مسودة في حالته الحالية.');
        }

        if (! $request->user()->isAdmin() && $sheet->hasActivity()) {
            return back()->with('error', 'سُجّلت على هذا الكشف زيارات أو دفعات، فلا يُغلق كمسودة إلا من المدير.');
        }

        $released = $dispatch->draft($sheet, $data['reason'], $request->user());

        return back()->with('success', "تم إغلاق الكشف كمسودة، ونُقلت {$released} فاتورة إلى \"بلا كشف\" لإعادة توزيعها.");
    }

    /**
     * إعادة تفعيل مسودة إلى "بانتظار البدء" (للمدير فقط، وبشرط وجود فواتير في الكشف).
     */
    public function reopen(Request $request, DistributionSheet $sheet, SheetDispatchService $dispatch)
    {
        if (! $request->user()->isAdmin() || ! $sheet->isDraft()) {
            abort(403);
        }
        if (! $request->user()->can('reopen', $sheet)) {
            return back()->with('error', 'لا يمكن إعادة تفعيل كشف بلا فواتير (فُرّغت فواتيره عند إغلاقه كمسودة).');
        }

        $dispatch->reopen($sheet, $request->user());

        return back()->with('success', 'تمت إعادة تفعيل الكشف.');
    }
}
