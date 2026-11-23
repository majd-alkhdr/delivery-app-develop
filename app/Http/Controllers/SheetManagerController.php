<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\Invoice;
use App\Models\InvoiceChangeProposal;
use App\Models\SheetAssignment;
use App\Services\Import\SheetImportService;
use App\Services\Import\WorkbookImporter;
use App\Services\NoticeAckService;
use App\Services\SheetDispatchService;
use App\Services\SheetInvoiceFilter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * شاشات مسؤول الكشوف (والمدير): الرفع، معاينة الدفعة، تشكيل الكشوف، والإرسال إلى مشرف التوزيع.
 */
class SheetManagerController extends Controller
{
    public function __construct(protected SheetDispatchService $dispatch)
    {
    }

    /**
     * الرئيسية: نموذج الرفع، آخر الدفعات، وعدّادات سريعة.
     */
    public function index()
    {
        $batches = ImportBatch::with('user')->latest('created_at')->paginate(15);

        $batchIds = $batches->pluck('id');
        $sheetsByBatch = DistributionSheet::whereIn('batch_id', $batchIds)->get(['id', 'batch_id', 'status'])->groupBy('batch_id');
        $unassignedByBatch = Invoice::where('placement', Invoice::UNASSIGNED)
            ->whereIn('last_seen_batch_id', $batchIds)
            ->selectRaw('last_seen_batch_id, COUNT(*) as c')
            ->groupBy('last_seen_batch_id')
            ->pluck('c', 'last_seen_batch_id');

        // المرحلة 7A: عناصر الدفعات المعلّقة (لكل دفعة وللمجموع)
        $pendingItemsByBatch = ImportBatchItem::whereIn('batch_id', $batchIds)->where('status', ImportBatchItem::PENDING)
            ->selectRaw('batch_id, COUNT(*) as c')->groupBy('batch_id')->pluck('c', 'batch_id');
        $pendingItemsTotal = ImportBatchItem::where('status', ImportBatchItem::PENDING)->count();
        $pendingItemBatches = ImportBatchItem::where('status', ImportBatchItem::PENDING)->distinct()->pluck('batch_id');

        // المرحلة 7B: «أرصدة غير مطبَّقة» في سجل الدفعات
        $balanceStatusByBatch = \App\Models\BalanceImport::whereIn('batch_id', $batchIds)->get()->keyBy('batch_id');

        $counters = [
            'unassigned' => Invoice::where('placement', Invoice::UNASSIGNED)->count(),
            // اقتراحات قديمة (قبل 7A) بلا عنصر دفعة + عناصر الدفعات المعلّقة
            'proposals' => InvoiceChangeProposal::where('status', InvoiceChangeProposal::PENDING)
                ->whereNotIn('id', ImportBatchItem::whereNotNull('proposal_id')->select('proposal_id'))->count() + $pendingItemsTotal,
            'preparing' => DistributionSheet::where('status', 'preparing')->count(),
            'review' => Customer::where('needs_review', true)->count(),
        ];

        $defaultDistributionDate = now('Asia/Damascus')->toDateString();

        return view('sheet-manager.index', compact('batches', 'sheetsByBatch', 'unassignedByBatch', 'counters', 'defaultDistributionDate',
            'pendingItemsByBatch', 'pendingItemsTotal', 'pendingItemBatches', 'balanceStatusByBatch'));
    }

