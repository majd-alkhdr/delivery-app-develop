<?php

namespace App\Support\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * أدوات كتابة Excel المشتركة بين تصدير الكشف (SheetExportService) وتصدير المالية:
 * نص صريح محمي من حقن المعادلات، صف ترويسة، حدود، عرض الأعمدة، إعداد A4 أفقي RTL، والحفظ المؤقت.
 */
trait WritesSpreadsheets
{
    /**
     * كتابة نص صريح (ليس معادلة أبداً): حماية من حقن المعادلات دون تغيير النص نفسه.
     */
    protected function text(Worksheet $ws, string $coordinate, string $value): void
    {
        $ws->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            $ws->getStyle($coordinate)->setQuotePrefix(true);
        }
    }

    protected function headerRow(Worksheet $ws, int $row, array $headers): void
    {
        foreach ($headers as $i => $header) {
            $this->text($ws, Coordinate::stringFromColumnIndex($i + 1).$row, $header);
        }
        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex(count($headers)).$row;
        $style = $ws->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('CED4DA');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    protected function borders(Worksheet $ws, string $range): void
    {
        $ws->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('6C757D');
    }

    protected function widths(Worksheet $ws, array $widths): void
    {
        foreach ($widths as $column => $width) {
            $ws->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * A4 أفقي، عرض صفحة واحدة، هوامش ~1 سم، توسيط، تكرار صف الترويسة، بلا شبكة، RTL،
     * وترويسة/تذييل اختياريان (أرقام الصفحات وتاريخ الطباعة).
     */
    protected function a4Landscape(Worksheet $ws, string $lastColumn, int $lastRow, int $headerRow, ?string $headerText = null, ?string $footerLeft = null): void
    {
        $setup = $ws->getPageSetup();
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        $setup->setHorizontalCentered(true);
        $setup->setRowsToRepeatAtTopByStartAndEnd($headerRow, $headerRow);
        $setup->setPrintArea("A1:{$lastColumn}{$lastRow}");

        $ws->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.4)->setRight(0.4)->setHeader(0.25)->setFooter(0.25);

        if ($headerText !== null) {
            $ws->getHeaderFooter()->setOddHeader('&C&"Arial,Bold"&12'.$headerText);
            $ws->getHeaderFooter()->setOddFooter('&Rصفحة &P من &N&Cطُبع: &D &T'.($footerLeft !== null ? '&L'.$footerLeft : ''));
        }

        $ws->setShowGridlines(false);
        $ws->setPrintGridlines(false);
        $ws->setRightToLeft(true);
        $ws->freezePane('A'.($headerRow + 1));
    }

    /**
     * حفظ الملف في مسار مؤقت.
     */
    public function save(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'exp').'.xlsx';
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
