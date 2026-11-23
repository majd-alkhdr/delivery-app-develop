<?php

namespace Tests\Feature\Phase6;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\RepFinanceService;
use App\Support\ArabicNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * سيناريو المندوب (المرحلة 6):
 * - المندوب rep1 بمركز «655231-كرم الخالدي»، وrep2 بمركز «700700-سامر».
 * - الكشف A (2026-10-10، أمر 3910، مكتمل): الشمال G:11=1000 وG:12=500 [rep1]، A:13=300 [كول سنتر]،
 *   G:14=0 [rep1]، G:15=700 مؤجلة [rep1]؛ الجنوب B:21=800 بكود عربي «٦٥٥٢٣١-كرم خالدي» [rep1]؛ الشرق G:22=600 [rep2].
 * - الكشف B (2026-10-12، أمر 3912، مكتمل): الشمال G:31=400 [rep1].
 * - الكشف C (2026-10-13، أمر 3913، غير مغلق): الشمال G:41=999 [rep1] ⇒ لا دَين.
 */
trait RepFixture
{
    protected User $admin;
    protected User $supervisor;
    protected User $accountant;
    protected User $repSupervisor;
    protected User $manager;
    protected User $d1;
    protected User $rep1;
    protected User $rep2;
    protected DistributionSheet $sheetA;
    protected DistributionSheet $sheetB;
    protected DistributionSheet $sheetC;
    protected Customer $north;
    protected Customer $south;
    protected Customer $east;

    protected function setUpReps(): void
    {
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
        $this->repSupervisor = User::factory()->role(Role::RepSupervisor)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->d1 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع الأول']);
        $this->rep1 = User::factory()->role(Role::Rep)->create(['name' => 'كرم', 'cost_center_code' => '655231', 'cost_center_label' => '655231-كرم الخالدي']);
        $this->rep2 = User::factory()->role(Role::Rep)->create(['name' => 'سامر', 'cost_center_code' => '700700', 'cost_center_label' => '700700-سامر']);

        $balances = [
            ['صيدلية الشمال', 'كتلة', 'منطقة الشمال', 98765.43, 0],
            ['صيدلية الجنوب', 'كتلة', 'منطقة الجنوب', 5000, 0],
            ['صيدلية الشرق', 'كتلة', 'منطقة الشرق', 600, 0],
        ];
        $rep1 = '655231-كرم الخالدي';
        $this->sheetA = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 11', 'customer' => 'صيدلية الشمال', 'cc' => $rep1, 'order' => 3910, 'delivery' => '10-10-2026', 'lines' => [['صنف', 1000]]],
            ['number' => 'ع G: 12', 'customer' => 'صيدلية الشمال', 'cc' => $rep1, 'order' => 3910, 'delivery' => '10-10-2026', 'lines' => [['صنف', 500]]],
            ['number' => 'ع A: 13', 'customer' => 'صيدلية الشمال', 'order' => 3910, 'delivery' => '10-10-2026', 'lines' => [['صنف', 300]]],
            ['number' => 'ع G: 14', 'customer' => 'صيدلية الشمال', 'cc' => $rep1, 'order' => 3910, 'delivery' => '10-10-2026', 'lines' => [['هدية', 0]]],
            ['number' => 'ع G: 15', 'customer' => 'صيدلية الشمال', 'cc' => $rep1, 'order' => 3910, 'delivery' => '20-10-2026', 'lines' => [['مؤجل', 700]]],
            ['number' => 'ع B: 21', 'customer' => 'صيدلية الجنوب', 'cc' => '٦٥٥٢٣١-كرم خالدي', 'order' => 3910, 'delivery' => '10-10-2026', 'lines' => [['صنف', 800]]],
            ['number' => 'ع G: 22', 'customer' => 'صيدلية الشرق', 'cc' => '700700-سامر', 'order' => 3910, 'delivery' => '10-10-2026', 'lines' => [['صنف', 600]]],
        ], $balances, '10-10-2026'), '2026-10-10');
        $this->sheetB = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 31', 'customer' => 'صيدلية الشمال', 'cc' => $rep1, 'order' => 3912, 'delivery' => '12-10-2026', 'lines' => [['صنف', 400]]],
        ], $balances, '12-10-2026'), '2026-10-12');
        $this->sheetC = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 41', 'customer' => 'صيدلية الشمال', 'cc' => $rep1, 'order' => 3913, 'delivery' => '13-10-2026', 'lines' => [['صنف', 999]]],
        ], $balances, '13-10-2026'), '2026-10-13');

        foreach ([$this->sheetA, $this->sheetB, $this->sheetC] as $sheet) {
            $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['all_distributor_id' => $this->d1->id])->assertSessionHasNoErrors();
        }
        $this->sheetA->update(['status' => 'completed']);
        $this->sheetB->update(['status' => 'completed']);

        $this->north = Customer::where('name', 'صيدلية الشمال')->firstOrFail();
        $this->south = Customer::where('name', 'صيدلية الجنوب')->firstOrFail();
        $this->east = Customer::where('name', 'صيدلية الشرق')->firstOrFail();
    }

    /**
     * دفعة موزع مباشرة (pending) في كشف.
     */
    protected function distributorPay(DistributionSheet $sheet, Customer $customer, float $amount): Payment
    {
        return Payment::create([
            'client_uuid' => (string) Str::uuid(), 'receipt_number' => 'D-20261010-'.Str::random(6),
            'distribution_sheet_id' => $sheet->id, 'customer_id' => $customer->id, 'entered_by' => $this->d1->id, 'entered_role' => 'distributor',
            'source' => 'sheet', 'amount' => $amount, 'currency' => 'SYP', 'amount_syp' => $amount, 'status' => 'pending',
            'location_flag' => 'no_gps', 'collected_at' => now(),
        ]);
    }

    protected function receiptImage(string $name = 'receipt.jpg', int $w = 800, int $h = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    /**
     * تحصيل مندوب عبر الواجهة (multipart مع الصورة افتراضياً).
     */
    protected function repPay(User $rep, Customer $customer, float $amount, array $extra = [], bool $withImage = true): TestResponse
    {
        $data = $extra + [
            'client_uuid' => (string) Str::uuid(),
            'customer_id' => $customer->id,
            'amount' => $amount,
            'currency' => 'SYP',
            'book_receipt_no' => (string) random_int(1000, 999999),
            'form_version' => 6,
        ];
        if ($withImage && ! array_key_exists('image', $data)) {
            $data['image'] = $this->receiptImage();
        }

        return $this->actingAs($rep)->post(route('rep.payments.store'), $data, ['Accept' => 'application/json']);
    }

    protected function repSignature(?array $reps = null, array $include = []): string
    {
        return app(RepFinanceService::class)->preview($reps, $include)['signature'];
    }

    protected function repExport(User $user, array $reps, array $include = [], ?string $signature = null): TestResponse
    {
        return $this->actingAs($user)->post(route('finance.rep-payments.export'), [
            'reps' => $reps,
            'include' => $include,
            'signature' => $signature ?? $this->repSignature($reps, $include),
            'confirm' => 1,
        ]);
    }

    protected function northArea(): string
    {
        return ArabicNormalizer::normalize('منطقة الشمال');
    }
}
