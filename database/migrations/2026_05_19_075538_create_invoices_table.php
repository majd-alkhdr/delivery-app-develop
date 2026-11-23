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
    Schema::create('invoices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('distribution_sheet_id')->constrained()->onDelete('cascade');
    $table->string('invoice_number');      // العمود B
    $table->string('customer_name');       // العمود C
    $table->string('statement')->nullable(); // العمود E (البيان)
    $table->decimal('invoice_amount', 15, 2); // العمود F (قيمة الفاتورة الفردية)
    $table->decimal('customer_total_invoices', 15, 2)->default(0); // العمود G (إجمالي فواتير الزبون)
    $table->string('invoice_pattern')->nullable(); // العمود H (النمط)
    $table->decimal('final_balance', 15, 2)->default(0); // العمود L (الرصيد الإجمالي)

    // أعمدة المدخلات والمزامنة للموزع
    $table->decimal('collected_amount', 15, 2)->default(0); // العمود O (المبلغ المقبوض)
    $table->text('notes')->nullable(); // العمود N (ملاحظات الموزع)
    $table->boolean('is_paid_full')->default(false);
    $table->timestamps();
});
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
