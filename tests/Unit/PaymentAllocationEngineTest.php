<?php

namespace Tests\Unit;

use App\Services\Finance\AllocationResult;
use App\Services\Finance\PaymentAllocationEngine;
use PHPUnit\Framework\TestCase;

/**
 * المرحلة 5-A: محرك تقسيم الدفعات (حساب نقي بالقروش، بلا قاعدة بيانات).
 */
class PaymentAllocationEngineTest extends TestCase
{
    private PaymentAllocationEngine $engine;

    private int $nextId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = (new PaymentAllocationEngine)->withGroups(['m' => 'milk', 'ارجيفيت' => 'argivit', 'g' => 'other', 'a' => 'other', 'b' => 'other']);
    }

    private function invoice(string $series, string $number, float $total, ?string $cc = '201201-كول سنتر'): array
    {
        return [
            'id' => $this->nextId++,
            'invoice_key' => mb_strtolower($series).':'.$number,
            'invoice_number' => "ع {$series}: {$number}",
            'series' => $series,
            'cost_center' => $cc,
            'total_cents' => PaymentAllocationEngine::toCents($total),
        ];
    }

    private function payment(?float $syp, string $currency = 'SYP', string $by = 'الموزع'): array
    {
        return ['id' => $this->nextId++, 'currency' => $currency, 'amount_syp_cents' => $syp === null ? null : PaymentAllocationEngine::toCents($syp), 'entered_by' => 1, 'entered_by_name' => $by];
    }

    private function allocated(AllocationResult $r): array
    {
        return array_column($r->invoiceRows, 'allocated', 'label');
    }

    public function test_milk_full_then_argivit_proportional_then_other_nothing(): void
    {
        $r = $this->engine->compute(1, [
            $this->invoice('G', '10', 500),
            $this->invoice('M', '1', 300),
            $this->invoice('ارجيفيت', '5', 200),
            $this->invoice('ارجيفيت', '6', 600),
        ], [$this->payment(700)]);

        $a = $this->allocated($r);
        $this->assertSame(300.0, (float) $a['M: 1']);
        // 400 على ارجيفيت بالتناسب 200:600 ⇒ 100 و300
        $this->assertSame(100.0, (float) $a['ارجيفيت: 5']);
        $this->assertSame(300.0, (float) $a['ارجيفيت: 6']);
        $this->assertSame(0.0, (float) $a['G: 10']);
        $this->assertSame(0, $r->surplusCents);
        $this->assertSame(['milk', 'argivit', 'argivit', 'other'], array_column($r->invoiceRows, 'group'));
        $this->assertSame(['paid', 'partial', 'partial', 'unpaid'], array_column($r->invoiceRows, 'status'));
        $this->assertSame(900.0, $r->totalUnpaidSyp());   // 100 + 300 + 500
    }

    public function test_milk_alone_not_covered_is_split_proportionally(): void
    {
        $r = $this->engine->compute(1, [
            $this->invoice('M', '1', 100),
            $this->invoice('M', '2', 300),
            $this->invoice('G', '3', 1000),
        ], [$this->payment(200)]);

        $a = $this->allocated($r);
        $this->assertSame(50.0, (float) $a['M: 1']);
        $this->assertSame(150.0, (float) $a['M: 2']);
        $this->assertSame(0.0, (float) $a['G: 3']);
    }

    public function test_everything_covered_with_surplus(): void
    {
        $r = $this->engine->compute(1, [$this->invoice('M', '1', 100), $this->invoice('G', '2', 250.5)], [$this->payment(400)]);

        $this->assertSame(['paid', 'paid'], array_column($r->invoiceRows, 'status'));
        $this->assertSame(4950, $r->surplusCents);   // 49.50
        $this->assertSame('201201-كول سنتر', $r->surplusCostCenter);
        $this->assertSame('suggested', $r->surplusCostCenterSource);
    }

    public function test_zero_and_negative_invoices_are_outside_allocation(): void
    {
        $r = $this->engine->compute(1, [
            $this->invoice('G', '1', 0),
            $this->invoice('G', '2', -50),
            $this->invoice('G', '3', 100),
        ], [$this->payment(30)]);

        $this->assertCount(1, $r->invoiceRows);
        $this->assertSame('G: 3', $r->invoiceRows[0]['label']);
        $this->assertSame(30.0, (float) $r->invoiceRows[0]['allocated']);
        $this->assertSame(['G: 1', 'G: 2'], array_column($r->excludedRows, 'label'));
    }

    public function test_allocated_plus_surplus_equals_payments_exactly_in_200_seeded_random_cases(): void
    {
        mt_srand(20261014);
        $series = ['M', 'ارجيفيت', 'G', 'A', 'B'];
        for ($case = 0; $case < 200; $case++) {
            $invoices = [];
            for ($i = 0, $n = mt_rand(1, 9); $i < $n; $i++) {
                $invoices[] = $this->invoice($series[mt_rand(0, 4)], (string) mt_rand(1, 99999), mt_rand(-500, 5_000_000) / 100);
            }
            $payments = [];
            for ($i = 0, $n = mt_rand(1, 4); $i < $n; $i++) {
                $payments[] = $this->payment(mt_rand(1, 6_000_000) / 100);
            }
            $previous = [];
            foreach ($invoices as $inv) {
                if (mt_rand(0, 3) === 0 && $inv['total_cents'] > 0) {
                    $previous[$inv['id']] = mt_rand(0, $inv['total_cents']);
                }
            }

            $r = $this->engine->compute(1, $invoices, $payments, $previous);

            $paid = array_sum(array_column($payments, 'amount_syp_cents'));
            $this->assertSame($paid, $r->allocatedCents() + $r->surplusCents, "الحالة {$case}");
            foreach ($r->invoiceRows as $row) {
                $this->assertLessThanOrEqual($row['remaining_cents'], $row['allocated_cents'], "الحالة {$case}");
                $this->assertGreaterThanOrEqual(0, $row['allocated_cents']);
                $this->assertSame($row['total_cents'], $row['previous_cents'] + $row['allocated_cents'] + $row['unpaid_cents']);
            }
            $centers = array_sum(array_column($r->costCenterRows, 'amount_cents'));
            $this->assertSame($r->allocatedCents(), $centers);
        }
    }

    public function test_largest_remainder_is_deterministic_by_series_then_number(): void
    {
        // 1 قرش على ثلاث فواتير متساوية: يذهب لأولها بالترتيب (السلسلة، الرقم)
        $invoices = [$this->invoice('G', '30', 1), $this->invoice('A', '20', 1), $this->invoice('G', '4', 1)];
        $r = $this->engine->compute(1, $invoices, [$this->payment(0.01)]);
        $this->assertSame(['A: 20', 'G: 4', 'G: 30'], array_column($r->invoiceRows, 'label'));
        $this->assertSame([1, 0, 0], array_column($r->invoiceRows, 'allocated_cents'));

        // التكرار يعطي النتيجة نفسها دائماً
        $again = $this->engine->compute(1, array_reverse($invoices), [$this->payment(0.01)]);
        $this->assertSame(array_column($r->invoiceRows, 'allocated_cents'), array_column($again->invoiceRows, 'allocated_cents'));

        // 100 على ثلاث متساوية ⇒ 33.34 + 33.33 + 33.33
        $r = $this->engine->compute(1, [$this->invoice('G', '1', 100), $this->invoice('G', '2', 100), $this->invoice('G', '3', 100)], [$this->payment(100)]);
        $this->assertSame([3334, 3333, 3333], array_column($r->invoiceRows, 'allocated_cents'));
    }

    public function test_largest_remainder_shares(): void
    {
        $this->assertSame([3334, 3333, 3333], $this->engine->largestRemainder(10000, [10000, 10000, 10000]));
        $this->assertSame([1, 2], $this->engine->largestRemainder(3, [10, 20]));
        $this->assertSame([0, 0], $this->engine->largestRemainder(0, [10, 20]));
        // مبالغ ضخمة بلا فيض عددي
        $big = $this->engine->largestRemainder(99_999_999_999, [100_000_000_000, 50_000_000_000]);
        $this->assertSame(99_999_999_999, array_sum($big));
    }

    public function test_cost_center_grouping_for_invoices_from_several_centers(): void
    {
        $r = $this->engine->compute(1, [
            $this->invoice('G', '1', 100, '655231-كرم الخالدي'),
            $this->invoice('G', '2', 200, '201201-كول سنتر'),
            $this->invoice('G', '3', 300, '655231-كرم الخالدي'),
        ], [$this->payment(600)]);

        $this->assertSame([
            ['cost_center' => '201201-كول سنتر', 'amount' => 200.0, 'amount_cents' => 20000],
            ['cost_center' => '655231-كرم الخالدي', 'amount' => 400.0, 'amount_cents' => 40000],
        ], array_map(fn ($c) => ['cost_center' => $c['cost_center'], 'amount' => (float) $c['amount'], 'amount_cents' => $c['amount_cents']], $r->costCenterRows));
    }

    public function test_several_payments_for_one_customer_are_pooled_and_usd_counts_in_syp(): void
    {
        $r = $this->engine->compute(1, [$this->invoice('G', '1', 20000)], [
            $this->payment(1000),
            $this->payment(13900, 'USD'),   // 100$ × 139
        ]);
        $this->assertSame(1490000, $r->paidCents);
        $this->assertSame(14900.0, (float) $r->invoiceRows[0]['allocated']);
        $this->assertSame('partial', $r->invoiceRows[0]['status']);
    }

    public function test_usd_without_amount_syp_is_incomplete_and_allocates_nothing(): void
    {
        $r = $this->engine->compute(1, [$this->invoice('G', '1', 500)], [$this->payment(100), $this->payment(null, 'USD')]);

        $this->assertTrue($r->isIncomplete());
        $this->assertSame(AllocationResult::INCOMPLETE_AWAITING_RATE, $r->incompleteReason);
        $this->assertSame(1, $r->awaitingRateCount);
        $this->assertSame(0, $r->allocatedCents());
        $this->assertSame(0, $r->surplusCents);
        $this->assertContains('awaiting_rate', $r->blockers());
    }

    public function test_previous_allocations_reduce_remaining(): void
    {
        $inv = $this->invoice('G', '1', 500);
        $r = $this->engine->compute(1, [$inv], [$this->payment(400)], [$inv['id'] => 30000]);

        $this->assertSame(200.0, (float) $r->invoiceRows[0]['remaining']);
        $this->assertSame(200.0, (float) $r->invoiceRows[0]['allocated']);
        $this->assertSame(20000, $r->surplusCents);
        $this->assertSame('paid', $r->invoiceRows[0]['status']);
    }

    public function test_surplus_cost_center_rules(): void
    {
        // مقترح: مركز أعلى فاتورة رقماً
        $r = $this->engine->compute(1, [
            $this->invoice('G', '900', 10, '1-أول'),
            $this->invoice('A', '1200', 10, '2-ثان'),
            $this->invoice('B', '80', 0, '3-ثالث'),
        ], [$this->payment(100)]);
        $this->assertSame('2-ثان', $r->surplusCostCenter);
        $this->assertSame('suggested', $r->surplusCostCenterSource);
        $this->assertSame([], $r->blockers());

        // اختيار صريح يتقدّم
        $r = $this->engine->compute(1, [$this->invoice('G', '9', 10, '1-أول')], [$this->payment(100)], [], '9-مختار');
        $this->assertSame('9-مختار', $r->surplusCostCenter);
        $this->assertSame('chosen', $r->surplusCostCenterSource);

        // بلا فواتير ⇒ بلا مركز ويمنع
        $r = $this->engine->compute(1, [], [$this->payment(100)]);
        $this->assertTrue($r->isExternalOnly());
        $this->assertNull($r->surplusCostCenter);
        $this->assertSame('none', $r->surplusCostCenterSource);
        $this->assertContains('surplus_without_cost_center', $r->blockers());
    }

    public function test_to_cents_is_exact(): void
    {
        $this->assertSame(123456, PaymentAllocationEngine::toCents('1234.56'));
        $this->assertSame(1235, PaymentAllocationEngine::toCents('12.345'));
        $this->assertSame(-5000, PaymentAllocationEngine::toCents('-50.00'));
        $this->assertSame(1, PaymentAllocationEngine::toCents(0.01));
        $this->assertSame(1390000, PaymentAllocationEngine::toCents(13900.0));
        $this->assertSame('655231-كرم الخالدي', PaymentAllocationEngine::costCenterLabel('655231', 'كرم الخالدي'));
        $this->assertNull(PaymentAllocationEngine::costCenterLabel(null, ' '));
    }
}
