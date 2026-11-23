<?php

namespace Tests\Feature\Phase3;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PharmacyLock;
use App\Models\SheetVisit;
use App\Models\User;
use App\Services\SheetDispatchService;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * I) المرحلة 3: الدفعات (العملة والتحقق)، idempotency، قفل الصيدلية، الإلغاء، الزيارة، والخصوصية.
 */
class PaymentsAndVisitsTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private User $admin;
    private User $supervisor;
    private User $accountant;
    private User $d1;
    private User $d2;
    private DistributionSheet $sheet;
    private Customer $north;
    private Customer $south;
    private Customer $deferredOnly;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع الأول']);
        $this->d2 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع الثاني']);

        $this->sheet = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية الشمال', 'lines' => [['صنف', 1500]]],
            ['number' => 'ع A: 2', 'customer' => 'صيدلية الشمال', 'delivery' => '20-10-2026', 'lines' => [['صنف', 4321]]],
            ['number' => 'ع B: 3', 'customer' => 'صيدلية الجنوب', 'lines' => [['صنف', 2222]]],
            ['number' => 'ع C: 4', 'customer' => 'صيدلية مؤجلة', 'delivery' => '21-10-2026', 'lines' => [['صنف', 3333]]],
        ], [
            ['صيدلية الشمال', 'كتلة', 'منطقة الشمال', 98765.43, 0],
            ['صيدلية الجنوب', 'كتلة', 'منطقة الجنوب', 5000, 0],
            ['صيدلية مؤجلة', 'كتلة', 'منطقة الشمال', 7777, 0],
        ]));

        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة الشمال'), 'distributor_id' => $this->d1->id],
            ['area_key' => ArabicNormalizer::normalize('منطقة الجنوب'), 'distributor_id' => $this->d2->id],
        ]])->assertSessionHasNoErrors();

        $this->north = Customer::where('name', 'صيدلية الشمال')->firstOrFail();
        $this->south = Customer::where('name', 'صيدلية الجنوب')->firstOrFail();
        $this->deferredOnly = Customer::where('name', 'صيدلية مؤجلة')->firstOrFail();
    }

    // ------------------------------------------------------------------
    // B) العملة والتحقق
    // ------------------------------------------------------------------

    public function test_syp_payment(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->north->id, 1500, ['receipt_number' => 'ABC-20261013-1', 'note' => 'دفعة'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('payment.receipt_number', 'ABC-20261013-1')
            ->assertJsonPath('payment.currency', 'SYP');

        $payment = Payment::firstOrFail();
        $this->assertSame('1500.00', $payment->amount);
        $this->assertSame('1500.00', $payment->amount_syp);
        $this->assertNull($payment->exchange_rate);
        $this->assertFalse($payment->rate_is_estimate);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('sheet', $payment->source);
        $this->assertSame('distributor', $payment->entered_role);
        $this->assertSame('in_progress', $this->sheet->fresh()->status);
        // تسجيل الدفعة يعني زيارة تلقائياً
        $this->assertSame('visited', SheetVisit::where('customer_id', $this->north->id)->value('status'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.created', 'subject_id' => $payment->id, 'user_id' => $this->d1->id]);
    }

    public function test_arabic_digits_and_thousand_separators_are_accepted(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->north->id, 0, ['amount' => '١٬٥٠٠'])->assertCreated();
        $this->assertSame('1500.00', Payment::first()->amount);
    }

    public function test_usd_payment_with_rate_in_range(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->north->id, 100, ['currency' => 'USD', 'exchange_rate' => '140', 'note' => 'دولار'])->assertCreated();

        $payment = Payment::firstOrFail();
        $this->assertSame('USD', $payment->currency);
        $this->assertSame('14000.00', $payment->amount_syp);
        $this->assertFalse($payment->rate_is_estimate);
        $this->assertSame('دولار — تم الحساب على 140', $payment->note);
    }

    public function test_usd_rate_out_of_range_needs_confirmation_then_saves(): void
    {
        $uuid = (string) Str::uuid();
        $this->payApi($this->d1, $this->sheet, $this->north->id, 100, ['client_uuid' => $uuid, 'currency' => 'USD', 'exchange_rate' => 20])
            ->assertStatus(422)
            ->assertJsonPath('requires_confirmation', true);
        $this->assertSame(0, Payment::count());

        $this->payApi($this->d1, $this->sheet, $this->north->id, 100, ['client_uuid' => $uuid, 'currency' => 'USD', 'exchange_rate' => 20, 'rate_confirmed' => 1])
            ->assertCreated();
        $this->assertSame('2000.00', Payment::first()->amount_syp);
    }

    public function test_usd_without_rate_is_an_estimate(): void
    {
        // المرحلة 5: السعر إلزامي للدولار الجديد؛ القديم المعلّق في الطابور (legacy_no_rate) وحده يُقبل بلا سعر
        $this->payApi($this->d1, $this->sheet, $this->north->id, 50, ['currency' => 'USD', 'note' => 'بلا سعر'])
            ->assertStatus(422)->assertJsonValidationErrors('exchange_rate');
        $this->payApi($this->d1, $this->sheet, $this->north->id, 50, ['currency' => 'USD', 'note' => 'بلا سعر', 'legacy_no_rate' => 1])->assertCreated();

        $payment = Payment::firstOrFail();
        $this->assertNull($payment->amount_syp);
        $this->assertTrue($payment->rate_is_estimate);
        $this->assertSame('بلا سعر', $payment->note);
    }

    public function test_amount_must_be_positive_and_usd_is_role_limited(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->north->id, 0)->assertStatus(422);
        $this->payApi($this->d1, $this->sheet, $this->north->id, -5)->assertStatus(422);
        $this->assertSame(0, Payment::count());

        $this->assertTrue($this->d1->canEnterUsd());
        $this->assertTrue(User::factory()->role(Role::Rep)->make()->canEnterUsd());
        $this->assertFalse($this->supervisor->canEnterUsd());
        $this->assertFalse($this->accountant->canEnterUsd());
    }

    // ------------------------------------------------------------------
    // C) Idempotency وأرقام الإيصالات
    // ------------------------------------------------------------------

    public function test_same_client_uuid_twice_creates_one_record(): void
    {
        $uuid = (string) Str::uuid();
        $first = $this->payApi($this->d1, $this->sheet, $this->north->id, 700, ['client_uuid' => $uuid])->assertCreated()->json('payment.id');
        $second = $this->payApi($this->d1, $this->sheet, $this->north->id, 700, ['client_uuid' => $uuid])->assertOk()->assertJsonPath('duplicate', true)->json('payment.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::count());

        $visitUuid = (string) Str::uuid();
        $this->visitApi($this->d1, $this->sheet, $this->north->id, ['client_uuid' => $visitUuid])->assertCreated();
        $this->visitApi($this->d1, $this->sheet, $this->north->id, ['client_uuid' => $visitUuid])->assertOk();
        $this->assertSame(1, SheetVisit::count());
    }

    public function test_receipt_number_collision_gets_a_suffix(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->north->id, 10, ['receipt_number' => 'XYZ-20261013-1'])->assertCreated();
        $this->payApi($this->d1, $this->sheet, $this->north->id, 20, ['receipt_number' => 'XYZ-20261013-1'])
            ->assertCreated()
            ->assertJsonPath('payment.receipt_number', 'XYZ-20261013-1-2');
    }

    public function test_receipt_data_shows_due_balance_not_raw_and_marks_duplicates(): void
    {
        $id = $this->payApi($this->d1, $this->sheet, $this->north->id, 4444)->json('payment.id');

        $first = $this->actingAs($this->d1)->getJson(route('payments.receipt-data', $id))->assertOk();
        $first->assertJsonPath('pharmacy', 'صيدلية الشمال')
            ->assertJsonPath('delivery_order_no', '3918')
            ->assertJsonPath('is_duplicate', false)
            ->assertJsonPath('entered_by', 'الموزع الأول');
        $this->assertEqualsWithDelta(94444.43, $first->json('due_before'), 0.001);   // 98,765.43 − 4,321 (المؤجلة)
        $this->assertEqualsWithDelta(90000.43, $first->json('due_after'), 0.001);
        $this->assertStringNotContainsString('98765', $first->getContent());

        // المرحلة 4: «نسخة مكررة» بعد طباعة ناجحة فعلية فقط، لا بمجرد جلب البيانات مرة ثانية
        $this->actingAs($this->d1)->getJson(route('payments.receipt-data', $id))->assertJsonPath('is_duplicate', false);
        $this->actingAs($this->d1)->postJson(route('print-events.store'), ['client_uuid' => (string) Str::uuid(), 'kind' => 'receipt', 'payment_id' => $id, 'mode' => 'ble', 'status' => 'success'])->assertCreated();
        $this->actingAs($this->d1)->getJson(route('payments.receipt-data', $id))->assertJsonPath('is_duplicate', true)->assertJsonPath('print_count', 1);
        $this->actingAs($this->d2)->getJson(route('payments.receipt-data', $id))->assertForbidden();
        $this->actingAs($this->supervisor)->getJson(route('payments.receipt-data', $id))->assertOk();
    }

    // ------------------------------------------------------------------
    // D.4) قفل الصيدلية
    // ------------------------------------------------------------------

    public function test_pharmacy_lock_blocks_second_distributor_until_released(): void
    {
        // كشف ثانٍ لنفس اليوم فيه الزبون نفسه (تعارض مُقَرّ به) مسند لموزع آخر
        $manager = User::factory()->role(Role::SheetManager)->create();
        $result = $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 90', 'customer' => 'صيدلية الشمال', 'order' => 5000, 'lines' => [['صنف', 300]]],
        ]), '2026-10-13', $manager);
        app(SheetDispatchService::class)->acknowledgeConflicts($result['batch'], $manager);
        app(SheetDispatchService::class)->sendBatch($result['batch'], $manager);
        $other = DistributionSheet::where('delivery_order_no', '5000')->firstOrFail();
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $other), ['all_distributor_id' => $this->d2->id]);

        $this->payApi($this->d1, $this->sheet, $this->north->id, 100)->assertCreated();

        $this->payApi($this->d2, $other, $this->north->id, 100)->assertStatus(423)->assertJsonPath('message', fn ($m) => str_contains($m, 'الموزع الأول'));
        $this->visitApi($this->d2, $other, $this->north->id)->assertStatus(423);
        $this->assertSame(1, Payment::count());
        $this->actingAs($this->d2)->get(route('distributor.sheet', $other->id))->assertSee('تمت زيارتها من قِبل الموزع الأول');

        // المشرف يفك القفل
        $lock = PharmacyLock::firstOrFail();
        $this->actingAs($this->d2)->post(route('locks.release', $lock))->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('locks.release', $lock))->assertSessionHas('success');
        $this->assertDatabaseHas('activity_logs', ['action' => 'pharmacy.unlocked', 'subject_id' => $lock->id]);

        $this->payApi($this->d2, $other, $this->north->id, 100)->assertCreated();
        $this->payApi($this->d1, $this->sheet, $this->north->id, 100)->assertStatus(423);
    }

    // ------------------------------------------------------------------
    // الخصوصية والنطاق
    // ------------------------------------------------------------------

    public function test_distributor_never_sees_deferred_or_raw_balance_in_html_or_json(): void
    {
        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->getContent();
        $json = $this->payApi($this->d1, $this->sheet, $this->north->id, 100)->getContent();
        $customers = $this->actingAs($this->d1)->get(route('distributor.customers'))->getContent();

        foreach ([$html, $json, $customers] as $content) {
            foreach (['ع A: 2', '4,321', '4321', '98,765', '98765', 'ع C: 4', '3,333', '7,777'] as $secret) {
                $this->assertStringNotContainsString($secret, $content, "تسرّب: {$secret}");
            }
        }
        $this->assertStringContainsString('94,444', $html);   // الرصيد المستحق
    }

    public function test_customer_outside_distributor_areas_is_forbidden(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->south->id, 100)->assertForbidden();
        $this->visitApi($this->d1, $this->sheet, $this->south->id)->assertForbidden();
        // زبون كل فواتيره مؤجلة ليس في الكشف
        $this->payApi($this->d1, $this->sheet, $this->deferredOnly->id, 100)->assertForbidden();
        // كشف غير مسند
        $stranger = User::factory()->role(Role::Distributor)->create();
        $this->payApi($stranger, $this->sheet, $this->north->id, 100)->assertForbidden();
        $this->assertSame(0, Payment::count());
    }

    // ------------------------------------------------------------------
    // D.5) الإلغاء (void)
    // ------------------------------------------------------------------

    public function test_distributor_voids_own_payment_today_before_export_only(): void
    {
        $id = $this->payApi($this->d1, $this->sheet, $this->north->id, 100)->json('payment.id');
        $payment = Payment::find($id);

        $this->actingAs($this->d1)->postJson(route('payments.void', $payment), ['reason' => ''])->assertStatus(422);
        $this->actingAs($this->d2)->postJson(route('payments.void', $payment), ['reason' => 'ليست لي'])->assertForbidden();

        $this->actingAs($this->d1)->postJson(route('payments.void', $payment), ['reason' => 'مبلغ خاطئ'])->assertOk();
        $this->assertNotNull($payment->fresh()->voided_at);
        $this->assertSame($this->d1->id, $payment->fresh()->voided_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.voided', 'subject_id' => $payment->id]);
        $this->assertNotNull(Payment::find($id));   // لا حذف

        // دفعة من يوم سابق ودفعة مصدّرة: ممنوعتان على الموزع
        $old = Payment::find($this->payApi($this->d1, $this->sheet, $this->north->id, 50)->json('payment.id'));
        $old->update(['collected_at' => now()->subDay()]);
        $this->actingAs($this->d1)->postJson(route('payments.void', $old), ['reason' => 'قديمة'])->assertForbidden();

        $exported = Payment::find($this->payApi($this->d1, $this->sheet, $this->north->id, 60)->json('payment.id'));
        $exported->update(['status' => 'exported', 'exported_at' => now()]);
        $this->actingAs($this->d1)->postJson(route('payments.void', $exported), ['reason' => 'مصدّرة'])->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('payments.void', $exported), ['reason' => 'مصدّرة'])->assertForbidden();

        // المشرف والمحاسب يلغيان قبل التصدير (حتى من يوم سابق)
        $this->actingAs($this->accountant)->post(route('payments.void', $old), ['reason' => 'إلغاء محاسبي'])->assertSessionHas('success');
        $this->assertNotNull($old->fresh()->voided_at);
    }

    public function test_voided_payments_are_excluded_from_indicators(): void
    {
        $id = $this->payApi($this->d1, $this->sheet, $this->north->id, 700)->json('payment.id');
        $this->payApi($this->d1, $this->sheet, $this->north->id, 30, ['currency' => 'USD', 'legacy_no_rate' => 1])->assertCreated();
        $this->actingAs($this->d1)->postJson(route('payments.void', $id), ['reason' => 'خطأ إدخال'])->assertOk();

        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->getContent();
        $this->assertMatchesRegularExpression('/id="stat-syp"\s*>\s*0\s*</', $html);
        $this->assertMatchesRegularExpression('/id="stat-usd"\s*>\s*30\.00\s*</', $html);
        $this->assertMatchesRegularExpression('/id="stat-visited-pharmacies"\s*>\s*1\s*</', $html);
        $this->assertMatchesRegularExpression('/id="stat-progress"\s*>\s*100%\s*</', $html);
    }

    // ------------------------------------------------------------------
    // D.3) الزيارة
    // ------------------------------------------------------------------

    public function test_visit_with_reasons(): void
    {
        $this->visitApi($this->d1, $this->sheet, $this->north->id, ['status' => 'not_visited'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->visitApi($this->d1, $this->sheet, $this->north->id, ['status' => 'not_visited', 'reason' => 'أخرى'])->assertStatus(422)->assertJsonValidationErrors('reason_other');

        $this->visitApi($this->d1, $this->sheet, $this->north->id, ['status' => 'not_visited', 'reason' => 'أخرى', 'reason_other' => 'طريق مغلق'])->assertCreated();
        $visit = SheetVisit::firstOrFail();
        $this->assertSame('not_visited', $visit->status);
        $this->assertSame('أخرى: طريق مغلق', $visit->reason);
        $this->assertDatabaseHas('activity_logs', ['action' => 'visit.recorded', 'subject_id' => $visit->id]);

        // إعادة التسجيل تُحدّث السجل نفسه (سجل واحد لكل كشف وزبون)
        $this->visitApi($this->d1, $this->sheet, $this->north->id)->assertCreated();
        $this->assertSame(1, SheetVisit::count());
        $this->assertSame('visited', $visit->fresh()->status);

        // بعد دفعة: لا يمكن تسجيلها «غير مزارة»
        $this->payApi($this->d1, $this->sheet, $this->north->id, 10)->assertCreated();
        $this->visitApi($this->d1, $this->sheet, $this->north->id, ['status' => 'not_visited', 'reason' => 'الصيدلية مغلقة'])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // F) مطابقة الموقع
    // ------------------------------------------------------------------

    public function test_location_matching_no_reference_near_far_and_no_gps(): void
    {
        // بلا GPS
        $this->visitApi($this->d1, $this->sheet, $this->north->id)->assertCreated()->assertJsonPath('visit.location_flag', 'no_gps');

        // بلا مرجع: يُحفظ الموقع كمرجع غير مؤكد
        $this->payApi($this->d1, $this->sheet, $this->north->id, 10, ['latitude' => 33.5138, 'longitude' => 36.2765])->assertCreated()
            ->assertJsonPath('payment.location_flag', 'no_reference');
        $north = $this->north->fresh();
        $this->assertEqualsWithDelta(33.5138, (float) $north->latitude, 0.00001);
        $this->assertFalse($north->location_confirmed);

        // قريب (~50 م)
        $this->payApi($this->d1, $this->sheet, $this->north->id, 10, ['latitude' => 33.5142, 'longitude' => 36.2766])->assertCreated()
            ->assertJsonPath('payment.location_flag', 'ok')
            ->assertJsonPath('warning', null);

        // بعيد (~1.1 كم): تحذير دون منع، والمرجع لا يتغير
        $far = $this->payApi($this->d1, $this->sheet, $this->north->id, 10, ['latitude' => 33.5238, 'longitude' => 36.2765])->assertCreated()
            ->assertJsonPath('payment.location_flag', 'far')
            ->assertJsonPath('warning', 'أنت بعيد عن موقع الصيدلية.');
        $this->assertGreaterThan(1000, $far->json('payment.distance_m'));
        $this->assertEqualsWithDelta(33.5138, (float) $this->north->fresh()->latitude, 0.00001);

        // المشرف يرى القائمة ويثبّت الموقع كمرجع
        $farPayment = Payment::where('location_flag', 'far')->firstOrFail();
        $this->actingAs($this->supervisor)->get(route('supervisor.far-visits', ['flag' => 'far']))->assertOk()->assertSee('صيدلية الشمال')->assertSee('تثبيت هذا الموقع كمرجع');
        $this->actingAs($this->d1)->get(route('supervisor.far-visits'))->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('supervisor.locations.pin'), ['type' => 'payment', 'id' => $farPayment->id])->assertSessionHas('success');

        $north = $this->north->fresh();
        $this->assertTrue($north->location_confirmed);
        $this->assertEqualsWithDelta(33.5238, (float) $north->latitude, 0.00001);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer.location_pinned', 'subject_id' => $north->id]);
    }

    // ------------------------------------------------------------------
    // E) تبويب العملاء والدفعة الخارجية
    // ------------------------------------------------------------------

    public function test_external_payment_is_linked_to_open_sheet_and_blocked_without_one(): void
    {
        // زبون كل فواتيره مؤجلة: يقبل دفعة خارجية مربوطة بالكشف المفتوح
        $this->actingAs($this->d1)->postJson(route('distributor.payments.external'), [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $this->deferredOnly->id, 'amount' => 500, 'currency' => 'SYP',
        ])->assertCreated();
        $payment = Payment::firstOrFail();
        $this->assertSame('external', $payment->source);
        $this->assertSame($this->sheet->id, $payment->distribution_sheet_id);
        $this->assertSame(0, SheetVisit::count());   // الخارجية لا تُنشئ زيارة في الكشف

        // زبون خارج مناطقه
        $this->actingAs($this->d1)->postJson(route('distributor.payments.external'), [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $this->south->id, 'amount' => 5, 'currency' => 'SYP',
        ])->assertForbidden();

        // بلا كشف مفتوح: ممنوعة برسالة واضحة
        $this->sheet->update(['status' => 'completed']);
        $this->actingAs($this->d1)->postJson(route('distributor.payments.external'), [
            'client_uuid' => (string) Str::uuid(), 'customer_id' => $this->deferredOnly->id, 'amount' => 5, 'currency' => 'SYP',
        ])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'لا يوجد لديك كشف مفتوح'));
        $this->assertSame(1, Payment::count());
    }

    public function test_customers_tab_lists_today_areas_with_due_balance(): void
    {
        // تبويب العملاء: مناطق كشوف اليوم المفتوحة
        $this->sheet->update(['distribution_date' => now('Asia/Damascus')->toDateString()]);

        $this->actingAs($this->d1)->get(route('distributor.customers'))
            ->assertOk()
            ->assertSee('صيدلية الشمال')
            ->assertSee('صيدلية مؤجلة')            // كل فواتيره مؤجلة: يظهر برصيده المستحق
            ->assertDontSee('صيدلية الجنوب')
            ->assertSee('دفعة خارجية');

        $this->actingAs($this->d1)->get(route('distributor.customers', ['q' => 'الشمال']))->assertSee('صيدلية الشمال')->assertDontSee('صيدلية مؤجلة');
        $this->actingAs($this->supervisor)->get(route('distributor.customers'))->assertForbidden();
    }

    // ------------------------------------------------------------------
    // G) المسودة والنشاط
    // ------------------------------------------------------------------

    public function test_sheet_with_activity_cannot_be_drafted_except_by_admin(): void
    {
        $this->payApi($this->d1, $this->sheet, $this->north->id, 100)->assertCreated();

        $this->actingAs($this->supervisor)->post(route('supervisor.sheet.draft', $this->sheet), ['reason' => 'خطأ رفع'])->assertSessionHas('error');
        $this->assertSame('in_progress', $this->sheet->fresh()->status);

        $this->actingAs($this->admin)->post(route('supervisor.sheet.draft', $this->sheet), ['reason' => 'قرار إداري'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $this->sheet->fresh()->status);
    }

    public function test_activity_guard_uses_visits_and_payments_only(): void
    {
        $guard = app(\App\Services\SheetActivityGuard::class);
        Invoice::where('customer_id', $this->north->id)->update(['visited_at' => now(), 'collected_amount' => 99]);
        $this->assertFalse($guard->customerHasActivity($this->sheet, $this->north->id));   // الأعمدة القديمة لا تُحتسب

        $id = $this->payApi($this->d1, $this->sheet, $this->north->id, 100)->json('payment.id');
        $this->assertTrue($guard->customerHasActivity($this->sheet, $this->north->id));

        Payment::find($id)->update(['voided_at' => now()]);
        SheetVisit::query()->update(['voided_at' => now()]);
        $this->assertFalse($guard->customerHasActivity($this->sheet, $this->north->id));
    }

    // ------------------------------------------------------------------
    // A.3 + H) مجموعة التقسيم
    // ------------------------------------------------------------------

    public function test_allocation_group_seed_and_settings_screen(): void
    {
        $this->assertSame('milk', \App\Models\InvoiceSeries::where('key', 'm')->value('allocation_group'));
        $this->assertSame('argivit', \App\Models\InvoiceSeries::where('key', 'ارجيفيت')->value('allocation_group'));
        $this->assertSame('other', \App\Models\InvoiceSeries::where('key', 'g')->value('allocation_group'));

        $series = \App\Models\InvoiceSeries::where('key', 'g')->first();
        $this->actingAs($this->d1)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($this->supervisor)->get(route('settings.index'))->assertOk()->assertSee('مجموعة التقسيم');
        $this->actingAs($this->supervisor)->put(route('settings.allocation', $series), ['allocation_group' => 'milk'])->assertSessionHas('success');
        $this->assertSame('milk', $series->fresh()->allocation_group);

        $log = ActivityLog::where('action', 'settings.allocation_group_changed')->firstOrFail();
        $this->assertSame(['allocation_group' => 'other'], $log->old_values);
        $this->assertSame($this->supervisor->id, $log->user_id);
    }

    public function test_old_routes_are_removed(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('distributor.invoice.update'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('distributor.external.payment'));
        $this->assertFalse(method_exists(\App\Http\Controllers\DistributorController::class, 'addExternalPayment'));
        $this->assertFalse(method_exists(\App\Http\Controllers\DistributorController::class, 'updateInvoice'));
    }
}
