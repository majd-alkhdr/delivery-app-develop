<?php

use App\Enums\Role;
use App\Http\Controllers\AccountantController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DistributorController;
use App\Http\Controllers\FieldControlController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\InvoiceLinesController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\InvoiceListController;
use App\Http\Controllers\InvoicePlacementController;
use App\Http\Controllers\InvoiceSeriesController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RepController;
use App\Http\Controllers\RepSheetController;
use App\Http\Controllers\RepFinanceController;
use App\Http\Controllers\SheetExportController;
use App\Http\Controllers\SheetManagementController;
use App\Http\Controllers\SheetManagerController;
use App\Http\Controllers\SupervisorController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// التوجيه المباشر لشاشة العمل المناسبة لدور المستخدم، أو لصفحة الدخول
Route::get('/', function () {
    if (Auth::check()) {
        return redirect()->route(Auth::user()->role->homeRoute());
    }

    return redirect()->route('login');
});

// يُبقى هذا المسار لأن بعض أجزاء النظام (مثل التحقق من البريد) تعيد التوجيه إليه
Route::get('/dashboard', function () {
    return redirect()->route(Auth::user()->role->homeRoute());
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // كانت لحسابات بلا واجهة (المندوب قبل المرحلة 6)؛ صار لكل دور واجهته فتحوّل إليها
    Route::get('/no-interface', fn () => redirect()->route(Auth::user()->role->homeRoute()))->name('no-interface');
});

// شاشات مشرف التوزيع: عرض الكشوف (عدا قيد التجهيز)، الإسناد، الإغلاق
// ملاحظة: حذف الكشف أُلغي نهائياً (المرحلة 2)؛ البديل: إعادة الإسناد أو الإغلاق كمسودة.
// قراءة الكشوف: والمشرف على المندوبين (المرحلة 6B) للقراءة فقط (الكشوف المنشورة؛ Policy::view)
Route::middleware(['auth', 'role:admin,supervisor,rep_supervisor'])->group(function () {
    Route::get('/supervisor', [SupervisorController::class, 'index'])->name('supervisor.index');
    Route::get('/supervisor/download/{id}', [SupervisorController::class, 'downloadSheet'])->name('supervisor.download');
    Route::get('/supervisor/sheet/{id}', [SupervisorController::class, 'showSheet'])->name('supervisor.sheet.show');
});
Route::middleware(['auth', 'role:admin,supervisor'])->group(function () {
    Route::post('/supervisor/sheet/complete/{id}', [SupervisorController::class, 'completeSheet'])->name('supervisor.complete');
    Route::put('/supervisor/sheet/{sheet}/assignments', [SheetManagementController::class, 'assign'])->name('supervisor.sheet.assign');
    Route::post('/supervisor/sheet/{sheet}/draft', [SheetManagementController::class, 'draft'])->name('supervisor.sheet.draft');
});

// الرفع انتقل إلى مسؤول الكشوف (المرحلة 2B): المسار القديم يحوّل إليه، و403 لغير المسؤول والمدير
Route::middleware(['auth', 'role:admin,sheet_manager'])->group(function () {
    Route::match(['get', 'post'], '/supervisor/upload', fn () => redirect()->route('sheet-manager.index'))->name('supervisor.upload');
});

