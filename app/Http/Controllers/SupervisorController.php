<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PharmacyLock;
use App\Models\SheetVisit;
use App\Models\SheetAssignment;
use App\Models\User;
use App\Services\CustomerBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SupervisorController extends Controller
{
    // 1. عرض صفحة رفع الملفات وقائمة الكشوفات
public function index(Request $request)
{
        $distributors = User::where('role', Role::Distributor)->orderBy('name')->get();
        // شاشة المشرف لا تعرض الكشوف قيد التجهيز (تخص مسؤول الكشوف حتى تُرسل)
        $query = DistributionSheet::query()->visibleToStaff()->with(['distributor', 'assignments.distributor'])->latest();
        // مشرف المندوبين (6B): الكشوف المنشورة فقط (لا المسودة)
        if ($request->user()->role === Role::RepSupervisor) {
            $query->where('status', '!=', 'draft');
        }
        // المرحلة 8A: عند تفعيل الحصر، كشوف فيها فواتير لمندوبيه في نطاقه، والأعداد من تلك الفواتير وحدها
        $repScope = \App\Services\Reps\RepScope::current($request->user());
        if ($repScope) {
            $query->whereIn('id', $repScope->sheetIds() ?: [0]);
        }

        if ($request->has('date') && $request->date != '') {
            $query->whereDate('created_at', $request->date);
        }

        if ($request->has('status') && $request->status != '') {
            $query->where('status', $request->status);
        }

        $sheets = $query->paginate(10);

       // استعلام واحد يجلب إحصائيات الصيدليات (غير المؤجلة) لكل الكشوفات المعروضة بالصفحة الحالية
       $sheetIds = $sheets->pluck('id');
       $allInvoices = Invoice::whereIn('distribution_sheet_id', $sheetIds)
           ->where('placement', Invoice::IN_SHEET)
           ->select('id', 'distribution_sheet_id', 'customer_id', 'customer_name', 'visited_at', 'area_key')
           ->get()
           ->when($repScope !== null, fn ($c) => $c->filter(fn ($i) => $repScope->allowsInvoiceId($i->id)))
           ->groupBy('distribution_sheet_id');

       // المرحلة 3: الزيارة من sheet_visits والدفعات (غير الملغاة)، وكشوف v1 من أعمدتها القديمة (قراءة فقط)
       $visitedCustomers = SheetVisit::active()->where('status', 'visited')->whereIn('distribution_sheet_id', $sheetIds)
           ->get(['distribution_sheet_id', 'customer_id'])
           ->concat(Payment::active()->whereIn('distribution_sheet_id', $sheetIds)->get(['distribution_sheet_id', 'customer_id']))
           ->groupBy('distribution_sheet_id')
           ->map(fn ($rows) => $rows->pluck('customer_id')->unique()->all());

       $sheets->each(function ($sheet) use ($allInvoices, $visitedCustomers) {
           $sheetInvoices = $allInvoices->get($sheet->id, collect());
           $uniqueCustomers = $sheetInvoices->unique(fn ($i) => $i->customer_id ?: $i->customer_name);
           $sheet->total_pharmacies_count = $uniqueCustomers->count();
           $visited = $visitedCustomers->get($sheet->id, []);
           $sheet->not_visited_count = $uniqueCustomers->filter(fn ($invoice) => $sheet->isV2()
               ? ! in_array($invoice->customer_id, $visited)
               : is_null($invoice->visited_at))->count();

           $areaKeys = $sheet->isV2() ? $sheetInvoices->pluck('area_key')->filter()->unique()->values()->all() : [];
           $sheet->unassigned_areas = $sheet->isDraft() ? [] : $sheet->unassignedAreaKeys($areaKeys);
       });

        // المرحلة 7H: «بانتظار اطلاع الموزع (N)» (استعلام واحد للصفحة)
        $unackedCounts = app(\App\Services\NoticeAckService::class)->unackedCounts($sheets->getCollection());

        return view('supervisor.index', compact('sheets', 'distributors', 'unackedCounts'));
}

public function showSheet(Request $request, $id, CustomerBalanceService $balanceService)
{
    $sheet = DistributionSheet::with(['distributor', 'assignments.distributor', 'statusChanger', 'supervisor'])->findOrFail($id);
    Gate::authorize('view', $sheet);

    // فواتير الكشف الموضوعة فيه فقط؛ الطلبيات المؤجلة خارج الكشوف (رابط إلى /invoices/deferred)
    $activeInvoices = Invoice::where('distribution_sheet_id', $id)
        ->where('placement', Invoice::IN_SHEET)
        ->where('is_deferred', false)
        ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
        ->withCount('lines')
        ->orderBy('id')
        ->get();

    // المرحلة 8A: مشرف المندوبين بحصر مفعّل يرى فواتير مندوبيه في نطاقه فقط (والمجاميع منها وحدها، لا إجمالي الكشف)
    $repScope = \App\Services\Reps\RepScope::current($request->user());
    if ($repScope) {
        $activeInvoices = $activeInvoices->filter(fn ($i) => $repScope->allowsInvoiceId($i->id))->values();
    }

    $totalInvoicesAmount = $activeInvoices->sum('invoice_amount');
    $totalBalancesAmount = $activeInvoices->unique('customer_name')->sum('final_balance');

    // المرحلة 3: الزيارات والدفعات من جدوليهما (كشوف v1 تبقى على أعمدتها القديمة للقراءة)
    $visits = SheetVisit::with('user:id,name')->active()->where('distribution_sheet_id', $sheet->id)->get()->keyBy('customer_id');
    $sheetPayments = Payment::with(['enteredBy:id,name', 'voider:id,name', 'customer:id,name'])->where('distribution_sheet_id', $sheet->id)->orderBy('id')->get();
    if ($repScope) {
        // دفعات صيدليات النطاق فقط (لا دفعات خارجية لزبائن آخرين)
        $sheetPayments = $sheetPayments->whereIn('customer_id', $activeInvoices->pluck('customer_id')->filter()->unique()->all())->values();
    }
    $paymentsByCustomer = $sheetPayments->groupBy('customer_id');
    $activePayments = $sheetPayments->whereNull('voided_at');
    $locks = $sheet->distribution_date
        ? PharmacyLock::with('holder:id,name')->whereIn('customer_id', $activeInvoices->pluck('customer_id')->filter()->unique())
            ->whereDate('distribution_date', $sheet->distribution_date)->whereNotNull('user_id')->get()->keyBy('customer_id')
        : collect();

    $totalCollectedAmount = $sheet->isV2()
        ? $activePayments->where('currency', 'SYP')->sum('amount')
        : $activeInvoices->sum('collected_amount');
    $totalCollectedUsd = $activePayments->where('currency', 'USD')->sum('amount');
    // دفعات خارجية لزبائن ليست لهم فواتير في الكشف
    $externalPayments = $sheetPayments->whereNotIn('customer_id', $activeInvoices->pluck('customer_id')->filter()->all())->values();

    $areas = $activeInvoices->pluck('area')->filter()->unique()->values();

    // خيارات "منطقة محددة" في زر التصدير
    $exportAreas = $activeInvoices->whereNotNull('area_key')
        ->mapWithKeys(fn ($i) => [$i->area_key => $i->area ?: 'بلا منطقة'])
        ->sort()
        ->all();
    $groupedInvoices = $activeInvoices->groupBy('customer_name');

    $totalPharmaciesCount = $groupedInvoices->count();
    $visitedPharmaciesCount = $groupedInvoices->filter(function ($invoices) use ($sheet, $visits, $paymentsByCustomer) {
        $first = $invoices->first();
        if (! $sheet->isV2()) {
            return ! is_null($first->visited_at);
        }

        return ($visits->get($first->customer_id)?->status === 'visited')
            || $paymentsByCustomer->get($first->customer_id, collect())->whereNull('voided_at')->isNotEmpty();
    })->count();
    $notVisitedPharmaciesCount = $totalPharmaciesCount - $visitedPharmaciesCount;

    // المرحلة 7B: الرصيد المعروض الواحد لكل زبون (رصيد المحاسبة − المؤجل − الدفعات غير المنعكسة) مع شرحه
    $balanceBreakdown = [];
    if ($sheet->isV2()) {
        $customerIds = $activeInvoices->pluck('customer_id')->filter()->unique()->values()->all();
        $balanceBreakdown = $balanceService->currentBalances(Customer::whereIn('id', $customerIds)->get(), $sheet->distribution_date);
        $totalBalancesAmount = round(collect($balanceBreakdown)->sum(fn ($b) => $b->net), 2);
    }

    $canManage = ! $request->routeIs('accountant.*')
        && ($request->user()->can('assign', $sheet) || $request->user()->can('close', $sheet));
    $assignment = $canManage ? $this->assignmentData($sheet) : null;
    $distributors = $canManage ? User::where('role', Role::Distributor)->orderBy('name')->get(['id', 'name']) : collect();
    // المرحلة 7A (E7): ما ينتظر القرار لفواتير الكشف (لنافذة تأكيد الإغلاق)
    $pendingChanges = $canManage && in_array($sheet->status, ['pending', 'in_progress'], true)
        ? app(\App\Services\Import\ImportReviewService::class)->pendingForSheet($sheet)
        : collect();

    return view('supervisor.sheet-show', compact(
        'sheet',
        'groupedInvoices',
        'exportAreas',
        'totalInvoicesAmount',
        'totalBalancesAmount',
        'totalCollectedAmount',
        'totalCollectedUsd',
        'visits',
        'paymentsByCustomer',
        'externalPayments',
        'locks',
        'areas',
        'totalPharmaciesCount',
        'visitedPharmaciesCount',
        'notVisitedPharmaciesCount',
        'balanceBreakdown',
        'canManage',
        'assignment',
        'distributors',
        'pendingChanges'
    ));
}

/**
 * بيانات جدول "إسناد المناطق": المناطق مجمّعة تحت كتلتها الأكثر تكراراً.
 */
private function assignmentData(DistributionSheet $sheet): array
{
    $rows = Invoice::where('invoices.distribution_sheet_id', $sheet->id)
        ->where('invoices.placement', Invoice::IN_SHEET)
        ->where('invoices.is_deferred', false)
        ->whereNotNull('invoices.area_key')
        ->leftJoin('customers', 'customers.id', '=', 'invoices.customer_id')
        ->get(['invoices.area_key', 'invoices.area', 'invoices.customer_name', 'customers.block']);

    $blocks = [];
    foreach ($rows->groupBy('area_key') as $areaKey => $areaRows) {
        $customers = $areaRows->unique('customer_name');
        if ($areaKey === SheetAssignment::NO_AREA) {
            $block = '— بلا منطقة —';
            $label = 'بلا منطقة';
        } else {
            $block = $customers->pluck('block')->filter()->countBy()->sortDesc()->keys()->first() ?? 'بلا كتلة';
            $label = $areaRows->pluck('area')->filter()->first() ?? $areaKey;
        }
        $blocks[$block][] = ['key' => $areaKey, 'label' => $label, 'customers' => $customers->count()];
    }
    ksort($blocks);

    $current = $sheet->assignments->mapWithKeys(fn ($a) => [(string) $a->area_key => $a->distributor_id])->all();

    return [
        'blocks' => $blocks,
        'all_distributor_id' => $current[''] ?? null,
        'current' => $current,
        'unassigned' => $sheet->unassignedAreaKeys($rows->pluck('area_key')->unique()->values()->all()),
    ];
}

/**
 * DEPRECATED (المرحلة 3): تحميل كشوف v1 القديمة فقط بحقن أعمدة التحصيل القديمة على الفواتير.
 * غير مرتبط بالدفعات الجديدة (payments)، وسيُستبدل بتصدير المالية في المرحلة 5.
 */
public function downloadSheet($id)
{
    // تحسين: جلب الكشف مع الموزع المرتبط به دفعة واحدة لتسريع الاستعلام
    $sheet = DistributionSheet::with('distributor')->findOrFail($id);
    Gate::authorize('view', $sheet);

    // كشوف v2: الملف الأصلي للأرشيف فقط ولا يُعدَّل؛ التحميل سيُستبدل بتصدير من قاعدة البيانات لاحقاً
    if ($sheet->isV2()) {
        return redirect()->back()->with('error', 'تحميل الكشف غير متاح لكشوف الصيغة الجديدة حالياً.');
    }

    // 1. المسار الفعلي على السيرفر أصبح يعتمد على الاسم الحقيقي النظيف المباشر
    $actualPath = 'excel_sheets/' . $sheet->file_name;

    if (!Storage::exists($actualPath)) {
        return redirect()->back()->with('error', 'الملف الأصلي غير موجود! يرجى رفع كشف جديد بعد هذا التعديل النظيف.');
    }

    // 2. جلب المسار الفيزيائي الكامل للملف
    $originalPath = Storage::path($actualPath);

    // 3. اسم الملف عند التحميل للإدارة (التعديل هنا)
    $distributorName = $sheet->distributor ? $sheet->distributor->name : 'موزع_غير_معروف';

    // تنظيف اسم الموزع من الفراغات والرموز غير المسموحة في أسماء الملفات بأنظمة التشغيل (مثل وندوز)
    $safeDistributorName = str_replace(['/', '\\', '*', ':', '?', '"', '<', '>', '|', ' '], '_', $distributorName);

    // دمج السابقة (معدل) + اسم الموزع + اسم الملف الأصلي (الذي يحتوي اللاحقة .xlsx تلقائياً)
    $downloadName = 'معدل_' . $safeDistributorName . '_' . $sheet->file_name;
    $tempPath = storage_path('app/public/' . $downloadName);

    if (!file_exists(storage_path('app/public'))) {
        mkdir(storage_path('app/public'), 0777, true);
    }

    // 4. نسخ الملف وحقن البيانات
    copy($originalPath, $tempPath);

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tempPath);
    $worksheet = $spreadsheet->getSheet(0);

    $invoices = $sheet->invoices()->get()->keyBy('invoice_number');
    $highestRow = $worksheet->getHighestRow();
    $processedCustomers = [];

    for ($row = 4; $row <= $highestRow; $row++) {
        $invoiceNumber = trim($worksheet->getCell([2, $row])->getValue());

        if ($invoiceNumber) {
            // استخدام الكولكشن المجلوبة مسبقاً بدل استعلام جديد لكل سطر
            $invoiceData = $invoices->get($invoiceNumber)
               ?? $invoices->first(fn($inv) => str_contains($inv->invoice_number, $invoiceNumber)
                                           || str_contains($invoiceNumber, $inv->invoice_number));

            if ($invoiceData) {
                $customerName = $invoiceData->customer_name;

                if (!in_array($customerName, $processedCustomers)) {
                    $worksheet->setCellValue([15, $row], (float)$invoiceData->collected_amount);
                    $worksheet->setCellValue([14, $row], $invoiceData->notes);
                    $processedCustomers[] = $customerName;
                } else {
                    $worksheet->setCellValue([15, $row], null);
                    $worksheet->setCellValue([14, $row], null);
                }
            }
        }
    }

    $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->setPreCalculateFormulas(false);
    $writer->save($tempPath);

    if (ob_get_contents()) {
        ob_end_clean();
    }

    return response()->download($tempPath, $downloadName)->deleteFileAfterSend(true);
}

