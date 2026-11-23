<?php

namespace Tests\Feature\Phase5;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Support\ArabicNormalizer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * كشف مالية مشترك لاختبارات المرحلة 5 (أمر التسليم 3918، تاريخ 2026-10-13):
 * - الشمال (d1): M:101 = 1000 [655231-كرم الخالدي]، ارجيفيت:201 = 600، G:301 = 400، G:302 = 0 (هدية)، G:303 = 2000 مؤجلة.
 * - الجنوب (d2): G:401 = 2222 [700100-الجنوب]؛ وصيدلية بلا زيارة (d2): B:501 = 777 [700100-الجنوب].
 */
trait FinanceFixture
{
    protected User $admin;
    protected User $supervisor;
    protected User $accountant;
    protected User $repSupervisor;
    protected User $manager;
    protected User $d1;
    protected User $d2;
    protected DistributionSheet $sheet;
    protected Customer $north;
    protected Customer $south;
    protected Customer $unvisited;

    private int $receiptSeq = 0;

    protected function setUpFinance(): void
    {
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
        $this->repSupervisor = User::factory()->role(Role::RepSupervisor)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع الأول']);
        $this->d2 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع الثاني']);

        $this->sheet = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع M: 101', 'customer' => 'صيدلية الشمال', 'cc' => '655231-كرم الخالدي', 'lines' => [['حليب', 1000]]],
            ['number' => 'ارجيفيت: 201', 'customer' => 'صيدلية الشمال', 'lines' => [['ارجيفيت', 600]]],
            ['number' => 'ع G: 301', 'customer' => 'صيدلية الشمال', 'lines' => [['صنف', 400]]],
            ['number' => 'ع G: 302', 'customer' => 'صيدلية الشمال', 'lines' => [['هدية', 0]]],
            ['number' => 'ع G: 303', 'customer' => 'صيدلية الشمال', 'delivery' => '20-10-2026', 'lines' => [['مؤجل', 2000]]],
            ['number' => 'ع G: 401', 'customer' => 'صيدلية الجنوب', 'cc' => '700100-الجنوب', 'lines' => [['صنف', 2222]]],
            ['number' => 'ع B: 501', 'customer' => 'صيدلية بلا زيارة', 'cc' => '700100-الجنوب', 'lines' => [['صنف', 777]]],
        ], [
            ['صيدلية الشمال', 'كتلة', 'منطقة الشمال', 98765.43, 0],
            ['صيدلية الجنوب', 'كتلة', 'منطقة الجنوب', 5000, 0],
            ['صيدلية بلا زيارة', 'كتلة', 'منطقة الجنوب', 777, 0],
        ]));

        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $this->sheet), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة الشمال'), 'distributor_id' => $this->d1->id],
            ['area_key' => ArabicNormalizer::normalize('منطقة الجنوب'), 'distributor_id' => $this->d2->id],
        ]])->assertSessionHasNoErrors();

        $this->north = Customer::where('name', 'صيدلية الشمال')->firstOrFail();
        $this->south = Customer::where('name', 'صيدلية الجنوب')->firstOrFail();
        $this->unvisited = Customer::where('name', 'صيدلية بلا زيارة')->firstOrFail();
    }

    /**
     * دفعة مباشرة في الكشف (pending).
     */
    protected function pay(Customer $customer, float $amount, string $currency = 'SYP', ?float $rate = null, ?User $by = null, ?DistributionSheet $sheet = null): Payment
    {
        $by ??= $this->d1;
        $sheet ??= $this->sheet;
        $this->receiptSeq++;

        return Payment::create([
            'client_uuid' => (string) Str::uuid(),
            'receipt_number' => 'TST-20261013-'.$this->receiptSeq,
            'distribution_sheet_id' => $sheet->id,
            'customer_id' => $customer->id,
            'entered_by' => $by->id,
            'entered_role' => $by->role->value,
            'source' => 'sheet',
            'amount' => $amount,
            'currency' => $currency,
            'exchange_rate' => $rate,
            'amount_syp' => $currency === 'SYP' ? $amount : ($rate !== null ? round($amount * $rate, 2) : null),
            'rate_is_estimate' => $currency === 'USD' && $rate === null,
            'status' => 'pending',
            'location_flag' => 'no_gps',
            'collected_at' => now(),
        ]);
    }

    protected function signature(?DistributionSheet $sheet = null): string
    {
        return app(FinanceService::class)->preview(($sheet ?? $this->sheet)->fresh())['signature'];
    }

    protected function exportAs(User $user, ?DistributionSheet $sheet = null, ?string $signature = null): TestResponse
    {
        $sheet ??= $this->sheet;

        return $this->actingAs($user)->post(route('finance.sheets.export', $sheet), [
            'signature' => $signature ?? $this->signature($sheet),
            'confirm' => 1,
        ]);
    }

    protected function book(TestResponse $response): Spreadsheet
    {
        $response->assertOk();

        return IOFactory::load($response->baseResponse->getFile()->getPathname());
    }

    /**
     * صفوف الورقة الأولى كما هي (بلا الترويسة).
     */
    protected function rows(Spreadsheet $book): array
    {
        $rows = $book->getSheet(0)->toArray(null, false, false, false);
        array_shift($rows);

        return $rows;
    }

    protected function fileName(TestResponse $response): string
    {
        preg_match("/filename\\*=UTF-8''([^;]+)/i", (string) $response->headers->get('content-disposition'), $m);

        return rawurldecode($m[1] ?? '');
    }
}
