<?php

namespace Tests\Feature\Phase7A;

use App\Models\ImportBatchItem;

/**
 * ثابت «لا صامت» (H1): عدد عناصر الفواتير في الدفعة (كل الأنواع عدا note وmissing) = عدد الفواتير المميزة في الملف،
 * ولكل عنصر شرح غير فارغ.
 */
trait NoSilence
{
    protected function assertNoSilence(array $result, int $distinctInvoices): void
    {
        $items = ImportBatchItem::where('batch_id', $result['batch_id'])
            ->whereNotIn('kind', [ImportBatchItem::NOTE, ImportBatchItem::MISSING])
            ->get();

        $this->assertCount($distinctInvoices, $items, 'كل فاتورة في الملف يجب أن تنتهي بعنصر واحد في الدفعة.');
        foreach ($items as $item) {
            $this->assertNotSame('', trim((string) $item->reason), "العنصر {$item->invoice_number} بلا شرح.");
        }
        foreach (ImportBatchItem::where('batch_id', $result['batch_id'])->get() as $item) {
            $this->assertNotSame('', trim((string) $item->reason));
        }
    }
}
