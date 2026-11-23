<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            // مطابقة حرفية تماماً كما في التطبيع (MySQL: utf8mb4_bin)
            $normalized = $table->string('name_normalized', 255)->unique();
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $normalized->collation('utf8mb4_bin');
            }
            $table->string('block', 255)->nullable();
            $table->string('area', 255)->nullable();
            $table->string('area_normalized', 255)->nullable()->index();
            // الرصيد الخام كما ورد في ملف الأرصدة (يتضمن قيمة الطلبيات المؤجلة)
            $table->decimal('balance', 15, 2)->default(0);
            $table->date('balance_as_of')->nullable();
            $table->unsignedBigInteger('last_balance_import_id')->nullable()->index();
            $table->boolean('is_hidden')->default(false);
            $table->boolean('needs_review')->default(false)->index();
            // أعمدة فقط للمرحلة القادمة
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
