<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\DistributionSheet;
use App\Models\User;
use App\Services\SheetExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * تصدير كشف v2 إلى Excel للطباعة (A4). كشوف v1 تبقى بزر «تحميل» القديم.
 */
class SheetExportController extends Controller
{
    public function export(Request $request, DistributionSheet $sheet, SheetExportService $exporter)
    {
        // المشرف والمحاسب: الكشف قيد التجهيز يظهر كغير موجود (404)؛ الموزع ممنوع من المسار أصلاً
        Gate::authorize('view', $sheet);
        if (! $sheet->isV2()) {
            abort(404);
        }

        $data = $request->validate([
            'distributor_id' => 'nullable|integer',
            'area_key' => 'nullable|string|max:255',
            'with_lines' => 'nullable|boolean',
        ]);

        $distributor = null;
        $areaKey = $data['area_key'] ?? null;
        if (! empty($data['distributor_id']) && $areaKey !== null) {
            throw ValidationException::withMessages(['distributor_id' => 'اختر موزعاً أو منطقة، وليس الاثنين معاً.']);
        }
        if (! empty($data['distributor_id'])) {
            // الموزع يجب أن يكون مسنداً في هذا الكشف
            if (! $sheet->assignments()->where('distributor_id', $data['distributor_id'])->exists()) {
                throw ValidationException::withMessages(['distributor_id' => 'هذا الموزع غير مسند في هذا الكشف.']);
            }
            $distributor = User::findOrFail($data['distributor_id']);
        }
        if ($areaKey !== null && ! in_array($areaKey, $sheet->areaKeys(), true)) {
            throw ValidationException::withMessages(['area_key' => 'هذه المنطقة غير موجودة في هذا الكشف.']);
        }
        $withLines = (bool) ($data['with_lines'] ?? false);

        // المرحلة 8A: الحصر بنطاق مشرف المندوبين (null لغيره أو حين الحصر مُطفأ)
        $result = $exporter->build($sheet, $request->user(), $distributor, $areaKey, $withLines, \App\Services\Reps\RepScope::current($request->user()));
        $path = $exporter->save($result['spreadsheet']);

        ActivityLog::record('sheet.exported', $sheet, [], [
            'scope' => $result['scope_label'],
            'with_lines' => $withLines,
            'file_name' => $result['filename'],
        ], [
            'distributor_id' => $distributor?->id,
            'area_key' => $areaKey,
            'customers' => $result['customers'],
            'invoices' => $result['invoices'],
        ]);

        // الاسم بالعربية: Content-Disposition بـ filename*=UTF-8'' (RFC 5987) مع بديل ASCII آمن، عبر Symfony
        $response = response()->download($path, null, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $result['filename'], $result['ascii_filename']);

        return $response;
    }
}