// شاشات مسؤول الكشوف (والمدير): الرفع، المعاينة، تشكيل الكشوف، الإرسال، مطابقة الزبائن
Route::middleware(['auth', 'role:admin,sheet_manager'])->prefix('sheet-manager')->name('sheet-manager.')->group(function () {
    Route::get('/', [SheetManagerController::class, 'index'])->name('index');
    Route::post('/upload', [SheetManagerController::class, 'upload'])->name('upload');
    // المرحلة 7G: تأكيد رفع ملف ينقصه عمود حرج
    Route::get('/uploads/{token}/confirm', [\App\Http\Controllers\UploadConfirmationController::class, 'show'])->name('uploads.confirm');
    Route::post('/uploads/{token}/confirm', [\App\Http\Controllers\UploadConfirmationController::class, 'store'])->name('uploads.confirm.store');
    // المرحلة 7E: صفحة الدفعة بتبويبات وأجزاء كسولة (?tab= و?partial=)
    Route::get('/batches/{batch}', [\App\Http\Controllers\BatchPageController::class, 'show'])->name('batches.show');
    Route::post('/batches/{batch}/send', [SheetManagerController::class, 'sendBatch'])->name('batches.send');
    Route::post('/batches/{batch}/acknowledge', [SheetManagerController::class, 'acknowledge'])->name('batches.acknowledge');
    Route::get('/sheets', [SheetManagerController::class, 'sheets'])->name('sheets.index');
    Route::get('/sheets/{sheet}', [SheetManagerController::class, 'sheet'])->name('sheets.show');
    Route::post('/sheets/{sheet}/send', [SheetManagerController::class, 'send'])->name('sheets.send');
    Route::post('/sheets/{sheet}/recall', [SheetManagerController::class, 'recall'])->name('sheets.recall');
    Route::post('/sheets/{sheet}/draft', [SheetManagementController::class, 'draft'])->name('sheets.draft');

    Route::post('/invoices/move', [InvoicePlacementController::class, 'move'])->name('invoices.move');
    Route::post('/invoices/remove', [InvoicePlacementController::class, 'remove'])->name('invoices.remove');
    Route::post('/invoices/{invoice}/place', [InvoicePlacementController::class, 'place'])->name('invoices.place');
    Route::post('/invoices/{invoice}/exclude', [InvoicePlacementController::class, 'exclude'])->name('invoices.exclude');
    Route::post('/invoices/{invoice}/restore', [InvoicePlacementController::class, 'restore'])->name('invoices.restore');
    Route::post('/invoices/{invoice}/cancel', [InvoicePlacementController::class, 'cancel'])->name('invoices.cancel');
    Route::post('/invoices/{invoice}/keep', [InvoicePlacementController::class, 'keep'])->name('invoices.keep');
    Route::post('/invoices/{invoice}/area', [InvoicePlacementController::class, 'area'])->name('invoices.area');
    Route::post('/proposals/{proposal}/apply', [InvoicePlacementController::class, 'applyProposal'])->name('proposals.apply');
    Route::post('/proposals/{proposal}/reject', [InvoicePlacementController::class, 'rejectProposal'])->name('proposals.reject');

    // المرحلة 7A: قرارات «مراجعة الفروق» في شاشة الدفعة
    Route::post('/items/{item}/apply', [\App\Http\Controllers\ImportReviewController::class, 'apply'])->name('items.apply');
    Route::post('/items/{item}/reject', [\App\Http\Controllers\ImportReviewController::class, 'reject'])->name('items.reject');
    Route::post('/batches/{batch}/apply-many', [\App\Http\Controllers\ImportReviewController::class, 'applyMany'])->name('batches.apply-many');
    // المرحلة 7E2: «تطبيق هذه المجموعة» (تغيّر وصفي فقط)
    Route::post('/batches/{batch}/apply-descriptive', [\App\Http\Controllers\ImportReviewController::class, 'applyDescriptive'])->name('batches.apply-descriptive');

    // المرحلة 7B: قرارات ملف الأرصدة المحجوز
    Route::post('/balance-imports/{import}/apply', [\App\Http\Controllers\BalanceDecisionController::class, 'apply'])->name('balances.apply');
    Route::post('/balance-imports/{import}/ignore', [\App\Http\Controllers\BalanceDecisionController::class, 'ignore'])->name('balances.ignore');
});

// المرحلة 7F: التراجع عن دفعة رفع بالكامل — المدير فقط (معاينة إلزامية ثم تنفيذ)
Route::middleware(['auth', 'role:admin'])->prefix('sheet-manager')->name('sheet-manager.')->group(function () {
    Route::get('/batches/{batch}/rollback', [\App\Http\Controllers\BatchRollbackController::class, 'show'])->name('batches.rollback');
    Route::post('/batches/{batch}/rollback', [\App\Http\Controllers\BatchRollbackController::class, 'store'])->name('batches.rollback.store');
});

