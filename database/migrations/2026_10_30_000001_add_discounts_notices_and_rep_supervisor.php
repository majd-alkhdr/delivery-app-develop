<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 6B:
 * - الحسم في الحركة اليومية: أعمدة الحسم وصافي السطر على invoice_lines، وعلامة net_uncertain على invoices.
 * - تنبيهات تعديل الكشف بعد نشره: revision على الكشف، وsheet_change_notices وsheet_user_seen.
 * - حماية الطابور دون اتصال: علامة «صيدلية خارج الكشف» على الدفعة والزيارة.
 * (دور rep_supervisor لا يحتاج ترحيلاً: users.role عمود نصي وليس ENUM.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_lines', function (Blueprint $table) {
            // line_total يبقى إجمالي السطر قبل الحسم كما في الملف
            $table->decimal('discount_percent', 6, 3)->nullable();
            $table->decimal('discount_amount', 15, 2)->nullable();
            $table->decimal('line_net', 15, 2)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            // قيمة غير صفرية في «حسم هدايا» أو «الإضافات»: قد يختلف الصافي عن برنامج المحاسبة
            $table->boolean('net_uncertain')->default(false);
        });

        Schema::table('distribution_sheets', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(0);
        });

        Schema::create('sheet_change_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_sheet_id')->constrained('distribution_sheets');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('customer_id')->nullable()->constrained('customers');
            $table->enum('type', ['added', 'removed', 'moved_in', 'moved_out', 'updated', 'area_changed']);
            $table->string('area_key', 191)->nullable();
            // نص عربي مختصر لحظة الحدث: رقم الفاتورة والصيدلية والمنطقة، بلا مبالغ
            $table->string('summary', 500);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['distribution_sheet_id', 'id']);
            $table->index(['distribution_sheet_id', 'customer_id']);
        });

        Schema::create('sheet_user_seen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distribution_sheet_id')->constrained('distribution_sheets');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('last_notice_id')->default(0);
            $table->timestamps();

            $table->unique(['distribution_sheet_id', 'user_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('outside_sheet')->default(false);
        });
        Schema::table('sheet_visits', function (Blueprint $table) {
            $table->boolean('outside_sheet')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sheet_visits', fn (Blueprint $table) => $table->dropColumn('outside_sheet'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('outside_sheet'));
        Schema::dropIfExists('sheet_user_seen');
        Schema::dropIfExists('sheet_change_notices');
        Schema::table('distribution_sheets', fn (Blueprint $table) => $table->dropColumn('revision'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('net_uncertain'));
        Schema::table('invoice_lines', fn (Blueprint $table) => $table->dropColumn(['discount_percent', 'discount_amount', 'line_net']));
    }
};