public function completeSheet(Request $request, $id, \App\Services\Import\ImportReviewService $review)
{
    // 1. جلب الكشف المطلوب أو إظهار خطأ إذا لم يكن موجوداً
    $sheet = DistributionSheet::findOrFail($id);
    Gate::authorize('view', $sheet);
    Gate::authorize('close', $sheet);

    if ($sheet->isDraft()) {
        return redirect()->back()->with('error', 'الكشف مسودة ولا يقبل أي تعديل.');
    }

    // المرحلة 7A (E7): تغييرات معلّقة لفواتيره ⇒ تأكيد صريح، ثم تُحفظ للتسوية بعد الإغلاق بدل تركها معلّقة للأبد
    $pending = $review->pendingForSheet($sheet);
    if ($pending->isNotEmpty() && ! $request->boolean('confirm_pending')) {
        return redirect()->back()->with('warning', 'لم يُغلق الكشف: لفواتيره '.$pending->count().' تغيير ينتظر القرار ('
            .$pending->map(fn ($p) => $p['number'].' — '.$p['label'])->implode('، ')
            .'). عند الإغلاق تُحفظ هذه التغييرات «بانتظار التسوية بعد الإغلاق» ولا تُطبَّق. أكّد الإغلاق من جديد.');
    }
    // المرحلة 7H: إشعارات إلزامية لم يطّلع عليها الموزع ⇒ تحذير بتأكيد (لا منع)
    $unacked = app(\App\Services\NoticeAckService::class)->unackedCount($sheet);
    if ($unacked > 0 && ! $request->boolean('confirm_unacked')) {
        return redirect()->back()->with('warning', "لم يُغلق الكشف: فيه {$unacked} إشعاراً بتعديل لم يطّلع عليه الموزع بعد (راجع «اطلاع الموزع» في صفحة الكشف). أكّد الإغلاق من جديد إن أردت الإغلاق رغم ذلك.");
    }
    $converted = $pending->isNotEmpty() ? $review->closeSheet($sheet) : 0;

    $oldStatus = $sheet->status;

    // 2. تحديث الحالة إلى مكتمل
    $sheet->update([
        'status' => 'completed',
        'status_changed_by' => Auth::id(),
        'status_changed_at' => now(),
    ]);

    // المرحلة 7B: final_balance يبقى محدّثاً عند الاكتمال (للتوافق؛ لا يُعرض)
    app(\App\Services\SheetFiguresService::class)->refresh($sheet->fresh());

    ActivityLog::record('sheet.completed', $sheet, ['status' => $oldStatus], ['status' => 'completed'], [
        'file_name' => $sheet->file_name,
        'pending_changes_saved_for_adjustment' => $converted,
        'unacked_notices' => $unacked,
    ]);

    // 3. العودة إلى الصفحة السابقة مع رسالة نجاح خضراء
    return redirect()->back()->with('success', 'تم إغلاق الكشف وتحويل حالته إلى مكتمل.'
        .($converted ? " وحُفظت {$converted} تغييرات معلّقة «بانتظار التسوية بعد الإغلاق» (لم تُطبَّق)." : ''));
}

