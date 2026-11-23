<?php

namespace App\Http\Controllers;

use App\Models\DistributionSheet;
use App\Services\SheetChangeNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «اطلعت» على تعديلات الكشف (المرحلة 6B-D3): يحدّث sheet_user_seen للمستخدم.
 */
class SheetNoticeController extends Controller
{
    public function seen(Request $request, DistributionSheet $sheet, SheetChangeNotifier $notifier)
    {
        Gate::authorize('view', $sheet);
        $notifier->markSeen($sheet, $request->user());
        // المرحلة 7H: للموزع، «اطلعت» في الشريط اطلاعٌ على الإشعارات الإلزامية التي بلا صيدلية (ما يخص صيدلية يُطّلع عليه في بطاقتها)
        if ($request->user()->role === \App\Enums\Role::Distributor) {
            app(\App\Services\NoticeAckService::class)->ackGeneral($sheet, $request->user());
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }
}