    /**
     * رفع ملف أو عدة ملفات (الحركة اليومية + الأرصدة) ثم التوجيه إلى معاينة الدفعة.
     */
    public function upload(Request $request, SheetImportService $importer)
    {
        $request->validate([
            'excel_files' => 'required|array|min:1|max:10',
            'excel_files.*' => 'required|file|mimes:xlsx,xls|max:20480',
            'distribution_date' => 'required|date',
            'balances_as_of' => 'nullable|date',
        ], [
            'excel_files.required' => 'اختر ملف إكسل واحداً على الأقل.',
            'excel_files.max' => 'يمكن رفع 10 ملفات كحد أقصى في المرة الواحدة.',
            'excel_files.*.mimes' => 'يجب أن تكون الملفات بصيغة xlsx أو xls.',
            'excel_files.*.max' => 'حجم الملف يجب ألا يتجاوز 20 ميغابايت.',
            'distribution_date.required' => 'حدد تاريخ التوزيع.',
            'distribution_date.date' => 'تاريخ التوزيع غير صالح.',
        ]);

        // المرحلة 7D: شبكة أمان لهذا الطلب وحده (ملف كبير): زمن أطول وذاكرة أكبر، دون رفعهما عاماً
        set_time_limit(300);
        $memory = $this->memoryLimitBytes();
        if ($memory !== -1 && $memory < 512 * 1024 ** 2) {
            ini_set('memory_limit', '512M');
        }

        try {
            $result = $importer->importBatch(
                $request->file('excel_files'),
                Carbon::parse($request->distribution_date)->toDateString(),
                $request->user(),
                $request->boolean('check_missing'),
                $request->filled('balances_as_of') ? Carbon::parse($request->balances_as_of)->toDateString() : null,
            );
        } catch (\App\Services\Import\ColumnsConfirmationRequired $e) {
            // المرحلة 7G: عمود حرج غائب ⇒ لم يُطبَّق شيء؛ الملف محفوظ وينتظر تأكيداً صريحاً
            $token = (string) \Illuminate\Support\Str::uuid();
            $request->session()->put(UploadConfirmationController::SESSION_KEY.'.'.$token, $e->pending + ['user_id' => $request->user()->id]);

            return redirect()->route('sheet-manager.uploads.confirm', $token);
        } catch (\Throwable $e) {
            // الدفعة معاملة واحدة: الاستثناء ألغاها كاملة، فلا شيء مطبَّق (الملف المرفوع يبقى محفوظاً)
            report($e);
            ActivityLog::record('import.failed', null, [], ['status' => 'failed'], [
                'stage' => $importer->stage(),
                'reason' => mb_substr($e->getMessage(), 0, 1000),
                'file_names' => collect($request->file('excel_files'))->map(fn ($f) => $f->getClientOriginalName())->all(),
                'distribution_date' => $request->distribution_date,
            ]);

            return redirect()->route('sheet-manager.index')
                ->with('error', 'تعذّر إكمال الرفع ولم يُطبَّق أي شيء، يمكنك إعادة المحاولة. (المرحلة: '.SheetImportService::stageLabel($importer->stage()).')');
        }

        $failed = collect($result['reports'])->where('success', false)->count();

        return redirect()->route('sheet-manager.batches.show', $result['batch_id'])->with(
            $failed ? 'warning' : 'success',
            $failed ? "تعذّر استيراد {$failed} ملف. التفاصيل أدناه." : 'تم الرفع. راجع الكشوف قبل إرسالها إلى مشرف التوزيع.'
        );
    }

    /**
     * كل الكشوف بكل حالاتها (لمسؤول الكشوف والمدير).
     */
    public function sheets(Request $request)
    {
        $query = DistributionSheet::with(['assignments.distributor', 'distributor'])->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date')) {
            $query->whereDate('distribution_date', $request->date);
        }

        $sheets = $query->paginate(20)->withQueryString();
        $this->attachSheetFigures($sheets->getCollection());

        // المرحلة 7H: «بانتظار اطلاع الموزع (N)»
        $unackedCounts = app(NoticeAckService::class)->unackedCounts($sheets->getCollection());

