<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'line_no',
        'item_name',
        'item_group',
        'quantity',
        'gift_quantity',
        'unit_price',
        'line_total',       // إجمالي السطر قبل الحسم (كما في الملف)
        'discount_percent', // المرحلة 6B: نسبة الحسم (نقاط مئوية)، null = ملف بلا عمود حسم
        'discount_amount',
        'line_net',         // الصافي = line_total − discount_amount
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'gift_quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'discount_percent' => 'decimal:3',
            'discount_amount' => 'decimal:2',
            'line_net' => 'decimal:2',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
