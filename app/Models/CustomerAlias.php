<?php

namespace App\Models;

use App\Support\ArabicNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAlias extends Model
{
    protected $fillable = ['customer_id', 'alias', 'alias_normalized', 'created_by'];

    protected static function booted(): void
    {
        static::saving(function (CustomerAlias $alias) {
            $alias->alias_normalized = ArabicNormalizer::normalize($alias->alias);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
