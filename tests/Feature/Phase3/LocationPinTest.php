<?php

namespace Tests\Feature\Phase3;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\PharmacyLock;
use App\Models\Setting;
use App\Models\SheetVisit;
use App\Models\User;
use App\Services\SheetInvoiceEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * إصلاح الموقع: الموزع يُسأل صراحة عن تثبيت الموقع المرجعي للصيدلية (none / unconfirmed / confirmed).
 */
class LocationPinTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private const GPS = ['latitude' => 33.5138, 'longitude' => 36.2765, 'accuracy_m' => 10];

    private User $admin;
    private User $supervisor;
    private User $d1;
    private User $d2;
    private DistributionSheet $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع الأول']);
        $this->d2 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع الثاني']);

        $this->sheet = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية بلا مرجع', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية غير مؤكدة', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية مؤكدة', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 4', 'customer' => 'صيدلية ثانية', 'lines' => [['صنف', 100]]],
        ], [
            ['صيدلية بلا مرجع', 'ك', 'منطقة', 10, 0],
            ['صيدلية غير مؤكدة', 'ك', 'منطقة', 10, 0],
            ['صيدلية مؤكدة', 'ك', 'منطقة', 10, 0],
            ['صيدلية ثانية', 'ك', 'منطقة', 10, 0],
        ]));
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $this->d1->id]);

        $this->customer('صيدلية غير مؤكدة')->forceFill(['latitude' => 33.5000, 'longitude' => 36.2700, 'location_confirmed' => false, 'location_source' => 'auto'])->save();
        $this->customer('صيدلية مؤكدة')->forceFill(['latitude' => 33.5138, 'longitude' => 36.2765, 'location_confirmed' => true, 'location_source' => 'supervisor'])->save();
    }

    private function customer(string $name): Customer
    {
        return Customer::where('name', $name)->firstOrFail();
    }

    public function test_no_reference_asks_then_pins_as_confirmed_distributor_reference(): void
    {
        $customer = $this->customer('صيدلية بلا مرجع');
        $uuid = (string) Str::uuid();

        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS + ['client_uuid' => $uuid])
            ->assertCreated()
            ->assertJsonPath('can_pin', true)
            ->assertJsonPath('reference_state', 'none')
            ->assertJsonPath('pin_result', null);
        $this->assertFalse($customer->fresh()->location_confirmed);
        $this->assertSame('auto', $customer->fresh()->location_source);

        // «تثبيت موقعي كمرجع»: إعادة إرسال السجل نفسه مع pin_reference
        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS + ['client_uuid' => $uuid, 'pin_reference' => 1])
            ->assertOk()
            ->assertJsonPath('pin_result', 'pinned')
            ->assertJsonPath('reference_state', 'confirmed')
            ->assertJsonPath('can_pin', false);

        $customer->refresh();
        $this->assertTrue($customer->location_confirmed);
        $this->assertSame('distributor', $customer->location_source);
        $this->assertSame(10, $customer->location_accuracy_m);
        $this->assertSame($this->d1->id, $customer->location_pinned_by);
        $this->assertNotNull($customer->location_pinned_at);
        $this->assertSame(1, SheetVisit::count());

        $log = ActivityLog::where('action', 'customer.location_pinned')->firstOrFail();
        $this->assertSame('distributor', $log->meta['source']);
        $this->assertEquals(10, $log->meta['accuracy_m']);
        $this->assertTrue($log->new_values['location_confirmed']);
        $this->assertFalse($log->old_values['location_confirmed']);

        // الزيارة التالية: لا سؤال
        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS)->assertJsonPath('can_pin', false)->assertJsonPath('reference_state', 'confirmed');
    }

    public function test_unconfirmed_reference_still_asks_and_reports_distance_even_after_remove_and_readd(): void
    {
        $customer = $this->customer('صيدلية غير مؤكدة');

        $response = $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS)->assertCreated()
            ->assertJsonPath('can_pin', true)
            ->assertJsonPath('reference_state', 'unconfirmed');
        $this->assertGreaterThan(150, $response->json('distance_m'));   // المرجع غير المؤكد بعيد ⇒ سطر المسافة في الواجهة
        $this->assertStringNotContainsString('33.5', json_encode($response->json()));   // لا إحداثيات خام

        // إخراج وإعادة (من الأدمن): لا يصبح مؤكداً ولا يسقط السؤال
        $invoice = Invoice::where('invoice_number', 'ع G: 2')->first();
        app(SheetInvoiceEditor::class)->remove($invoice, 'تجربة', $this->admin);
        app(SheetInvoiceEditor::class)->place($invoice->fresh(), $this->sheet, $this->admin);

        $this->assertFalse($customer->fresh()->location_confirmed);
        // المرحلة 7H: الإخراج والإعادة على كشف مُرسل إشعاران إلزاميان: «اطلعت» قبل الزيارة التالية
        $this->actingAs($this->d1)->postJson(route('distributor.notices.ack', [$this->sheet, $customer->id]))->assertOk();
        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS)->assertJsonPath('can_pin', true)->assertJsonPath('reference_state', 'unconfirmed');
    }

    public function test_confirmed_reference_is_not_asked_and_pin_is_ignored(): void
    {
        $customer = $this->customer('صيدلية مؤكدة');

        $this->visitApi($this->d1, $this->sheet, $customer->id, ['latitude' => 33.6, 'longitude' => 36.3, 'accuracy_m' => 5, 'pin_reference' => 1])
            ->assertCreated()
            ->assertJsonPath('can_pin', false)
            ->assertJsonPath('reference_state', 'confirmed')
            ->assertJsonPath('pin_result', 'ignored_confirmed');   // يُتجاهل مع سبب للواجهة

        $customer->refresh();
        $this->assertEqualsWithDelta(33.5138, (float) $customer->latitude, 0.00001);
        $this->assertSame('supervisor', $customer->location_source);
        $this->assertSame(0, ActivityLog::where('action', 'customer.location_pinned')->count());
    }

    public function test_inaccurate_gps_rejects_the_pin_but_keeps_the_visit(): void
    {
        $customer = $this->customer('صيدلية بلا مرجع');

        $this->visitApi($this->d1, $this->sheet, $customer->id, ['latitude' => 33.5138, 'longitude' => 36.2765, 'accuracy_m' => 120, 'pin_reference' => 1])
            ->assertCreated()
            ->assertJsonPath('pin_result', 'inaccurate')
            ->assertJsonPath('pin_message', 'الموقع غير دقيق بعد، انتظر لحظات وأعد المحاولة.')
            ->assertJsonPath('can_pin', true);

        $this->assertSame(1, SheetVisit::count());
        $this->assertFalse($customer->fresh()->location_confirmed);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.location_pin_skipped', 'subject_id' => $customer->id]);

        // الحد قابل للضبط من الإعدادات (5–500)
        $this->actingAs($this->supervisor)->put(route('settings.location'), ['location_pin_max_accuracy_m' => 501])->assertSessionHasErrors();
        $this->actingAs($this->supervisor)->put(route('settings.location'), ['location_pin_max_accuracy_m' => 200])->assertSessionHas('success');
        $this->assertSame(200, Setting::get('location_pin_max_accuracy_m'));
        $this->actingAs($this->supervisor)->get(route('settings.index'))->assertSee('settings-location', false);
    }

    public function test_no_gps_or_not_visited_never_asks_or_pins(): void
    {
        $customer = $this->customer('صيدلية بلا مرجع');

        $this->visitApi($this->d1, $this->sheet, $customer->id, ['pin_reference' => 1])
            ->assertCreated()
            ->assertJsonPath('can_pin', false)
            ->assertJsonPath('pin_result', null);

        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS + ['status' => 'not_visited', 'reason' => 'الصيدلية مغلقة', 'pin_reference' => 1])
            ->assertCreated()
            ->assertJsonPath('can_pin', false)
            ->assertJsonPath('pin_result', null);

        $this->assertFalse($customer->fresh()->location_confirmed);
    }

    public function test_same_client_uuid_twice_pins_once(): void
    {
        $customer = $this->customer('صيدلية بلا مرجع');
        $payload = self::GPS + ['client_uuid' => (string) Str::uuid(), 'pin_reference' => 1];

        $this->visitApi($this->d1, $this->sheet, $customer->id, $payload)->assertCreated()->assertJsonPath('pin_result', 'pinned');
        $this->visitApi($this->d1, $this->sheet, $customer->id, $payload)->assertOk()->assertJsonPath('pin_result', 'already_pinned');

        $this->assertSame(1, SheetVisit::count());
        $this->assertSame(1, ActivityLog::where('action', 'customer.location_pinned')->count());
        $this->assertSame(0, ActivityLog::where('action', 'customer.location_pin_skipped')->count());
    }

    public function test_first_distributor_wins_the_pin(): void
    {
        // كشف آخر في يوم آخر للصيدلية نفسها مسند للموزع الثاني (القفل على مستوى اليوم)
        $other = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 9', 'customer' => 'صيدلية بلا مرجع', 'lines' => [['صنف', 50]]],
        ]), '2026-10-14');
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $other), ['all_distributor_id' => $this->d2->id]);
        $customer = $this->customer('صيدلية بلا مرجع');

        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS + ['pin_reference' => 1])->assertJsonPath('pin_result', 'pinned');
        $this->visitApi($this->d2, $other, $customer->id, ['latitude' => 33.6, 'longitude' => 36.4, 'accuracy_m' => 5, 'pin_reference' => 1])
            ->assertCreated()
            ->assertJsonPath('pin_result', 'ignored_confirmed')
            ->assertJsonPath('can_pin', false);

        $customer->refresh();
        $this->assertSame($this->d1->id, $customer->location_pinned_by);
        $this->assertEqualsWithDelta(33.5138, (float) $customer->latitude, 0.00001);
        $this->assertSame(1, ActivityLog::where('action', 'customer.location_pinned')->count());
        $this->assertSame('already_confirmed', ActivityLog::where('action', 'customer.location_pin_skipped')->firstOrFail()->new_values['reason']);
    }

    public function test_declining_logs_once_and_the_next_visit_asks_again(): void
    {
        $customer = $this->customer('صيدلية بلا مرجع');
        $uuid = (string) Str::uuid();

        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS + ['client_uuid' => $uuid]);
        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS + ['client_uuid' => $uuid, 'pin_declined' => 1])->assertJsonPath('pin_result', 'declined');
        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS + ['client_uuid' => $uuid, 'pin_declined' => 1]);
        $this->assertSame(1, ActivityLog::where('action', 'customer.location_pin_skipped')->count());

        // «ليس الآن»: تبقى بلا تثبيت وتظهر للمشرف، ويُسأل في الزيارة التالية
        $this->assertFalse($customer->fresh()->location_confirmed);
        $this->actingAs($this->supervisor)->get(route('supervisor.far-visits', ['flag' => 'no_reference']))->assertSee('صيدلية بلا مرجع');
        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS)->assertJsonPath('can_pin', true);
    }

    public function test_external_payment_pins_with_the_same_rules(): void
    {
        $customer = $this->customer('صيدلية ثانية');

        $this->actingAs($this->d1)->postJson(route('distributor.payments.external'), self::GPS + [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $customer->id, 'amount' => 100, 'currency' => 'SYP', 'pin_reference' => 1,
        ])->assertCreated()->assertJsonPath('pin_result', 'pinned');

        $this->assertTrue($customer->fresh()->location_confirmed);
        $this->assertSame('distributor', $customer->fresh()->location_source);

        // ودفعة الكشف بدقة ضعيفة: الدفعة محفوظة والتثبيت مرفوض
        $other = $this->customer('صيدلية بلا مرجع');
        $this->payApi($this->d1, $this->sheet, $other->id, 50, ['latitude' => 33.5, 'longitude' => 36.2, 'accuracy_m' => 300, 'pin_reference' => 1])
            ->assertCreated()->assertJsonPath('pin_result', 'inaccurate');
        $this->assertFalse($other->fresh()->location_confirmed);
    }

    public function test_supervisor_can_still_repin_with_supervisor_source(): void
    {
        $customer = $this->customer('صيدلية بلا مرجع');
        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS + ['pin_reference' => 1])->assertJsonPath('pin_result', 'pinned');
        $far = $this->visitApi($this->d1, $this->sheet, $customer->id, ['latitude' => 33.5300, 'longitude' => 36.2765, 'accuracy_m' => 5])->assertJsonPath('visit.location_flag', 'far');

        $this->actingAs($this->supervisor)->post(route('supervisor.locations.pin'), ['type' => 'visit', 'id' => $far->json('visit.id')])->assertSessionHas('success');

        $customer->refresh();
        $this->assertSame('supervisor', $customer->location_source);
        $this->assertSame($this->supervisor->id, $customer->location_pinned_by);
        $this->assertNotNull($customer->location_pinned_at);
        $this->assertEqualsWithDelta(33.5300, (float) $customer->latitude, 0.00001);
    }

    public function test_removed_and_readded_pharmacy_does_not_inherit_old_visit_or_lock(): void
    {
        $customer = $this->customer('صيدلية ثانية');
        $this->visitApi($this->d1, $this->sheet, $customer->id, self::GPS)->assertCreated();
        $invoice = Invoice::where('invoice_number', 'ع G: 4')->first();

        app(SheetInvoiceEditor::class)->remove($invoice, 'إخراج للتجربة', $this->admin);

        $this->assertNotNull(SheetVisit::where('customer_id', $customer->id)->value('voided_at'));   // أُلغيت ولم تُحذف
        $this->assertSame(1, SheetVisit::count());
        $this->assertNull(PharmacyLock::where('customer_id', $customer->id)->value('user_id'));      // فُك القفل
        $this->assertDatabaseHas('activity_logs', ['action' => 'visit.voided']);

        app(SheetInvoiceEditor::class)->place($invoice->fresh(), $this->sheet, $this->admin);
        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->getContent();
        $this->assertMatchesRegularExpression('/data-search="صيدليه ثانيه"[^>]*data-status="not_visited"/u', $html);

        // موزع آخر يستطيع التسجيل الآن (لا قفل متسرب)
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $this->d2->id]);
        // المرحلة 7H: إشعارا الإخراج والإعادة إلزاميان: «اطلعت» من الموزع الجديد قبل التسجيل
        $this->actingAs($this->d2)->postJson(route('distributor.notices.ack', [$this->sheet, $customer->id]))->assertOk();
        $this->visitApi($this->d2, $this->sheet, $customer->id)->assertCreated();
    }

    public function test_payments_keep_the_lock_when_pharmacy_leaves_the_sheet(): void
    {
        $customer = $this->customer('صيدلية ثانية');
        $this->payApi($this->d1, $this->sheet, $customer->id, 100)->assertCreated();

        app(SheetInvoiceEditor::class)->remove(Invoice::where('invoice_number', 'ع G: 4')->first(), 'إخراج', $this->admin);

        // الدفعة سجل مالي لا يُلغى تلقائياً، فيبقى القفل لصاحبها
        $this->assertSame($this->d1->id, PharmacyLock::where('customer_id', $customer->id)->value('user_id'));
    }

    public function test_distributor_card_shows_reference_badges_without_raw_coordinates(): void
    {
        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->getContent();

        $this->assertStringContainsString('بلا موقع مرجعي', $html);
        $this->assertStringContainsString('موقع غير مؤكد', $html);
        $this->assertStringContainsString('موقع مؤكد', $html);
        $this->assertStringContainsString('id="pinModal"', $html);
        $this->assertStringContainsString('هذه الصيدلية بلا موقع مرجعي مؤكد. هل أنت الآن عند الصيدلية؟', $html);
        $this->assertStringNotContainsString('33.5138', $html);
        $this->assertStringNotContainsString('36.2765', $html);

        $this->actingAs($this->d1)->get(route('distributor.customers'));   // تبويب العملاء يتضمن النافذة أيضاً
        $this->sheet->update(['distribution_date' => now('Asia/Damascus')->toDateString()]);
        $this->actingAs($this->d1)->get(route('distributor.customers'))->assertSee('id="pinModal"', false)->assertSee('data-ref-state="unconfirmed"', false);
    }

    /**
     * تحسين نافذة التثبيت: تلميح دقة الحاسوب في /settings، وعناصر السطر الحي في النافذة (بلا إحداثيات).
     */
    public function test_pin_accuracy_hint_in_settings_and_live_line_in_modal(): void
    {
        foreach ([$this->admin, $this->supervisor] as $user) {
            $this->actingAs($user)->get(route('settings.index'))->assertOk()
                ->assertSee('على الحاسوب تكون الدقة عادةً أقل من 500 م؛ جرّب التثبيت على الجوال في مكان مفتوح.');
        }

        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->getContent();
        foreach (['id="pin_accuracy_live"', 'id="pin_accuracy_text"', 'id="pin_refresh_btn"', 'id="pin_skip_btn"'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringNotContainsString('33.5138', $html);
    }
}