// عرض صفحة إدارة الحسابات وجلب المستخدمين (للأدمن فقط)
public function manageUsers()
{
    // جلب جميع المستخدمين وترتيبهم الأحدث أولاً
    $users = User::orderBy('created_at', 'desc')->get();
    $adminsCount = $users->where('role', Role::Admin)->count();

    return view('supervisor.users', compact('users', 'adminsCount'));
}

// التحقق من البيانات وحفظ المستخدم الجديد بكلمة مرور مشفرة
public function storeUser(Request $request)
{
    // التحقق الصارم من المدخلات لمنع الأخطاء البرمجية وتكرار الإيميلات
    $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|string|email|max:255|unique:users',
        'password' => 'required|string|min:8',
        'role'     => ['required', Rule::enum(Role::class)],
    ], $this->userValidationMessages());

    // المرحلة 6: مركز كلفة المندوب (كود-اسم) مطلوب لحساب مندوب
    $costCenter = $this->repCostCenter($request, null);

    // إنشاء الحساب وتخزينه في قاعدة البيانات
    $user = User::create([
        'name'     => $request->name,
        'email'    => $request->email,
        'password' => Hash::make($request->password),
        'role'     => $request->role,
    ] + $costCenter);

    if ($user->role === Role::Rep) {
        ActivityLog::record('rep.cost_center_set', $user, ['cost_center_label' => null, 'cost_center_code' => null], $costCenter);
    }

    ActivityLog::record('user.created', $user, [], [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role->value,
    ]);

    return redirect()->back()->with('success', 'تم إنشاء الحساب الجديد بنجاح.');
}

