<?php

namespace Tests\Feature\Phase4;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PrintEvent;
use App\Models\Setting;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 4: الطباعة (الإعدادات، أحداث الطباعة، النسخة المكررة، صفحة الاختبار، الأزرار، الصلاحيات والخصوصية).
 */
class PrintTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private User $admin;
    private User $supervisor;
    private User $d1;
    private User $d2;
    private DistributionSheet $sheet;
    private Customer $north;
    private Customer $south;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create();
        $this->d2 = User::factory()->role(Role::Distributor)->create();

        $this->sheet = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية الشمال', 'lines' => [['صنف', 1500]]],
            ['number' => 'ع A: 2', 'customer' => 'صيدلية الشمال', 'delivery' => '20-10-2026', 'lines' => [['صنف مؤجل', 4321]]],
            ['number' => 'ع B: 3', 'customer' => 'صيدلية الجنوب', 'lines' => [['صنف', 2222]]],
        ], [
            ['صيدلية الشمال', 'ك', 'منطقة الشمال', 98765.43, 0],
            ['صيدلية الجنوب', 'ك', 'منطقة الجنوب', 5000, 0],
        ]));
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة الشمال'), 'distributor_id' => $this->d1->id],
            ['area_key' => ArabicNormalizer::normalize('منطقة الجنوب'), 'distributor_id' => $this->d2->id],
        ]]);
        $this->north = Customer::where('name', 'صيدلية الشمال')->firstOrFail();
        $this->south = Customer::where('name', 'صيدلية الجنوب')->firstOrFail();
    }

    private function payment(User $user, Customer $customer): Payment
    {
        $id = $this->payApi($user, $this->sheet, $customer->id, 1000)->assertCreated()->json('payment.id');

        return Payment::findOrFail($id);
    }

    private function event(User $user, array $data)
    {
        return $this->actingAs($user)->postJson(route('print-events.store'), $data + [
            'client_uuid' => (string) Str::uuid(),
            'mode' => 'ble',
            'status' => 'success',
        ]);
    }

    // ------------------------------------------------------------------
    // POST /print-events
    // ------------------------------------------------------------------

    public function test_print_events_are_idempotent_and_scoped(): void
    {
        $payment = $this->payment($this->d1, $this->north);
        $uuid = (string) Str::uuid();

        $this->event($this->d1, ['client_uuid' => $uuid, 'kind' => 'receipt', 'payment_id' => $payment->id, 'device_label' => 'MTP-II'])
            ->assertCreated()->assertJsonPath('print_count', 1)->assertJsonPath('duplicate', false);
        $this->event($this->d1, ['client_uuid' => $uuid, 'kind' => 'receipt', 'payment_id' => $payment->id])
            ->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('print_count', 1);
        $this->assertSame(1, PrintEvent::count());
        $this->assertSame($this->sheet->id, PrintEvent::first()->distribution_sheet_id);

        // دفعة/فاتورة/زبون خارج نطاق الموزع ⇒ 403
        $this->event($this->d2, ['kind' => 'receipt', 'payment_id' => $payment->id])->assertForbidden();
        $southInvoice = Invoice::where('invoice_number', 'ع B: 3')->first();
        $this->event($this->d1, ['kind' => 'invoice', 'invoice_id' => $southInvoice->id])->assertForbidden();
        $this->event($this->d1, ['kind' => 'merged', 'distribution_sheet_id' => $this->sheet->id, 'customer_id' => $this->south->id])->assertForbidden();
        $deferred = Invoice::where('invoice_number', 'ع A: 2')->first();
        $this->event($this->d1, ['kind' => 'invoice', 'invoice_id' => $deferred->id])->assertForbidden();
        $stranger = User::factory()->role(Role::Distributor)->create();
        $this->event($stranger, ['kind' => 'merged', 'distribution_sheet_id' => $this->sheet->id, 'customer_id' => $this->north->id])->assertForbidden();

        // ضمن النطاق
        $this->event($this->d1, ['kind' => 'invoice', 'invoice_id' => Invoice::where('invoice_number', 'ع G: 1')->value('id')])->assertCreated();
        $this->event($this->d1, ['kind' => 'merged', 'distribution_sheet_id' => $this->sheet->id, 'customer_id' => $this->north->id])->assertCreated();

        // الأدمن والمشرف لا يطبعون بيانات الكشوف
        $this->event($this->supervisor, ['kind' => 'receipt', 'payment_id' => $payment->id])->assertForbidden();
        $this->event($this->admin, ['kind' => 'receipt', 'payment_id' => $payment->id])->assertForbidden();
    }

    public function test_receipt_event_for_unsynced_payment_uuid_returns_404_then_works(): void
    {
        $paymentUuid = (string) Str::uuid();
        $this->event($this->d1, ['kind' => 'receipt', 'payment_client_uuid' => $paymentUuid])->assertNotFound();

        $this->payApi($this->d1, $this->sheet, $this->north->id, 500, ['client_uuid' => $paymentUuid])->assertCreated();
        $this->event($this->d1, ['kind' => 'receipt', 'payment_client_uuid' => $paymentUuid])->assertCreated()->assertJsonPath('print_count', 1);
    }

    // ------------------------------------------------------------------
    // is_duplicate وprint_count
    // ------------------------------------------------------------------

    public function test_duplicate_flag_flips_only_after_a_successful_real_print(): void
    {
        $payment = $this->payment($this->d1, $this->north);
        $url = route('payments.receipt-data', $payment);

        // الجلب مرتين لا يغيّر الوسم
        $this->actingAs($this->d1)->getJson($url)->assertJsonPath('is_duplicate', false)->assertJsonPath('print_count', 0);
        $this->actingAs($this->d1)->getJson($url)->assertJsonPath('is_duplicate', false)->assertJsonPath('print_count', 0);

        // المعاينة والفشل لا يقلبانه
        $this->event($this->d1, ['kind' => 'receipt', 'payment_id' => $payment->id, 'mode' => 'preview'])->assertCreated()->assertJsonPath('print_count', 0);
        $this->event($this->d1, ['kind' => 'receipt', 'payment_id' => $payment->id, 'status' => 'failed', 'error' => 'انقطع الاتصال'])->assertCreated();
        $this->actingAs($this->d1)->getJson($url)->assertJsonPath('is_duplicate', false)->assertJsonPath('print_count', 0);

        // طباعة ناجحة ⇒ النسخة التالية مكررة
        $this->event($this->d1, ['kind' => 'receipt', 'payment_id' => $payment->id, 'mode' => 'browser'])->assertCreated();
        $this->actingAs($this->d1)->getJson($url)->assertJsonPath('is_duplicate', true)->assertJsonPath('print_count', 1);
    }

    // ------------------------------------------------------------------
    // GET /print/config
    // ------------------------------------------------------------------

    public function test_print_config_values_ranges_etag_and_access(): void
    {
        $response = $this->actingAs($this->d1)->getJson(route('print.config'))->assertOk()->assertHeader('ETag');
        $response->assertJsonPath('settings.print_paper_dots', 576)
            ->assertJsonPath('settings.print_header_title', 'مستودع نيو هبة للأدوية')
            ->assertJsonPath('settings.print_mark_duplicates', true)
            ->assertJsonPath('ranges.print_chunk_bytes.min', 20)
            ->assertJsonPath('ranges.print_paper_dots.max', 832);
        $this->assertSame(['settings', 'ranges', 'etag'], array_keys($response->json()));

        $etag = $response->headers->get('ETag');
        $this->actingAs($this->d1)->getJson(route('print.config'), ['If-None-Match' => $etag])->assertStatus(304);

        foreach ([Role::Rep, Role::Admin, Role::Supervisor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->getJson(route('print.config'))->assertOk();
        }
        foreach ([Role::SheetManager, Role::Accountant] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->getJson(route('print.config'))->assertForbidden();
        }

        // لا حقول حساسة
        $content = $response->getContent();
        foreach (['98765', '4321', 'balance', 'password', 'token'] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    // ------------------------------------------------------------------
    // الإعدادات
    // ------------------------------------------------------------------

    public function test_print_settings_ranges_and_who_can_edit(): void
    {
        $valid = [
            'printing_enabled' => 1, 'print_paper_dots' => 384, 'print_chunk_bytes' => 180, 'print_chunk_delay_ms' => 40,
            'print_slice_rows' => 64, 'print_threshold' => 140, 'print_feed_lines' => 3, 'print_cut' => 1,
            'print_header_title' => 'مستودع تجريبي', 'print_footer_text' => 'شكراً', 'print_mark_duplicates' => 0,
        ];

        $this->actingAs($this->supervisor)->put(route('settings.printing'), ['print_paper_dots' => 900] + $valid)->assertSessionHasErrors('print_paper_dots');
        $this->actingAs($this->supervisor)->put(route('settings.printing'), ['print_chunk_bytes' => 10] + $valid)->assertSessionHasErrors('print_chunk_bytes');
        $this->actingAs($this->supervisor)->put(route('settings.printing'), ['print_threshold' => 255] + $valid)->assertSessionHasErrors('print_threshold');

        $this->actingAs($this->supervisor)->put(route('settings.printing'), $valid)->assertSessionHas('success');
        $this->assertSame(384, Setting::get('print_paper_dots'));
        $this->assertTrue(Setting::get('printing_enabled'));
        $this->assertFalse(Setting::get('print_mark_duplicates'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.printing_changed']);

        $this->actingAs($this->admin)->put(route('settings.printing'), $valid)->assertSessionHas('success');
        foreach ([$this->d1, User::factory()->role(Role::SheetManager)->create(), User::factory()->role(Role::Accountant)->create()] as $user) {
            $this->actingAs($user)->put(route('settings.printing'), $valid)->assertForbidden();
        }
        $this->actingAs($this->supervisor)->get(route('settings.index'))->assertSee('settings-printing', false)->assertSee('عرض الصورة بالنقاط');
    }

    public function test_migration_does_not_overwrite_existing_settings(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        Setting::set('print_paper_dots', 384);
        Setting::set('printing_enabled', true);

        // إعادة تشغيل migration المرحلة 4 وحدها
        Artisan::call('migrate:rollback', ['--path' => 'database/migrations/2026_10_27_000001_create_print_events_and_print_settings.php']);
        Setting::set('print_paper_dots', 384);   // قيمة موجودة قبل التشغيل
        Artisan::call('migrate', ['--path' => 'database/migrations/2026_10_27_000001_create_print_events_and_print_settings.php']);

        $this->assertSame(384, Setting::get('print_paper_dots'));
        $this->assertTrue(Setting::get('printing_enabled'));
        $this->assertSame(160, Setting::get('print_threshold'));   // الغائبة تُضاف بقيمتها الافتراضية
    }

    // ------------------------------------------------------------------
    // صفحة الاختبار والأزرار
    // ------------------------------------------------------------------

    public function test_printer_test_page_roles(): void
    {
        foreach ([Role::Distributor, Role::Rep, Role::Admin, Role::Supervisor] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get(route('print.test'))->assertOk()->assertSee('نمط المعايرة')->assertSee('print-service.js', false);
        }
        foreach ([Role::SheetManager, Role::Accountant] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->get(route('print.test'))->assertForbidden();
        }
    }

    public function test_print_buttons_follow_printing_enabled(): void
    {
        $this->payment($this->d1, $this->north);

        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->getContent();
        $this->assertMatchesRegularExpression('/data-print-customer="'.$this->north->id.'"\s+disabled/', $html);
        $this->assertMatchesRegularExpression('/data-print-receipt="[0-9a-f-]{36}"[^>]*disabled/s', $html);
        $this->assertStringNotContainsString('print-service.js', $html);   // المحرك لا يُحمَّل ما دامت الطباعة معطّلة

        Setting::set('printing_enabled', true);
        $html = $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->getContent();
        $this->assertDoesNotMatchRegularExpression('/data-print-customer="'.$this->north->id.'"\s+disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-print-receipt="[0-9a-f-]{36}"[^>]*disabled[^>]*>/s', $html);
        $this->assertStringContainsString('print-service.js', $html);
        $this->assertStringContainsString('data-printer-status', $html);

        // الصفحات الإدارية لا تحمّل محرك الطباعة
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertDontSee('print-service.js', false);
    }

    // ------------------------------------------------------------------
    // سجل النشاط والخصوصية
    // ------------------------------------------------------------------

    public function test_activity_log_events_and_supervisor_visibility(): void
    {
        $payment = $this->payment($this->d1, $this->north);
        $this->event($this->d1, ['kind' => 'receipt', 'payment_id' => $payment->id, 'device_label' => 'MTP-II'])->assertCreated();
        $this->event($this->d1, ['kind' => 'receipt', 'payment_id' => $payment->id, 'status' => 'failed', 'error' => 'انقطع الاتصال'])->assertCreated();
        $this->event($this->d1, ['kind' => 'receipt', 'payment_id' => $payment->id, 'mode' => 'preview'])->assertCreated();

        $success = ActivityLog::where('action', 'print.success')->firstOrFail();
        $this->assertSame('receipt', $success->new_values['kind']);
        $this->assertSame('ble', $success->new_values['mode']);
        $this->assertSame('MTP-II', $success->meta['device_label']);
        $this->assertSame($payment->id, $success->meta['payment_id']);
        $this->assertSame(1, ActivityLog::where('action', 'print.failed')->count());
        $this->assertSame(2, ActivityLog::where('action', 'like', 'print.%')->count());   // المعاينة لا تُسجَّل

        $this->actingAs($this->supervisor)->get(route('activity.index'))
            ->assertViewHas('logs', fn ($logs) => collect($logs->items())->pluck('action')->contains('print.success'));
    }

    public function test_new_endpoints_never_leak_raw_balance_or_deferred(): void
    {
        Setting::set('printing_enabled', true);
        $payment = $this->payment($this->d1, $this->north);

        $responses = [
            $this->actingAs($this->d1)->getJson(route('print.config'))->getContent(),
            $this->event($this->d1, ['kind' => 'receipt', 'payment_id' => $payment->id])->getContent(),
            $this->actingAs($this->d1)->getJson(route('payments.receipt-data', $payment))->getContent(),
            $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->getContent(),
            $this->actingAs($this->d1)->get(route('print.test'))->getContent(),
            $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->getContent(),
        ];
        foreach ($responses as $content) {
            foreach (['98765', '98,765', 'ع A: 2', '4321', '4,321', 'صنف مؤجل'] as $secret) {
                $this->assertStringNotContainsString($secret, $content, "تسرّب: {$secret}");
            }
        }

        // print_counts في استجابة الأسطر (لوسم النسخة المكررة دون اتصال)
        $this->event($this->d1, ['kind' => 'merged', 'distribution_sheet_id' => $this->sheet->id, 'customer_id' => $this->north->id]);
        $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))
            ->assertJsonPath('print_counts.merged.'.$this->north->id, 1);
    }

    public function test_print_events_table_has_no_null_based_unique_index(): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('print_events')"))->where('unique', 1)->pluck('name')->all();
        $this->assertSame(['print_events_client_uuid_unique'], array_values(array_filter($indexes, fn ($n) => ! str_starts_with($n, 'sqlite_'))));
    }
}
