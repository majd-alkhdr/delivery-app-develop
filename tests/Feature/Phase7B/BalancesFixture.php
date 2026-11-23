<?php

namespace Tests\Feature\Phase7B;

use App\Enums\Role;
use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Import\SheetImportService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * سيناريو المرحلة 7B: «صيدلية التجربة» (منطقة التجربة) بفاتورتين G:1=1000 (مندوب A) وG:2=500 (مندوب B) في أمر 1580،
 * ورصيدها 5000 بتاريخ 13-10-2026. ثلاث صيدليات أخرى برصيد حتى لا يتجاوز تصفير زبون واحد نسبة 30%.
 */
trait BalancesFixture
{
    protected User $admin;
    protected User $manager;
    protected User $supervisor;
    protected User $accountant;
    protected User $repSupervisor;
    protected User $d1;
    protected User $repA;
    protected User $repB;
    protected DistributionSheet $sheet;
    protected Customer $pharmacy;

    protected function setUpBalances(): void
    {
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
        $this->repSupervisor = User::factory()->role(Role::RepSupervisor)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع']);
        $this->repA = User::factory()->role(Role::Rep)->create(['name' => 'مندوب أ', 'cost_center_code' => '655231', 'cost_center_label' => '655231-مندوب أ']);
        $this->repB = User::factory()->role(Role::Rep)->create(['name' => 'مندوب ب', 'cost_center_code' => '700700', 'cost_center_label' => '700700-مندوب ب']);
    }

    protected function others(): array
    {
        return ['صيدلية ثانية' => 2000, 'صيدلية ثالثة' => 3000, 'صيدلية رابعة' => 4000];
    }

    protected function dailyFile(float $g1 = 1000, ?array $balances = null, string $asOf = '13-10-2026', array $extra = []): string
    {
        $balances ??= ['صيدلية التجربة' => 5000] + $this->others();

        return $this->buildWorkbook(array_merge([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية التجربة', 'cc' => '655231-مندوب أ', 'order' => 1580, 'lines' => [['صنف أ', $g1]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية التجربة', 'cc' => '700700-مندوب ب', 'order' => 1580, 'lines' => [['صنف ب', 500]]],
        ], $extra), array_map(fn ($name, $v) => [$name, 'كتلة', 'منطقة التجربة', $v, 0], array_keys($balances), $balances), $asOf);
    }

    /** ملف أرصدة فقط؛ $asOf = null ⇒ بلا نص «ولغاية» */
    protected function balancesFile(array $balances, ?string $asOf = '13-10-2026'): string
    {
        $spreadsheet = new Spreadsheet;
        $rows = [['أرصدة الزبائن'], ['x'], [$asOf ? "اعتباراً من  1-1-2023 ولغاية  {$asOf}" : 'أرصدة بلا تاريخ'], ['اسم العميل', 'الكتلة', 'المنطقة', 'مدين', 'دائن']];
        foreach ($balances as $name => $v) {
            $rows[] = [$name, 'كتلة', 'منطقة التجربة', $v, 0];
        }
        $spreadsheet->getActiveSheet()->setTitle('الأرصدة')->fromArray($rows, null, 'A1', true);

        return $this->save($spreadsheet);
    }

    protected function upload(string $path, ?User $user = null, ?string $manualAsOf = null): array
    {
        return app(SheetImportService::class)->importBatch([$this->uploaded($path, basename($path))], '2026-10-13', $user ?? $this->manager, false, $manualAsOf);
    }

    /** الرفع الأول، الإرسال، الإسناد للموزع */
    protected function publish(): void
    {
        $result = $this->upload($this->dailyFile());
        app(\App\Services\SheetDispatchService::class)->sendBatch($result['batch'], $this->manager);
        $this->sheet = DistributionSheet::where('delivery_order_no', '1580')->sole();
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['all_distributor_id' => $this->d1->id])->assertSessionHasNoErrors();
        $this->pharmacy = Customer::where('name', 'صيدلية التجربة')->sole();
    }

    protected function pay(float $amount): Payment
    {
        $this->payApi($this->d1, $this->sheet, $this->pharmacy->id, $amount)->assertSuccessful();

        return Payment::latest('id')->first();
    }

    protected function exportSheet(): void
    {
        app(FinanceService::class)->export($this->sheet->fresh(), $this->accountant);
    }

    protected function latestImport(): BalanceImport
    {
        return BalanceImport::latest('id')->first();
    }

    protected function net(?Customer $customer = null): float
    {
        return app(\App\Services\CustomerBalanceService::class)->currentBalance(($customer ?? $this->pharmacy)->fresh(), $this->sheet->fresh())->net;
    }
}