        return view('sheet-manager.sheets', compact('sheets', 'unackedCounts'));
    }

    /**
     * صفحة الكشف لمسؤول الكشوف: فواتيره مع إجراءات النقل والإخراج وتغيير المنطقة، وإضافة فاتورة.
     */
    public function sheet(Request $request, DistributionSheet $sheet)
    {
        Gate::authorize('view', $sheet);

        // المرحلة 8C: فلترة الفواتير وفرزها على الخادم، والفلاتر في الرابط
        $filters = $request->validate([
            'search' => 'nullable|string|max:100',
            'area' => 'nullable|string|max:255',
            'block' => 'nullable|string|max:255',
            'origin' => 'nullable|in:'.implode(',', array_keys(SheetInvoiceFilter::ORIGINS)),
            'min' => 'nullable|numeric|min:0',
            'max' => 'nullable|numeric|min:0',
            'review' => 'nullable|boolean',
            'sort' => 'nullable|in:'.implode(',', array_keys(SheetInvoiceFilter::SORTS)),
        ]);
        $filter = new SheetInvoiceFilter;
        $invoices = $filter->apply($filter->base($sheet)->withCount('lines'), $sheet, $filters)->get();
        $filterOptions = $filter->options($sheet);
        $filtering = SheetInvoiceFilter::active($filters);
        $totals = $filter->base($sheet)->selectRaw('COUNT(*) as invoices, COALESCE(SUM(invoice_amount), 0) as amount, COUNT(DISTINCT customer_name) as customers')->first();

        // زبائن عليهم نشاط (زيارة أو دفعة غير ملغاة) في هذا الكشف
        $activityCustomers = \App\Models\SheetVisit::active()->where('distribution_sheet_id', $sheet->id)->pluck('customer_id')
            ->merge(\App\Models\Payment::active()->where('distribution_sheet_id', $sheet->id)->pluck('customer_id'))
            ->unique()->values()->all();

        $date = $sheet->distribution_date?->toDateString();
        $canEdit = $request->user()->can('manageInvoices', $sheet);
        $targetSheets = $this->targetSheets($date)->where('id', '!=', $sheet->id)->values();
        $areaOptions = $this->areaOptions();

        // البحث لإضافة فاتورة: في "بلا كشف" وفي كشوف اليوم نفسه الأخرى
        $searchResults = collect();
        if ($canEdit && $request->filled('q')) {
            $q = trim($request->q);
            $searchResults = Invoice::query()
                ->where(fn ($w) => $w->where('invoice_number', 'like', "%{$q}%")->orWhere('customer_name', 'like', "%{$q}%"))
                ->where(function ($w) use ($targetSheets) {
                    $w->where('placement', Invoice::UNASSIGNED)
                        ->orWhere(fn ($x) => $x->where('placement', Invoice::IN_SHEET)->whereIn('distribution_sheet_id', $targetSheets->pluck('id')));
                })
                ->with('distributionSheet')
                ->limit(30)
                ->get();
        }

        $conflicts = $date ? $this->dispatch->conflicts($date, [$sheet->id]) : [];
        $blockers = $sheet->isPreparing() ? $this->dispatch->blockers(collect([$sheet])) : [];
        // المرحلة 7A: دفعات فيها عناصر معلّقة تخص هذا الكشف (رابط من رسالة منع الإرسال)
        $pendingItemBatches = ImportBatchItem::where('status', ImportBatchItem::PENDING)
            ->where(fn ($q) => $q->where('sheet_id', $sheet->id)->orWhere('target_sheet_id', $sheet->id)
                ->orWhereIn('invoice_id', $filter->base($sheet)->select('id')))
            ->distinct()->pluck('batch_id')->all();
        $canRecall = $this->dispatch->canRecall($sheet, $request->user());
        $draftable = in_array($sheet->status, $this->dispatch->draftableStatuses($request->user()), true);

        return view('sheet-manager.sheet', compact(
            'sheet', 'invoices', 'activityCustomers', 'canEdit', 'targetSheets', 'areaOptions', 'searchResults', 'conflicts', 'blockers', 'canRecall', 'draftable',
            'pendingItemBatches', 'filters', 'filterOptions', 'filtering', 'totals'
        ));
    }

    public function send(Request $request, DistributionSheet $sheet)
    {
        $batch = $sheet->batch_id ? ImportBatch::find($sheet->batch_id) : null;
        if ($batch && ! $this->confirmedCostCenter($request, $batch)) {
            return back()->with('warning', WorkbookImporter::COST_CENTER_COLUMN_MISSING_TEXT.' أكّد الإرسال من نافذة التأكيد.');
        }
        $this->dispatch->send($sheet, $request->user());
        if ($batch && self::costCenterMissing($batch)) {
            ActivityLog::record('sheet.sent_without_cost_center', $sheet, [], ['status' => 'pending'], ['batch_id' => $batch->id, 'sheets' => 1]);
        }

        return back()->with('success', "أُرسل الكشف ({$sheet->title()}) إلى مشرف التوزيع.");
    }

    public function sendBatch(Request $request, ImportBatch $batch)
    {
        if (! $this->confirmedCostCenter($request, $batch)) {
            return back()->with('warning', WorkbookImporter::COST_CENTER_COLUMN_MISSING_TEXT.' أكّد الإرسال من نافذة التأكيد.');
        }
        $count = $this->dispatch->sendBatch($batch, $request->user());
        if (self::costCenterMissing($batch)) {
            ActivityLog::record('sheet.sent_without_cost_center', null, [], ['status' => 'pending'], ['batch_id' => $batch->id, 'sheets' => $count]);
        }

        return back()->with('success', "أُرسل {$count} كشف إلى مشرف التوزيع.");
    }

    /**
     * المرحلة 7E: ملف الدفعة بلا عمود «مركز الكلفة» (ملاحظة حرجة) وما زالت في كشوفها فواتير بلا مركز كلفة؟
     */
    public static function costCenterMissing(ImportBatch $batch): bool
    {
        return ImportBatchItem::where('batch_id', $batch->id)->where('kind', ImportBatchItem::NOTE)
                ->where('new->code', WorkbookImporter::COST_CENTER_COLUMN_MISSING)->exists()
            // المرحلة 7E2: صُحّحت لاحقاً (إعادة رفع بالعمود ثم تطبيق) ⇒ لم يبقَ في كشوف الدفعة فاتورة بلا مركز كلفة
            && Invoice::whereIn('distribution_sheet_id', DistributionSheet::where('batch_id', $batch->id)->select('id'))
                ->where('placement', Invoice::IN_SHEET)->whereNull('cost_center_code')->exists();
    }

    /** الإرسال بلا عمود مركز الكلفة لا يُمنع، لكنه يتطلب تأكيداً صريحاً */
    private function confirmedCostCenter(Request $request, ImportBatch $batch): bool
    {
        return $request->boolean('confirm_no_cost_center') || ! self::costCenterMissing($batch);
    }

    public function recall(Request $request, DistributionSheet $sheet)
    {
        $this->dispatch->recall($sheet, $request->user());

        return back()->with('success', 'تم سحب الكشف من مشرف التوزيع وإعادته إلى "قيد التجهيز".');
    }

    public function acknowledge(Request $request, ImportBatch $batch)
    {
        $this->dispatch->acknowledgeConflicts($batch, $request->user());

        return back()->with('success', 'تم تسجيل إقرارك بالتعارضات.');
    }

    // =====================================================================

    /**
     * كشوف يوم التوزيع غير المكتملة (هدف الوضع/النقل).
     */
    protected function targetSheets(?string $date): Collection
    {
        if (! $date) {
            return collect();
        }

        return DistributionSheet::where('format', 'v2')
            ->whereDate('distribution_date', $date)
            ->whereIn('status', DistributionSheet::EDITABLE_STATUSES)
            ->orderBy('delivery_order_no')
            ->get(['id', 'delivery_order_no', 'status', 'original_name', 'format', 'file_name']);
    }

    /**
     * @return array<string, string> مفتاح المنطقة => اسمها
     */
    protected function areaOptions(): array
    {
        $areas = Customer::whereNotNull('area_normalized')
            ->select('area_normalized', 'area')
            ->distinct()
            ->orderBy('area')
            ->get()
            ->unique('area_normalized')
            ->mapWithKeys(fn ($c) => [$c->area_normalized => $c->area])
            ->all();

        return [SheetAssignment::NO_AREA => 'بلا منطقة'] + $areas;
    }

    protected function attachSheetFigures(Collection $sheets): void
    {
        $rows = Invoice::whereIn('distribution_sheet_id', $sheets->pluck('id'))
            ->where('placement', Invoice::IN_SHEET)
            ->get(['distribution_sheet_id', 'customer_name', 'area_key', 'invoice_amount'])
            ->groupBy('distribution_sheet_id');

        foreach ($sheets as $sheet) {
            $group = $rows->get($sheet->id, collect());
            $sheet->invoices_count = $group->count();
            $sheet->customers_count = $group->unique('customer_name')->count();
            $sheet->areas_count = $group->pluck('area_key')->filter()->unique()->count();
            $sheet->invoices_total = round((float) $group->sum('invoice_amount'), 2);
        }
    }

    /** حدّ الذاكرة الحالي بالبايت (-1 = بلا حد) */
    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
