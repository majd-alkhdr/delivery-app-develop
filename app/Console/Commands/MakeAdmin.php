<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'user:make-admin {email : البريد الإلكتروني لحساب موجود}';

    protected $description = 'ترقية حساب موجود إلى دور المدير (admin)';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("لا يوجد حساب بالبريد: {$email}");

            return self::FAILURE;
        }

        if ($user->role === Role::Admin) {
            $this->info("الحساب {$email} مدير بالفعل. لم يتغير شيء.");

            return self::SUCCESS;
        }

        $oldRole = $user->role?->value;
        $user->role = Role::Admin;
        $user->save();

        ActivityLog::record('user.updated', $user, ['role' => $oldRole], ['role' => Role::Admin->value], [
            'source' => 'artisan user:make-admin',
        ]);

        $this->info("تمت ترقية {$user->name} ({$email}) من {$oldRole} إلى admin.");

        return self::SUCCESS;
    }
}