// المرحلة 7F: الاستبعاد/الإلغاء الجماعي وإعادة الإدراج — مسؤول الكشوف والمدير (كالإجراء الفردي)
Route::middleware(['auth', 'role:admin,sheet_manager'])->prefix('invoices/bulk')->name('bulk-invoices.')->group(function () {
    Route::get('/preview', [\App\Http\Controllers\BulkInvoiceController::class, 'preview'])->name('preview');
    Route::post('/', [\App\Http\Controllers\BulkInvoiceController::class, 'store'])->name('store');
    Route::get('/{operation}/undo', [\App\Http\Controllers\BulkInvoiceController::class, 'undoPreview'])->name('undo.preview');
    Route::post('/{operation}/undo', [\App\Http\Controllers\BulkInvoiceController::class, 'undo'])->name('undo');
});

// مطابقة الزبائن وإخفاؤهم: مسؤول الكشوف والمدير
Route::middleware(['auth', 'role:admin,sheet_manager'])->group(function () {
    Route::post('/customers/{customer}/visibility', [CustomerController::class, 'toggleVisibility'])->name('customers.visibility');
    Route::get('/customers/review', [CustomerController::class, 'review'])->name('customers.review');
    Route::post('/customers/{customer}/link', [CustomerController::class, 'link'])->name('customers.link');
    Route::post('/customers/{customer}/approve', [CustomerController::class, 'approve'])->name('customers.approve');
    Route::get('/invoices/unassigned', [InvoiceListController::class, 'unassigned'])->name('invoices.unassigned');
});

// قائمة الزبائن (المشرف للقراءة فقط)
Route::middleware(['auth', 'role:admin,sheet_manager,supervisor'])->group(function () {
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
});

// المرحلة 8C: سجل النشاط لكل الأدوار؛ ما يُرى حسب منفّذ الإجراء (ActivityLogVisibility)
Route::middleware(['auth', 'role:admin,sheet_manager,supervisor,rep_supervisor,rep,distributor,accountant'])->group(function () {
    Route::get('/activity', [ActivityLogController::class, 'index'])->name('activity.index');
    Route::get('/activity/{log}', [ActivityLogController::class, 'show'])->whereNumber('log')->name('activity.show');
});

// الطلبيات المؤجلة: للقراءة للمشرف والمحاسب
Route::middleware(['auth', 'role:admin,sheet_manager,supervisor,accountant'])->group(function () {
    Route::get('/invoices/deferred', [InvoiceListController::class, 'deferred'])->name('invoices.deferred');
});

// تصدير كشف v2 إلى Excel للطباعة (A4): الموزع ممنوع، والصلاحية التفصيلية عبر DistributionSheetPolicy::view
Route::middleware(['auth', 'role:admin,sheet_manager,supervisor,accountant,rep_supervisor'])->group(function () {
    Route::get('/sheets/{sheet}/export', [SheetExportController::class, 'export'])->name('sheets.export');
});

// المدير فقط
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::post('/supervisor/sheet/{sheet}/reopen', [SheetManagementController::class, 'reopen'])->name('supervisor.sheet.reopen');
    Route::get('/admin/series', [InvoiceSeriesController::class, 'index'])->name('admin.series.index');
    Route::put('/admin/series/{series}', [InvoiceSeriesController::class, 'update'])->name('admin.series.update');
    Route::put('/settings/finance', [SettingsController::class, 'updateFinance'])->name('settings.finance');
    Route::put('/settings/balances', [SettingsController::class, 'updateBalances'])->name('settings.balances');
    Route::put('/settings/finance-rep', [SettingsController::class, 'updateRepFinance'])->name('settings.finance-rep');
    // المرحلة 8B: «خطة المندوب»
    Route::put('/settings/rep-plan', [SettingsController::class, 'updateRepPlan'])->name('settings.rep-plan');
    // المرحلة 8D: مفتاح «كشف الصيدلية الكامل للمندوب»
    Route::put('/settings/rep-full-statement', [SettingsController::class, 'updateRepFullStatement'])->name('settings.rep-full-statement');
    // المرحلة 7H: الإشعارات الإلزامية للموزع
    Route::put('/settings/ack-types', [SettingsController::class, 'updateAckTypes'])->name('settings.ack-types');
});

