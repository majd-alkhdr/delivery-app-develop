<?php

namespace App\Services\Balances;

use Illuminate\Support\Facades\DB;

/**
 * المرحلة 7E: هل لتغيّر رصيد الزبون ارتباط بحركة مسجّلة في التطبيق ضمن نافذة اللقطة [prev_as_of, as_of]؟
 * الارتباط = فاتورة في كشف (أي حالة) بتاريخ فاتورة أو توزيع ضمن النافذة، أو دفعة غير ملغاة ضمنها،
 * أو تعديل لاحق مطبَّق ضمنها، أو تغيير مقترح ينتظر (اقتراح مفتوح أو بانتظار التسوية)، أو عنصر مراجعة معلّق/محجوز.
 * التغيّر غير المرتبط «خارجي»: مبيعات أو تحصيل خارج التطبيق، لا يحتاج تحققاً.
 * يعمل بجداول القاعدة مباشرة (يستعمله المفسِّر والترحيل الذي يملأ اللقطات القديمة)، وبعدد ثابت من الاستعلامات لكل 500 زبون.
 */
class SnapshotScope
{
    public const APP = 'app';
    public const EXTERNAL = 'external';

    public const EXTERNAL_NOTE = 'تغيّر لا يرتبط بحركة مسجّلة في التطبيق (مبيعات أو تحصيل خارج التطبيق)';

    /**
     * @param  array<int|string, array{customer_id: int, from: ?string, to: ?string}>  $windows  مفتاح => الزبون ومدى النافذة (Y-m-d، null = مفتوح)
     * @return array<int|string, bool> مفتاح => مرتبط بالتطبيق
     */
    public static function linked(array $windows): array
    {
        $result = array_map(fn () => false, $windows);
        $byCustomer = [];
        foreach ($windows as $key => $window) {
            $byCustomer[(int) $window['customer_id']][] = $key;
        }

        foreach (array_chunk(array_keys($byCustomer), 500) as $chunk) {
            $hit = function (int $customerId, ?string $date) use (&$result, $byCustomer, $windows) {
                if ($date === null) {
                    return;
                }
                $date = substr($date, 0, 10);
                foreach ($byCustomer[$customerId] ?? [] as $key) {
                    $w = $windows[$key];
                    if (($w['from'] === null || $date >= substr($w['from'], 0, 10)) && ($w['to'] === null || $date <= substr($w['to'], 0, 10))) {
                        $result[$key] = true;
                    }
                }
            };
            $always = function (int $customerId) use (&$result, $byCustomer) {
                foreach ($byCustomer[$customerId] ?? [] as $key) {
                    $result[$key] = true;
                }
            };

            // فاتورة في كشف (أي حالة): تاريخ الفاتورة أو تاريخ توزيع الكشف ضمن النافذة
            DB::table('invoices')->join('distribution_sheets', 'distribution_sheets.id', '=', 'invoices.distribution_sheet_id')
                ->whereIn('invoices.customer_id', $chunk)->where('invoices.placement', 'in_sheet')
                ->select('invoices.customer_id', 'invoices.invoice_date', 'distribution_sheets.distribution_date')
                ->get()->each(function ($row) use ($hit) {
                    $hit((int) $row->customer_id, $row->invoice_date);
                    $hit((int) $row->customer_id, $row->distribution_date);
                });

            // دفعة غير ملغاة (بتاريخ التحصيل، أو الإنشاء إن لم يُسجَّل)
            DB::table('payments')->whereIn('customer_id', $chunk)->whereNull('voided_at')
                ->select('customer_id', 'collected_at', 'created_at')->get()
                ->each(fn ($row) => $hit((int) $row->customer_id, $row->collected_at ?? $row->created_at));

            // تعديل لاحق مطبَّق ضمن النافذة
            DB::table('invoice_adjustments')->whereIn('customer_id', $chunk)->where('status', 'applied')
                ->select('customer_id', 'applied_at')->get()
                ->each(fn ($row) => $hit((int) $row->customer_id, $row->applied_at));

            // تغيير مقترح ينتظر (قبل الإغلاق أو بانتظار التسوية بعده)
            DB::table('invoice_change_proposals')->join('invoices', 'invoices.id', '=', 'invoice_change_proposals.invoice_id')
                ->whereIn('invoices.customer_id', $chunk)->whereIn('invoice_change_proposals.status', ['pending', 'awaiting_adjustment'])
                ->distinct()->pluck('invoices.customer_id')->each(fn ($id) => $always((int) $id));

            // عنصر مراجعة معلّق أو محجوز
            DB::table('import_batch_items')->whereIn('customer_id', $chunk)->whereIn('status', ['pending', 'blocked'])
                ->distinct()->pluck('customer_id')->each(fn ($id) => $always((int) $id));
        }

        return $result;
    }
}