// حذف الحساب مع منع حذف الحساب الحالي أو آخر أدمن
public function deleteUser($id)
{
    $user = User::findOrFail($id);

    // حماية: منع المستخدم من حذف حسابه الحالي الذي يسجل به الدخول
    if ($user->id === Auth::id()) {
        return redirect()->back()->with('error', 'لا يمكنك حذف حسابك الحالي الذي تستخدمه الآن!');
    }

    // حماية: يجب أن يبقى في النظام مدير واحد على الأقل
    if ($user->isAdmin() && $this->adminsCount() <= 1) {
        return redirect()->back()->with('error', 'لا يمكن حذف آخر مدير في النظام.');
    }

       // حماية حرجة: منع حذف أي حساب مرتبط بكشوفات توزيع (كموزع أو كمشرف رافع أو بإسناد منطقة)
       // لأن العلاقة بقاعدة البيانات Cascade، وحذف الحساب سيمحو كل سجلاته المالية التاريخية نهائياً
       $hasLinkedSheets = DistributionSheet::where('distributor_id', $id)
           ->orWhere('supervisor_id', $id)
           ->exists()
           || SheetAssignment::where('distributor_id', $id)->exists();

       if ($hasLinkedSheets) {
           return redirect()->back()->with('error',
               'لا يمكن حذف هذا الحساب لأنه مرتبط بكشوفات توزيع موجودة في النظام (كموزع أو كرافع للكشف). حذفه سيمحو تلك السجلات المالية نهائياً.'
           );
       }

    $snapshot = [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role?->value,
    ];

    // حذف الحساب
    $user->delete();

    ActivityLog::record('user.deleted', $user, $snapshot, []);

    return redirect()->back()->with('success', 'تم حذف الحساب وإلغاء صلاحياته من النظام.');
}

