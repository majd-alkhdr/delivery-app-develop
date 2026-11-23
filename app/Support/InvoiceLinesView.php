<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceSeries;

/**
 * عرض أسطر الفاتورة (المرحلة 6B) من مصدر واحد: الجزئية المشتركة، و/invoices/{invoice}/lines،
 * وendpoint الموزع invoice-lines (والكاش دون اتصال)، وأسطر كشوف المندوب.
 *
 * الإفرادي قبل الحسم، والإجمالي (line_total) قبل الحسم كما في الملف، والحسم % بجانبه.
 * التذييل: المجموع قبل الحسم، حسم الأقلام، الصافي (يطابق invoice_amount).
 * عمود الحسم وتذييله يظهران فقط إن وُجد سطر واحد على الأقل بحسم > 0.
 */
class InvoiceLinesView
{
    /** رقم نسخة مخطط JSON الأسطر (يدخل ETag والكاش في IndexedDB) */
    public const SCHEMA = 2;

    /**
     * @return array{lines: array<int, array>, gross_total: float, discount_total: float, net_total: float, has_discount: bool, mismatch: bool}
     */
    public static function summarize(Invoice $invoice): array
    {
        $lines = $invoice->lines->map(function ($l) {
            $gross = round((float) $l->line_total, 2);
            $discount = round((float) ($l->discount_amount ?? 0), 2);

            return [
                'item_name' => $l->item_name,
                'item_group' => $l->item_group,
                'quantity' => (float) $l->quantity,
                'gift_quantity' => (float) $l->gift_quantity,
                'unit_price' => (float) $l->unit_price,
                'line_total' => $gross,
                'discount_percent' => $l->discount_percent !== null ? (float) $l->discount_percent : null,
                'discount_amount' => $discount,
                // الصافي = الإجمالي قبل الحسم − الحسم (تعريفاً؛ line_net المخزّن سجلّ الاستيراد)
                'line_net' => round($gross - $discount, 2),
            ];
        })->values()->all();

        $gross = round(array_sum(array_column($lines, 'line_total')), 2);
        $discount = round(array_sum(array_column($lines, 'discount_amount')), 2);
        $net = round(array_sum(array_column($lines, 'line_net')), 2);

        return [
            'lines' => $lines,
            'gross_total' => $gross,
            'discount_total' => $discount,
            'net_total' => $net,
            'has_discount' => collect($lines)->contains(fn ($l) => (float) ($l['discount_amount'] ?? 0) > 0 || (float) ($l['discount_percent'] ?? 0) > 0),
            'mismatch' => $lines !== [] && abs($net - (float) $invoice->invoice_amount) >= 0.01,
        ];
    }

    /**
     * فاتورة بأسطرها لـJSON الموزع (والكاش دون اتصال) وكشوف المندوب: الصيغة نفسها في الموضعين.
     */
    public static function payload(Invoice $invoice): array
    {
        $view = self::summarize($invoice);

        return [
            'id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'customer_name' => $invoice->customer_name,
            'number' => $invoice->invoice_number,
            'series' => $invoice->series,
            'series_label' => InvoiceSeries::labelFor($invoice->series),
            'series_color' => InvoiceSeries::colorFor($invoice->series),
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'delivery_date' => $invoice->delivery_date?->format('Y-m-d'),
            'cost_center_name' => $invoice->cost_center_name,
            // المرحلة 6B-G: مركز الكلفة «كود-اسم» والبيان كما في الحركة اليومية (لا «0» ولا فراغ)
            'cost_center' => self::costCenter($invoice),
            'invoice_note' => self::note($invoice->invoice_note),
            'entered_by' => $invoice->entered_by_name,
            'total' => round((float) $invoice->invoice_amount, 2),
            'is_zero_value' => (bool) $invoice->is_zero_value,
            'lines' => $view['lines'],
            // الصافي (Σ صافي الأسطر) يقارَن بإجمالي الفاتورة كما سبق
            'lines_total' => $view['net_total'],
            'gross_total' => $view['gross_total'],
            'discount_total' => $view['discount_total'],
            'has_discount' => $view['has_discount'],
            'mismatch' => $view['mismatch'],
        ];
    }

    /**
     * مركز الكلفة كما في ملف الحركة: «كود-اسم».
     */
    public static function costCenter(Invoice $invoice): ?string
    {
        return \App\Services\Finance\PaymentAllocationEngine::costCenterLabel($invoice->cost_center_code, $invoice->cost_center_name);
    }

    /**
     * بيان الفاتورة للعرض: «0» والفراغ والمسافات = لا بيان؛ «***» بيان عادي.
     */
    public static function note(?string $note): ?string
    {
        $note = trim(preg_replace('/\s+/u', ' ', (string) $note) ?? '');

        return ($note === '' || $note === '0') ? null : $note;
    }

    /**
     * «10%» أو «2.5%» (بلا أصفار زائدة).
     */
    public static function percent(?float $value): string
    {
        return $value === null ? '' : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.').'%';
    }
}
