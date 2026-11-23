<?php

namespace App\Models;

use App\Support\ArabicNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'name_normalized',
        'block',
        'area',
        'area_normalized',
        'balance',
        'balance_as_of',
        'last_balance_import_id',
        'is_hidden',
        'needs_review',
        'latitude',
        'longitude',
        'location_confirmed',
        'location_pinned_by',
        'location_pinned_at',
        'location_accuracy_m',
        'location_source',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'balance_as_of' => 'date',
            'is_hidden' => 'boolean',
            'needs_review' => 'boolean',
            'location_confirmed' => 'boolean',
            'location_pinned_at' => 'datetime',
            'location_accuracy_m' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // الاسم والمنطقة المطبَّعان يُحسبان دائماً من النص الأصلي
        static::saving(function (Customer $customer) {
            if ($customer->isDirty('name') || $customer->name_normalized === null) {
                $customer->name_normalized = ArabicNormalizer::normalize($customer->name);
            }
            if ($customer->isDirty('area')) {
                $customer->area_normalized = $customer->area !== null && trim($customer->area) !== ''
                    ? ArabicNormalizer::normalize($customer->area)
                    : null;
            }
        });
    }

    /**
     * مفتاح المنطقة المستخدم في إسناد الكشوفات ('__none__' للزبائن بلا منطقة).
     */
    /**
     * حالة الموقع المرجعي: none (لا مرجع) / unconfirmed (غير مؤكد) / confirmed (مؤكد).
     */
    public function referenceState(): string
    {
        if ($this->latitude === null || $this->longitude === null) {
            return 'none';
        }

        return $this->location_confirmed ? 'confirmed' : 'unconfirmed';
    }

    public function areaKey(): string
    {
        return $this->area_normalized ?: SheetAssignment::NO_AREA;
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(CustomerAlias::class);
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, "location_pinned_by");
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_hidden', false);
    }

    /**
     * البحث عن زبون بالاسم المطبَّع ثم بالأسماء البديلة.
     */
    public static function findByName(string $name): ?self
    {
        $normalized = ArabicNormalizer::normalize($name);

        return static::where('name_normalized', $normalized)->first()
            ?? CustomerAlias::where('alias_normalized', $normalized)->first()?->customer;
    }
}