public function update(Request $request, $id)
{
    $user = User::findOrFail($id);

    // التحقق من المدخلات (مع استثناء البريد الإلكتروني للمستخدم الحالي من شرط الفريد)
    $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|max:255|unique:users,email,' . $user->id,
        'role' => ['required', Rule::enum(Role::class)],
        'password' => 'nullable|string|min:8',
    ], $this->userValidationMessages());

    $newRole = Role::from($request->role);

    // حماية: منع المستخدم من تغيير صلاحية حسابه الحالي بنفسه (تجنباً لفقدان الوصول لنفسه)
    if ($user->id === Auth::id() && $newRole !== $user->role) {
        return redirect()->back()->with('error', 'لا يمكنك تغيير صلاحية حسابك الحالي بنفسك! اطلب من مدير آخر تنفيذ هذا التغيير.');
    }

    // حماية: لا يمكن سحب صلاحية آخر مدير في النظام
    if ($user->isAdmin() && $newRole !== Role::Admin && $this->adminsCount() <= 1) {
        return redirect()->back()->with('error', 'لا يمكن تغيير دور آخر مدير في النظام.');
    }

    $costCenter = $this->repCostCenter($request, $user, $newRole);
    $oldCostCenter = ['cost_center_label' => $user->cost_center_label, 'cost_center_code' => $user->cost_center_code];

    $oldValues = [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role?->value,
    ];

    // تحديث البيانات الأساسية
    $user->name = $request->name;
    $user->email = $request->email;
    $user->role = $newRole;
    $user->fill($costCenter);

    // إذا تم كتابة كلمة مرور جديدة قم بتحديثها
    if ($request->filled('password')) {
        $user->password = Hash::make($request->password);
    }

    $user->save();

    if ($oldCostCenter !== $costCenter && ($newRole === Role::Rep || $oldCostCenter['cost_center_code'] !== null)) {
        ActivityLog::record('rep.cost_center_set', $user, $oldCostCenter, $costCenter);
    }

    ActivityLog::record('user.updated', $user, $oldValues, [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role->value,
    ], [
        // اسم المفتاح لا يحتوي كلمة password لأن السجل يحذف أي مفتاح حساس
        'credentials_changed' => $request->filled('password'),
    ]);

    return redirect()->back()->with('success', 'تم تحديث بيانات الحساب بنجاح.');
}

    /**
     * مركز كلفة المندوب (المرحلة 6): «كود-اسم» مطلوب للمندوب، والكود (ما قبل أول شرطة بعد التطبيع)
     * فريد بين حسابات المندوبين (فحص التطبيق؛ الفهرس غير unique). لغير المندوب يُفرَّغ.
     *
     * @return array{cost_center_label: ?string, cost_center_code: ?string}
     */
    private function repCostCenter(Request $request, ?User $user, ?Role $role = null): array
    {
        $role ??= Role::tryFrom((string) $request->input('role'));
        if ($role !== Role::Rep) {
            return ['cost_center_label' => null, 'cost_center_code' => null];
        }

        $label = preg_replace('/\s+/u', ' ', trim((string) $request->input('cost_center_label'))) ?? '';
        $code = \App\Support\CostCenter::codeFromLabel($label);
        if ($label === '' || $code === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cost_center_label' => 'مركز الكلفة مطلوب للمندوب بصيغة «كود-اسم» كما في ملف الحركة (مثل 655231-كرم الخالدي).',
            ]);
        }
        if (mb_strlen($label) > 255 || mb_strlen($code) > 50) {
            throw \Illuminate\Validation\ValidationException::withMessages(['cost_center_label' => 'مركز الكلفة أطول من المسموح.']);
        }

        $taken = User::where('role', Role::Rep)
            ->when($user, fn ($q) => $q->whereKeyNot($user->id))
            ->whereNotNull('cost_center_code')
            ->get(['id', 'name', 'cost_center_code'])
            ->first(fn ($u) => \App\Support\CostCenter::normalizeCode($u->cost_center_code) === $code);
        if ($taken) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'cost_center_label' => "كود مركز الكلفة {$code} مستخدم لمندوب آخر ({$taken->name}).",
            ]);
        }

        // بأرقام لاتينية في النص المعتمد
        $label = $code.'-'.trim(explode('-', $label, 2)[1]);

        return ['cost_center_label' => $label, 'cost_center_code' => $code];
    }

    private function adminsCount(): int
    {
        return User::where('role', Role::Admin)->count();
    }

    /**
     * @return array<string, string>
     */
    private function userValidationMessages(): array
    {
        return [
            'name.required'     => 'الاسم مطلوب.',
            'email.required'    => 'البريد الإلكتروني مطلوب.',
            'email.email'       => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique'      => 'هذا البريد الإلكتروني مسجل مسبقاً في النظام!',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min'      => 'كلمة المرور يجب ألا تقل عن 8 خانات.',
            'role.required'     => 'اختر نوع الحساب.',
            'role.enum'         => 'نوع الحساب غير صحيح.',
        ];
    }
}
