<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_sheets', function (Blueprint $table) {
            // v1 = الصيغة القديمة، v2 = ملف الحركة اليومية والأرصدة
            $table->string('format', 10)->default('v1')->after('id');
            $table->string('delivery_order_no', 100)->nullable();
            $table->date('report_from')->nullable();
            $table->date('report_to')->nullable();
            $table->date('distribution_date')->nullable();
            $table->decimal('total_all', 15, 2)->nullable();
            $table->decimal('total_deferred', 15, 2)->nullable();
            $table->decimal('total_effective', 15, 2)->nullable();
            $table->string('original_name', 255)->nullable();
            $table->string('stored_path', 255)->nullable();
            $table->uuid('batch_id')->nullable()->index();
            $table->text('status_reason')->nullable();
            $table->unsignedBigInteger('status_changed_by')->nullable();
            $table->timestamp('status_changed_at')->nullable();
        });

        // كشوف v2 قد تُرفع بدون موزع (الإسناد عبر sheet_assignments)
        Schema::table('distribution_sheets', function (Blueprint $table) {
            $table->unsignedBigInteger('distributor_id')->nullable()->change();
        });

        // الكشوفات الحالية: الاسم الأصلي = اسم الملف المخزن
        DB::table('distribution_sheets')->whereNull('original_name')->update(['original_name' => DB::raw('file_name')]);
    }

    public function down(): void
    {
        Schema::table('distribution_sheets', function (Blueprint $table) {
            $table->dropIndex(['batch_id']);
            $table->dropColumn([
                'format', 'delivery_order_no', 'report_from', 'report_to', 'distribution_date',
                'total_all', 'total_deferred', 'total_effective', 'original_name', 'stored_path',
                'batch_id', 'status_reason', 'status_changed_by', 'status_changed_at',
            ]);
        });
    }
};
