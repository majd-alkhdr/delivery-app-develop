<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\Import\DuplicateUploadNotice;
use App\Services\Import\SheetImportService;
use Illuminate\Http\Request;

/**
 * المرحلة 7G: تأكيد رفع ملف ينقصه عمود حرج. الملفات محفوظة منذ الرفع ولم يُطبَّق منها شيء؛
 * التطبيق يتطلب خانة «أفهم أن العمود غير موجود وأريد المتابعة» ويُسجَّل نصها واسم المستخدم في سجل النشاط.
 */
class UploadConfirmationController extends Controller
{
    public const SESSION_KEY = 'pending_uploads';

    public function show(Request $request, string $token)
    {
        $pending = $this->pending($request, $token);
        if (! $pending) {
            return redirect()->route('sheet-manager.index')->with('warning', 'انتهت صلاحية هذا الرفع أو طُبّق سابقاً. أعد رفع الملف.');
        }

        return view('sheet-manager.upload-confirm', [
            'token' => $token,
            'pending' => $pending,
            'duplicate' => DuplicateUploadNotice::for($pending['file_hash'] ?? null, $pending['distribution_date']),
        ]);
    }

    public function store(Request $request, string $token, SheetImportService $importer)
    {
        $pending = $this->pending($request, $token);
        if (! $pending) {
            return redirect()->route('sheet-manager.index')->with('warning', 'انتهت صلاحية هذا الرفع أو طُبّق سابقاً. أعد رفع الملف.');
        }
        $request->validate(['confirm_missing_columns' => 'accepted'], [
            'confirm_missing_columns.accepted' => 'لم يُطبَّق شيء: ضع علامة على «'.$pending['text'].'» للمتابعة.',
        ]);

        set_time_limit(300);

        try {
            $result = $importer->importStored($pending['files'], $pending['distribution_date'], $request->user(),
                (bool) $pending['check_missing'], $pending['balances_as_of'], true);
        } catch (\Throwable $e) {
            report($e);
            ActivityLog::record('import.failed', null, [], ['status' => 'failed'], [
                'stage' => $importer->stage(),
                'reason' => mb_substr($e->getMessage(), 0, 1000),
                'file_names' => array_column($pending['files'], 'original_name'),
                'distribution_date' => $pending['distribution_date'],
            ]);

            return redirect()->route('sheet-manager.index')
                ->with('error', 'تعذّر إكمال الرفع ولم يُطبَّق أي شيء، يمكنك إعادة المحاولة. (المرحلة: '.SheetImportService::stageLabel($importer->stage()).')');
        }
        $request->session()->forget(self::SESSION_KEY.'.'.$token);

        ActivityLog::record('import.columns_confirmed', $result['batch'], [], ['confirmed' => true], [
            'text' => $pending['text'],
            'user_name' => $request->user()->name,
            'columns' => array_column($pending['columns'], 'column'),
            'file_names' => array_column($pending['files'], 'original_name'),
            'distribution_date' => $pending['distribution_date'],
            'estimate' => $pending['estimate'],
        ]);

        $failed = collect($result['reports'])->where('success', false)->count();

        return redirect()->route('sheet-manager.batches.show', $result['batch_id'])->with(
            $failed ? 'warning' : 'success',
            $failed ? "تعذّر استيراد {$failed} ملف. التفاصيل أدناه." : 'تم الرفع رغم الأعمدة الناقصة (سُجّل تأكيدك). راجع الدفعة قبل إرسالها.'
        );
    }

    /** الرفع المعلّق في جلسة المستخدم نفسه فقط */
    private function pending(Request $request, string $token): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY.'.'.$token);

        return is_array($pending) && ($pending['user_id'] ?? null) === $request->user()->id ? $pending : null;
    }
}
