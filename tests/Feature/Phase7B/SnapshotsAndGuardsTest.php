<?php

namespace Tests\Feature\Phase7B;

use App\Models\BalanceImport;
use App\Models\Customer;
use App\Models\CustomerBalanceSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FieldActivity;
use Tests\Support\Workbooks;
use Tests\TestCase;

/**
 * المرحلة 7B (I1–I3): اللقطات، حارس تاريخ ملف الأرصدة، وحارس التصفير.
 */
class SnapshotsAndGuardsTest extends TestCase
{
    use RefreshDatabase, Workbooks, FieldActivity, BalancesFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBalances();
    }

    private function balance(string $name): float
    {
        return (float) Customer::where('name', $name)->value('balance');
    }

    public function test_snapshots_only_for_changed_created_and_zeroed(): void
    {
        $this->upload($this->dailyFile());
        $first = $this->latestImport();
        $this->assertSame(4, CustomerBalanceSnapshot::where('balance_import_id', $first->id)->where('kind', 'created')->count());

        // تغيّر (5000←4800)، جديد (100)، غاب (رابعة 4000 ⇒ 0)، ثابتان (ثانية وثالثة)
        $second = $this->upload($this->balancesFile(['صيدلية التجربة' => 4800, 'صيدلية ثانية' => 2000, 'صيدلية ثالثة' => 3000, 'صيدلية جديدة' => 100]));
        $import = $this->latestImport();
        $this->assertSame(BalanceImport::APPLIED, $import->status);

        $snaps = CustomerBalanceSnapshot::with('customer')->where('balance_import_id', $import->id)->get()->keyBy(fn ($s) => $s->customer->name);
        $this->assertEqualsCanonicalizing(['صيدلية التجربة', 'صيدلية جديدة', 'صيدلية رابعة'], $snaps->keys()->all());
        $this->assertSame(['changed', 5000.0, 4800.0, -200.0], [$snaps['صيدلية التجربة']->kind, (float) $snaps['صيدلية التجربة']->prev_balance, (float) $snaps['صيدلية التجربة']->balance, (float) $snaps['صيدلية التجربة']->delta]);
        $this->assertSame('created', $snaps['صيدلية جديدة']->kind);
        $this->assertSame(['zeroed', 4000.0, 0.0], [$snaps['صيدلية رابعة']->kind, (float) $snaps['صيدلية رابعة']->prev_balance, (float) $snaps['صيدلية رابعة']->balance]);
        $this->assertSame(2, $import->stats['unchanged']);
        $this->assertNotNull($import->stored_path);   // الملف الأصلي محفوظ ومربوط بالدفعة
        $this->assertSame($second['batch_id'], $import->batch_id);
    }

    public function test_older_file_is_held_and_only_admin_applies_with_reason(): void
    {
        $this->upload($this->dailyFile());
        $result = $this->upload($this->balancesFile(['صيدلية التجربة' => 4800] + $this->others(), '01-10-2026'));
        $import = $this->latestImport();

        $this->assertSame([BalanceImport::HELD, BalanceImport::HOLD_OLDER], [$import->status, $import->hold_reason]);
        $this->assertSame(5000.0, $this->balance('صيدلية التجربة'));
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))->assertOk()
            ->assertSee('تاريخ ملف الأرصدة (01-10) أقدم من الأرصدة المطبَّقة (13-10). لم تُطبَّق الأرصدة حتى تقرر.')
            ->assertSee('تجاهل أرصدة هذا الملف');
        $this->actingAs($this->manager)->get(route('sheet-manager.index'))->assertSee('أرصدة غير مطبَّقة');

        $this->actingAs($this->manager)->post(route('sheet-manager.balances.apply', $import), ['reason' => 'محاولة'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('sheet-manager.balances.apply', $import))->assertSessionHasErrors('reason');
        $this->assertSame(5000.0, $this->balance('صيدلية التجربة'));

        $this->actingAs($this->admin)->post(route('sheet-manager.balances.apply', $import), ['reason' => 'تصحيح من المحاسبة'])->assertSessionHasNoErrors();
        $this->assertSame(4800.0, $this->balance('صيدلية التجربة'));
        $this->assertSame('تصحيح من المحاسبة', $import->fresh()->decision_note);
        $this->assertDatabaseHas('activity_logs', ['action' => 'customers.balances_applied_after_hold']);
    }

    public function test_ignoring_a_held_file_keeps_balances_and_file(): void
    {
        $this->upload($this->dailyFile());
        $this->upload($this->balancesFile(['صيدلية التجربة' => 4800] + $this->others(), '01-10-2026'));
        $import = $this->latestImport();

        $this->actingAs($this->manager)->post(route('sheet-manager.balances.ignore', $import), ['note' => 'ملف قديم'])->assertSessionHasNoErrors();
        $this->assertSame(BalanceImport::IGNORED, $import->fresh()->status);
        $this->assertSame(5000.0, $this->balance('صيدلية التجربة'));
        $this->assertTrue(\Illuminate\Support\Facades\Storage::exists($import->stored_path));
    }

    public function test_same_date_file_is_applied_with_note(): void
    {
        $this->upload($this->dailyFile());
        $result = $this->upload($this->balancesFile(['صيدلية التجربة' => 4900] + $this->others()));

        $this->assertSame(4900.0, $this->balance('صيدلية التجربة'));
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'notes']))
            ->assertSee('ملف أرصدة آخر بالتاريخ نفسه (2026-10-13) طُبِّق سابقاً؛ الأحدث رفعاً هو المعتمد');
    }

    public function test_file_without_date_needs_manual_date(): void
    {
        $this->upload($this->dailyFile());
        $this->upload($this->balancesFile(['صيدلية التجربة' => 4700] + $this->others(), null));
        $import = $this->latestImport();
        $this->assertSame([BalanceImport::HELD, BalanceImport::HOLD_NO_DATE], [$import->status, $import->hold_reason]);
        $this->assertSame(5000.0, $this->balance('صيدلية التجربة'));

        $this->actingAs($this->manager)->post(route('sheet-manager.balances.apply', $import))->assertSessionHasErrors('as_of');
        $this->actingAs($this->manager)->post(route('sheet-manager.balances.apply', $import), ['as_of' => '2026-10-14'])->assertSessionHasNoErrors();
        $import->refresh();
        $this->assertSame([BalanceImport::APPLIED, true, '2026-10-14'], [$import->status, $import->as_of_manual, $import->as_of->toDateString()]);
        $this->assertSame(4700.0, $this->balance('صيدلية التجربة'));

        // والتاريخ اليدوي في نموذج الرفع يُطبّق مباشرة مع ملاحظة
        $result = $this->upload($this->balancesFile(['صيدلية التجربة' => 4600] + $this->others(), null), null, '2026-10-15');
        $this->assertSame(4600.0, $this->balance('صيدلية التجربة'));
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'notes']))->assertSee('أُدخل تاريخ الأرصدة يدوياً (2026-10-15)');
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))->assertSee('تاريخ أُدخل يدوياً');
    }

    public function test_zeroing_above_threshold_stops_and_asks(): void
    {
        $this->upload($this->dailyFile());
        $result = $this->upload($this->balancesFile(['صيدلية التجربة' => 5000]));
        $import = $this->latestImport();

        $this->assertSame([BalanceImport::HELD, BalanceImport::HOLD_ZEROING], [$import->status, $import->hold_reason]);
        $this->assertSame(2000.0, $this->balance('صيدلية ثانية'));
        $this->assertSame(0, CustomerBalanceSnapshot::where('balance_import_id', $import->id)->count());
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))
            ->assertSee('سيُصفَّر رصيد 3 صيدلية (75% من الزبائن ذوي الرصيد غير الصفري)')->assertSee('نعم، الملف كامل')->assertSee('لا، تجاهل الأرصدة');

        $this->actingAs($this->manager)->post(route('sheet-manager.balances.apply', $import), ['confirm_zeroing' => 1])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->balance('صيدلية ثانية'));
        $this->assertSame(3, CustomerBalanceSnapshot::where('balance_import_id', $import->id)->where('kind', 'zeroed')->count());
        $this->actingAs($this->manager)->get(route('sheet-manager.batches.show', ['batch' => $result['batch_id'], 'tab' => 'balances']))
            ->assertSee('زبائن غابوا عن ملف الأرصدة فصُفِّر رصيدهم (3)')->assertSee('صيدلية رابعة: من');
    }
}