// المرحلة 8A: إسناد المندوبين لمشرفي المندوبين وتفعيل الحصر — المدير فقط
Route::middleware(['auth', 'role:admin'])->prefix('admin/rep-assignments')->name('admin.rep-assignments.')->group(function () {
    Route::get('/', [\App\Http\Controllers\RepAssignmentController::class, 'index'])->name('index');
    Route::post('/', [\App\Http\Controllers\RepAssignmentController::class, 'store'])->name('store');
    Route::put('/scoping', [\App\Http\Controllers\RepAssignmentController::class, 'scoping'])->name('scoping');
    Route::post('/{assignment}/end', [\App\Http\Controllers\RepAssignmentController::class, 'end'])->name('end');
    Route::post('/{assignment}/move', [\App\Http\Controllers\RepAssignmentController::class, 'move'])->name('move');
});

// المرحلة 8A: «ملف المندوب» للمشرف (ضمن نطاقه) والأدمن
Route::middleware(['auth', 'role:admin,rep_supervisor'])->group(function () {
    Route::get('/rep-supervisor/reps/{rep}', [\App\Http\Controllers\RepProfileController::class, 'show'])->name('rep-supervisor.reps.show');
    // المرحلة 8D: كشف الصيدلية الكامل (نطاق مندوبي المشرف، والأدمن للكل)
    Route::get('/rep-supervisor/pharmacies/{customer}', [\App\Http\Controllers\PharmacyStatementController::class, 'showForStaff'])->name('rep-supervisor.pharmacies.show');

    // المرحلة 8B: خطط المندوبين والتغطية
    Route::get('/rep-supervisor/plans', [\App\Http\Controllers\RepPlanSupervisorController::class, 'index'])->name('rep-supervisor.plans');
    Route::get('/rep-supervisor/reps/{rep}/plan', [\App\Http\Controllers\RepPlanSupervisorController::class, 'show'])->name('rep-supervisor.plan.show');
    Route::post('/rep-supervisor/reps/{rep}/plan/templates', [\App\Http\Controllers\RepPlanSupervisorController::class, 'storeTemplate'])->name('rep-supervisor.plan.templates.store');
    Route::delete('/rep-supervisor/plan/templates/{template}', [\App\Http\Controllers\RepPlanSupervisorController::class, 'destroyTemplate'])->name('rep-supervisor.plan.templates.destroy');
    Route::post('/rep-supervisor/reps/{rep}/plan/overrides', [\App\Http\Controllers\RepPlanSupervisorController::class, 'storeOverride'])->name('rep-supervisor.plan.overrides.store');
    Route::post('/rep-supervisor/reps/{rep}/plan/overrides/remove', [\App\Http\Controllers\RepPlanSupervisorController::class, 'removeOverride'])->name('rep-supervisor.plan.overrides.remove');
    Route::post('/rep-supervisor/plan-visits/{visit}/correct', [\App\Http\Controllers\RepPlanSupervisorController::class, 'correctVisit'])->name('rep-supervisor.plan.visits.correct');
    Route::get('/rep-supervisor/coverage', [\App\Http\Controllers\RepCoverageController::class, 'index'])->name('rep-supervisor.coverage');
    Route::get('/rep-supervisor/coverage/export', [\App\Http\Controllers\RepCoverageController::class, 'export'])->name('rep-supervisor.coverage.export');
    Route::get('/rep-supervisor/coverage/{rep}/{date}', [\App\Http\Controllers\RepCoverageController::class, 'day'])->name('rep-supervisor.coverage.day');
});

// إدارة المستخدمين: المدير فقط
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/supervisor/users', [SupervisorController::class, 'manageUsers'])->name('supervisor.users.index');
    Route::post('/supervisor/users/store', [SupervisorController::class, 'storeUser'])->name('supervisor.users.store');
    Route::put('/supervisor/users/{id}', [SupervisorController::class, 'update'])->name('supervisor.users.update');
    Route::delete('/supervisor/users/delete/{id}', [SupervisorController::class, 'deleteUser'])->name('supervisor.users.delete');
});

