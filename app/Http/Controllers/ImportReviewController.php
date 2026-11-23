<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Services\Import\ImportReviewService;
use Illuminate\Http\Request;

/**
 * قرارات «مراجعة الفروق» في شاشة الدفعة (المرحلة 7A): تطبيق، تجاهل، تطبيق الكل.
 * المسارات داخل مجموعة مسؤول الكشوف والمدير (role:admin,sheet_manager)، والخدمة تتحقق مرة أخرى.
 */
class ImportReviewController extends Controller
{
    public function __construct(private ImportReviewService $review)
    {
    }

    public function apply(Request $request, ImportBatchItem $item)
    {
        $result = $this->review->apply($item, $request->user(), $request->boolean('confirm_printed'));

        return $this->respond($result, 'item');
    }

    public function reject(Request $request, ImportBatchItem $item)
    {
        $request->validate(['note' => 'nullable|string|max:500']);
        $this->review->reject($item, $request->user(), $request->input('note'));

        return back()->with('success', 'تم تجاهل العنصر ('.($item->invoice_number ?? '#'.$item->id).') وبقيت الفاتورة كما هي.');
    }

    public function applyMany(Request $request, ImportBatch $batch)
    {
        $items = ImportBatchItem::where('batch_id', $batch->id)->where('status', ImportBatchItem::PENDING)->orderBy('id')->get();
        $results = $this->review->applyMany($items, $request->user(), $request->boolean('confirm_printed'));
        $applied = collect($results)->where('ok', true)->count();

        return back()
            ->with('apply_results', $results)
            ->with($applied === count($results) ? 'success' : 'warning', "تطبيق الكل: طُبّق {$applied} من ".count($results).' عنصراً. التفاصيل أدناه.');
    }

    /**
     * المرحلة 7E2: «تطبيق هذه المجموعة» لعناصر «تغيّر وصفي فقط». تُعرض المخفقة فقط (قد تكون المجموعة بالآلاف).
     */
    public function applyDescriptive(Request $request, ImportBatch $batch)
    {
        set_time_limit(300);
        $results = $this->review->applyDescriptive($batch, $request->user(), $request->boolean('confirm_printed'));
        $applied = collect($results)->where('ok', true)->count();
        $failed = collect($results)->where('ok', false)->values()->all();

        return back()
            ->with('apply_results', $failed)
            ->with($failed ? 'warning' : 'success', "تطبيق مجموعة «تغيّر وصفي فقط»: طُبّق {$applied} من ".count($results).' عنصراً.'.($failed ? ' ما لم يُطبَّق مذكور أدناه.' : ''));
    }

    /**
     * @param  array{ok: bool, status: string, message: string}  $result
     */
    public function respond(array $result, string $errorKey)
    {
        if ($result['ok']) {
            return back()->with('success', $result['message']);
        }
        if ($result['status'] === 'needs_print_confirm') {
            return back()->with('warning', $result['message']);
        }

        return back()->withErrors([$errorKey => $result['message']]);
    }
}
