<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Customer;
use App\Services\Reps\PharmacyStatementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * المرحلة 8D: «صيدلياتي» و«كشف الصيدلية الكامل» (عرض فقط).
 * - /rep/pharmacies و/rep/pharmacies/{customer}: المندوب (صيدليات باعها).
 * - /rep-supervisor/pharmacies/{customer}: مشرف المندوبين (نطاق مندوبيه) والأدمن (الكل).
 * الوصول والحصص من PharmacyStatementService::shareReps (null ⇒ 403).
 */
class PharmacyStatementController extends Controller
{
    public function __construct(private PharmacyStatementService $statements)
    {
    }

    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $rep = $request->user();
        $result = $this->statements->pharmaciesForRep($rep, $data['q'] ?? null, (int) ($data['page'] ?? 1));

        return view('rep.pharmacies', [
            'rows' => $result['rows'],
            'total' => $result['total'],
        ]);
    }

    public function show(Request $request, Customer $customer)
    {
        return $this->render($request, $customer);
    }

    public function showForStaff(Request $request, Customer $customer)
    {
        return $this->render($request, $customer);
    }

    private function render(Request $request, Customer $customer)
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:50',
            'who' => ['nullable', Rule::in(['all', 'mine'])],
            'period' => ['nullable', Rule::in(['default', 'all', 'range'])],
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'mode' => ['nullable', Rule::in(['internal', 'pharmacy'])],
            'page' => 'nullable|integer|min:1',
            'pp' => 'nullable|integer|min:1',
        ]);
        $viewer = $request->user();
        $shareReps = $this->statements->shareReps($viewer, $customer);
        if ($shareReps === null) {
            abort(403, $viewer->role === Role::Rep ? 'لا يمكنك فتح كشف صيدلية لم تبعها.' : 'هذه الصيدلية خارج نطاقك.');
        }

        $statement = $this->statements->build($viewer, $customer, $shareReps, $filters);

        return view('pharmacy-statement.show', [
            's' => $statement,
            'viewer' => $viewer,
            'isRep' => $viewer->role === Role::Rep,
            'pharmacyMode' => ($filters['mode'] ?? 'internal') === 'pharmacy',
            'filters' => [
                'q' => $filters['q'] ?? '',
                'who' => $filters['who'] ?? 'all',
                'period' => $filters['period'] ?? 'default',
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
            ],
            'baseRoute' => $viewer->role === Role::Rep ? 'rep.pharmacies.show' : 'rep-supervisor.pharmacies.show',
        ]);
    }
}
