<?php

namespace Tests\Feature\Phase8C;

use App\Enums\Role;
use App\Models\Customer;
use App\Models\DistributionSheet;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 8C (1): فلترة معاينة كشف «قيد التجهيز» على الخادم لاكتشاف الفاتورة النازلة بالخطأ.
 */
class SheetFilterTest extends TestCase
{
    use RefreshDatabase, Workbooks;

    private User $manager;
    private DistributionSheet $a;
    private DistributionSheet $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->manager = User::factory()->role(Role::SheetManager)->create();

        $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 5000]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية جيم', 'delivery' => '10-10-2026', 'lines' => [['صنف', 300]]],
            ['number' => 'ع G: 4', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 40]]],
            ['number' => 'ع G: 9', 'customer' => 'صيدلية دال', 'order' => 4000, 'lines' => [['صنف', 70]]],
        ], [
            ['صيدلية ألف', 'كتلة الشمال', 'منطقة أ', 1000, 0],
            ['صيدلية باء', 'كتلة الشمال', 'منطقة أ', 1000, 0],
            ['صيدلية جيم', 'كتلة الجنوب', 'منطقة شاذة', 1000, 0],
            ['صيدلية دال', 'كتلة الجنوب', 'منطقة ب', 1000, 0],
        ]), '2026-10-13', $this->manager);

        $this->a = DistributionSheet::where('delivery_order_no', '3918')->firstOrFail();
        $this->b = DistributionSheet::where('delivery_order_no', '4000')->firstOrFail();
        $this->assertTrue($this->a->isPreparing());
    }

    private function inv(string $number): Invoice
    {
        return Invoice::where('invoice_number', $number)->firstOrFail();
    }

    /** أرقام الفواتير المعروضة في الجدول بترتيبها */
    private function shown(array $query = []): array
    {
        $response = $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', [$this->a] + $query))->assertOk();

        return $response->viewData('invoices')->pluck('invoice_number')->all();
    }

    public function test_area_block_and_search_filter_on_the_server_with_counts(): void
    {
        $area = $this->inv('ع G: 3')->area_key;
        $response = $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', [$this->a, 'area' => $area]))->assertOk();
        $this->assertSame(['ع G: 3'], $response->viewData('invoices')->pluck('invoice_number')->all());
        // الفلترة استعلام: الفاتورة المستبعدة لا تصل إلى الصفحة أصلاً
        $response->assertDontSee('ع G: 2')->assertSee('1 من أصل 4 فاتورة');

        // قوائم المنطقة والكتلة من قيم هذا الكشف وحده مع العدد (منطقة ب في كشف آخر)
        $options = $response->viewData('filterOptions');
        $this->assertSame([3, 1], array_values(array_map(fn ($a) => $a['count'], $options['areas'])));
        $this->assertNotContains('منطقة ب', array_column($options['areas'], 'label'));
        $response->assertSee('منطقة شاذة (1)')->assertSee('كتلة الشمال (3)');

        $this->assertSame(['ع G: 1', 'ع G: 2', 'ع G: 4'], $this->shown(['block' => 'كتلة الشمال', 'sort' => 'order']));

        // بحث مطبَّع عربياً (ألف/الف) وبرقم الفاتورة
        $this->assertSame(['ع G: 1', 'ع G: 4'], $this->shown(['search' => 'الف', 'sort' => 'order']));
        $this->assertSame(['ع G: 2'], $this->shown(['search' => 'G: 2']));
    }

    public function test_value_range_and_sorts(): void
    {
        $this->assertSame(['ع G: 2', 'ع G: 3', 'ع G: 1', 'ع G: 4'], $this->shown(['sort' => 'amount_desc']));
        $this->assertSame(['ع G: 4', 'ع G: 1', 'ع G: 3', 'ع G: 2'], $this->shown(['sort' => 'amount_asc']));
        $this->assertSame(['ع G: 1', 'ع G: 3'], $this->shown(['min' => 100, 'max' => 300, 'sort' => 'order']));

        // المجاميع في الرأس للمعروض من أصل الكل
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', [$this->a, 'min' => 1000]))
            ->assertSee('1 من أصل 4 فاتورة')->assertSee('إجمالي المعروض 5,000.00 من أصل 5,440.00');
    }

    public function test_origin_filters_from_existing_data_only(): void
    {
        // تاريخ التسليم يختلف عن تاريخ التوزيع
        $this->assertSame(['ع G: 3'], $this->shown(['origin' => 'date_changed']));

        // بلا مركز كلفة
        $this->inv('ع G: 4')->update(['cost_center_code' => null]);
        $this->assertSame(['ع G: 4'], $this->shown(['origin' => 'no_cost_center']));

        // نُقلت إلى هذا الكشف (invoice.moved)
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.move'), ['invoice_ids' => [$this->inv('ع G: 9')->id], 'sheet_id' => $this->a->id])->assertSessionHasNoErrors();
        $this->assertSame(['ع G: 9'], $this->shown(['origin' => 'moved']));

        // أضافها المستخدم يدوياً (من «بلا كشف»)
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.remove'), ['invoice_ids' => [$this->inv('ع G: 1')->id], 'reason' => 'للاختبار'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post(route('sheet-manager.invoices.place', $this->inv('ع G: 1')), ['sheet_id' => $this->a->id])->assertSessionHasNoErrors();
        $this->assertSame(['ع G: 1'], $this->shown(['origin' => 'manual']));

        // أُضيفت بآخر رفع: رفع ثانٍ يضيف فاتورة جديدة للأمر نفسه
        $this->importFiles($this->buildWorkbook([
            ['number' => 'ع G: 1', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 100]]],
            ['number' => 'ع G: 2', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 5000]]],
            ['number' => 'ع G: 3', 'customer' => 'صيدلية جيم', 'delivery' => '10-10-2026', 'lines' => [['صنف', 300]]],
            ['number' => 'ع G: 4', 'customer' => 'صيدلية ألف', 'lines' => [['صنف', 40]]],
            ['number' => 'ع G: 20', 'customer' => 'صيدلية باء', 'lines' => [['صنف', 15]]],
        ]), '2026-10-13', $this->manager);
        $this->assertSame(['ع G: 20'], $this->shown(['origin' => 'latest_batch']));

        $options = $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->a))->viewData('filterOptions');
        $this->assertSame(['latest_batch', 'moved', 'manual', 'date_changed', 'no_cost_center'], array_keys($options['origins']));
    }

    public function test_needs_review_filter_appears_only_when_useful(): void
    {
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->a))->assertDontSee('name="review"', false);

        Customer::where('name', 'صيدلية جيم')->update(['needs_review' => true]);
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', $this->a))->assertSee('name="review"', false)->assertSee('يحتاج مراجعة (1)');
        $this->assertSame(['ع G: 3'], $this->shown(['review' => 1]));
    }

    public function test_filters_live_in_the_url_and_actions_return_to_the_filtered_result(): void
    {
        $url = route('sheet-manager.sheets.show', [$this->a, 'area' => $this->inv('ع G: 3')->area_key, 'sort' => 'amount_desc']);
        $html = $this->actingAs($this->manager)->get($url)->assertOk()
            ->assertSee('مسح الفلاتر')
            ->assertSee('data-clear-filters', false)
            ->getContent();
        // كل فاتورة في النتيجة لها «أخرجها من الكشف» (الإجراء القائم نفسه)
        $this->assertSame(1, substr_count($html, 'أخرجها من الكشف'));
        // الجدول يصير بطاقات على الجوال
        $this->assertStringContainsString('stack-sm', $html);
        $this->assertStringContainsString('data-label="القيمة"', $html);

        $this->actingAs($this->manager)->from($url)
            ->post(route('sheet-manager.invoices.remove'), ['invoice_ids' => [$this->inv('ع G: 3')->id], 'reason' => 'نزلت بالخطأ'])
            ->assertRedirect($url);
        $this->assertSame(Invoice::UNASSIGNED, $this->inv('ع G: 3')->placement);

        // قيم غير صالحة تُرفض ولا تُطبَّق
        $this->actingAs($this->manager)->get(route('sheet-manager.sheets.show', [$this->a, 'origin' => 'hack']))->assertSessionHasErrors('origin');
    }

    public function test_permissions_unchanged(): void
    {
        foreach ([Role::Supervisor, Role::Distributor, Role::Accountant, Role::RepSupervisor, Role::Rep] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(route('sheet-manager.sheets.show', [$this->a, 'search' => 'ألف']))->assertForbidden();
        }
        $this->actingAs(User::factory()->role(Role::Admin)->create())->get(route('sheet-manager.sheets.show', [$this->a, 'search' => 'ألف']))->assertOk();
    }
}
