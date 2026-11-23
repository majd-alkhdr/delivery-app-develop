<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\InvoiceSeries;
use App\Models\Setting;
use App\Services\Finance\FinanceService;
use App\Services\Finance\RepFinanceService;
use App\Support\PrintSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * الإعدادات (مشرف/أدمن): مجموعة التقسيم لكل سلسلة فواتير (allocation_group).
 * يستخدمها محرك تقسيم الدفعات (المرحلة 5)؛ وقسم المالية (قالب البيان) للأدمن فقط.
 */
class SettingsController extends Controller
{
    /** المرحلة 7B: إعدادات الأرصدة وقيمها الافتراضية */
    public const BALANCE_DEFAULTS = [
        'balance_unexplained_tolerance' => 1,
        'balance_notice_min_change' => 1,
        'balances_zeroing_confirm_percent' => 30,
        'balances_stale_days' => 2,
        'exported_unreflected_days' => 3,
    ];

    /**
     * قسم الأرصدة (الأدمن فقط).
     */
    public function updateBalances(Request $request)
    {
        if (! $request->user()->isAdmin()) {
            abort(403);
        }
        $data = $request->validate(collect(self::BALANCE_DEFAULTS)->map(fn () => ['required', 'numeric', 'min:0', 'max:100000'])->all());
        foreach ($data as $key => $value) {
            $old = Setting::get($key, self::BALANCE_DEFAULTS[$key]);
            if ((float) $old !== (float) $value) {
                Setting::set($key, (float) $value);
                ActivityLog::record('settings.balances_changed', null, [$key => $old], [$key => (float) $value]);
            }
        }

        return back()->with('success', 'تم حفظ إعدادات الأرصدة.');
    }

    public function index()
    {
        $series = InvoiceSeries::orderBy('key')->get();
        $location = [
            'pin_max_accuracy_m' => (int) Setting::get('location_pin_max_accuracy_m', 50),
            'radius_m' => (int) Setting::get('location_radius_m', 150),
        ];

        $printing = PrintSettings::values();

        $template = FinanceService::template();
        $finance = [
            'statement_template' => $template,
            'statement_preview' => strtr($template, ['{user}' => 'أحمد', '{order}' => '3918']),
            'rep_statement_template' => RepFinanceService::template(),
            'rep_statement_preview' => RepFinanceService::statement(RepFinanceService::template(), '655231-كرم الخالدي', 12),
        ];

        $balanceSettings = collect(self::BALANCE_DEFAULTS)->map(fn ($default, $key) => Setting::get($key, $default))->all();

        $repPlan = collect(\App\Services\Reps\RepPlanService::SETTINGS)->keys()->mapWithKeys(fn ($key) => [$key => \App\Services\Reps\RepPlanService::setting($key)])->all();

        return view('settings.index', compact('series', 'location', 'printing', 'finance', 'balanceSettings', 'repPlan'));
    }

    /**
     * المرحلة 8B: قسم «خطة المندوب» (الأدمن): حدّ المطابقة، أسوأ دقة GPS، أيام التسجيل المتأخر، ساعة تنبيه «لا نشاط».
     */
    public function updateRepPlan(Request $request)
    {
        $data = $request->validate([
            'plan_location_max_distance_m' => ['required', 'integer', 'min:10', 'max:5000'],
            'plan_min_gps_accuracy_m' => ['required', 'integer', 'min:5', 'max:2000'],
            'rep_plan_backfill_days' => ['required', 'integer', 'min:0', 'max:7'],
            'plan_no_activity_hour' => ['required', 'integer', 'min:0', 'max:23'],
        ]);
        $old = [];
        $new = [];
        foreach ($data as $key => $value) {
            $before = \App\Services\Reps\RepPlanService::setting($key);
            if ($before !== (int) $value) {
                $old[$key] = $before;
                $new[$key] = (int) $value;
                Setting::set($key, (int) $value);
            }
        }
        if ($new) {
            ActivityLog::record('settings.rep_plan_changed', null, $old, $new);
        }

        return back()->with('success', 'تم حفظ إعدادات خطة المندوب.');
    }

    /**
     * المرحلة 8D: مفتاح «كشف الصيدلية الكامل للمندوب» (الافتراضي مفعّل). الإيقاف يعيد الكشف إلى فواتير المندوب ودفعاته فقط.
     */
    public function updateRepFullStatement(Request $request)
    {
        $key = \App\Services\Reps\PharmacyStatementService::SETTING;
        $old = \App\Services\Reps\PharmacyStatementService::fullEnabled();
        $new = $request->boolean('enabled');
        if ($old !== $new) {
            Setting::set($key, $new);
            ActivityLog::record('settings.rep_full_statement_changed', null, [$key => $old], [$key => $new]);
        }

        return back()->with('success', $new ? 'فُعّل كشف الصيدلية الكامل للمندوبين.' : 'أُوقف كشف الصيدلية الكامل: يرى المندوب فواتيره ودفعاته فقط.');
    }