// روابط الموزع (المرحلة 3): الدفعات والزيارات في payments وsheet_visits
Route::middleware(['auth', 'role:distributor'])->group(function () {
    Route::get('/distributor', [DistributorController::class, 'index'])->name('distributor.index');
    Route::get('/distributor/sheet/{id}', [DistributorController::class, 'showSheet'])->name('distributor.sheet');
    Route::get('/distributor/customers', [DistributorController::class, 'customers'])->name('distributor.customers');
    Route::post('/distributor/sheets/{sheet}/payments', [DistributorController::class, 'storePayment'])->name('distributor.payments.store');
    Route::post('/distributor/sheets/{sheet}/visits', [DistributorController::class, 'storeVisit'])->name('distributor.visits.store');
    Route::post('/distributor/payments/external', [DistributorController::class, 'storeExternalPayment'])->name('distributor.payments.external');
    Route::get('/distributor/sheets/{sheet}/invoice-lines', [DistributorController::class, 'invoiceLines'])->name('distributor.invoice-lines');
});

// إلغاء دفعة وبيانات إيصالها: الموزع لدفعاته، والمشرف والمحاسب والأدمن
Route::middleware(['auth', 'role:admin,supervisor,accountant,distributor,rep'])->group(function () {
    Route::post('/payments/{payment}/void', [PaymentController::class, 'void'])->name('payments.void');
    Route::get('/payments/{payment}/receipt-data', [PaymentController::class, 'receiptData'])->name('payments.receipt-data');
});

// الطباعة (المرحلة 4): الإعدادات وصفحة الاختبار للموزع والمندوب والأدمن والمشرف؛ أحداث طباعة البيانات للموزع والمندوب
Route::middleware(['auth', 'role:distributor,rep,admin,supervisor'])->group(function () {
    Route::get('/print/config', [PrintController::class, 'config'])->name('print.config');
    Route::get('/print/test', [PrintController::class, 'test'])->name('print.test');
});
Route::middleware(['auth', 'role:distributor,rep'])->group(function () {
    Route::post('/print-events', [PrintController::class, 'storeEvent'])->name('print-events.store');
});

// أدوات المشرف والأدمن: فك القفل، الزيارات البعيدة وتثبيت الموقع، الإعدادات
Route::middleware(['auth', 'role:admin,supervisor'])->group(function () {
    Route::post('/pharmacy-locks/{lock}/release', [FieldControlController::class, 'releaseLock'])->name('locks.release');
    Route::get('/supervisor/far-visits', [FieldControlController::class, 'farVisits'])->name('supervisor.far-visits');
    Route::post('/supervisor/locations/pin', [FieldControlController::class, 'pinLocation'])->name('supervisor.locations.pin');
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/series/{series}/allocation', [SettingsController::class, 'updateAllocation'])->name('settings.allocation');
    Route::put('/settings/location', [SettingsController::class, 'updateLocation'])->name('settings.location');
    Route::put('/settings/printing', [SettingsController::class, 'updatePrinting'])->name('settings.printing');
});

// أسطر فاتورة للإدارة (قراءة فقط)؛ الموزع ممنوع
Route::middleware(['auth', 'role:admin,sheet_manager,supervisor,accountant,rep_supervisor'])->group(function () {
    Route::get('/invoices/{invoice}/lines', [InvoiceLinesController::class, 'show'])->name('invoices.lines');
});

// روابط المحاسب (قراءة وتحميل فقط، بدون رفع أو حذف أو تعديل) — والمدير يراها أيضاً
Route::middleware(['auth', 'role:admin,accountant'])->group(function () {
    Route::get('/accountant', [AccountantController::class, 'index'])->name('accountant.index');
    Route::get('/accountant/sheet/{id}', [SupervisorController::class, 'showSheet'])->name('accountant.sheet.show');
    Route::get('/accountant/download/{id}', [SupervisorController::class, 'downloadSheet'])->name('accountant.download');
});

