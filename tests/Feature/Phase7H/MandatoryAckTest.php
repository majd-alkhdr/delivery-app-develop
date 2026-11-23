<?php

namespace Tests\Feature\Phase7H;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\SheetChangeNotice;
use App\Models\SheetNoticeAck;
use App\Models\SheetVisit;
use App\Models\User;
use App\Services\NoticeAckService;
use App\Services\SheetChangeNotifier;
use App\Services\SheetInvoiceEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7H (2): اطلاع الموزع الإلزامي: المنع على الصيدلية المتأثرة (409 للطلب الحي)، القبول والوسم من الطابور دون اتصال،
 * ولوحة «اطلاع الموزع» وعدّاداتها، والصلاحيات، وثبات عدد الاستعلامات.
 */
class MandatoryAckTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    private User $manager;
    private User $supervisor;
    private User $d1;
    private User $d2;
    private DistributionSheet $sheet;
    private Customer $a;
    private Customer $b;

    private array $balances = [
        ['صيدلية واحد', 'كتلة', 'منطقة أ', 5000, 0],
        ['صيدلية اثنان', 'كتلة', 'منطقة أ', 5000, 0],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع الأول']);
        $this->d2 = User::factory()->role(Role::Distributor)->create();
        $this->sheet = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 1000]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية اثنان', 'order' => 1580, 'lines' => [['صنف', 500]]],
        ], $this->balances));
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $this->d1->id])->assertSessionHasNoErrors();
        $this->a = Customer::where('name', 'صيدلية واحد')->sole();
        $this->b = Customer::where('name', 'صيدلية اثنان')->sole();
    }

    /** تعديل حقيقي على كشف مُرسل: وضع فاتورة «بلا كشف» للصيدلية أ فيه ⇒ إشعار «added» */
    private function addInvoiceToA(string $number = 'ع G: 9'): SheetChangeNotice
    {
        $this->importFiles($this->buildWorkbook([['number' => $number, 'customer' => 'صيدلية واحد', 'order' => 0, 'lines' => [['صنف', 300]]]], $this->balances), '2026-10-13', $this->manager);
        app(SheetInvoiceEditor::class)->place(Invoice::where('invoice_number', $number)->sole(), $this->sheet, $this->manager);

        return SheetChangeNotice::latest('id')->first();
    }

    private function notice(string $type, ?int $customerId): SheetChangeNotice
    {
        return SheetChangeNotice::create([
            'distribution_sheet_id' => $this->sheet->id, 'invoice_id' => null, 'customer_id' => $customerId, 'type' => $type,
            'area_key' => null, 'summary' => 'تعديل '.$type, 'created_at' => now(),
        ]);
    }

    public function test_live_recording_on_affected_pharmacy_is_409_until_acknowledged(): void
    {
        $notice = $this->addInvoiceToA();
        $this->assertSame('added', $notice->type);
        $this->assertSame($this->a->id, $notice->customer_id);

        // الصيدلية المتأثرة: زيارة ودفعة ⇒ 409 برسالة عربية
        $this->payApi($this->d1, $this->sheet, $this->a->id, 100)->assertStatus(409)
            ->assertJsonPath('ack_required', true)
            ->assertJsonPath('message', 'تغيّرت بيانات هذه الصيدلية (أُضيفت فاتورة). اطّلع على التعديل أولاً.')
            ->assertJsonPath('notices.0.id', $notice->id);
        $this->visitApi($this->d1, $this->sheet, $this->a->id)->assertStatus(409);
        $this->visitApi($this->d1, $this->sheet, $this->a->id, ['status' => 'not_visited', 'reason' => 'الصيدلية مغلقة'])->assertStatus(409);
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, SheetVisit::count());

        // صيدلية أخرى في الكشف نفسه ⇒ مسموح
        $this->payApi($this->d1, $this->sheet, $this->b->id, 100)->assertCreated();
        $this->visitApi($this->d1, $this->sheet, $this->b->id)->assertSuccessful();

        // البطاقة: الصندوق وزر «اطلعت» والأزرار معطّلة
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()
            ->assertSee('data-ack-box="'.$this->a->id.'"', false)
            ->assertSee(route('distributor.notices.ack', [$this->sheet, $this->a->id]))
            ->assertDontSee('data-ack-box="'.$this->b->id.'"', false);

        // «اطلعت» ⇒ يُخزَّن لكل إشعار (من، متى) ويُسجَّل
        $this->actingAs($this->d1)->postJson(route('distributor.notices.ack', [$this->sheet, $this->a->id]))->assertOk()->assertJsonPath('acked', 1);
        $ack = SheetNoticeAck::sole();
        $this->assertSame([$notice->id, $this->d1->id], [(int) $ack->notice_id, (int) $ack->user_id]);
        $this->assertNotNull($ack->acked_at);
        $log = ActivityLog::where('action', 'sheet.notice_acked')->sole();
        $this->assertSame([$notice->id], $log->meta['notice_ids']);
        $this->assertSame($this->a->id, $log->meta['customer_id']);

        $this->payApi($this->d1, $this->sheet, $this->a->id, 100)->assertCreated();
        $this->assertFalse(Payment::where('customer_id', $this->a->id)->sole()->recorded_before_ack);
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertDontSee('data-ack-box="'.$this->a->id.'"', false);
    }

    public function test_distributor_cannot_close_and_supervisor_close_warns_with_confirmation(): void
    {
        $this->addInvoiceToA();

        // لا مسار «إنهاء الكشف» للموزع أصلاً: الإغلاق للمشرف والأدمن فقط
        $this->actingAs($this->d1)->post(route('supervisor.complete', $this->sheet->id))->assertForbidden();

        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))->assertOk()->assertSee('name="confirm_unacked"', false);
        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $this->sheet->id))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, '1 إشعاراً بتعديل لم يطّلع عليه الموزع'));
        $this->assertSame('pending', $this->sheet->fresh()->status);

        $this->actingAs($this->supervisor)->post(route('supervisor.complete', $this->sheet->id), ['confirm_unacked' => 1])->assertSessionHas('success');
        $this->assertSame('completed', $this->sheet->fresh()->status);
        $this->assertSame(1, ActivityLog::where('action', 'sheet.completed')->sole()->meta['unacked_notices']);
    }

    public function test_each_configured_type_blocks_and_removing_a_type_or_missing_customer_does_not(): void
    {
        foreach (SheetChangeNotice::TYPES as $type) {
            $notice = $this->notice($type, $this->a->id);
            $this->payApi($this->d1, $this->sheet, $this->a->id, 10)->assertStatus(409);
            $this->actingAs($this->d1)->postJson(route('distributor.notices.ack', [$this->sheet, $this->a->id]))->assertJsonPath('acked', 1);
            $this->assertTrue(SheetNoticeAck::where('notice_id', $notice->id)->exists(), $type);
        }
        $this->assertSame(0, Payment::count());

        // إزالة نوع من الإعداد ⇒ غير ملزم
        $admin = User::factory()->role(Role::Admin)->create();
        $this->actingAs($admin)->put(route('settings.ack-types'), ['types' => array_values(array_diff(SheetChangeNotice::TYPES, ['updated']))])->assertSessionHas('success');
        $this->assertNotContains('updated', NoticeAckService::requiredTypes());
        $this->assertSame(1, ActivityLog::where('action', 'settings.ack_types_changed')->count());
        $this->notice('updated', $this->a->id);
        $this->payApi($this->d1, $this->sheet, $this->a->id, 10)->assertCreated();

        // إشعار بلا صيدلية: لا يمنع شيئاً، ويظهر في الشريط حتى «اطلعت»
        $general = $this->notice('removed', null);
        $this->payApi($this->d1, $this->sheet, $this->b->id, 10)->assertCreated();
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertSee('تعديل removed');
        $this->actingAs($this->d1)->post(route('sheets.notices.seen', $this->sheet));
        $this->assertTrue(SheetNoticeAck::where('notice_id', $general->id)->where('user_id', $this->d1->id)->exists());
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertDontSee('تعديل removed');

        // إفراغ الإعداد ⇒ لا شيء يمنع
        Setting::set(NoticeAckService::SETTING, []);
        $this->notice('added', $this->a->id);
        $this->payApi($this->d1, $this->sheet, $this->a->id, 10)->assertCreated();
    }

    public function test_balance_seen_from_card_counts_as_ack_for_balance_notice(): void
    {
        $notice = $this->notice('balance_changed', $this->a->id);
        $this->payApi($this->d1, $this->sheet, $this->a->id, 10)->assertStatus(409)->assertJsonPath('message', 'تغيّرت بيانات هذه الصيدلية (تغيّر الرصيد). اطّلع على التعديل أولاً.');
        $this->actingAs($this->d1)->post(route('distributor.balance-seen', [$this->sheet, $this->a->id]));
        $this->assertTrue(SheetNoticeAck::where('notice_id', $notice->id)->exists());
        $this->payApi($this->d1, $this->sheet, $this->a->id, 10)->assertCreated();
    }

    public function test_offline_queued_payment_is_always_accepted_flagged_and_never_lost_or_duplicated(): void
    {
        // سُجّلت على الجهاز دون اتصال قبل 10 دقائق (آخر إشعار عرفه الجهاز: لا شيء)، والتعديل حدث بعدها
        $recordedAt = now()->subMinutes(10)->toIso8601String();
        $notice = $this->addInvoiceToA();
        $uuid = (string) Str::uuid();
        $queued = ['client_uuid' => $uuid, 'offline' => 1, 'recorded_at' => $recordedAt, 'known_notice_id' => 0];

        $this->payApi($this->d1, $this->sheet, $this->a->id, 750, $queued)->assertCreated()->assertJsonPath('duplicate', false);
        $payment = Payment::sole();
        $this->assertSame('750.00', (string) $payment->amount);
        $this->assertTrue($payment->recorded_before_ack);
        $this->assertSame([$notice->id], $payment->ack_context['notice_ids']);
        $this->assertFalse($payment->ack_context['notices'][0]['before_recording']);   // الإشعار بعد وقت التسجيل
        $this->assertTrue($payment->ack_context['notices'][0]['unknown_to_device']);
        $this->assertSame(0, $payment->ack_context['known_notice_id']);
        $log = ActivityLog::where('action', 'payment.recorded_before_ack')->sole();
        $this->assertSame($payment->id, (int) $log->subject_id);
        $this->assertSame([$notice->id], $log->meta['notice_ids']);

        // إعادة الإرسال نفسها (client_uuid) لا تكرر ولا تُرفض
        $this->payApi($this->d1, $this->sheet, $this->a->id, 750, $queued)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(1, Payment::count());

        // زيارة من الطابور كذلك
        $this->visitApi($this->d1, $this->sheet, $this->a->id, ['offline' => 1, 'recorded_at' => $recordedAt])->assertSuccessful();
        $this->assertTrue(SheetVisit::sole()->recorded_before_ack);

        // الإلزام باقٍ للتسجيلات الحية التالية حتى «اطلعت»
        $this->payApi($this->d1, $this->sheet, $this->a->id, 5)->assertStatus(409);

        // الشارة عند مسؤول الكشوف والمالية والمشرف
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->sheet))->assertOk()
            ->assertSee('سُجّل قبل اطلاع الموزع على تعديل')->assertSee($payment->receipt_number);
        $accountant = User::factory()->role(Role::Accountant)->create();
        $this->actingAs($accountant)->get(route('finance.sheets.show', $this->sheet))->assertOk()->assertSee('سُجّل قبل اطلاع الموزع على تعديل');
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))->assertOk()->assertSee('سُجّل قبل اطلاع الموزع على تعديل');
    }

    public function test_offline_payload_carries_pending_acks_and_changes_etag(): void
    {
        $first = $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->assertOk();
        $this->assertSame([], (array) $first->json('pending_acks'));
        $notice = $this->addInvoiceToA();

        $second = $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet))->assertOk()
            ->assertJsonPath('last_notice_id', $notice->id)
            ->assertJsonPath('pending_acks.'.$this->a->id.'.0.id', $notice->id);
        $this->assertNotSame($first->headers->get('ETag'), $second->headers->get('ETag'));

        $this->actingAs($this->d1)->postJson(route('distributor.notices.ack', [$this->sheet, $this->a->id]))->assertOk();
        $third = $this->actingAs($this->d1)->getJson(route('distributor.invoice-lines', $this->sheet));
        $this->assertSame([], (array) $third->json('pending_acks'));
        $this->assertNotSame($second->headers->get('ETag'), $third->headers->get('ETag'));
    }

    public function test_sheet_manager_panel_states_counters_sorting_and_list_badge(): void
    {
        $na = $this->addInvoiceToA('ع G: 9');
        $nb = $this->notice('updated', $this->b->id);

        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->sheet))->assertOk()
            ->assertSee('اطلاع الموزع')->assertSee('2 لم يطّلع')->assertSee('لم يطّلع: الموزع الأول');
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.index'))->assertSee('بانتظار اطلاع الموزع (2)');
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertSee('بانتظار اطلاع الموزع (2)');

        // اطلاع على أ فقط ⇒ ب (غير المُطَّلَع عليه) أولاً
        $this->actingAs($this->d1)->postJson(route('distributor.notices.ack', [$this->sheet, $this->a->id]))->assertOk();
        $panel = app(NoticeAckService::class)->panel($this->sheet);
        $this->assertSame([2, 1], [$panel['total'], $panel['unacked']]);
        $this->assertSame([$nb->id, $na->id], $panel['rows']->pluck('notice.id')->all());
        $this->assertSame([false, true], $panel['rows']->pluck('acked')->all());
        $this->assertSame('الموزع الأول', $panel['rows'][1]['acks'][0]->user->name);
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->sheet))->assertSee('1 لم يطّلع')->assertSee('اطّلع');
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.index'))->assertSee('بانتظار اطلاع الموزع (1)');

        $this->actingAs($this->d1)->postJson(route('distributor.notices.ack', [$this->sheet, $this->b->id]))->assertOk();
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.index'))->assertDontSee('بانتظار اطلاع الموزع');
    }

    public function test_batch_screen_summarises_notices_awaiting_ack_after_decisions(): void
    {
        $this->sheet->update(['status' => 'in_progress']);
        $result = $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 1000]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية اثنان', 'order' => 1580, 'lines' => [['صنف', 500]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية واحد', 'order' => 1580, 'lines' => [['صنف', 250]]],
        ], $this->balances), '2026-10-13', $this->manager);
        $item = \App\Models\ImportBatchItem::where('batch_id', $result['batch_id'])->where('invoice_number', 'ع G: 3')->sole();
        $this->actingAs($this->manager)->post(route('sheet-manager.items.apply', $item))->assertSessionHasNoErrors();

        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', $result['batch_id']))->assertOk()
            ->assertSee('1 إشعاراً بانتظار اطلاع الموزع')->assertSee(route('sheet-manager.sheets.show', $this->sheet).'#ack-panel', false);
    }

    public function test_permissions_other_distributor_cannot_ack_and_unauthorised_roles_do_not_see_panel(): void
    {
        $this->addInvoiceToA();
        // موزع غير مسند لهذا الكشف
        $this->actingAs($this->d2)->postJson(route('distributor.notices.ack', [$this->sheet, $this->a->id]))->assertForbidden();
        $this->assertSame(0, SheetNoticeAck::count());
        // غير الموزع لا يصل المسار
        $this->actingAs($this->manager)->postJson(route('distributor.notices.ack', [$this->sheet, $this->a->id]))->assertForbidden();

        $repSupervisor = User::factory()->role(Role::RepSupervisor)->create();
        $this->actingAs($repSupervisor)->get(route('supervisor.sheet.show', $this->sheet->id))->assertOk()->assertDontSee('id="ack-panel"', false);
        $this->actingAs($this->d1)->get(route('distributor.sheet', $this->sheet->id))->assertOk()->assertDontSee('id="ack-panel"', false);
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $this->sheet->id))->assertOk()->assertSee('id="ack-panel"', false);
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->sheet))->assertOk()->assertSee('id="ack-panel"', false);
    }

    public function test_query_count_is_constant_as_notices_grow(): void
    {
        $count = function (User $viewer, string $url): int {
            $this->actingAs($viewer)->get($url)->assertOk();   // تسخين
            $n = 0;
            DB::listen(function () use (&$n) {
                $n++;
            });
            $this->actingAs($viewer)->get($url)->assertOk();

            return $n;
        };
        $make = function (int $times) {
            foreach (range(1, $times) as $i) {
                $this->notice($i % 2 ? 'updated' : 'balance_changed', $i % 3 ? $this->a->id : $this->b->id);
                $this->notice('removed', null);
            }
        };

        $make(3);
        $managerSmall = $count($this->manager, route('sheet-manager.sheets.show', $this->sheet));
        $distributorSmall = $count($this->d1, route('distributor.sheet', $this->sheet->id));
        $make(40);
        $managerBig = $count($this->manager, route('sheet-manager.sheets.show', $this->sheet));
        $distributorBig = $count($this->d1, route('distributor.sheet', $this->sheet->id));

        $this->assertSame($managerSmall, $managerBig);
        $this->assertSame($distributorSmall, $distributorBig);
        $this->assertSame(86, app(NoticeAckService::class)->unackedCount($this->sheet));
    }
}
