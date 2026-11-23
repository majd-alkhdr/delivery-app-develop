<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // يولّده العميل (JS) لكل دفعة: يمنع التكرار عند إعادة الإرسال دون اتصال
            $table->string('client_uuid', 36)->unique();
            $table->string('receipt_number', 64)->unique();
            // nullable فقط للتوافق؛ كل دفعة جديدة مربوطة بكشف
            $table->foreignId('distribution_sheet_id')->nullable()->constrained('distribution_sheets');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('entered_by')->constrained('users');
            $table->string('entered_role', 20);
            $table->enum('source', ['sheet', 'external', 'rep']);
            $table->decimal('amount', 16, 2);
            $table->enum('currency', ['SYP', 'USD'])->default('SYP');
            $table->decimal('exchange_rate', 16, 4)->nullable();
            $table->decimal('amount_syp', 16, 2)->nullable();
            $table->boolean('rate_is_estimate')->default(false);
            $table->text('note')->nullable();
            $table->enum('status', ['pending', 'exported', 'paid'])->default('pending');
            $table->string('receipt_image_path', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->enum('location_flag', ['ok', 'far', 'no_reference', 'no_gps'])->default('no_gps');
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();

            $table->index(['distribution_sheet_id', 'customer_id']);
            $table->index('customer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