// القسم المالي (المرحلة 6B): عرض = viewFinance (الأدمن والمحاسب ومشرف المندوبين)، تصدير التقارير = exportFinanceReports،
// التعديل و«التصدير للمحاسبة» وإعادة تنزيل ملفاته = manageFinance (الأدمن والمحاسب). مشرف التوزيع خارج القسم المالي كله.
Route::middleware(['auth', 'can:viewFinance'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/sheets/{sheet}', [FinanceController::class, 'show'])->name('sheets.show');
    Route::get('/unpaid', [FinanceController::class, 'unpaid'])->name('unpaid');
});
Route::middleware(['auth', 'can:exportFinanceReports'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/unpaid/export', [FinanceController::class, 'unpaidExport'])->name('unpaid.export');
});
Route::middleware(['auth', 'can:manageFinance'])->prefix('finance')->name('finance.')->group(function () {
    Route::post('/sheets/{sheet}/cash-counts', [FinanceController::class, 'storeCashCount'])->name('cash-counts.store');
    Route::post('/sheets/{sheet}/surplus', [FinanceController::class, 'setSurplus'])->name('sheets.surplus');
    Route::post('/sheets/{sheet}/export', [FinanceController::class, 'export'])->name('sheets.export');
    Route::post('/payments/{payment}/rate', [FinanceController::class, 'setRate'])->name('payments.rate');
    Route::get('/exports/{export}/download/{kind}', [FinanceController::class, 'download'])->name('exports.download');
});

// المندوب (المرحلة 6): ذممي، تحصيل لأي زبون، دفعاتي، والتحصيل بصورة إيصال (multipart في طلب واحد)
Route::middleware(['auth', 'role:rep'])->prefix('rep')->name('rep.')->group(function () {
    Route::get('/', [RepController::class, 'index'])->name('index');
    Route::get('/customers', [RepController::class, 'customers'])->name('customers');
    // المرحلة 8D: «صيدلياتي» وكشف الصيدلية الكامل
    Route::get('/pharmacies', [\App\Http\Controllers\PharmacyStatementController::class, 'index'])->name('pharmacies.index');
    Route::get('/pharmacies/{customer}', [\App\Http\Controllers\PharmacyStatementController::class, 'show'])->name('pharmacies.show');
    Route::get('/payments', [RepController::class, 'payments'])->name('payments');
    Route::post('/payments', [RepController::class, 'storePayment'])->middleware('throttle:30,1')->name('payments.store');
    // المرحلة 6B: كشوف المندوب (قراءة فقط، بفواتيره هو فقط)
    Route::get('/sheets', [RepSheetController::class, 'index'])->name('sheets.index');
    Route::get('/sheets/{sheet}', [RepSheetController::class, 'show'])->name('sheets.show');
    Route::get('/sheets/{sheet}/lines', [RepSheetController::class, 'lines'])->name('sheets.lines');
    // المرحلة 8B: «خطتي»
    Route::get('/plan', [\App\Http\Controllers\RepPlanController::class, 'show'])->name('plan');
    Route::post('/plan/visits', [\App\Http\Controllers\RepPlanController::class, 'store'])->middleware('throttle:60,1')->name('plan.visits');
    Route::post('/plan/notices/{notice}/seen', [\App\Http\Controllers\RepPlanController::class, 'seen'])->name('plan.notices.seen');
});
// استبدال صورة الإيصال قبل التصدير: المندوب صاحب الدفعة أو المحاسب أو الأدمن (التفصيل في الكونترولر)
Route::middleware(['auth', 'role:rep,accountant,admin', 'throttle:30,1'])->group(function () {
    Route::post('/rep/payments/{payment}/image', [RepController::class, 'replaceImage'])->name('rep.payments.image');
});
// عرض صورة الإيصال: صاحب الدفعة والمحاسب والأدمن ومشرف المندوبين (القسم المالي؛ لم تعد لمشرف التوزيع)
Route::middleware(['auth', 'role:rep,accountant,admin,rep_supervisor'])->group(function () {
    Route::get('/payments/{payment}/image', [RepController::class, 'image'])->name('payments.image');
});

// المالية للمندوبين: عرض (viewFinance)، وتصدير التقارير (exportFinanceReports)، والتعديل والتصدير للمحاسبة (manageFinance)
Route::middleware(['auth', 'can:viewFinance'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/rep-payments', [RepFinanceController::class, 'payments'])->name('rep-payments');
    Route::get('/rep-receivables', [RepFinanceController::class, 'receivables'])->name('rep-receivables');
});
Route::middleware(['auth', 'can:exportFinanceReports'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/rep-receivables/export', [RepFinanceController::class, 'receivablesExport'])->name('rep-receivables.export');
    Route::get('/rep-payments/list-export', [RepFinanceController::class, 'paymentsListExport'])->name('rep-payments.list-export');
});
Route::middleware(['auth', 'can:manageFinance'])->prefix('finance')->name('finance.')->group(function () {
    Route::post('/rep-payments/{payment}/review', [RepFinanceController::class, 'review'])->name('rep-payments.review');
    Route::post('/rep-payments/export', [RepFinanceController::class, 'export'])->name('rep-payments.export');
});

