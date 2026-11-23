<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // الحالة الجديدة preparing لا تحتاج تعديلاً على العمود (status نصي string)
        Schema::table('distribution_sheets', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->index(['distribution_date', 'delivery_order_no']);
        });
    }

    public function down(): void
    {
        Schema::table('distribution_sheets', function (Blueprint $table) {
            $table->dropIndex(['distribution_date', 'delivery_order_no']);
            $table->dropColumn(['sent_at', 'sent_by']);
        });
    }
};
