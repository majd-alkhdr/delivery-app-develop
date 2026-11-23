<?php

namespace App\Services\Reps;

use App\Models\Customer;
use App\Models\RepPlanVisit;
use App\Support\Excel\WritesSpreadsheets;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * المرحلة 8B: تصدير التغطية إلى Excel: صفحة ملخص (لكل مندوب ونطاق) وصفحة تفصيل (صف لكل صيدلية-يوم بكل الحقول).
 * مقيَّد بنطاق المشرف (البيانات من RepCoverageService بالحصر نفسه) والترويسة تذكره.
 */
class RepCoverageExcel
{
    use WritesSpreadsheets;

    private const DETAIL = ['التاريخ', 'اليوم', 'المندوب', 'المشرف', 'النطاق', 'الصيدلية', 'المنطقة', 'الحالة', 'سبب عدم الزيارة', 'وقت الزيارة',
        'حالة الموقع', 'المسافة (م)', 'دقة GPS (م)', 'خط عرض الزيارة', 'خط طول الزيارة', 'خط عرض الصيدلية', 'خط طول الصيدلية', 'ملاحظة الموقع', 'صُحّحت'];

    private const SUMMARY = ['المندوب', 'النطاق', 'المخطَّط', 'المزارة', 'لم تُزَر', 'المتبقية', 'نسبة التغطية %', 'مطابقة', 'نسبة المطابقة %', 'بعيدة', 'بلا GPS', 'دقة ضعيفة', 'بلا موقع صيدلية'];

    public function build(array $data, string $scopeText): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $title = 'تغطية المندوبين '.$data['from'].' — '.$data['to'].' — '.$scopeText;

        // الملخص
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('الملخص');
        $ws->mergeCells('A1:M1');
        $this->text($ws, 'A1', $title);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $this->headerRow($ws, 2, self::SUMMARY);
        $row = 3;
        foreach ($data['cards'] as $c) {
            $values = [$data['reps'][$c['rep_id']]->name ?? '#'.$c['rep_id'], $c['scope'], $c['planned'], $c['visited'], $c['not_visited'], $c['remaining'],
                RepCoverageService::pct($c['visited'], $c['planned']), $c['location']['matched'], RepCoverageService::pct($c['location']['matched'], $c['visited']),
                $c['location']['mismatch'], $c['location']['no_gps'], $c['location']['low_accuracy'], $c['location']['no_pharmacy_location']];
            $this->row($ws, $row++, $values);
        }
        $this->borders($ws, 'A2:M'.max(2, $row - 1));
        $this->widths($ws, ['A' => 20, 'B' => 26, 'C' => 10, 'D' => 10, 'E' => 10, 'F' => 10, 'G' => 13, 'H' => 10, 'I' => 14, 'J' => 10, 'K' => 10, 'L' => 11, 'M' => 15]);
        $this->a4Landscape($ws, 'M', max(2, $row - 1), 2, $title);

        // التفصيل
        $ws = $spreadsheet->createSheet();
        $ws->setTitle('التفصيل');
        $ws->mergeCells('A1:S1');
        $this->text($ws, 'A1', $title);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $this->headerRow($ws, 2, self::DETAIL);
        $customers = [];
        foreach (array_chunk(array_values(array_unique(array_column($data['rows'], 'customer_id'))), 1000) as $chunk) {
            $customers += Customer::whereIn('id', $chunk)->get(['id', 'name', 'area'])->keyBy('id')->all();
        }
        $row = 3;
        foreach ($data['rows'] as $r) {
            /** @var ?RepPlanVisit $v */
            $v = $r['visit'];
            $c = $customers[$r['customer_id']] ?? null;
            $this->row($ws, $row++, [
                $r['date'], RepPlanService::dayName($r['date']), $data['reps'][$r['rep_id']]->name ?? '', (string) $r['supervisor'], $r['scope'],
                (string) $c?->name, (string) $c?->area,
                $v ? ($v->status === RepPlanVisit::VISITED ? 'تمت الزيارة' : 'لم تتم') : 'متبقية',
                $v && $v->status === RepPlanVisit::NOT_VISITED ? (RepPlanVisit::REASONS[$v->reason_code] ?? '').($v->reason_text ? ': '.$v->reason_text : '') : '',
                $v?->visited_at?->copy()->setTimezone(RepPlanService::TZ)->format('H:i') ?? '',
                $v?->location_status ? RepPlanVisit::LOCATION_LABELS[$v->location_status] : '',
                $v?->distance_m, $v?->accuracy_m, $v?->lat, $v?->lng, $v?->pharmacy_lat, $v?->pharmacy_lng, (string) $v?->location_note,
                $v?->corrected_at ? 'نعم' : '',
            ]);
        }
        $this->borders($ws, 'A2:S'.max(2, $row - 1));
        $this->widths($ws, ['A' => 12, 'B' => 9, 'C' => 18, 'D' => 16, 'E' => 22, 'F' => 26, 'G' => 14, 'H' => 12, 'I' => 22, 'J' => 9, 'K' => 18,
            'L' => 10, 'M' => 10, 'N' => 12, 'O' => 12, 'P' => 12, 'Q' => 12, 'R' => 30, 'S' => 8]);
        $this->a4Landscape($ws, 'S', max(2, $row - 1), 2, $title);
        $spreadsheet->setActiveSheetIndex(0);

        return $this->save($spreadsheet);
    }

    private function row($ws, int $row, array $values): void
    {
        foreach ($values as $i => $value) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1).$row;
            is_string($value) ? $this->text($ws, $cell, $value) : $ws->setCellValue($cell, $value);
        }
    }
}
