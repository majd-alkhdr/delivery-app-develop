<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** الإعدادات الجديدة (لا تكتب فوق قيمة موجودة) */
    private const SETTINGS = [
        'usd_rate_default' => 139,
        'usd_rate_min' => 50,
        'usd_rate_max' => 500,
        'location_radius_m' => 150,
        'receipt_prefix_length' => 3,
        // الحد الأقصى المعقول لمبلغ دفعة واحدة (قابل للضبط)
        'payment_amount_max' => 1000000000,
        'printing_enabled' => false,
    ];

    public function up(): void
    {
        // موقع الصيدلية المرجعي: latitude/longitude موجودان على customers من المرحلة 2، ويُضاف التأكيد
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('location_confirmed')->default(false);
        });

        $now = now();
        foreach (self::SETTINGS as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value), 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('location_confirmed');
        });
        DB::table('settings')->whereIn('key', array_keys(self::SETTINGS))->delete();
    }
};
