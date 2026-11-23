<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DistributionSheetPolicy
{
    /**
     * عرض الكشف:
     * - المدير ومسؤول الكشوف: دائماً.
     * - المشرف والمحاسب: كل الحالات عدا "قيد التجهيز".
     * - مشرف المندوبين: الكشوف المنشورة فقط (pending/in_progress/completed)، للقراءة.
     * - الموزع: كشف له فيه إسناد، وحالته pending أو in_progress أو completed.
     */
    public function view(User $user, DistributionSheet $sheet): Response
    {
        if ($user->hasRole(Role::Admin, Role::SheetManager)) {
            return Response::allow();
        }

        if ($user->hasRole(Role::Supervisor, Role::Accountant)) {
            return $sheet->isPreparing()
                ? Response::denyAsNotFound('الكشف غير موجود.')
                : Response::allow();
        }

        // مشرف المندوبين (المرحلة 6B): الكشوف المنشورة فقط (لا قيد التجهيز ولا المسودة)، للقراءة
        if ($user->role === Role::RepSupervisor) {
            if (! in_array($sheet->status, ['pending', 'in_progress', 'completed'], true)) {
                return Response::denyAsNotFound('الكشف غير موجود.');
            }
            // المرحلة 8A: عند تفعيل الحصر، الكشوف التي فيها فواتير لمندوبيه في صيدليات نطاقه فقط
            $scope = \App\Services\Reps\RepScope::current($user);

            return $scope === null || $scope->allowsSheet($sheet)
                ? Response::allow()
                : Response::deny('هذا الكشف خارج نطاقك.');
        }

        return $this->isAssignedDistributor($user, $sheet, DistributionSheet::DISTRIBUTOR_STATUSES)
            ? Response::allow()
            : Response::deny('هذا الكشف غير مسند إليك.');
    }

    /**
     * تسجيل زيارات ودفعات على الكشف: للموزع المُسند، والكشف v2 بحالة pending أو in_progress.
     * (كشوف v1 للقراءة فقط منذ المرحلة 3.)
     */
    public function update(User $user, DistributionSheet $sheet): Response
    {
        return $sheet->isV2() && $this->isAssignedDistributor($user, $sheet, ['pending', 'in_progress'])
            ? Response::allow()
            : Response::deny('لا يمكنك التسجيل على هذا الكشف.');
    }

    /**
     * إسناد المناطق وإعادة الإسناد: المدير والمشرف، والكشف ليس قيد التجهيز.
     * (المسودة ترفضها SheetAssignmentService برسالة واضحة.)
     */
    public function assign(User $user, DistributionSheet $sheet): bool
    {
        return $user->hasRole(Role::Admin, Role::Supervisor) && ! $sheet->isPreparing();
    }

    /**
     * إغلاق الكشف (مكتمل أو مسودة بسبب): المدير والمشرف، والكشف ليس قيد التجهيز.
     */
    public function close(User $user, DistributionSheet $sheet): bool
    {
        return $user->hasRole(Role::Admin, Role::Supervisor) && ! $sheet->isPreparing();
    }

    /**
     * نقل/إضافة/استبعاد الفواتير: المدير ومسؤول الكشوف، والكشف قيد التجهيز أو منشور (ليس مكتملاً ولا مسودة).
     */
    public function manageInvoices(User $user, DistributionSheet $sheet): bool
    {
        return $user->hasRole(Role::Admin, Role::SheetManager)
            && $sheet->isV2()
            && in_array($sheet->status, DistributionSheet::EDITABLE_STATUSES, true);
    }

    /**
     * إعادة تفعيل مسودة: المدير فقط، وبشرط أن يحتوي الكشف فواتير.
     */
    public function reopen(User $user, DistributionSheet $sheet): bool
    {
        return $user->isAdmin()
            && $sheet->isDraft()
            && $sheet->invoices()
                ->where(fn ($q) => $q->whereNull('invoice_pattern')->orWhere('invoice_pattern', '!=', 'external'))
                ->exists();
    }

    private function isAssignedDistributor(User $user, DistributionSheet $sheet, array $statuses): bool
    {
        return $user->role === Role::Distributor
            && in_array($sheet->status, $statuses, true)
            && $sheet->assignments()->where('distributor_id', $user->id)->exists();
    }
}
