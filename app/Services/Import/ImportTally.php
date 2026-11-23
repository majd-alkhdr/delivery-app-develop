<?php

namespace App\Services\Import;

/**
 * حصيلة معالجة ملف واحد (تُدمج في حصيلة الدفعة فقط إذا نجح الملف كاملاً).
 */
class ImportTally
{
    /** @var array<string, array{count: int, total: float}> sheets / deferred / unassigned / other */
    public array $buckets = [];

    /** @var array<int, int> */
    public array $createdSheetIds = [];

    /** @var array<int, int> */
    public array $touchedSheetIds = [];

    /** @var array<int, array> تحديثات طُبّقت تلقائياً */
    public array $updates = [];

    /** @var array<int, int> */
    public array $proposalIds = [];

    /** @var array<int, array{number: string, sheet_id: int}> فواتير أضيفت إلى كشف منشور */
    public array $addedToPublished = [];

    /** @var array<string, array<int, string>> قوائم أرقام فواتير لكل نوع تحذير */
    public array $lists = [];

    /** @var array<int, string> */
    public array $warnings = [];

    /** @var array<int, array{id: int, order: ?string, count: int, total: float}> كشوف الفواتير الموجودة مسبقاً بلا تغيير */
    public array $unchangedSheets = [];

    /** @var array<int, int> */
    public array $newCustomerIds = [];

    /** @var array<int, array> المرحلة 7A: عناصر الدفعة (تُحفظ في import_batch_items داخل معاملة الملف) */
    public array $items = [];

    /** @var array<string, array> ملاحظات على الملف مُجمّعة بالرمز */
    public array $notes = [];

    /** @var array<string, bool> مفاتيح الفواتير التي ظهرت في الملف (لفحص الغائبة) */
    public array $seenKeys = [];

    /** @var array<int, bool> فواتير لها عنصر في هذا الملف */
    public array $itemInvoiceIds = [];

    /** @var array<int, array{sheet_id: int, parent_id: int, order: string, no: int, invoices: array<int, string>}> المرحلة 7H: ملاحق أُنشئت في الملف */
    public array $supplements = [];

    public function item(array $item): void
    {
        $this->items[] = $item;
    }

    public function note(string $code, string $level, string $text, ?string $example = null): void
    {
        $this->notes[$code] ??= ['code' => $code, 'level' => $level, 'text' => $text, 'examples' => []];
        if ($level === 'warning') {
            $this->notes[$code]['level'] = 'warning';
        }
        if ($example !== null) {
            $this->notes[$code]['examples'][] = $example;
        }
    }

    public function count(string $bucket, float $amount): void
    {
        $this->buckets[$bucket] ??= ['count' => 0, 'total' => 0.0];
        $this->buckets[$bucket]['count']++;
        $this->buckets[$bucket]['total'] = round($this->buckets[$bucket]['total'] + $amount, 2);
    }

    /**
     * فاتورة موجودة مسبقاً في كشف وبالبصمة نفسها: لا تغيير.
     */
    public function unchanged(\App\Models\DistributionSheet $sheet, float $amount): void
    {
        $this->count('unchanged', $amount);
        $this->unchangedSheets[$sheet->id] ??= ['id' => $sheet->id, 'order' => $sheet->isSupplement() ? $sheet->orderLabel() : $sheet->delivery_order_no, 'count' => 0, 'total' => 0.0];
        $this->unchangedSheets[$sheet->id]['count']++;
        $this->unchangedSheets[$sheet->id]['total'] = round($this->unchangedSheets[$sheet->id]['total'] + $amount, 2);
    }

    public function touch(?int $sheetId): void
    {
        if ($sheetId) {
            $this->touchedSheetIds[$sheetId] = $sheetId;
        }
    }

    public function listed(string $type, string $value): void
    {
        $this->lists[$type][] = $value;
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function merge(ImportTally $other): void
    {
        foreach ($other->buckets as $bucket => $data) {
            $this->buckets[$bucket] ??= ['count' => 0, 'total' => 0.0];
            $this->buckets[$bucket]['count'] += $data['count'];
            $this->buckets[$bucket]['total'] = round($this->buckets[$bucket]['total'] + $data['total'], 2);
        }
        $this->createdSheetIds = array_values(array_unique(array_merge($this->createdSheetIds, $other->createdSheetIds)));
        $this->touchedSheetIds = $this->touchedSheetIds + $other->touchedSheetIds;
        $this->updates = array_merge($this->updates, $other->updates);
        $this->proposalIds = array_values(array_unique(array_merge($this->proposalIds, $other->proposalIds)));
        $this->addedToPublished = array_merge($this->addedToPublished, $other->addedToPublished);
        foreach ($other->lists as $type => $values) {
            $this->lists[$type] = array_values(array_unique(array_merge($this->lists[$type] ?? [], $values)));
        }
        $this->warnings = array_merge($this->warnings, $other->warnings);
        foreach ($other->unchangedSheets as $id => $row) {
            if (isset($this->unchangedSheets[$id])) {
                $this->unchangedSheets[$id]['count'] += $row['count'];
                $this->unchangedSheets[$id]['total'] = round($this->unchangedSheets[$id]['total'] + $row['total'], 2);
            } else {
                $this->unchangedSheets[$id] = $row;
            }
        }
        $this->newCustomerIds = array_values(array_unique(array_merge($this->newCustomerIds, $other->newCustomerIds)));
        $this->seenKeys += $other->seenKeys;
        $this->supplements = $this->supplements + $other->supplements;
    }

    public function bucket(string $name): array
    {
        return $this->buckets[$name] ?? ['count' => 0, 'total' => 0.0];
    }
}
