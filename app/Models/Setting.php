<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إعدادات عامة (مفتاح => قيمة JSON). بدون واجهة في هذه المرحلة.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
