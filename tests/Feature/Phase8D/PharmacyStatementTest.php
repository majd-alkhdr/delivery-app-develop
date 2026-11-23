<?php

namespace Tests\Feature\Phase8D;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\Finance\PaymentAllocationEngine;
use App\Services\Finance\RepReceivableService;
use App\Services\Finance\UnpaidReportService;
use App\Services\Reps\PharmacyStatementService;
use App\Support\CostCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * المرحلة 8D: كشف الصيدلية الكامل للمندوب ولمشرف المندوبين (عرض فقط).
 */
class PharmacyStatementTest extends TestCase
{
    use RefreshDatabase, StatementFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStatement();
    }

    private function state(User $rep, Customer $customer): array
    {
        return app(RepReceivableService::class)->state($rep, [$customer->id])->get($customer->id) ?? ['remaining_cents' => 0, 'invoices' => collect()];
    }

    private function statement(User $viewer, Customer $customer): array
    {
        $service = app(PharmacyStatementService::class);

        return $service->build($viewer, $customer, $service->shareReps($viewer, $customer), ['period' => 'all']);
    }

    /**
     * قيم مميزة لمندوب لا يجوز أن تظهر لغيره: المتبقي والمسدَّد لكل فاتورة جزئية، وإجمالي ذمته وحصصه من الدفعات.
     *
     * @return array<int, string>
     */
    private function secretsOf(User $rep, Customer $customer, string $visibleHtml = ''): array
    {
        $state = $this->state($rep, $customer);
        $values = [$state['remaining_cents']];
        foreach ($state['invoices'] as $i) {
            if ($i['remaining_cents'] > 0 && $i['remaining_cents'] < $i['total_cents']) {
                $values[] = $i['remaining_cents'];
                $values[] = $i['total_cents'] - $i['remaining_cents'];
            }
        }
        $admin = $this->statement($this->admin, $customer);
        foreach ($admin['payments']->items() as $p) {
            if (isset($p['shares'][$rep->id]) && $p['shares'][$rep->id] !== $p['amount_cents']) {
                $values[] = $p['shares'][$rep->id];
            }
        }

        // ما هو علني أصلاً (قيم الفواتير والمبالغ الكاملة للدفعات) لا يُعدّ سراً حتى لو تطابق رقمه
        $public = collect($admin['invoices']->items())->pluck('amount_cents')->merge(collect($admin['payments']->items())->pluck('amount_cents'))->all();

        return collect($values)->filter(fn ($c) => $c > 0 && ! in_array($c, $public, true))->map(fn ($c) => self::money($c))->unique()->values()->all();
    }

    // =====================================================================
    // 1) هوية الأرقام
    // =====================================================================

    public function test_numbers_are_identical_to_the_receivables_screen_and_engine(): void
    {
        $share = $this->state($this->karam, $this->center)['remaining_cents'];
        $this->assertGreaterThan(0, $share);

        // البطاقة = رقم «ذممي» لهذه الصيدلية حرفياً، وقائمة «صيدلياتي» كذلك
        $region = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', $this->center)));
        $this->assertMatchesRegularExpression('/id="ps-my-share">'.preg_quote(self::money($share), '/').'</', $region);
        $index = app(RepReceivableService::class)->receivablesForRep($this->karam)->firstWhere('customer_id', $this->center->id);
        $this->assertSame($share, $index['remaining_cents']);
        $this->actingAs($this->karam)->get(route('rep.index'))->assertSee(self::money($index['remaining_cents']));
        $list = $this->actingAs($this->karam)->get(route('rep.pharmacies.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-customer="'.$this->center->id.'".*?data-share>'.preg_quote(self::money($share), '/').'</s', $list);

        // الباقي = الرصيد الكامل − حصتي
        $s = $this->statement($this->karam, $this->center);
        $net = PaymentAllocationEngine::toCents(app(CustomerBalanceService::class)->currentBalance($this->center)->net);
        $this->assertSame($net, $s['balance_cents']);
        $this->assertSame(1000000 - 260000, $net);   // 10000 − دفعات لم تنعكس (800 + 1000 + 300 + 500)
        $this->assertSame($share, $s['shares_cents']);
        $this->assertSame($net - $share, $s['rest_cents']);
        $this->assertStringContainsString(self::money($net - $share), $region);

        // حصتي من دفعة الموزع المصدَّرة = لقطات التوزيع على فواتيري
        $karamInvoices = Invoice::where('cost_center_code', '111')->pluck('id')->all();
        $exportedShare = PaymentAllocationEngine::toCents(PaymentAllocation::where('source', 'distributor')->where('customer_id', $this->center->id)->whereIn('invoice_id', $karamInvoices)->sum('allocated'));
        $this->assertSame(33182, $exportedShare);   // 800 × 700 / (700 + 987.65) بأكبر باقي
        $rows = collect($s['payments']->items())->keyBy('payment_id');
        $this->assertSame($exportedShare, $rows[$this->distExported->id]['shares'][$this->karam->id]);
        $this->assertSame('مصدَّرة', $rows[$this->distExported->id]['status_label']);

        // حصتي من دفعة الموزع المعلّقة = التوزيع الحي للمحرك على فواتيري
        $live = app(UnpaidReportService::class)->liveAllocation($this->s1, [$this->center->id])['results'][$this->center->id];
        $liveShare = collect($live->invoiceRows)->whereIn('invoice_id', $karamInvoices)->sum('allocated_cents');
        $this->assertGreaterThan(0, $liveShare);
        $this->assertSame($liveShare, $rows[$this->distPending->id]['shares'][$this->karam->id]);
        $this->assertSame('معلّقة', $rows[$this->distPending->id]['status_label']);

        // دفعتي كاملة، ودفعة سامر 0 لي
        $this->assertSame(30000, $rows[$this->karamPay->id]['shares'][$this->karam->id]);
        $this->assertSame([], $rows[$this->samerPay->id]['shares']);

        // «ذممي»: الفواتير المتبقية تجمع إلى الحصة
        $this->assertSame($share, $s['rep_cards'][0]['open_invoices']->sum('remaining_cents'));
    }

    // =====================================================================
    // 2) سيناريو 3 مندوبين
    // =====================================================================

    public function test_three_reps_each_see_all_invoices_with_names_and_only_their_own_shares(): void
    {
        $reps = [$this->karam, $this->samer, $this->nader];
        foreach ($reps as $rep) {
            $region = $this->region($this->actingAs($rep)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'period' => 'all'])));

            // كل الفواتير بأسماء مندوبيها وغير المنسوب
            foreach (['G: 101', 'G: 102', 'G: 103', 'G: 104', 'G: 105', 'G: 201', 'G: 202', 'G: 301', 'G: 401', 'G: 402'] as $label) {
                $this->assertStringContainsString($label, $region, $rep->name.' '.$label);
            }
            foreach (['كرم', 'سامر', 'نادر', PharmacyStatementService::NO_REP] as $name) {
                $this->assertStringContainsString($name, $region);
            }
            // كل الدفعات بمبالغها الكاملة
            foreach (['800.00', '1,000.00', '300.00', '500.00'] as $amount) {
                $this->assertStringContainsString($amount, $region);
            }
            // حصته هو
            $this->assertStringContainsString(self::money($this->state($rep, $this->center)['remaining_cents']), $region);

            // لا متبقي/مسدَّد/حصة لغيره في النص الكامل للاستجابة
            $full = $this->actingAs($rep)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'period' => 'all']))->getContent();
            foreach ($reps as $other) {
                if ($other->is($rep)) {
                    continue;
                }
                foreach ($this->secretsOf($other, $this->center) as $secret) {
                    $this->assertStringNotContainsString($secret, $full, $rep->name.' يرى '.$secret.' من '.$other->name);
                }
            }
            // صفوف فواتير غيره بلا متبقٍّ
            $this->assertSame(substr_count($region, 'data-my-badge'), Invoice::where('cost_center_code', $rep->cost_center_code)->where('customer_id', $this->center->id)->count());
        }

        // سامر جزئي فعلاً (يضمن أن الفحص أعلاه ذو معنى)
        $this->assertNotEmpty($this->secretsOf($this->samer, $this->center));
        $this->assertGreaterThanOrEqual(3, count($this->secretsOf($this->samer, $this->center)));
    }

    public function test_invoice_rows_of_other_reps_carry_no_remaining_keys(): void
    {
        $s = $this->statement($this->karam, $this->center);
        foreach ($s['invoices']->items() as $row) {
            if ($row['rep_id'] !== $this->karam->id) {
                $this->assertArrayNotHasKey('remaining_cents', $row);
                $this->assertArrayNotHasKey('paid_cents', $row);
            }
        }
        foreach ($s['payments']->items() as $p) {
            $this->assertSame([], array_diff(array_keys($p['shares']), [$this->karam->id]));
        }
    }

    // =====================================================================
    // 3) الوصول
    // =====================================================================

    public function test_rep_access_requires_a_non_excluded_sale(): void
    {
        $never = $this->rep('لم يبع', '444');
        $this->actingAs($never)->get(route('rep.pharmacies.show', $this->center))->assertForbidden();

        $excludedOnly = $this->rep('مستبعد', '555');
        $this->invoice($this->s1, 'G: 601', 100, $excludedOnly, null, Invoice::EXCLUDED);
        $this->actingAs($excludedOnly)->get(route('rep.pharmacies.show', $this->center))->assertForbidden();

        $deferredOnly = $this->rep('مؤجل', '666');
        $this->invoice(null, 'G: 602', 100, $deferredOnly);
        $this->actingAs($deferredOnly)->get(route('rep.pharmacies.show', $this->center))->assertOk();

        // في كشف مكتمل
        $this->actingAs($this->nader)->get(route('rep.pharmacies.show', $this->center))->assertOk();
        // نادر لم يبع «الطرف»
        $this->actingAs($this->nader)->get(route('rep.pharmacies.show', $this->edge))->assertForbidden();

        // بلا مركز كلفة
        $noCode = User::factory()->role(Role::Rep)->create();
        $this->actingAs($noCode)->get(route('rep.pharmacies.show', $this->center))->assertForbidden();
        $this->actingAs($noCode)->get(route('rep.pharmacies.index'))->assertOk()->assertSee('لا صيدليات');
    }

    public function test_forbidden_roles_and_admin_sees_every_share(): void
    {
        foreach ([$this->distributor, $this->supervisor, $this->manager, $this->accountant] as $user) {
            $this->actingAs($user)->get(route('rep-supervisor.pharmacies.show', $this->center))->assertForbidden();
            $this->actingAs($user)->get(route('rep.pharmacies.show', $this->center))->assertForbidden();
            $this->actingAs($user)->get(route('rep.pharmacies.index'))->assertForbidden();
        }
        // المشرف والأدمن لا يدخلان مسار المندوب
        $this->actingAs($this->admin)->get(route('rep.pharmacies.show', $this->center))->assertForbidden();

        $region = $this->region($this->actingAs($this->admin)->get(route('rep-supervisor.pharmacies.show', ['customer' => $this->center, 'period' => 'all'])));
        foreach ([$this->karam, $this->samer, $this->nader] as $rep) {
            $this->assertStringContainsString('data-rep="'.$rep->id.'"', $region);
            $this->assertStringContainsString(self::money($this->state($rep, $this->center)['remaining_cents']), $region);
        }
        $s = $this->statement($this->admin, $this->center);
        $total = collect([$this->karam, $this->samer, $this->nader])->sum(fn ($r) => $this->state($r, $this->center)['remaining_cents']);
        $this->assertSame($total, $s['shares_cents']);
        $this->assertSame($s['balance_cents'] - $total, $s['rest_cents']);
    }

    // =====================================================================
    // 4) مشرف المندوبين
    // =====================================================================

    public function test_supervisors_see_only_their_reps_shares_within_their_scope_with_scoping_on_and_off(): void
    {
        $this->assignSupervisors();
        foreach ([false, true] as $on) {
            $this->setScoping($on);
            $tag = $on ? 'مفعّل' : 'مطفأ';

            // أحمد: المركز (كرم + سامر)، لا نادر؛ الطرف خارج نطاقه (كرم فيها عند باسل، وسامر فيها بلا مشرف)
            $r1 = $this->region($this->actingAs($this->sup1)->get(route('rep-supervisor.pharmacies.show', ['customer' => $this->center, 'period' => 'all'])));
            $this->assertStringContainsString('data-rep="'.$this->karam->id.'"', $r1, $tag);
            $this->assertStringContainsString('data-rep="'.$this->samer->id.'"', $r1, $tag);
            $this->assertStringNotContainsString('data-rep="'.$this->nader->id.'"', $r1, $tag);
            $this->assertStringContainsString('نادر', $r1, $tag);   // اسمه على فاتورته يظهر
            $full1 = $this->actingAs($this->sup1)->get(route('rep-supervisor.pharmacies.show', ['customer' => $this->center, 'period' => 'all']))->getContent();
            foreach ($this->secretsOf($this->nader, $this->center) as $secret) {
                $this->assertStringNotContainsString($secret, $full1, $tag);
            }
            $this->actingAs($this->sup1)->get(route('rep-supervisor.pharmacies.show', $this->edge))->assertForbidden();

            // باسل: الطرف (كرم فقط)، والمركز خارج نطاقه
            $r2 = $this->region($this->actingAs($this->sup2)->get(route('rep-supervisor.pharmacies.show', $this->edge)));
            $this->assertStringContainsString('data-rep="'.$this->karam->id.'"', $r2, $tag);
            $this->assertStringNotContainsString('data-rep="'.$this->samer->id.'"', $r2, $tag);
            $this->assertStringContainsString(self::money($this->state($this->karam, $this->edge)['remaining_cents']), $r2);
            $this->actingAs($this->sup2)->get(route('rep-supervisor.pharmacies.show', $this->center))->assertForbidden();

            // الحصة عند أحمد = state حرفياً
            $s = $this->statement($this->sup1, $this->center);
            $this->assertEqualsCanonicalizing([$this->karam->id, $this->samer->id], $s['rep_cards']->map(fn ($c) => $c['rep']->id)->all());
            $this->assertSame($this->state($this->karam, $this->center)['remaining_cents'] + $this->state($this->samer, $this->center)['remaining_cents'], $s['shares_cents']);
        }

        // مشرف بلا إسنادات
        $lonely = User::factory()->role(Role::RepSupervisor)->create();
        $this->actingAs($lonely)->get(route('rep-supervisor.pharmacies.show', $this->center))->assertForbidden();
    }

    // =====================================================================
    // 5) وضع العرض للصيدلية
    // =====================================================================

    public function test_pharmacy_mode_hides_shares_names_and_badges(): void
    {
        $this->assignSupervisors();
        foreach ([[$this->karam, 'rep.pharmacies.show'], [$this->sup1, 'rep-supervisor.pharmacies.show'], [$this->admin, 'rep-supervisor.pharmacies.show']] as [$viewer, $route]) {
            $region = $this->region($this->actingAs($viewer)->get(route($route, ['customer' => $this->center, 'mode' => 'pharmacy', 'period' => 'all'])));
            $this->assertStringContainsString('data-mode="pharmacy"', $region);
            foreach (['حصتي', 'ذممي', 'ذمم ', 'فاتورتي', 'الباقي', 'كرم', 'سامر', 'نادر', 'الموزع وسيم', PharmacyStatementService::NO_REP, 'data-rep=', 'data-mine', 'المتبقي', 'المسدَّد'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $region, $viewer->name.': '.$hidden);
            }
            foreach (['G: 101', 'G: 102', 'G: 105', '800.00', '1,000.00', 'رصيد الصيدلية الكامل', 'window.print()'] as $shown) {
                $this->assertStringContainsString($shown, $region);
            }
            foreach ($this->secretsOf($this->karam, $this->center) as $secret) {
                $this->assertStringNotContainsString($secret, $region);
            }
        }
        // الافتراضي داخلي
        $this->assertStringContainsString('data-mode="internal"', $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', $this->center))));
    }

    // =====================================================================
    // 6) مفتاح الأمان
    // =====================================================================

    public function test_kill_switch_limits_rep_to_own_invoices_and_payments_and_is_logged(): void
    {
        $this->assertTrue(PharmacyStatementService::fullEnabled());
        $this->actingAs($this->admin)->put(route('settings.rep-full-statement'), [])->assertSessionHasNoErrors();
        $this->assertFalse(PharmacyStatementService::fullEnabled());
        $log = ActivityLog::where('action', 'settings.rep_full_statement_changed')->latest('id')->firstOrFail();
        $this->assertSame(['rep_full_statement' => true], $log->old_values);
        $this->assertSame(['rep_full_statement' => false], $log->new_values);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->actingAs($this->admin)->get(route('settings.index'))->assertOk()->assertSee('settings-rep-full-statement', false);

        $region = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'period' => 'all'])));
        $this->assertStringContainsString('data-statement-restricted', $region);
        foreach (['G: 101', 'G: 201', 'G: 301', 'G: 402'] as $mine) {
            $this->assertStringContainsString($mine, $region);
        }
        foreach (['G: 102', 'G: 103', 'G: 104', 'G: 105', 'سامر', 'نادر', 'الموزع وسيم', 'data-card="rest"'] as $other) {
            $this->assertStringNotContainsString($other, $region);
        }
        $this->assertSame(1, substr_count($region, 'ps-payment-row'));
        // حصتي لم تتغير
        $this->assertStringContainsString(self::money($this->state($this->karam, $this->center)['remaining_cents']), $region);

        // الأدمن يرى الكامل دائماً؛ وإعادة التفعيل تُسجَّل
        $this->assertStringContainsString('G: 102', $this->region($this->actingAs($this->admin)->get(route('rep-supervisor.pharmacies.show', ['customer' => $this->center, 'period' => 'all']))));
        $this->actingAs($this->admin)->put(route('settings.rep-full-statement'), ['enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(PharmacyStatementService::fullEnabled());
        $this->assertSame(2, ActivityLog::where('action', 'settings.rep_full_statement_changed')->count());
        // حفظ دون تغيير لا يُسجَّل
        $this->actingAs($this->admin)->put(route('settings.rep-full-statement'), ['enabled' => '1']);
        $this->assertSame(2, ActivityLog::where('action', 'settings.rep_full_statement_changed')->count());
        // غير الأدمن لا يغيّره
        $this->actingAs($this->accountant)->put(route('settings.rep-full-statement'), [])->assertForbidden();
    }

    // =====================================================================
    // 7) غير المنسوب، والرصيد السالب/الصفر، وصيدلية بلا دفعات
    // =====================================================================

    public function test_unattributed_invoices_are_labelled_and_fall_into_the_rest(): void
    {
        $s = $this->statement($this->karam, $this->center);
        $rows = collect($s['invoices']->items())->keyBy('label');
        $this->assertSame(PharmacyStatementService::NO_REP, $rows['G: 104']['rep_name']);
        $this->assertSame(PharmacyStatementService::NO_REP, $rows['G: 105']['rep_name']);
        $this->assertNull($rows['G: 104']['rep_id']);
        $this->assertSame($s['balance_cents'] - $s['shares_cents'], $s['rest_cents']);
        $region = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'period' => 'all'])));
        $this->assertStringContainsString('يشمل ما تبقّى على فواتير مندوبين آخرين، وأرصدة غير منسوبة لمندوب', $region);
    }

    public function test_negative_and_zero_balance_and_no_payments_render_with_explanations(): void
    {
        $neg = Customer::create(['name' => 'صيدلية السالب', 'area' => 'منطقة الوسط', 'balance' => -500]);
        $this->invoice($this->s1, 'G: 701', 900, $this->karam, $neg);
        $region = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', $neg)));
        $this->assertStringContainsString('رصيد سالب', $region);
        $this->assertStringContainsString('لا دفعات مسجّلة', $region);
        $this->assertStringContainsString('data-rest-negative', $region);
        $this->assertStringContainsString('−'.self::money(140000), $region);   // −500 − 900
        $this->assertStringContainsString('لا تاريخ للأرصدة', $region);

        $zero = Customer::create(['name' => 'صيدلية الصفر', 'area' => 'منطقة الوسط', 'balance' => 0]);
        $this->invoice($this->s4, 'G: 702', 50, $this->karam, $zero);
        $this->actingAs($this->karam)->get(route('rep.pharmacies.show', $zero))->assertOk()->assertSee('لا رصيد مستحق');
        $this->actingAs($this->admin)->get(route('rep-supervisor.pharmacies.show', $zero))->assertOk();
    }

    // =====================================================================
    // 8) الفترة الافتراضية والبحث والتصفية
    // =====================================================================

    public function test_default_period_search_and_mine_filter(): void
    {
        // الافتراضي عند كرم: G:401 (سامر، قبل 200 يوم) مخفية؛ وG:402 (له، قديمة) مخفية لأن دفعته 300 سدّدتها بالأقدم
        $this->assertSame(0, collect($this->state($this->karam, $this->center)['invoices'])->firstWhere('label', 'G: 402')['remaining_cents']);
        $region = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', $this->center)));
        $this->assertStringNotContainsString('G: 401', $region);
        $this->assertStringNotContainsString('G: 402', $region);
        $this->assertStringContainsString('G: 301', $region);   // قيد التسليم
        $this->assertStringContainsString('قيد التسليم', $region);
        $this->assertStringContainsString('مسلَّمة', $region);
        // عند سامر العكس
        $samer = $this->region($this->actingAs($this->samer)->get(route('rep.pharmacies.show', $this->center)));
        $this->assertStringContainsString('G: 401', $samer);
        $this->assertStringNotContainsString('G: 402', $samer);

        $all = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'period' => 'all'])));
        $this->assertStringContainsString('G: 401', $all);

        $range = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'period' => 'range', 'from' => now('Asia/Damascus')->subDays(6)->toDateString()])));
        $table = strstr(strstr($range, 'id="ps-invoices-table"'), '</table>', true);   // جدول الفواتير وحده («ذممي» غير مقيَّد بالفترة)
        $this->assertStringContainsString('G: 201', $table);
        $this->assertStringNotContainsString('G: 101', $table);

        $search = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'q' => '202', 'period' => 'all'])));
        $this->assertSame(1, substr_count($search, 'ps-invoice-row'));

        $mine = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'who' => 'mine', 'period' => 'all'])));
        $this->assertSame(4, substr_count($mine, 'ps-invoice-row'));
        $this->assertStringNotContainsString('G: 102', $mine);

        $this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'from' => 'x']))->assertSessionHasErrors('from');
    }

    // =====================================================================
    // 9) الروابط والقوائم
    // =====================================================================

    public function test_links_from_rep_and_supervisor_screens(): void
    {
        $this->assignSupervisors();
        $this->setScoping(true);

        // شريط المندوب و«ذممي» و«تحصيل لأي زبون»
        $index = $this->actingAs($this->karam)->get(route('rep.index'))->assertOk();
        $index->assertSee(route('rep.pharmacies.index'))->assertSee('صيدلياتي')->assertSee(route('rep.pharmacies.show', $this->center));
        $customers = $this->actingAs($this->nader)->get(route('rep.customers'))->assertOk()->getContent();
        $this->assertStringContainsString(route('rep.pharmacies.show', $this->center), $customers);
        $this->assertStringNotContainsString(route('rep.pharmacies.show', $this->edge), $customers);   // لم يبعها

        // «صيدلياتي»: المركز والطرف لكرم، مرتبة بالحصة الأعلى
        $list = $this->actingAs($this->karam)->get(route('rep.pharmacies.index'))->assertOk()->getContent();
        $this->assertStringContainsString('صيدلية المركز', $list);
        $this->assertStringContainsString('صيدلية الطرف', $list);
        $this->assertLessThan(strpos($list, 'صيدلية الطرف'), strpos($list, 'صيدلية المركز'));
        $this->actingAs($this->karam)->get(route('rep.pharmacies.index', ['q' => 'الطرف']))->assertDontSee('صيدلية المركز')->assertSee('صيدلية الطرف');
        $this->actingAs($this->nader)->get(route('rep.pharmacies.index'))->assertDontSee('صيدلية الطرف');

        // المشرف: ملف المندوب (الذمم والتسديدات) وذمم/دفعات المندوبين والتغطية
        $link = route('rep-supervisor.pharmacies.show', $this->center);
        $this->actingAs($this->sup1)->get(route('rep-supervisor.reps.show', ['rep' => $this->karam, 'tab' => 'receivables']))->assertOk()->assertSee($link);
        $this->actingAs($this->sup1)->get(route('rep-supervisor.reps.show', ['rep' => $this->karam, 'tab' => 'payments']))->assertOk()->assertSee($link);
        $this->actingAs($this->sup1)->get(route('finance.rep-receivables'))->assertOk()->assertSee($link);
        $this->actingAs($this->sup1)->get(route('finance.rep-payments', ['status' => 'pending']))->assertOk()->assertSee($link);
        $this->actingAs($this->admin)->get(route('finance.rep-receivables'))->assertOk()->assertSee($link);
        // لا رابط للمحاسب (لا وصول له)، ولا رابط لصيدلية خارج نطاق المشرف
        $this->actingAs($this->accountant)->get(route('finance.rep-receivables'))->assertOk()->assertDontSee('rep-supervisor/pharmacies', false);
        $this->actingAs($this->sup1)->get(route('finance.rep-receivables'))->assertDontSee(route('rep-supervisor.pharmacies.show', $this->edge));
    }

    // =====================================================================
    // 10) الجوال
    // =====================================================================

    public function test_mobile_layout_uses_stacked_cards_without_wide_tables(): void
    {
        $html = $this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'period' => 'all']))->assertOk()->getContent();
        $region = $this->region($this->actingAs($this->karam)->get(route('rep.pharmacies.show', ['customer' => $this->center, 'period' => 'all'])));
        $this->assertStringContainsString('@media (max-width: 767.98px)', $html);
        $this->assertStringContainsString('#pharmacy-statement .ps-stack tr { display: block;', $html);
        $this->assertStringContainsString('@media print', $html);
        // كل جداول الكشف «مكدّسة» على الجوال، ولا table-responsive (تمرير أفقي) ولا عرض ثابت
        $this->assertSame(substr_count($region, '<table'), substr_count($region, 'ps-stack'));
        $this->assertStringNotContainsString('table-responsive', $region);
        $this->assertDoesNotMatchRegularExpression('/min-width:\s*\d{3,}px/', $region);
        // كل خلية بيانات معنونة لعرض البطاقة
        preg_match_all('/<td(?![^>]*colspan)[^>]*>/', $region, $cells);
        foreach ($cells[0] as $td) {
            $this->assertStringContainsString('data-label=', $td);
        }
        $this->assertStringContainsString('touch-ui', $html);
    }

    // =====================================================================
    // 11) الأداء
    // =====================================================================

    public function test_query_count_is_constant_from_30_to_300_invoices_with_five_reps(): void
    {
        $reps = collect(range(1, 5))->map(fn ($n) => $this->rep('مندوب '.$n, (string) (800 + $n)));
        $big = Customer::create(['name' => 'صيدلية كبيرة', 'area' => 'منطقة الوسط', 'balance' => 50000]);
        $small = Customer::create(['name' => 'صيدلية صغيرة', 'area' => 'منطقة الوسط', 'balance' => 5000]);
        $sheets = [$this->s1, $this->s2, $this->s4];
        foreach ([[$big, 300], [$small, 30]] as [$customer, $count]) {
            $rows = [];
            for ($n = 0; $n < $count; $n++) {
                $rep = $reps[$n % 5];
                $sheet = $sheets[$n % 3];
                $number = 'ع G: '.(900000 + $customer->id * 1000 + $n);
                $rows[] = [
                    'distribution_sheet_id' => $sheet->id, 'invoice_number' => $number, 'series' => 'G', 'customer_name' => $customer->name,
                    'customer_id' => $customer->id, 'invoice_amount' => 100 + $n, 'delivery_date' => $sheet->distribution_date->toDateString(),
                    'placement' => Invoice::IN_SHEET, 'is_deferred' => false, 'invoice_key' => \App\Support\InvoiceFingerprint::keyFromNumber($number),
                    'cost_center_code' => $rep->cost_center_code, 'cost_center_name' => $rep->name, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            foreach (array_chunk($rows, 100) as $chunk) {
                Invoice::insert($chunk);
            }
            foreach ($reps as $rep) {
                $this->repPayment($rep, $customer, 50);
            }
            $this->distributorPay($this->s1, $customer, 700);
        }
        $this->assertSame(300, Invoice::where('customer_id', $big->id)->count());

        $count = function (User $viewer, Customer $customer, string $route) {
            $this->actingAs($viewer)->get(route($route, $customer))->assertOk();   // إحماء
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($viewer)->get(route($route, ['customer' => $customer, 'period' => 'all']))->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $adminBig = $count($this->admin, $big, 'rep-supervisor.pharmacies.show');
        $adminSmall = $count($this->admin, $small, 'rep-supervisor.pharmacies.show');
        $this->assertSame($adminSmall, $adminBig, 'الأدمن: عدد الاستعلامات يتغير بعدد الفواتير');
        $repBig = $count($reps[0], $big, 'rep.pharmacies.show');
        $repSmall = $count($reps[0], $small, 'rep.pharmacies.show');
        $this->assertSame($repSmall, $repBig, 'المندوب: عدد الاستعلامات يتغير بعدد الفواتير');
        $this->assertLessThan(80, $adminBig);

        // الترقيم: 25 فاتورة في الصفحة
        $region = $this->region($this->actingAs($this->admin)->get(route('rep-supervisor.pharmacies.show', ['customer' => $big, 'period' => 'all'])));
        $this->assertSame(PharmacyStatementService::PER_PAGE, substr_count($region, 'ps-invoice-row'));
        $page2 = $this->region($this->actingAs($this->admin)->get(route('rep-supervisor.pharmacies.show', ['customer' => $big, 'period' => 'all', 'page' => 12])));
        $this->assertSame(300 - 11 * PharmacyStatementService::PER_PAGE, substr_count($page2, 'ps-invoice-row'));
    }

    // =====================================================================
    // 12) عرض فقط
    // =====================================================================

    public function test_viewing_writes_nothing(): void
    {
        $this->assignSupervisors();
        $tables = ['payments', 'payment_allocations', 'invoices', 'customers', 'activity_logs', 'settings'];
        $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()]);
        $this->actingAs($this->karam)->get(route('rep.pharmacies.show', $this->center))->assertOk();
        $this->actingAs($this->karam)->get(route('rep.pharmacies.index'))->assertOk();
        $this->actingAs($this->sup1)->get(route('rep-supervisor.pharmacies.show', $this->center))->assertOk();
        $this->actingAs($this->admin)->get(route('rep-supervisor.pharmacies.show', ['customer' => $this->center, 'mode' => 'pharmacy']))->assertOk();
        foreach ($tables as $t) {
            $this->assertSame($before[$t], DB::table($t)->get()->toJson(), $t);
        }
        $this->assertNotNull(CostCenter::normalizeCode('111'));
    }
}
