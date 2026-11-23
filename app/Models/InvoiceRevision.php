<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نسخة فاتورة قبل الكتابة فوقها بتغيير وارد من ملف (المرحلة 7A): الترويسة والأسطر كما كانت.
 */
class InvoiceRevision extends Model
{
    protected $fillable = ['invoice_id', 'revision_no', 'header', 'lines', 'batch_id', 'import_batch_item_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'header' => 'array',
            'lines' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * حفظ الحالة الحالية للفاتورة (قبل التعديل) كنسخة جديدة مرقّمة.
     */
    public static function snapshot(Invoice $invoice, ?string $batchId, ?int $itemId, ?int $userId): self
    {
        return self::create(self::attributesFor($invoice, $batchId, $itemId, $userId, (int) self::where('invoice_id', $invoice->id)->max('revision_no') + 1));
    }

    /**
     * المرحلة 7E2: صفات النسخة برقم معروف مسبقاً (للتطبيق الجماعي).
     */
    public static function attributesFor(Invoice $invoice, ?string $batchId, ?int $itemId, ?int $userId, int $revisionNo): array
    {
        $invoice->loadMissing('lines');

        return [
            'invoice_id' => $invoice->id,
            'revision_no' => $revisionNo,
            'header' => [
                'invoice_number' => $invoice->invoice_number,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer_name,
                'invoice_amount' => (float) $invoice->invoice_amount,
                'invoice_date' => $invoice->invoice_date?->toDateString(),
                'delivery_date' => $invoice->delivery_date?->toDateString(),
                'delivery_order_no' => $invoice->delivery_order_no,
                'cost_center_code' => $invoice->cost_center_code,
                'cost_center_name' => $invoice->cost_center_name,
                'entered_by_name' => $invoice->entered_by_name,
                'invoice_note' => $invoice->invoice_note,
                'placement' => $invoice->placement,
                'distribution_sheet_id' => $invoice->distribution_sheet_id,
                'content_hash' => $invoice->content_hash,
                'net_uncertain' => (bool) $invoice->net_uncertain,
            ],
            'lines' => $invoice->lines->map(fn ($l) => [
                'line_no' => $l->line_no,
                'item_name' => $l->item_name,
                'item_group' => $l->item_group,
                'quantity' => (float) $l->quantity,
                'gift_quantity' => (float) $l->gift_quantity,
                'unit_price' => (float) $l->unit_price,
                'line_total' => (float) $l->line_total,
                'discount_percent' => $l->discount_percent !== null ? (float) $l->discount_percent : null,
                'discount_amount' => $l->discount_amount !== null ? (float) $l->discount_amount : null,
                'line_net' => $l->line_net !== null ? (float) $l->line_net : null,
            ])->values()->all(),
            'batch_id' => $batchId,
            'import_batch_item_id' => $itemId,
            'created_by' => $userId,
        ];
    }
}
