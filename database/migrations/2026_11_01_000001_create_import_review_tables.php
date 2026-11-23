<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 7A: مراجعة الفروق عند إعادة الرفع.
 * - import_batch_items: نتيجة كل فاتورة وكل ملاحظة في الدفعة (لا شيء يُتجاهل بصمت).
 * - invoice_revisions: نسخة الفاتورة القديمة قبل تطبيق أي تغيير وارد من ملف.
 * - invoice_change_proposals.type: change (قبل الإغلاق) أو post_close (بعد الإغلاق، للتسوية في 7C).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batch_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('batch_id');
            $table->string('kind', 30);
            $table->string('status', 20);
            $table->string('level', 10)->nullable();                // للملاحظات: info | warning
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->string('invoice_key', 255)->nullable()->index();
            $table->string('invoice_number', 255)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->unsignedBigInteger('sheet_id')->nullable()->index();
            $table->string('sheet_label', 100)->nullable();
            $table->unsignedBigInteger('target_sheet_id')->nullable()->index();
            $table->text('reason');
            $table->json('old')->nullable();
            $table->json('new')->nullable();
            $table->unsignedBigInteger('proposal_id')->nullable();
            $table->text('blocked_reason')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'kind', 'status']);
        });

        Schema::create('invoice_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedInteger('revision_no');
            $table->json('header');
            $table->json('lines');
            $table->uuid('batch_id')->nullable();
            $table->unsignedBigInteger('import_batch_item_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'revision_no']);
        });

        // حماية: اختبار ترحيل المرحلة 2B يشغّل الترحيلات اللاحقة قبل جدول الاقتراحات
        if (Schema::hasTable('invoice_change_proposals') && ! Schema::hasColumn('invoice_change_proposals', 'type')) {
            Schema::table('invoice_change_proposals', function (Blueprint $table) {
                $table->string('type', 20)->default('change');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoice_change_proposals', 'type')) {
            Schema::table('invoice_change_proposals', function (Blueprint $table) {
                $table->dropColumn('type');
            });
        }
        Schema::dropIfExists('invoice_revisions');
        Schema::dropIfExists('import_batch_items');
    }
};
