<?php

namespace Tests\Feature\Phase2C;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\DistributionSheet;
use App\Models\User;
use App\Services\SheetExportNamer;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * ملحق 2C: اسم ملف تصدير الكشف حسب دور من يصدّره، بجانب رقم أمر التسليم.
 */
class ExportNamingTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $admin;
    private User $manager;
    private User $supervisor;
    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->role(Role::Admin)->create();
        $this->manager = User::factory()->role(Role::SheetManager)->create();
        $this->supervisor = User::factory()->role(Role::Supervisor)->create();
        $this->accountant = User::factory()->role(Role::Accountant)->create();
    }

    /** اسم الملف من filename*=UTF-8'' في الترويسة */
    private function fileName(TestResponse $response): string
    {
        $response->assertOk();
        $header = $response->headers->get('content-disposition');
        $this->assertStringStartsWith('attachment;', $header);
        // RFC 5987: اسم الترميز غير حساس لحالة الأحرف (Symfony يكتبه utf-8)
        $this->assertMatchesRegularExpression("/filename\\*=UTF-8''([^;]+)/i", $header);
        preg_match("/filename\\*=UTF-8''([^;]+)/i", $header, $m);

        return rawurldecode($m[1]);
    }

    private function export(User $user, DistributionSheet $sheet, array $params = []): TestResponse
    {
        return $this->actingAs($user)->get(route('sheets.export', ['sheet' => $sheet] + $params));
    }

    /**
     * كشف بثلاث مناطق في ثلاث كتل.
     */
    private function threeAreaSheet(): DistributionSheet
    {
        return $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 200]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية جيم', 'lines' => [['صنف', 300]]],
        ], [
            ['صيدلية ألف', 'كتلة الشمال', 'منطقة أ', 10, 0],
            ['صيدلية باء', 'كتلة الجنوب', 'منطقة ب', 10, 0],
            ['صيدلية جيم', 'كتلة الوسط', 'منطقة ج', 10, 0],
        ]));
    }

    private function assignAreas(DistributionSheet $sheet, array $areaToDistributor): void
    {
        $rows = [];
        foreach ($areaToDistributor as $area => $distributor) {
            $rows[] = ['area_key' => ArabicNormalizer::normalize($area), 'distributor_id' => $distributor->id];
        }
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['rows' => $rows])->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------------
    // المحاسب: اسم الموزع
    // ------------------------------------------------------------------

    public function test_accountant_gets_distributor_name_with_order_and_rfc5987_header(): void
    {
        $sheet = $this->publishWorkbook($this->fixturePath());
        $d = User::factory()->role(Role::Distributor)->create(['name' => 'سامر الحلبي']);
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['all_distributor_id' => $d->id]);

        $response = $this->export($this->accountant, $sheet);

        $this->assertSame('سامر الحلبي - 3918.xlsx', $this->fileName($response));
        $this->assertStringContainsString('filename=sheet_3918.xlsx', $response->headers->get('content-disposition'));

        // سطر العنوان داخل الملف يعرض الاسم نفسه
        $book = IOFactory::load($response->baseResponse->getFile()->getPathname());
        $this->assertSame('كشف توزيع — أمر التسليم #3918 — الموزع: سامر الحلبي', $book->getSheetByName('الكشف')->getCell('A1')->getValue());

        $log = ActivityLog::where('action', 'sheet.exported')->latest('id')->first();
        $this->assertSame('سامر الحلبي - 3918.xlsx', $log->new_values['file_name']);
    }

    public function test_accountant_two_three_filtered_and_unassigned_names(): void
    {
        $sheet = $this->threeAreaSheet();
        [$a, $b, $c] = [
            User::factory()->role(Role::Distributor)->create(['name' => 'أحمد']),
            User::factory()->role(Role::Distributor)->create(['name' => 'بلال']),
            User::factory()->role(Role::Distributor)->create(['name' => 'جمال']),
        ];

        // بلا إسناد: قاعدة الكتلة/المنطقة (أول كتلة بترتيب الصفوف: المنطقة ثم الاسم)
        $this->assertSame('كتلة الشمال - 3918.xlsx', $this->fileName($this->export($this->accountant, $sheet)));

        // موزعان
        $this->assignAreas($sheet, ['منطقة أ' => $a, 'منطقة ب' => $b, 'منطقة ج' => $b]);
        $this->assertSame('أحمد + بلال - 3918.xlsx', $this->fileName($this->export($this->accountant, $sheet)));

        // ثلاثة: الأول والثاني ثم +N
        $this->assignAreas($sheet, ['منطقة أ' => $a, 'منطقة ب' => $b, 'منطقة ج' => $c]);
        $this->assertSame('أحمد + بلال +1 - 3918.xlsx', $this->fileName($this->export($this->accountant, $sheet)));

        // مصفّى بموزع: اسمه وحده
        $this->assertSame('جمال - 3918.xlsx', $this->fileName($this->export($this->accountant, $sheet, ['distributor_id' => $c->id])));
    }

    // ------------------------------------------------------------------
    // المشرف ومسؤول الكشوف والأدمن: الكتلة أو المنطقة
    // ------------------------------------------------------------------

    public function test_staff_roles_get_block_or_area_never_distributor_name(): void
    {
        $sheet = $this->threeAreaSheet();
        $d = User::factory()->role(Role::Distributor)->create(['name' => 'بلال']);
        $this->assignAreas($sheet, ['منطقة ب' => $d]);

        foreach ([$this->supervisor, $this->manager, $this->admin] as $user) {
            $this->assertSame('كتلة الشمال - 3918.xlsx', $this->fileName($this->export($user, $sheet)), $user->role->value);
            // مصفّى بمنطقة: اسم المنطقة
            $this->assertSame('منطقة ج - 3918.xlsx', $this->fileName($this->export($user, $sheet, ['area_key' => ArabicNormalizer::normalize('منطقة ج')])));
            // مصفّى بموزع: الكتلة/المنطقة لا اسم الموزع
            $this->assertSame('كتلة الجنوب - 3918.xlsx', $this->fileName($this->export($user, $sheet, ['distributor_id' => $d->id])));
        }

        $book = IOFactory::load($this->export($this->supervisor, $sheet)->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('الكتلة/المنطقة: كتلة الشمال', $book->getSheetByName('الكشف')->getCell('A1')->getValue());
    }

    public function test_falls_back_to_first_area_then_no_area(): void
    {
        $noBlock = $this->publishWorkbook($this->buildWorkbook(
            [['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]],
            [['صيدلية ألف', null, 'منطقة الميدان', 10, 0]]
        ));
        $this->assertSame('منطقة الميدان - 3918.xlsx', $this->fileName($this->export($this->supervisor, $noBlock)));

        $nothing = $this->publishWorkbook($this->buildWorkbook(
            [['number' => 'ع G: 7', 'customer' => 'صيدلية بلا بيانات', 'order' => 4000, 'lines' => [['صنف', 100]]]]
        ));
        $this->assertSame('بلا منطقة - 4000.xlsx', $this->fileName($this->export($this->supervisor, $nothing)));
    }

    public function test_empty_delivery_order_uses_sheet_id(): void
    {
        $sheet = $this->publishWorkbook($this->buildWorkbook([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $sheet->update(['delivery_order_no' => null]);

        $response = $this->export($this->supervisor, $sheet);
        $this->assertSame("بلا منطقة - #{$sheet->id}.xlsx", $this->fileName($response));
        $this->assertStringContainsString("filename=sheet_{$sheet->id}.xlsx", $response->headers->get('content-disposition'));
    }

    // ------------------------------------------------------------------
    // التنظيف
    // ------------------------------------------------------------------

    public function test_sanitizing_truncation_reserved_and_empty_names(): void
    {
        $namer = app(SheetExportNamer::class);
        $sheet = new DistributionSheet(['format' => 'v2', 'delivery_order_no' => '3918']);
        $sheet->id = 5;
        $name = fn (string $area) => $namer->fileName($sheet, $this->supervisor, ['area_key' => 'x', 'area_label' => $area]);

        $dirty = "\u{200F}../منطقة: \"الشمال\"/..\\الشرقية|*?<>\u{202B}  \u{200E}";
        $clean = $name($dirty);
        $this->assertSame('منطقة الشمال . الشرقية - 3918.xlsx', $clean);
        foreach (['/', '\\', ':', '"', '..', '*', '?', '<', '>', '|', "\u{200F}", "\u{200E}", "\u{202B}"] as $bad) {
            $this->assertStringNotContainsString($bad, $clean);
        }

        $long = $name(str_repeat('منطقة طويلة ', 30));
        $this->assertSame(120, mb_strlen(substr($long, 0, -5)));                       // بلا ".xlsx"
        $this->assertStringEndsWith(' - 3918.xlsx', $long);

        $this->assertSame('CON_ - 3918.xlsx', $name('CON'));
        $this->assertSame('nul_ - 3918.xlsx', $name('nul'));
        $this->assertSame('كشف - 3918.xlsx', $name(' / .. : '));
    }

    public function test_every_export_entry_point_gives_the_same_name_for_the_same_role(): void
    {
        $sheet = $this->threeAreaSheet();
        $d = User::factory()->role(Role::Distributor)->create(['name' => 'أحمد']);
        $this->actingAs($this->supervisor)->put(route('supervisor.sheet.assign', $sheet), ['all_distributor_id' => $d->id]);
        $url = route('sheets.export', $sheet);

        // كل الأزرار تشير إلى المسار نفسه
        $this->actingAs($this->accountant)->get(route('accountant.index'))->assertSee($url);
        $this->actingAs($this->accountant)->get(route('accountant.sheet.show', $sheet->id))->assertSee($url);
        $this->actingAs($this->supervisor)->get(route('supervisor.index'))->assertSee($url);
        $this->actingAs($this->supervisor)->get(route('supervisor.sheet.show', $sheet->id))->assertSee($url);
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.index'))->assertSee($url);
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $sheet))->assertSee($url);

        $this->assertSame('أحمد - 3918.xlsx', $this->fileName($this->export($this->accountant, $sheet)));
        $this->assertSame('أحمد - 3918.xlsx', $this->fileName($this->export($this->accountant, $sheet, ['with_lines' => 1])));
        $this->assertSame('كتلة الشمال - 3918.xlsx', $this->fileName($this->export($this->supervisor, $sheet)));
        $this->assertSame('كتلة الشمال - 3918.xlsx', $this->fileName($this->export($this->manager, $sheet, ['with_lines' => 1])));
    }
}
