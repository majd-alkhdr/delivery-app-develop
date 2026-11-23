<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * الأسماء الافتراضية لسلاسل الفواتير (أسماء فقط، بلا أي معلومة عن العملة).
 * آمن للتكرار: لا يكتب فوق اسم عدّله المدير.
 */
class InvoiceSeriesSeeder extends Seeder
{
    public const DEFAULTS = [
        'g' => 'General',
        'a' => 'Hammers Team',
        'b' => 'Heroes Team',
        'c' => "Sales Fighter's",
        'm' => 'عرض حليب ومعقمات',
        'ارجيفيت' => 'مبيعات دولار',
    ];

    public function run(): void
    {
        $now = now();
        foreach (self::DEFAULTS as $key => $label) {
            if (! DB::table('invoice_series')->where('key', $key)->exists()) {
                DB::table('invoice_series')->insert([
                    'key' => $key,
                    'label' => $label,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
