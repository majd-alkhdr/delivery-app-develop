<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            // مفتاح المنطقة المطبَّع وقت الرفع ('__none__' = بلا منطقة) لتحديد ما يراه كل موزع
            $areaKey = $table->string('area_key', 255)->nullable()->index();
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $areaKey->collation('utf8mb4_bin');
            }
            $table->string('series', 100)->nullable();
            $table->date('invoice_date')->nullable();
            $table->date('delivery_date')->nullable()->index();
            $table->string('cost_center_code', 50)->nullable();
            $table->string('cost_center_name', 255)->nullable();
            $table->string('entered_by_name', 255)->nullable();
            $table->string('invoice_note', 255)->nullable();
            $table->boolean('is_zero_value')->default(false);
            $table->boolean('is_deferred')->default(false)->index();
            $table->index('invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['invoice_number']);
            $table->dropIndex(['area_key']);
            $table->dropIndex(['delivery_date']);
            $table->dropIndex(['is_deferred']);
            $table->dropColumn([
                'customer_id', 'area_key', 'series', 'invoice_date', 'delivery_date', 'cost_center_code',
                'cost_center_name', 'entered_by_name', 'invoice_note', 'is_zero_value', 'is_deferred',
            ]);
        });
    }
};
