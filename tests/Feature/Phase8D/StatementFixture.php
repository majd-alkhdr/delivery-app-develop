<?php

namespace Tests\Feature\Phase8D;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Reps\RepScope;
use App\Support\InvoiceFingerprint;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * سيناريو المرحلة 8D: «صيدلية المركز» (منطقة الوسط) يبيعها 3 مندوبين:
 * - كرم (111): G:101=1000 [S1]، G:201=700 [S2]، G:301=555 [S3 قيد التسليم]، G:402=250 [S4 قديم].
 * - سامر (222): G:102=2345.67 [S1]، G:202=987.65 [S2]، G:401=1234.56 [S4 قديم].
 * - نادر (333): G:103=1543.21 [S1].
 * - بلا مركز: G:104=400 [S1]؛ مركز غريب 999: G:105=300 [S1].
 * الكشوف: S1 مكتمل (قبل 10 أيام)، S2 مكتمل (قبل 5 أيام)، S3 قيد التحصيل (أمس)، S4 مكتمل (قبل 200 يوم).
 * الدفعات: موزع 800 على S2 (مُصدَّرة عبر FinanceService)، موزع 1000 على S1 (معلّقة)، كرم 300، سامر 500 (معلّقتان).
 * «صيدلية الطرف» (منطقة الطرف): كرم G:501=600، سامر G:502=650 [S1].
 */
trait StatementFixture
{
    protected User $admin;
    protected User $accountant;
    protected User $supervisor;
    protected User $manager;
    protected User $distributor;
    protected User $sup1;
    protected User $sup2;
    protected User $karam;
    protected User $samer;
    protected User $nader;
    protected Customer $center;
    protected Customer $edge;
    protected DistributionSheet $s1;
    protected DistributionSheet $s2;
    protected DistributionSheet $s3;
    protected DistributionSheet $s4;
    protected Payment $distExported;
    protected Payment $distPending;
    protected Payment $karamPay;
    protected Payment $samerPay;

    protected function setUpStatement(bool $export = true): void
    {
        $this->admin = User::factory()->role(Role::Admin)->create(['name' => 'المدير']);
        $this->accountant = User::factory()->role(Role::Accountant)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->distributor = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع وسيم']);
        $this->sup1 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف أحمد']);
        $this->sup2 = User::factory()->role(Role::RepSupervisor)->create(['name' => 'المشرف باسل']);
        $this->karam = $this->rep('كرم', '111');
        $this->samer = $this->rep('سامر', '222');
        $this->nader = $this->rep('نادر', '333');

        $this->center = Customer::create(['name' => 'صيدلية المركز', 'area' => 'منطقة الوسط', 'block' => 'كتلة 1', 'balance' => 10000, 'balance_as_of' => now('Asia/Damascus')->subDay()->toDateString()]);
        $this->edge = Customer::create(['name' => 'صيدلية الطرف', 'area' => 'منطقة الطرف', 'block' => 'كتلة 2', 'balance' => 1250, 'balance_as_of' => now('Asia/Damascus')->subDay()->toDateString()]);

        $this->s1 = $this->sheet('completed', 10, 5001);
        $this->s2 = $this->sheet('completed', 5, 5002);
        $this->s3 = $this->sheet('in_progress', 1, 5003);
        $this->s4 = $this->sheet('completed', 200, 4000);

        $this->invoice($this->s1, 'G: 101', 1000, $this->karam);
        $this->invoice($this->s1, 'G: 102', 2345.67, $this->samer);
        $this->invoice($this->s1, 'G: 103', 1543.21, $this->nader);
        $this->invoice($this->s1, 'G: 104', 400, null);
        $this->invoice($this->s1, 'G: 105', 300, '999-مركز غريب');
        $this->invoice($this->s2, 'G: 201', 700, $this->karam);
        $this->invoice($this->s2, 'G: 202', 987.65, $this->samer);
        $this->invoice($this->s3, 'G: 301', 555, $this->karam);
        $this->invoice($this->s4, 'G: 401', 1234.56, $this->samer);
        $this->invoice($this->s4, 'G: 402', 250, $this->karam);
        $this->invoice($this->s1, 'G: 501', 600, $this->karam, $this->edge);
        $this->invoice($this->s1, 'G: 502', 650, $this->samer, $this->edge);

        $this->distExported = $this->distributorPay($this->s2, $this->center, 800);
        if ($export) {
            app(FinanceService::class)->export($this->s2, $this->admin);
            $this->distExported->refresh();
        }
        $this->distPending = $this->distributorPay($this->s1, $this->center, 1000);
        $this->karamPay = $this->repPayment($this->karam, $this->center, 300);
        $this->samerPay = $this->repPayment($this->samer, $this->center, 500);
    }

