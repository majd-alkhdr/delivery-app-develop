<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 7F: التراجع عن دفعة رفع والاستبعاد الجماعي.
 * - import_batches: حالة الدفعة (applied | rolled_back) ومن تراجع ومتى ولماذا.
 * - import_batch_rollbacks: سجل التراجع ونسخة تدقيق مضغوطة لما حُذف وما أُعيد.
 * - balance_import_states: أرصدة كل الزبائن قبل تطبيق ملف أرصدة (مضغوطة)، كي يعود التراجع إليها بدقة.
 * - invoice_bulk_operations: عملية استبعاد/إلغاء جماعية بمعرّف، لإعادة الإدراج الجماعي.
 */
return new class extends Migration
{
    public function up(): void
    {
        // حماية: اختبار ترحيل المرحلة 2B يشغّل الترحيلات اللاحقة قبل جدول الدفعات
        if (Schema::hasTable('import_batches') && ! Schema::hasColumn('import_batches', 'status')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->string('status', 20)->default('applied');
                $table->timestamp('rolled_back_at')->nullable();
                $table->unsignedBigInteger('rolled_back_by')->nullable();
                $table->text('rollback_reason')->nullable();
            });
        }

        if (! Schema::hasTable('import_batch_rollbacks')) {
            Schema::create('import_batch_rollbacks', function (Blueprint $table) {
                $table->id();
                $table->uuid('batch_id')->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->text('reason');
                $table->json('counts')->nullable();
                // JSON مضغوط (gzip + base64): المعرّفات والمفاتيح والقيم الأساسية لما حُذف وما أُعيد
                $table->longText('audit')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('balance_import_states')) {
            Schema::create('balance_import_states', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('balance_import_id')->unique();
                $table->unsignedInteger('customers_count')->default(0);
                // JSON مضغوط: customer_id => [balance, balance_as_of, last_balance_import_id, block, area, area_normalized, needs_review]
                $table->longText('payload');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoice_bulk_operations')) {
            Schema::create('invoice_bulk_operations', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                // exclude | cancel | restore | uncancel
                $table->string('action', 20);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->uuid('batch_id')->nullable()->index();
                $table->json('filters')->nullable();
                $table->longText('invoice_ids');
                $table->json('skipped')->nullable();
                $table->unsignedInteger('count')->default(0);
                // عملية «إعادة إدراج» تشير إلى عملية الاستبعاد التي عكستها
                $table->unsignedBigInteger('undoes_id')->nullable()->index();
                $table->timestamp('undone_at')->nullable();
                $table->unsignedBigInteger('undone_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_bulk_operations');
        Schema::dropIfExists('balance_import_states');
        Schema::dropIfExists('import_batch_rollbacks');
        if (Schema::hasTable('import_batches') && Schema::hasColumn('import_batches', 'status')) {
            Schema::table('import_batches', function (Blueprint $table) {
                $table->dropColumn(['status', 'rolled_back_at', 'rolled_back_by', 'rollback_reason']);
            });
        }
    }
};
