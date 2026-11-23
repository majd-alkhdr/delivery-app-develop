<?php

namespace App\Services\Finance;

use App\Models\User;
use App\Support\Excel\WritesSpreadsheets;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * قائمة دفعات المندوبين إلى Excel (المرحلة 6B): تقرير للقراءة فقط، ليس «التصدير للمحاسبة» ولا يغيّر حالة أي دفعة.
 */
class RepPaymentsListExcel
{
    use WritesSpreadsheets;

    private const HEADERS = ['المندوب', 'مركز الكلفة', 'الصيدلية', 'المنطقة', 'المبلغ', 'العملة', 'السعر', 'بالليرة', 'رقم الدفتر', 'وقت التحصيل', 'المراجعة', 'الحالة', 'تنبيهات'];

    private const REVIEW = ['none' => 'لم تُراجع', 'verified' => 'تم التحقق', 'needs_review' => 'بحاجة مراجعة'];

    /**
     * @return array{spreadsheet: Spreadsheet, filename: string, ascii_filename: string}
     */
    public function build(Collection $payments, User $by, ?string $scopeLabel = null): array
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(11);
        $spreadsheet->getProperties()->setCreator($by->name)->setTitle('دفعات المندوبين');
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle('دفعات المندوبين');

        $date = now('Asia/Damascus')->format('Y-m-d');
        $ws->mergeCells('A1:M1');
        $this->text($ws, 'A1', 'دفعات المندوبين — '.$date.' — عدد: '.$payments->count()
            // المرحلة 8A: قائمة مشرف مندوبين بحصر مفعّل
            .($scopeLabel ? ' — مقيَّد بنطاق: '.preg_replace('/^النطاق: /u', '', $scopeLabel) : ''));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $headerRow = 2;
        $this->headerRow($ws, $headerRow, self::HEADERS);
        $row = $headerRow + 1;
        foreach ($payments as $p) {
            $flags = array_filter([
                $p->isVoided() ? 'ملغاة' : null,
                $p->exceeds_known_debt ? 'أكبر من الدَين المعروف' : null,
                $p->duplicate_book_no ? 'رقم دفتر مكرر' : null,
            ]);
            $this->text($ws, "A{$row}", (string) $p->enteredBy?->name);
            $this->text($ws, "B{$row}", (string) ($p->cost_center_label ?? $p->enteredBy?->cost_center_label));
            $this->text($ws, "C{$row}", (string) $p->customer?->name);
            $this->text($ws, "D{$row}", (string) $p->customer?->area);
            $ws->setCellValue("E{$row}", round((float) $p->amount, 2));
            $this->text($ws, "F{$row}", $p->currency === 'USD' ? 'دولار' : 'ليرة');
            $ws->setCellValue("G{$row}", $p->exchange_rate !== null ? (float) $p->exchange_rate : null);
            $ws->setCellValue("H{$row}", $p->amount_syp !== null ? round((float) $p->amount_syp, 2) : null);
            $this->text($ws, "I{$row}", (string) $p->book_receipt_no);
            $this->text($ws, "J{$row}", (string) $p->collected_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
            $this->text($ws, "K{$row}", self::REVIEW[$p->review_status] ?? (string) $p->review_status);
            $this->text($ws, "L{$row}", $p->status === 'pending' ? 'غير مُصدَّرة' : 'مُصدَّرة');
            $this->text($ws, "M{$row}", implode('، ', $flags));
            $row++;
        }
        $last = max($headerRow, $row - 1);
        if ($last > $headerRow) {
            $ws->getStyle('E'.($headerRow + 1).":H{$last}")->getNumberFormat()->setFormatCode('#,##0.00');
            $ws->getStyle('E'.($headerRow + 1).":H{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        $this->borders($ws, "A{$headerRow}:M{$last}");
        $this->widths($ws, ['A' => 18, 'B' => 24, 'C' => 28, 'D' => 16, 'E' => 13, 'F' => 8, 'G' => 9, 'H' => 14, 'I' => 12, 'J' => 16, 'K' => 13, 'L' => 12, 'M' => 26]);
        $this->a4Landscape($ws, 'M', $last, $headerRow, 'دفعات المندوبين — '.$date, $date);

        return [
            'spreadsheet' => $spreadsheet,
            'filename' => 'دفعات المندوبين - '.$date.'.xlsx',
            'ascii_filename' => 'rep_payments_'.$date.'.xlsx',
        ];
    }
}
