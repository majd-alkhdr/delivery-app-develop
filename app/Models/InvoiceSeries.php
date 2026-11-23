<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * أسماء سلاسل الفواتير (G وA وB...). المفتاح يُخزَّن بحروف صغيرة.
 */
class InvoiceSeries extends Model
{
    protected $table = 'invoice_series';

    protected $fillable = ['key', 'label', 'allocation_group'];

    /** مجموعات التقسيم (لمحرك المرحلة 5) */
    public const ALLOCATION_GROUPS = [
        'milk' => 'حليب ومعقمات',
        'argivit' => 'ارجيفيت',
        'other' => 'أخرى',
    ];

    /** ألوان ثابتة للشارات؛ كل سلسلة تأخذ لوناً حسب مفتاحها */
    private const PALETTE = [
        'g' => '#1d4ed8',
        'a' => '#0f766e',
        'b' => '#7c3aed',
        'c' => '#b45309',
        'm' => '#be185d',
        'ارجيفيت' => '#15803d',
    ];

    private const FALLBACK_PALETTE = ['#0369a1', '#4d7c0f', '#9333ea', '#c2410c', '#0e7490', '#a21caf', '#334155'];

    private static ?array $labelCache = null;

    public static function keyFor(?string $series): ?string
    {
        if ($series === null || trim($series) === '') {
            return null;
        }

        return mb_strtolower(trim($series), 'UTF-8');
    }

    /**
     * إنشاء السلسلة تلقائياً إن لم تكن معروفة (label = key).
     */
    public static function ensure(?string $series): ?self
    {
        $key = static::keyFor($series);
        if ($key === null) {
            return null;
        }
        static::$labelCache = null;

        return static::firstOrCreate(['key' => $key], ['label' => $key]);
    }

    public static function labelFor(?string $series): ?string
    {
        $key = static::keyFor($series);
        if ($key === null) {
            return null;
        }
        static::$labelCache ??= static::pluck('label', 'key')->all();

        return static::$labelCache[$key] ?? $series;
    }

    public static function colorFor(?string $series): string
    {
        $key = static::keyFor($series) ?? '';
        if (isset(self::PALETTE[$key])) {
            return self::PALETTE[$key];
        }

        return self::FALLBACK_PALETTE[crc32($key) % count(self::FALLBACK_PALETTE)];
    }

    public static function flushCache(): void
    {
        static::$labelCache = null;
    }
}