    protected function rep(string $name, string $code): User
    {
        return User::factory()->role(Role::Rep)->create(['name' => $name, 'cost_center_code' => $code, 'cost_center_label' => $code.'-'.$name]);
    }

    protected function sheet(string $status, int $daysAgo, int $order): DistributionSheet
    {
        return DistributionSheet::create([
            'format' => 'v2', 'file_name' => 'x.xlsx', 'status' => $status, 'delivery_order_no' => (string) $order,
            'supervisor_id' => $this->supervisor->id, 'distributor_id' => $this->distributor->id,
            'distribution_date' => now('Asia/Damascus')->subDays($daysAgo)->toDateString(),
        ]);
    }

    /**
     * @param  User|string|null  $center  مندوب، أو نص مركز «كود-اسم»، أو null (بلا مركز)
     */
    protected function invoice(?DistributionSheet $sheet, string $label, float $amount, User|string|null $center, ?Customer $customer = null, ?string $placement = null): Invoice
    {
        $customer ??= $this->center;
        $number = 'ع '.$label;
        [$code, $name] = match (true) {
            $center instanceof User => [$center->cost_center_code, $center->name],
            is_string($center) => explode('-', $center, 2),
            default => [null, null],
        };

        return Invoice::create([
            'distribution_sheet_id' => $sheet?->id, 'invoice_number' => $number, 'series' => 'G',
            'customer_name' => $customer->name, 'customer_id' => $customer->id, 'invoice_amount' => $amount,
            'delivery_date' => $sheet?->distribution_date?->toDateString() ?? now('Asia/Damascus')->subDays(3)->toDateString(),
            'placement' => $placement ?? ($sheet ? Invoice::IN_SHEET : Invoice::DEFERRED),
            'invoice_key' => InvoiceFingerprint::keyFromNumber($number), 'cost_center_code' => $code, 'cost_center_name' => $name,
        ]);
    }

    protected function distributorPay(DistributionSheet $sheet, Customer $customer, float $amount): Payment
    {
        return Payment::create([
            'client_uuid' => (string) Str::uuid(), 'receipt_number' => 'D-'.Str::random(8),
            'distribution_sheet_id' => $sheet->id, 'customer_id' => $customer->id, 'entered_by' => $this->distributor->id, 'entered_role' => 'distributor',
            'source' => 'sheet', 'amount' => $amount, 'currency' => 'SYP', 'amount_syp' => $amount, 'status' => 'pending',
            'location_flag' => 'no_gps', 'collected_at' => now(),
        ]);
    }

    protected function repPayment(User $rep, Customer $customer, float $amount): Payment
    {
        return Payment::create([
            'client_uuid' => (string) Str::uuid(), 'receipt_number' => 'R-'.Str::random(8),
            'customer_id' => $customer->id, 'entered_by' => $rep->id, 'entered_role' => 'rep',
            'source' => 'rep', 'amount' => $amount, 'currency' => 'SYP', 'amount_syp' => $amount, 'status' => 'pending',
            'book_receipt_no' => (string) random_int(1000, 999999), 'cost_center_code' => $rep->cost_center_code, 'cost_center_label' => $rep->cost_center_label,
            'location_flag' => 'no_gps', 'collected_at' => now(),
        ]);
    }

    /**
     * كرم مشترك: منطقة الوسط عند المشرف أحمد، ومنطقة الطرف عند المشرف باسل. سامر: منطقة الوسط عند أحمد. نادر بلا مشرف.
     */
    protected function assignSupervisors(): void
    {
        $this->assign($this->karam, $this->sup1, ['منطقة الوسط'])->assertSessionHasNoErrors();
        $this->assign($this->karam, $this->sup2, ['منطقة الطرف'])->assertSessionHasNoErrors();
        $this->assign($this->samer, $this->sup1, ['منطقة الوسط'])->assertSessionHasNoErrors();
    }

    protected function assign(User $rep, User $supervisor, array $areas): TestResponse
    {
        return $this->actingAs($this->admin)->from(route('admin.rep-assignments.index'))->post(route('admin.rep-assignments.store'), [
            'rep_id' => $rep->id, 'supervisor_id' => $supervisor->id, 'scope_type' => 'area', 'scope_values' => $areas,
        ]);
    }

    protected function setScoping(bool $on): void
    {
        Setting::set(RepScope::SETTING, $on);
    }

    /**
     * محتوى الكشف وحده (بلا شريط التنقل الذي يحمل اسم المستخدم الحالي).
     */
    protected function region(TestResponse $response): string
    {
        $html = $response->assertOk()->getContent();
        $start = strpos($html, 'id="pharmacy-statement"');
        $this->assertNotFalse($start, 'لا يوجد قسم الكشف');
        $end = strpos($html, '<script', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    protected static function money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}