    /**
     * المرحلة 7H: أنواع إشعارات تعديل الكشف التي تُلزم الموزع بـ«اطلعت» قبل التسجيل على الصيدلية (الأدمن).
     */
    public function updateAckTypes(Request $request)
    {
        $data = $request->validate([
            'types' => ['nullable', 'array'],
            'types.*' => ['string', \Illuminate\Validation\Rule::in(\App\Models\SheetChangeNotice::TYPES)],
        ]);
        $old = \App\Services\NoticeAckService::requiredTypes();
        $new = array_values(array_intersect(\App\Models\SheetChangeNotice::TYPES, $data['types'] ?? []));
        if ($old !== $new) {
            Setting::set(\App\Services\NoticeAckService::SETTING, $new);
            ActivityLog::record('settings.ack_types_changed', null, ['types' => $old], ['types' => $new]);
        }

        return back()->with('success', 'تم حفظ أنواع الإشعارات الإلزامية.');
    }

    /**
     * قسم الموقع: أسوأ دقة GPS مقبولة لتثبيت الموزع موقع الصيدلية كمرجع (5–500 م).
     */
    public function updateLocation(Request $request)
    {
        $data = $request->validate([
            'location_pin_max_accuracy_m' => ['required', 'integer', 'min:5', 'max:500'],
        ], [
            'location_pin_max_accuracy_m.min' => 'أقل قيمة 5 أمتار.',
            'location_pin_max_accuracy_m.max' => 'أكبر قيمة 500 متر.',
        ]);

        $old = (int) Setting::get('location_pin_max_accuracy_m', 50);
        Setting::set('location_pin_max_accuracy_m', (int) $data['location_pin_max_accuracy_m']);

        ActivityLog::record('settings.location_changed', null, ['location_pin_max_accuracy_m' => $old], [
            'location_pin_max_accuracy_m' => (int) $data['location_pin_max_accuracy_m'],
        ]);

        return back()->with('success', 'تم حفظ إعدادات الموقع.');
    }

    /**
     * قسم «الطباعة»: إعدادات عامة تُضبط لاحقاً على الطابعة الحقيقية (وللجهاز تجاوزات محلية من صفحة الاختبار).
     */
    public function updatePrinting(Request $request)
    {
        $data = $request->validate(PrintSettings::rules());

        $old = PrintSettings::values();
        $new = [];
        foreach (PrintSettings::FIELDS as $key => [$type]) {
            $value = match ($type) {
                'bool' => $request->boolean($key),
                'int' => (int) $data[$key],
                default => trim((string) ($data[$key] ?? '')),
            };
            $new[$key] = $value;
            Setting::set($key, $value);
        }

        $changed = array_filter($new, fn ($v, $k) => $old[$k] !== $v, ARRAY_FILTER_USE_BOTH);
        if ($changed) {
            ActivityLog::record('settings.printing_changed', null, array_intersect_key($old, $changed), $changed);
        }

        return back()->with('success', 'تم حفظ إعدادات الطباعة.');
    }

    /**
     * قسم «المالية» (الأدمن فقط): قالب بيان المالية. يُحفظ حرفياً بلا trim (مستثنى من TrimStrings).
     */
    public function updateFinance(Request $request)
    {
        $data = $request->validate([
            'finance_statement_template' => ['required', 'string', 'max:500'],
        ], ['finance_statement_template.required' => 'قالب البيان إلزامي.']);

        $old = FinanceService::template();
        $new = (string) $data['finance_statement_template'];
        if ($old !== $new) {
            Setting::set('finance_statement_template', $new);
            ActivityLog::record('settings.finance_changed', null, ['finance_statement_template' => $old], ['finance_statement_template' => $new]);
        }

        return back()->with('success', 'تم حفظ إعدادات المالية.');
    }

    /**
     * قالب بيان المندوبين (المرحلة 6، الأدمن فقط): يُحفظ حرفياً بلا trim (مستثنى من TrimStrings).
     */
    public function updateRepFinance(Request $request)
    {
        $data = $request->validate([
            'finance_rep_statement_template' => ['required', 'string', 'max:500'],
        ], ['finance_rep_statement_template.required' => 'قالب البيان إلزامي.']);

        $old = RepFinanceService::template();
        $new = (string) $data['finance_rep_statement_template'];
        if ($old !== $new) {
            Setting::set('finance_rep_statement_template', $new);
            ActivityLog::record('settings.finance_changed', null, ['finance_rep_statement_template' => $old], ['finance_rep_statement_template' => $new]);
        }

        return back()->with('success', 'تم حفظ قالب بيان المندوبين.');
    }

    public function updateAllocation(Request $request, InvoiceSeries $series)
    {
        $data = $request->validate([
            'allocation_group' => ['required', Rule::in(array_keys(InvoiceSeries::ALLOCATION_GROUPS))],
        ]);

        $old = $series->allocation_group;
        if ($old === $data['allocation_group']) {
            return back();
        }

        $series->update(['allocation_group' => $data['allocation_group']]);

        ActivityLog::record('settings.allocation_group_changed', $series, ['allocation_group' => $old], [
            'allocation_group' => $series->allocation_group,
        ], ['series' => $series->key]);

        return back()->with('success', "تم تحديث مجموعة التقسيم للسلسلة \"{$series->key}\".");
    }
}
