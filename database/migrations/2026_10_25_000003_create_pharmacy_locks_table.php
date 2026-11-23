<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // قفل الصيدلية: صف واحد لكل (زبون، يوم توزيع) عبر كل الكشوف.
        // user_id = صاحب القفل الحالي، وnull = مفكوك (يأخذه أول موزع يسجّل بعده).
        Schema::create('pharmacy_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->date('distribution_date');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('distribution_sheet_id')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'distribution_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_locks');
    }
};
