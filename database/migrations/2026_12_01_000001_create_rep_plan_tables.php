<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة 8B: خطة عمل المندوب.
 * - rep_plan_templates: الخطة الأسبوعية المتكررة. weekday: السبت=0، الأحد=1 … الجمعة=6.
 *   starts_on/ends_on: مدة سريان البند (لتبقى أرقام التغطية للأيام الماضية كما كانت عند تعديل القالب أو نقل الإشراف).
 * - rep_plan_overrides: تعديل بتاريخ محدد (add | remove) دون المساس بالقالب.
 * - rep_plan_visits: زيارات المندوب (مستقلة عن sheet_visits)، زيارة واحدة لكل (مندوب، زبون، تاريخ).
 * - rep_plan_notices: إشعارات تعديل الخطة للمندوب حتى «اطلعت».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rep_plan_templates')) {
            Schema::create('rep_plan_templates', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedTinyInteger('weekday');
                // area | block (القيمة مطبَّعة كما في 8A، والنص الأصلي للعرض)
                $table->string('scope_type', 10);
                $table->string('scope_value', 255);
                $table->string('scope_label', 255)->nullable();
                $table->boolean('active')->default(true);
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // البند نفسه لا يتكرر في الفترة نفسها (ويُعاد إنشاؤه بفترة جديدة بعد إنهائه)
                $table->unique(['rep_user_id', 'weekday', 'scope_type', 'scope_value', 'starts_on'], 'rep_plan_templates_unique');
                $table->index(['supervisor_user_id', 'active']);
            });
        }

        if (! Schema::hasTable('rep_plan_overrides')) {
            Schema::create('rep_plan_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_user_id')->constrained('users')->cascadeOnDelete();
                $table->date('plan_date');
                $table->string('action', 10);
                $table->string('scope_type', 10);
                $table->string('scope_value', 255);
                $table->string('scope_label', 255)->nullable();
                $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 500)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['rep_user_id', 'plan_date', 'action', 'scope_type', 'scope_value'], 'rep_plan_overrides_unique');
                $table->index('plan_date');
            });
        }

        if (! Schema::hasTable('rep_plan_visits')) {
            Schema::create('rep_plan_visits', function (Blueprint $table) {
                $table->id();
                $table->uuid('client_uuid')->unique();
                $table->foreignId('rep_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->date('plan_date');
                // مالك البند وقت التسجيل (لا يتغيّر بنقل الإسناد لاحقاً)
                $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('scope_type', 10)->nullable();
                $table->string('scope_value', 255)->nullable();
                // visited | not_visited
                $table->string('status', 20);
                $table->string('reason_code', 30)->nullable();
                $table->string('reason_text', 500)->nullable();
                $table->timestamp('visited_at')->nullable();
                $table->decimal('lat', 10, 7)->nullable();
                $table->decimal('lng', 10, 7)->nullable();
                $table->unsignedInteger('accuracy_m')->nullable();
                $table->decimal('pharmacy_lat', 10, 7)->nullable();
                $table->decimal('pharmacy_lng', 10, 7)->nullable();
                $table->unsignedInteger('distance_m')->nullable();
                // matched | mismatch | no_pharmacy_location | no_gps | low_accuracy (للزيارات المتمّة فقط)
                $table->string('location_status', 30)->nullable();
                $table->string('location_note', 500)->nullable();
                $table->timestamp('corrected_at')->nullable();
                $table->timestamps();

                $table->unique(['rep_user_id', 'customer_id', 'plan_date'], 'rep_plan_visits_unique');
                $table->index(['plan_date', 'rep_user_id']);
                $table->index(['supervisor_user_id', 'plan_date']);
            });
        }

        if (! Schema::hasTable('rep_plan_notices')) {
            Schema::create('rep_plan_notices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_user_id')->constrained('users')->cascadeOnDelete();
                $table->date('plan_date')->nullable();
                $table->string('text', 1000);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('seen_at')->nullable();
                $table->timestamps();

                $table->index(['rep_user_id', 'seen_at']);
            });
        }

        if (Schema::hasTable('settings')) {
            $radius = DB::table('settings')->where('key', 'location_radius_m')->value('value');
            $defaults = [
                // الافتراضي = حدّ «بعيد عن الموقع» للموزع إن وُجد، وإلا 150
                'plan_location_max_distance_m' => $radius !== null ? (int) json_decode($radius) : 150,
                'plan_min_gps_accuracy_m' => 100,
                'rep_plan_backfill_days' => 0,
                'plan_no_activity_hour' => 13,
            ];
            foreach ($defaults as $key => $value) {
                if (! DB::table('settings')->where('key', $key)->exists()) {
                    DB::table('settings')->insert(['key' => $key, 'value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_plan_notices');
        Schema::dropIfExists('rep_plan_visits');
        Schema::dropIfExists('rep_plan_overrides');
        Schema::dropIfExists('rep_plan_templates');
        if (Schema::hasTable('settings')) {
            DB::table('settings')->whereIn('key', ['plan_location_max_distance_m', 'plan_min_gps_accuracy_m', 'rep_plan_backfill_days', 'plan_no_activity_hour'])->delete();
        }
    }
};
