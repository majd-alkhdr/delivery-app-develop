<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Services\ActivityLogVisibility;
use Illuminate\Http\Request;

/**
 * سجل النشاط لكل الأدوار (المرحلة 8C): القالب والمرشّحات نفسها، والمرئي وحده يختلف حسب منفّذ الإجراء
 * (ActivityLogVisibility). كل قراءة هنا تمر عبر scopeVisibleTo.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer',
            'action' => 'nullable|string|max:100',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ]);
        $viewer = $request->user();

        $query = ActivityLog::query()->visibleTo($viewer)->with('user:id,name,role')->latest('created_at')->latest('id');

        // مستخدم غير مرئي في الطلب يُتجاهل (لا يكشف شيئاً ولا وجود سجله)
        $userId = $request->filled('user_id') && ActivityLogVisibility::canSeeUser($viewer, $request->integer('user_id'))
            ? $request->integer('user_id') : null;
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $logs = $query->paginate(25)->withQueryString();

        // أنواع الأحداث الموجودة في المرئي فقط
        $present = ActivityLog::query()->visibleTo($viewer)->distinct()->pluck('action')->all();
        $actions = collect($present)->mapWithKeys(fn ($a) => [$a => ActivityLog::ACTIONS[$a] ?? $a])->sort();

        $users = ActivityLogVisibility::users($viewer)->orderBy('name')->get(['id', 'name', 'role']);
        $compact = $viewer->hasRole(Role::Rep, Role::Distributor);

        return view('activity.index', compact('logs', 'actions', 'users', 'userId', 'compact'));
    }

    /**
     * تفاصيل سجل واحد: سجل غير مرئي للمستخدم ⇒ 403 كبقية المشروع.
     */
    public function show(Request $request, ActivityLog $log)
    {
        abort_unless(ActivityLogVisibility::canSee($request->user(), $log), 403);
        $log->load('user:id,name,role');

        return view('activity.show', ['log' => $log, 'compact' => $request->user()->hasRole(Role::Rep, Role::Distributor)]);
    }
}
