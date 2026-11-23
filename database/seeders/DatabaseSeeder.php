<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * بيانات تجريبية لبيئة التطوير فقط (كلمة المرور لكل الحسابات: 12345678).
     */
    public function run(): void
    {
        $accounts = [
            ['مدير النظام', 'admin@test.com', Role::Admin],
            ['مسؤول الكشوف', 'sheets@test.com', Role::SheetManager],
            ['مشرف التوزيع', 'supervisor@test.com', Role::Supervisor],
            ['المحاسب', 'accountant@test.com', Role::Accountant],
            ['مشرف المندوبين', 'rep-supervisor@test.com', Role::RepSupervisor],
            ['الموزع الأول', 'distributor@test.com', Role::Distributor],
            ['الموزع الثاني', 'distributor2@test.com', Role::Distributor],
            ['الموزع الثالث', 'distributor3@test.com', Role::Distributor],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => bcrypt('12345678'),
                'role' => $role,
            ]);
        }
    }
}
