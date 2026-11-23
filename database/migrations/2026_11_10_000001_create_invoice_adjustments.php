<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 7C: «تعديل لاحق» على فاتورة في كشف مكتمل وأثره على ذمة المندوب.
 * - invoice_adjustments: كل تعديل (من الملف أو يدوي) بقيمه قبل/بعد وذمة المندوب قبل/بعد والفائض إن نتج.
 * - invoice_change_proposals: سبب تجاهل اقتراح post_close (يبقى ظاهراً).
 * لا يمس finance_exports ولا payment_allocations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('sheet_id')->nullable()->constrained('distribution_sheets')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('cost_center_code', 50)->nullable();
            $table->foreignId('rep_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 10);                         // file | manual
            $table->decimal('old_amount', 15, 2);
            $table->decimal('new_amount', 15, 2);
            $table->decimal('delta', 15, 2);                    // new − old
            $table->string('reason_code', 30);
            $table->text('reason_text')->nullable();
            $table->uuid('source_batch_id')->nullable();
            $table->foreignId('proposal_id')->nullable()->constrained('invoice_change_proposals')->nullOnDelete();
            $table->unsignedBigInteger('import_batch_item_id')->nullable();
            $table->foreignId('balance_snapshot_id')->nullable()->constrained('customer_balance_snapshots')->nullOnDelete();
            $table->string('snapshot_note')->nullable();        // «لا توجد لقطة رصيد مطابقة»
            $table->unsignedBigInteger('invoice_revision_id')->nullable();
            $table->decimal('rep_debt_before', 15, 2)->nullable();
            $table->decimal('rep_debt_after', 15, 2)->nullable();
            $table->decimal('surplus', 15, 2)->default(0);
            $table->string('status', 10)->default('applied');  // applied | reverted
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('reverted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable();
            $table->text('revert_reason')->nullable();
            $table->timestamp('rep_seen_at')->nullable();       // «اطلعت» من المندوب على أثر التعديل
            $table->timestamps();

            $table->index(['rep_user_id', 'applied_at']);
            $table->index('invoice_id');
            $table->index(['status', 'kind']);
        });

        // الحارس: ترحيل اختبار الـbackfill (المرحلة 2B) يشغّل هذا الملف قبل إنشاء الجدول
        if (Schema::hasTable('invoice_change_proposals') && ! Schema::hasColumn('invoice_change_proposals', 'decision_note')) {
            Schema::table('invoice_change_proposals', function (Blueprint $table) {
                $table->text('decision_note')->nullable()->after('decided_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoice_change_proposals', 'decision_note')) {
            Schema::table('invoice_change_proposals', function (Blueprint $table) {
                $table->dropColumn('decision_note');
            });
        }
        Schema::dropIfExists('invoice_adjustments');
    }
};
