<?php

namespace App\Enums;

/**
 * أدوار المستخدمين في النظام (القيمة مخزنة نصياً في users.role).
 */
enum Role: string
{
    case Admin = 'admin';
    case SheetManager = 'sheet_manager';
    case Supervisor = 'supervisor';
    case Distributor = 'distributor';
    case Accountant = 'accountant';
    case Rep = 'rep';
    // المرحلة 6B: عرض فقط للكشوف والقسم المالي
    case RepSupervisor = 'rep_supervisor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'مدير النظام',
            self::SheetManager => 'مسؤول الكشوف',
            self::Supervisor => 'مشرف التوزيع',
            self::Distributor => 'موزع',
            self::Accountant => 'محاسب',
            self::Rep => 'مندوب',
            self::RepSupervisor => 'مشرف المندوبين',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Admin => 'fa-user-tie',
            self::SheetManager => 'fa-layer-group',
            self::Supervisor => 'fa-user-shield',
            self::Distributor => 'fa-truck',
            self::Accountant => 'fa-calculator',
            self::Rep => 'fa-id-badge',
            self::RepSupervisor => 'fa-binoculars',
        };
    }

    /**
     * كلاس لون الشارة في الواجهات.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Admin => 'text-bg-dark',
            self::SheetManager => 'bg-warning-subtle text-warning-emphasis',
            self::Supervisor => 'bg-danger-subtle text-danger-emphasis',
            self::Distributor => 'bg-success-subtle text-success-emphasis',
            self::Accountant => 'bg-info-subtle text-info-emphasis',
            self::Rep => 'bg-secondary-subtle text-secondary-emphasis',
            self::RepSupervisor => 'bg-primary-subtle text-primary-emphasis',
        };
    }

    /**
     * اسم المسار الذي يُوجَّه إليه المستخدم بعد الدخول أو عند فتح / و /dashboard.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Admin, self::Supervisor, self::RepSupervisor => 'supervisor.index',
            self::SheetManager => 'sheet-manager.index',
            self::Distributor => 'distributor.index',
            self::Accountant => 'accountant.index',
            self::Rep => 'rep.index',
        };
    }

    /**
     * @return array<string, string> قائمة value => label للقوائم المنسدلة
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
