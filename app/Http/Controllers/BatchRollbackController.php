<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Services\Import\BatchRollbackService;
use Illuminate\Http\Request;

/**
 * المرحلة 7F: «التراجع عن هذه الدفعة» — معاينة إلزامية بالأرقام ثم تنفيذ بسبب ورقم الدفعة. الأدمن فقط (مجموعة المسار).
 */
class BatchRollbackController extends Controller
{
    public function show(ImportBatch $batch, BatchRollbackService $service)
    {
        return view('sheet-manager.batch-rollback', ['batch' => $batch, 'analysis' => $service->analyze($batch), 'code' => self::code($batch)]);
    }

    public function store(Request $request, ImportBatch $batch, BatchRollbackService $service)
    {
        $code = self::code($batch);
        $request->validate([
            'reason' => 'required|string|min:3|max:1000',
            'confirm_code' => ['required', 'string', function ($attribute, $value, $fail) use ($code) {
                if (trim((string) $value) !== $code) {
                    $fail('اكتب رقم الدفعة كما هو ظاهر للتأكيد ('.$code.').');
                }
            }],
        ], [
            'reason.required' => 'سبب التراجع إلزامي.',
            'reason.min' => 'اكتب سبباً واضحاً (3 أحرف على الأقل).',
            'confirm_code.required' => 'اكتب رقم الدفعة للتأكيد.',
        ]);

        $result = $service->rollback($batch, $request->user(), $request->input('reason'));
        $c = $result['counts'];

        return redirect()->route('sheet-manager.batches.show', $batch)->with('success', 'تم التراجع عن الدفعة: حُذفت '.$c['invoices_deleted'].' فاتورة (منها '.$c['deferred_removed']
            .' مؤجلة)، وأُعيدت '.$c['invoices_restored'].' فاتورة لحالتها السابقة، وحُذف '.$c['customers_deleted'].' زبوناً و'.$c['sheets_deleted'].' كشفاً. يمكنك الآن رفع الملف الصحيح.');
    }

    /** رقم الدفعة الذي يُكتب للتأكيد: أول 8 محارف من معرّفها */
    public static function code(ImportBatch $batch): string
    {
        return substr((string) $batch->id, 0, 8);
    }
}
