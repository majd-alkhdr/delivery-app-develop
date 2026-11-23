<?php

use Database\Seeders\InvoiceSeriesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_series', function (Blueprint $table) {
            $table->id();
            $key = $table->string('key', 100)->unique(); // حروف صغيرة دائماً
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $key->collation('utf8mb4_bin');
            }
            $table->string('label', 255);
            $table->timestamps();
        });

        // الأسماء الافتراضية (لا تكتب فوق أي اسم موجود)
        (new InvoiceSeriesSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_series');
    }
};
