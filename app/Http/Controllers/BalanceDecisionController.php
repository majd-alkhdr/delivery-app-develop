<?php

namespace App\Http\Controllers;

use App\Models\BalanceImport;
use App\Services\Balances\BalanceImportService;
use Illuminate\Http\Request;

/**
 * قرارات ملف الأرصدة المحجوز (المرحلة 7B): تجاهل، تطبيق رغم ذلك (الأدمن بسبب)، تأكيد التصفير، إدخال التاريخ يدوياً.
 * المسارات داخل مجموعة مسؤول الكشوف والمدير.
 */
class BalanceDecisionController extends Controller
{
    public function __construct(private BalanceImportService $balances)
    {
    }

    public function apply(Request $request, BalanceImport $import)
    {
        $data = $request->validate([
            'reason' => 'nullable|string|max:500',
            'confirm_zeroing' => 'nullable|boolean',
            'as_of' => 'nullable|date',
        ]);
        if ($import->hold_reason === BalanceImport::HOLD_OLDER && ! $request->user()->isAdmin()) {
            abort(403, 'تطبيق أرصدة أقدم من المطبَّقة للمدير فقط.');
        }
        if ($import->hold_reason === BalanceImport::HOLD_OLDER && mb_strlen(trim((string) ($data['reason'] ?? ''))) < 3) {
            return back()->withErrors(['reason' => 'سبب تطبيق الأرصدة الأقدم إلزامي (3 أحرف على الأقل).']);
        }
        if ($import->hold_reason === BalanceImport::HOLD_NO_DATE && empty($data['as_of'])) {
            return back()->withErrors(['as_of' => 'أدخل تاريخ الأرصدة قبل التطبيق.']);
        }

        $result = $this->balances->applyHeld($import, $request->user(), $data['reason'] ?? null, $request->boolean('confirm_zeroing'),
            ! empty($data['as_of']) ? \Carbon\Carbon::parse($data['as_of'])->toDateString() : null);

        return $result['ok'] ? back()->with('success', $result['message']) : back()->withErrors(['balances' => $result['message']]);
    }

    public function ignore(Request $request, BalanceImport $import)
    {
        $request->validate(['note' => 'nullable|string|max:500']);
        $this->balances->ignore($import, $request->user(), $request->input('note'));

        return back()->with('success', 'تُجوهلت أرصدة هذا الملف: لم تتغيّر أرصدة الزبائن، والملف محفوظ.');
    }
}
