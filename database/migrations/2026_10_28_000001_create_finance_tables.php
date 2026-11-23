<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 5 (المالية): الدفعات التصديرية ولقطاتها الثابتة، مطابقة الصندوق، اختيار مركز كلفة الفائض،
 * وربط الدفعة بدفعتها التصديرية، وإعداد قالب «بيان المالية».
 */
return new class extends Migration
{
    /** القالب الافتراضي حرفياً: لا مسافة قبل «يومية» ومسافة واحدة في النهاية */
    public const STATEMENT_TEMPLATE = 'بيد السيد {user} إيصال إيصال مالية كشف رقم {order}يومية ';

    public function up(): void
    {
        Schema::create('finance_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_sheet_id')->constrained('distribution_sheets');
            // رقم الدفعة التصديرية المتسلسل داخل الكشف (1، 2، ...)
            $table->unsignedInteger('sequence');
            $table->foreignId('exported_by')->constrained('users');
            $table->timestamp('exported_at')->nullable();
            $table->unsignedInteger('payments_count')->default(0);
            $table->decimal('total_syp', 16, 2)->default(0);
            $table->decimal('total_usd', 16, 2)->default(0);
            $table->decimal('surplus_syp', 16, 2)->default(0);
            // نص القالب المستخدم وتوقيعه (لإعادة التنزيل كما صُدّر)
            $table->text('statement_template')->nullable();
            $table->string('template_signature', 64)->nullable();
            $table->timestamps();

            $table->unique(['distribution_sheet_id', 'sequence']);
        });

        // أسطر الملفات كما صُدّرت: allocation/surplus = ملف الاستيراد الرباعي، receipt = كشف المقبوضات (والدولار)
        Schema::create('finance_export_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_export_id')->constrained('finance_exports');
            $table->enum('row_type', ['allocation', 'surplus', 'receipt']);
            $table->unsignedInteger('sort')->default(0);
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->string('customer_name', 255);
            $table->text('statement');
            $table->string('cost_center', 255)->nullable();
            $table->decimal('amount', 16, 2);
            // لصفوف receipt فقط
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->string('currency', 3)->nullable();
            $table->decimal('amount_original', 16, 2)->nullable();
            $table->decimal('exchange_rate', 16, 4)->nullable();
            $table->string('receipt_number', 64)->nullable();
            $table->timestamps();

            $table->index(['finance_export_id', 'row_type']);
        });

        // لقطة توزيع كل دفعة تصديرية على الفواتير (لا تتغير بتعديل لاحق للفاتورة)
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('finance_export_id')->constrained('finance_exports');
            $table->foreignId('distribution_sheet_id')->constrained('distribution_sheets');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->string('invoice_key', 255)->nullable();
            $table->string('invoice_number', 255)->nullable();
            $table->string('series', 50)->nullable();
            $table->string('cost_center', 255)->nullable();
            $table->string('allocation_group', 20);
            $table->decimal('invoice_total', 16, 2);
            $table->decimal('remaining_before', 16, 2);
            $table->decimal('allocated', 16, 2);
            $table->decimal('unpaid', 16, 2);
            $table->string('status', 10);
            $table->timestamps();

            $table->index('invoice_id');
            $table->index(['distribution_sheet_id', 'customer_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'finance_export_id')) {
                $table->foreignId('finance_export_id')->nullable()->constrained('finance_exports');
            }
        });

        // مطابقة الصندوق: المستلم فعلياً لكل (كشف، موزع، عملة)
        Schema::create('cash_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_sheet_id')->constrained('distribution_sheets');
            $table->foreignId('distributor_id')->constrained('users');
            $table->enum('currency', ['SYP', 'USD']);
            $table->decimal('amount', 16, 2);
            $table->text('note')->nullable();
            $table->foreignId('entered_by')->constrained('users');
            $table->timestamps();

            $table->unique(['distribution_sheet_id', 'distributor_id', 'currency']);
        });

        // اختيار المحاسب الصريح لمركز كلفة الفائض (قبل التصدير)
        Schema::create('finance_surplus_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_sheet_id')->constrained('distribution_sheets');
            $table->foreignId('customer_id')->constrained('customers');
            $table->string('cost_center', 255);
            $table->foreignId('chosen_by')->constrained('users');
            $table->timestamps();

            $table->unique(['distribution_sheet_id', 'customer_id']);
        });

        if (! DB::table('settings')->where('key', 'finance_statement_template')->exists()) {
            $now = now();
            DB::table('settings')->insert([
                'key' => 'finance_statement_template',
                'value' => json_encode(self::STATEMENT_TEMPLATE, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'finance_export_id')) {
                $table->dropConstrainedForeignId('finance_export_id');
            }
        });
        Schema::dropIfExists('finance_surplus_choices');
        Schema::dropIfExists('cash_counts');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('finance_export_rows');
        Schema::dropIfExists('finance_exports');
        DB::table('settings')->where('key', 'finance_statement_template')->delete();
    }
};
