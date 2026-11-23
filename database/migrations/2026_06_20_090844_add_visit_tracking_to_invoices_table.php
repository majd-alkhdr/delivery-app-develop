<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::table('invoices', function (Blueprint $table) {
        $table->timestamp('visited_at')->nullable()->after('is_paid_full');
        // لتجهيز الأرضية لتسجيل الموقع مستقبلاً كما ذكرت
        $table->decimal('latitude', 10, 7)->nullable()->after('visited_at');
        $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
    });
}

public function down(): void
{
    Schema::table('invoices', function (Blueprint $table) {
        $table->dropColumn(['visited_at', 'latitude', 'longitude']);
    });
}
};
