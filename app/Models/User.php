<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'cost_center_code',
        'cost_center_label',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /**
     * هل يملك المستخدم أحد الأدوار المعطاة؟
     */
    public function hasRole(Role|string ...$roles): bool
    {
        foreach ($roles as $role) {
            $role = $role instanceof Role ? $role : Role::tryFrom($role);
            if ($role !== null && $this->role === $role) {
                return true;
            }
        }

        return false;
    }

    /**
     * إدخال دفعات بالدولار: للموزع (ولاحقاً المندوب) فقط.
     */
    public function canEnterUsd(): bool
    {
        return $this->hasRole(Role::Distributor, Role::Rep);
    }

    public function isRep(): bool
    {
        return $this->role === Role::Rep;
    }

    /**
     * مندوب بمركز كلفة مضبوط (المرحلة 6): شرط تسجيل التحصيل.
     */
    public function hasCostCenter(): bool
    {
        return trim((string) $this->cost_center_code) !== '' && trim((string) $this->cost_center_label) !== '';
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }
}
