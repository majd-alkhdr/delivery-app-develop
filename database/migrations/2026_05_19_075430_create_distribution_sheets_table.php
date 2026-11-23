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
    Schema::create('distribution_sheets', function (Blueprint $table) {
        $table->id();
        // نربط الكشف بالموزع (المستخدم) الذي سيقوم بالتحصيل
        $table->foreignId('distributor_id')->constrained('users')->onDelete('cascade');
        // نربط الكشف بالمشرف الذي قام برفع الملف
        $table->foreignId('supervisor_id')->constrained('users')->onDelete('cascade');

        $table->string('file_name'); // اسم ملف الإكسل (مثال: كشف_زاهرة.xlsx)
        $table->string('status')->default('pending'); // حالة الكشف: pending (قيد التوزيع)، completed (تم)، imported (تم ترحيله للمحاسبة)
        $table->timestamps(); // ينشئ تلقائياً عامودي تاريخ الإنشاء وتاريخ التعديل
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distribution_sheets');
    }
};
