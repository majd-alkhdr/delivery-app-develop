<?php

namespace Tests\Unit;

use App\Services\Finance\RepReceivableService;
use App\Services\Finance\UnpaidReportService;
use App\Support\CostCenter;
use PHPUnit\Framework\TestCase;

/**
 * المرحلة 6-B: خصم دفعات المندوب FIFO (نقي، بالقروش) وتطبيع كود مركز الكلفة.
 */
class RepFifoTest extends TestCase
{
    private function service(): RepReceivableService
    {
        // fifo() نقية لا تستخدم الاعتماديات
        $unpaid = (new \ReflectionClass(UnpaidReportService::class))->newInstanceWithoutConstructor();

        return new RepReceivableService($unpaid);
    }

    public function test_oldest_first_then_partial(): void
    {
        $r = $this->service()->fifo([11 => 100000, 12 => 50000, 31 => 40000], [1 => 120000]);
        $this->assertSame([1 => [11 => 100000, 12 => 20000]], $r['allocations']);
        $this->assertSame([11 => 0, 12 => 30000, 31 => 40000], $r['remaining']);
        $this->assertSame([], $r['surplus']);
    }

    public function test_surplus_is_recorded_per_payment_and_not_carried(): void
    {
        $r = $this->service()->fifo([11 => 1000], [1 => 600, 2 => 700]);
        $this->assertSame([1 => [11 => 600], 2 => [11 => 400]], $r['allocations']);
        $this->assertSame([2 => 300], $r['surplus']);
        $this->assertSame([11 => 0], $r['remaining']);

        $none = $this->service()->fifo([], [5 => 999]);
        $this->assertSame([5 => 999], $none['surplus']);
    }

    public function test_consistency_over_seeded_random_cases(): void
    {
        mt_srand(6);
        for ($case = 0; $case < 200; $case++) {
            $invoices = [];
            for ($i = 1, $n = mt_rand(0, 6); $i <= $n; $i++) {
                $invoices[$i] = mt_rand(0, 500000);
            }
            $payments = [];
            for ($p = 100, $n = mt_rand(1, 4); $p < 100 + $n; $p++) {
                $payments[$p] = mt_rand(1, 400000);
            }
            $r = $this->service()->fifo($invoices, $payments);
            $allocated = array_sum(array_map('array_sum', $r['allocations']));
            $this->assertSame(array_sum($payments), $allocated + array_sum($r['surplus']), "الحالة {$case}");
            foreach ($invoices as $id => $open) {
                $this->assertSame($open, $r['remaining'][$id] + array_sum(array_column($r['allocations'], $id)));
                $this->assertGreaterThanOrEqual(0, $r['remaining'][$id]);
            }
        }
    }

    public function test_cost_center_code_normalization(): void
    {
        $this->assertSame('655231', CostCenter::normalizeCode(' ٦٥٥٢٣١ '));
        $this->assertSame('655231', CostCenter::codeFromLabel('٦٥٥٢٣١-كرم خالدي'));
        $this->assertSame('655231', CostCenter::codeFromLabel('655231 - كرم الخالدي'));
        $this->assertNull(CostCenter::codeFromLabel('بلا كود'));
        $this->assertNull(CostCenter::normalizeCode('  '));
        $this->assertContains('٦٥٥٢٣١', CostCenter::codeVariants('655231'));
    }
}
