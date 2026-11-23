<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 7G: بصمة ملف الدفعة (SHA-256) لتنبيه «هذا الملف نفسه رُفع سابقاً». الدفعات القديمة تبقى بلا بصمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        // حماية: اختبار ترحيل المرحلة 2B يشغّل الترحيلات اللاحقة قبل جدول الدفعات
        if (Schema::hasTable('import_batches') && ! Schema::hasColumn('import_batches', 'file_hash')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->string('file_hash', 64)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('import_batches') && Schema::hasColumn('import_batches', 'file_hash')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->dropIndex(['file_hash']);
                $table->dropColumn('file_hash');
            });
        }
    }
};
