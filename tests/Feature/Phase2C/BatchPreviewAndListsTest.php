<?php

namespace Tests\Feature\Phase2C;

use App\Enums\Role;
use App\Models\DistributionSheet;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\SheetAssignment;
use App\Models\User;
use App\Support\ArabicNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Workbooks;
use Tests\Support\FieldActivity;
use Tests\TestCase;

/**
 * E) المرحلة 2C: المعاينة حين تكون كل الفواتير موجودة مسبقاً، عنوان الكشف وموزعوه في القوائم، وشرح الحالات.
 */
class BatchPreviewAndListsTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // ------------------------------------------------------------------
    // A) المعاينة
    // ------------------------------------------------------------------

    public function test_reuploading_published_file_shows_unchanged_card_banner_and_consistent_numbers(): void
    {
        $sheet = $this->publishWorkbook($this->fixturePath());
        $manager = User::factory()->role(Role::SheetManager)->create();

        $result = $this->importFiles($this->fixturePath(), '2026-10-13', $manager);
        $batch = $result['batch'];

        $this->assertSame(1, DistributionSheet::count());              // لا كشف جديد
        $this->assertSame(42, Invoice::count());
        $this->assertSame(42, $batch->stat('unchanged_count'));
        $this->assertEqualsWithDelta(105898.04, $batch->stat('unchanged_total'), 0.001);
        $this->assertSame([['id' => $sheet->id, 'order' => '3918', 'count' => 42, 'total' => 105898.04]], $batch->stat('unchanged_sheets'));
        $this->assertSame(0, $batch->stat('sheets.count'));
        $this->assertSame(42, $result['reports'][0]['counts']['unchanged']);

        // إجمالي الملف = كشوف جديدة + مؤجلة + بلا كشف + موجودة مسبقاً + أخرى
        $sum = $batch->stat('sheets.total') + $batch->stat('deferred.total') + $batch->stat('unassigned.total')
            + $batch->stat('unchanged_total') + $batch->stat('other.total');
        $this->assertEqualsWithDelta($batch->stat('file_total'), $sum, 0.001);

        $this->actingAs($manager)->get(route('sheet-manager.batches.show', $batch))
            ->assertOk()
            ->assertSee('لم يُنشأ كشف جديد: كل فواتير الملف موجودة مسبقاً بلا تغيير')
            ->assertSee('موجودة مسبقاً بلا تغيير (42)')
            ->assertSee('105,898.04')
            ->assertSee('في كشوف جديدة (0)')
            ->assertSee('أمر التسليم #3918')
            ->assertSee(route('sheet-manager.sheets.show', $sheet->id))
            ->assertSee('موجودة مسبقاً: 42');
    }

    public function test_numbers_stay_consistent_with_mixed_new_and_unchanged_invoices(): void
    {
        $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
        ]));

        $result = $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],                 // بلا تغيير
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'order' => 4000, 'lines' => [['صنف', 40]]],   // كشف جديد
            ['number' => 'ع G: 3', 'customer' => 'صيدلية باء', 'delivery' => '20-10-2026', 'lines' => [['صنف', 7]]], // مؤجلة
        ]));
        $batch = $result['batch'];

        $this->assertSame(1, $batch->stat('unchanged_count'));
        $this->assertSame(1, $batch->stat('sheets.count'));
        $this->assertEqualsWithDelta(40, $batch->stat('sheets.total'), 0.001);
        $this->assertEqualsWithDelta(147, $batch->stat('file_total'), 0.001);
        $this->assertEqualsWithDelta(147, $batch->stat('sheets.total') + $batch->stat('deferred.total') + $batch->stat('unassigned.total')
            + $batch->stat('unchanged_total') + $batch->stat('other.total'), 0.001);

        $this->actingAs(User::factory()->role(Role::SheetManager)->create())->get(route('sheet-manager.batches.show', $batch))
            ->assertOk()->assertDontSee('لم يُنشأ كشف جديد');
    }

    public function test_old_batches_without_the_new_numbers_show_a_dash(): void
    {
        $batch = ImportBatch::create([
            'id' => (string) Str::uuid(),
            'distribution_date' => '2026-10-13',
            'file_names' => ['old.xlsx'],
            'stats' => [
                'file_total' => 100, 'sheets' => ['count' => 1, 'total' => 100], 'deferred' => ['count' => 0, 'total' => 0],
                'unassigned' => ['count' => 0, 'total' => 0], 'other' => ['count' => 0, 'total' => 0],
                'files' => [['name' => 'old.xlsx', 'success' => true, 'error' => null, 'notes' => [], 'counts' => ['invoices' => 1, 'lines' => 1, 'customers' => 1, 'matched' => 1, 'new' => 0]]],
            ],
            'warnings' => [],
        ]);

        $this->actingAs(User::factory()->role(Role::SheetManager)->create())->get(route('sheet-manager.batches.show', $batch))
            ->assertOk()
            ->assertSee('موجودة مسبقاً بلا تغيير (—)')
            ->assertSee('موجودة مسبقاً: —')
            ->assertDontSee('لم يُنشأ كشف جديد');
    }

    // ------------------------------------------------------------------
    // B) القوائم: العنوان والموزعون
    // ------------------------------------------------------------------

    public function test_accountant_sees_distributor_names_order_title_and_totals(): void
    {
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $accountant = User::factory()->role(Role::Accountant)->create();
        $d1 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع سامر']);
        $d2 = User::factory()->role(Role::Distributor)->create(['name' => 'الموزع رامي']);

        $assigned = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 50]]],
        ], [['صيدلية ألف', 'ك', 'منطقة أ', 10, 0], ['صيدلية باء', 'ك', 'منطقة ب', 10, 0]]));
        $this->actingAs($supervisor)->put(route('supervisor.sheet.assign', $assigned), ['rows' => [
            ['area_key' => ArabicNormalizer::normalize('منطقة أ'), 'distributor_id' => $d1->id],
            ['area_key' => ArabicNormalizer::normalize('منطقة ب'), 'distributor_id' => $d2->id],
        ]])->assertSessionHasNoErrors();

        $unassigned = $this->publishWorkbook($this->buildWorkbook([
            ['number' => 'ع G: 9', 'customer' => 'صيدلية جيم', 'order' => 5000, 'lines' => [['صنف', 30]]],
        ]));

        $v1 = DistributionSheet::create(['distributor_id' => $d1->id, 'supervisor_id' => $supervisor->id, 'file_name' => 'قديم.xlsx', 'status' => 'pending']);
        Invoice::create(['distribution_sheet_id' => $v1->id, 'invoice_number' => 'OLD-1', 'customer_name' => 'ز', 'invoice_amount' => 70]);

        $this->assertSame('أمر التسليم #3918 — 2026-10-13', $assigned->fresh()->displayTitle());
        $this->assertSame('قديم.xlsx', $v1->displayTitle());

        $this->actingAs($accountant)->get(route('accountant.index'))
            ->assertOk()
            ->assertSee('أمر التسليم #3918')
            ->assertSee('أمر التسليم #5000')
            ->assertSee('الموزع سامر')
            ->assertSee('الموزع رامي')
            ->assertSee('غير مسند')
            ->assertDontSee('غير معين')
            ->assertSee('قديم.xlsx')
            ->assertSee('صيغة قديمة')
            ->assertSee('تاريخ التوزيع')
            ->assertSee('150.00')
            ->assertSee('70.00')
            ->assertSee(route('sheets.export', $assigned))
            ->assertSee(route('accountant.download', $v1->id))
            ->assertDontSee(route('sheets.export', $v1));

        foreach ([route('supervisor.index'), route('sheet-manager.sheets.index')] as $url) {
            $user = str_contains($url, 'sheet-manager') ? User::factory()->role(Role::SheetManager)->create() : $supervisor;
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('أمر التسليم #3918')->assertSee('الموزع سامر')->assertSee('غير مسند')->assertDontSee('غير معين');
        }
        $this->assertSame('pending', $unassigned->fresh()->status);
    }

    public function test_distributors_label_truncates_and_uses_eager_loaded_relations(): void
    {
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $sheet = DistributionSheet::create(['format' => 'v2', 'supervisor_id' => $supervisor->id, 'file_name' => 'x.xlsx', 'status' => 'pending', 'delivery_order_no' => '1']);
        foreach (['أ', 'ب', 'ج', 'د', 'هـ'] as $i => $n) {
            $d = User::factory()->role(Role::Distributor)->create(['name' => "موزع {$n}"]);
            SheetAssignment::create(['sheet_id' => $sheet->id, 'distributor_id' => $d->id, 'area_key' => "area{$i}"]);
        }

        $loaded = DistributionSheet::with(['assignments.distributor', 'distributor'])->find($sheet->id);
        DB::enableQueryLog();
        $label = $loaded->distributorsLabel();
        $this->assertCount(0, DB::getQueryLog());          // لا استعلام إضافي بعد التحميل المسبق
        $this->assertSame('موزع أ، موزع ب +3', $label);
    }

    // ------------------------------------------------------------------
    // C) شرح الحالات
    // ------------------------------------------------------------------

    public function test_status_badge_has_hint_for_every_status(): void
    {
        $hints = [
            'preparing' => 'عند مسؤول الكشوف ولم يُرسل بعد',
            'pending' => 'يبدأ تلقائياً عند أول تسجيل',
            'in_progress' => 'بدأ الموزعون التسجيل عليه',
            'completed' => 'أغلقه المشرف',
            'draft' => 'أُغلق بلا توزيع',
        ];
        foreach ($hints as $status => $hint) {
            $html = Blade::render('<x-status-badge :status="$s" :reason="$r" />', ['s' => $status, 'r' => $status === 'draft' ? 'ملف مكرر' : null]);
            $this->assertStringContainsString($hint, $html, $status);
            $this->assertStringContainsString('title=', $html);
            $this->assertStringContainsString('data-bs-toggle="tooltip"', $html);
        }
        $this->assertStringContainsString('السبب: ملف مكرر', Blade::render('<x-status-badge status="draft" reason="ملف مكرر" />'));
    }

    public function test_sheet_pages_explain_status_and_ask_for_assignment_when_pending(): void
    {
        $sheet = $this->publishWorkbook($this->buildWorkbook([['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]]]));
        $supervisor = User::factory()->role(Role::Supervisor)->create();
        $manager = User::factory()->role(Role::SheetManager)->create();
        $distributor = User::factory()->role(Role::Distributor)->create();

        foreach ([[$supervisor, route('supervisor.sheet.show', $sheet->id)], [$manager, route('sheet-manager.sheets.show', $sheet)]] as [$user, $url]) {
            $this->actingAs($user)->get($url)->assertOk()
                ->assertSee('أُرسل، ولم يسجّل الموزع أي زيارة أو دفعة بعد')
                ->assertSee('ابدأ بإسناد المناطق لموزعين');
        }

        // بعد الإسناد يختفي التنبيه، والانتقال إلى "قيد التحصيل" يبقى تلقائياً عند أول تسجيل
        $this->actingAs($supervisor)->put(route('supervisor.sheet.assign', $sheet), ['all_distributor_id' => $distributor->id]);
        $this->actingAs($supervisor)->get(route('supervisor.sheet.show', $sheet->id))->assertDontSee('ابدأ بإسناد المناطق لموزعين');

        $this->payApi($distributor, $sheet, Invoice::first()->customer_id, 10)->assertCreated();
        $this->assertSame('in_progress', $sheet->fresh()->status);
        $this->actingAs($supervisor)->get(route('supervisor.sheet.show', $sheet->id))->assertSee('بدأ الموزعون التسجيل عليه');
    }
}
