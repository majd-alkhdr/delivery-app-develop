<?php

namespace Tests\Feature\Phase8B;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\RepSupervisorAssignment;
use App\Models\User;
use App\Services\Reps\RepScope;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * سيناريو المرحلة 8B:
 * - المندوب كرم مشترك: «نهر عيشة» و«كتلة الميدان» عند المشرف أحمد (sup1)، و«المزة» عند المشرف باسل (sup2).
 * - نهر عيشة: N1..N10 (N10 بلا موقع)، وN1..N3 في «كتلة الميدان». المزة: S1..S3. صيدلية مخفية H في نهر عيشة.
 * - اليوم: الأحد 25-10-2026 الساعة 10:00 (دمشق)؛ weekday الأحد = 1 (السبت = 0).
 */
trait PlanFixture
{
    protected User $admin;
    protected User $sup1;
    protected User $sup2;
    protected User $rep;
    protected User $rep2;
    protected array $north = [];
    protected array $south = [];

    protected const LAT = 33.5;
    protected const LNG = 36.3;
    protected const TODAY = '2026-10-25';

    protected function setUpPlan(): void
    {
        $this->travelTo(Carbon::parse(self::TODAY.' 10:00', 'Asia/Damascus'));
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->sup1 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف أحمد']);
        $this->sup2 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف باسل']);
        $this->rep = User::factory()->role(Role::Rep)->create(['name' => 'كرم']);
        $this->rep2 = User::factory()->role(Role::Rep)->create(['name' => 'سامر']);

        for ($i = 1; $i <= 10; $i++) {
            $this->north[$i] = Customer::create([
                'name' => 'صيدلية شمال '.$i, 'area' => 'نهر عيشة', 'block' => $i <= 3 ? 'كتلة الميدان' : null,
                'latitude' => $i === 10 ? null : self::LAT + $i * 0.01, 'longitude' => $i === 10 ? null : self::LNG,
            ]);
        }
        for ($i = 1; $i <= 3; $i++) {
            $this->south[$i] = Customer::create(['name' => 'صيدلية جنوب '.$i, 'area' => 'المزة', 'latitude' => self::LAT - $i * 0.01, 'longitude' => self::LNG]);
        }
        Customer::create(['name' => 'صيدلية مخفية', 'area' => 'نهر عيشة', 'is_hidden' => true, 'latitude' => self::LAT, 'longitude' => self::LNG]);

        $this->assignment($this->rep, $this->sup1, 'area', 'نهر عيشة');
        $this->assignment($this->rep, $this->sup1, 'block', 'كتلة الميدان');
        $this->assignment($this->rep, $this->sup2, 'area', 'المزة');
    }

    protected function assignment(User $rep, User $supervisor, string $type, ?string $label): RepSupervisorAssignment
    {
        return RepSupervisorAssignment::create([
            'rep_user_id' => $rep->id, 'supervisor_user_id' => $supervisor->id, 'scope_type' => $type,
            'scope_value' => RepScope::normalizeValue($label), 'scope_label' => $label, 'created_by' => $this->admin->id,
        ]);
    }

    protected function value(string $label): string
    {
        return RepScope::normalizeValue($label);
    }

    /** إضافة بند للقالب عبر المسار (كما يفعل المشرف) */
    protected function addTemplate(User $by, int $weekday, string $type, string $label, ?User $owner = null): TestResponse
    {
        return $this->actingAs($by)->from(route('rep-supervisor.plan.show', $this->rep))->post(route('rep-supervisor.plan.templates.store', $this->rep), [
            'weekday' => $weekday, 'scope' => ($owner ?? $by)->id.'|'.$type.'|'.$this->value($label),
        ]);
    }

    protected function visit(Customer $customer, array $data = [], ?User $rep = null): TestResponse
    {
        return $this->actingAs($rep ?? $this->rep)->postJson(route('rep.plan.visits'), $data + [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'plan_date' => self::TODAY, 'status' => 'visited',
        ]);
    }

    /** موقع المندوب على بعد ~$meters شمال الصيدلية */
    protected function near(Customer $customer, int $meters, int $accuracy = 10): array
    {
        return ['lat' => (float) $customer->latitude + $meters / 111195, 'lng' => (float) $customer->longitude, 'accuracy' => $accuracy];
    }
}
