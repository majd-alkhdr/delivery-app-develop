<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // زيارة على مستوى الصيدلية لكل كشف: سجل واحد لكل (كشف، زبون) يُحدَّث عند إعادة التسجيل.
        // الفهرس الفريد على عمودين غير nullable (لا اعتماد على unique مع NULL).
        Schema::create('sheet_visits', function (Blueprint $table) {
            $table->id();
            $table->string('client_uuid', 36)->unique();
            $table->foreignId('distribution_sheet_id')->constrained('distribution_sheets');
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('user_id')->constrained('users');
            $table->enum('status', ['visited', 'not_visited']);
            $table->string('reason', 255)->nullable();
            $table->timestamp('visited_at')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->enum('location_flag', ['ok', 'far', 'no_reference', 'no_gps'])->default('no_gps');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();

            $table->unique(['distribution_sheet_id', 'customer_id']);
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_visits');
    }
};