// المرحلة 7B: «تغيّرات الأرصدة» — قراءة للمشرفَين ومسؤول الكشوف ومشرف المندوبين، والمراجعة للمحاسب والأدمن
Route::middleware(['auth', 'can:viewBalanceChanges'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/balance-changes', [\App\Http\Controllers\BalanceChangesController::class, 'index'])->name('balance-changes');
});
Route::middleware(['auth', 'can:reviewBalanceChanges'])->prefix('finance')->name('finance.')->group(function () {
    Route::post('/balance-changes/{snapshot}/review', [\App\Http\Controllers\BalanceChangesController::class, 'review'])->name('balance-changes.review');
});
// المرحلة 7C: «التعديلات اللاحقة» — عرض للمشرفَين ومسؤول الكشوف ومشرف المندوبين، والتطبيق/التجاهل للمحاسب والأدمن، والتراجع للأدمن
Route::middleware(['auth', 'can:viewAdjustments'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/adjustments', [\App\Http\Controllers\InvoiceAdjustmentController::class, 'index'])->name('adjustments');
});
Route::middleware(['auth', 'can:applyAdjustments'])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/adjustments/create', [\App\Http\Controllers\InvoiceAdjustmentController::class, 'create'])->name('adjustments.create');
    Route::post('/adjustments', [\App\Http\Controllers\InvoiceAdjustmentController::class, 'store'])->name('adjustments.store');
    Route::post('/adjustments/proposals/{proposal}/apply', [\App\Http\Controllers\InvoiceAdjustmentController::class, 'apply'])->name('adjustments.apply');
    Route::post('/adjustments/proposals/{proposal}/ignore', [\App\Http\Controllers\InvoiceAdjustmentController::class, 'ignore'])->name('adjustments.ignore');
});
Route::middleware(['auth', 'can:revertAdjustments'])->prefix('finance')->name('finance.')->group(function () {
    Route::post('/adjustments/{adjustment}/revert', [\App\Http\Controllers\InvoiceAdjustmentController::class, 'revert'])->name('adjustments.revert');
});
Route::middleware(['auth', 'role:rep'])->group(function () {
    Route::post('/rep/adjustments/{adjustment}/seen', [\App\Http\Controllers\InvoiceAdjustmentController::class, 'seen'])->name('rep.adjustment-seen');
});

// المرحلة 7B: «اطلعت» على تغيّر رصيد صيدلية — الموزع في كشفه، والمندوب في كشوفه
Route::middleware(['auth', 'role:distributor'])->group(function () {
    Route::post('/distributor/sheets/{sheet}/balance-seen/{customer}', [DistributorController::class, 'balanceSeen'])->name('distributor.balance-seen');
    // المرحلة 7H: «اطلعت» على تعديلات صيدلية في الكشف (يفك منع التسجيل عليها)
    Route::post('/distributor/sheets/{sheet}/notices/ack/{customer}', [DistributorController::class, 'ackCustomer'])->name('distributor.notices.ack');
});
Route::middleware(['auth', 'role:rep'])->group(function () {
    Route::post('/rep/balance-seen/{snapshot}', [RepSheetController::class, 'balanceSeen'])->name('rep.balance-seen');
});

// «اطلعت» على تعديلات الكشف بعد نشره (المرحلة 6B): الصلاحية التفصيلية عبر DistributionSheetPolicy::view
Route::middleware(['auth', 'role:admin,sheet_manager,supervisor,rep_supervisor,distributor'])->group(function () {
    Route::post('/sheets/{sheet}/notices/seen', [\App\Http\Controllers\SheetNoticeController::class, 'seen'])->name('sheets.notices.seen');
});

require __DIR__.'/auth.php';
