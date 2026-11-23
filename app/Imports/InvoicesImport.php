<?php

namespace App\Imports;

use App\Models\Invoice;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithStartRow;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
// 1. قمنا باستيراد الواجهة المسؤولة عن حساب المعادلات تلقائياً هنا
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

// 2. قمنا بإضافة الواجهة (WithCalculatedFormulas) في سطر تعريف الكلاس ليتم تفعيلها
/**
 * @deprecated منذ المرحلة 2: صيغة الأعمدة القديمة (B وC وE...) لم تعد تُرفع من الواجهة.
 *             الرفع الحالي عبر App\Services\Import\WorkbookImporter و SheetImportService.
 *             يبقى الكلاس للرجوع إليه فقط ولا يُستدعى من أي مكان.
 */
class InvoicesImport implements ToModel, WithStartRow, WithMultipleSheets
{
    private $sheetId;

    public function __construct($sheetId)
    {
        $this->sheetId = $sheetId;
    }

    public function sheets(): array
    {
        return [
            0 => $this,
        ];
    }

    public function startRow(): int
    {
        return 4; // تبدأ القراءة من السطر الرابع
    }

   public function model(array $row)
    {
        // 1. التأكد من وجود الأعمدة الأساسية في السطر
        if (!isset($row[1]) || !isset($row[2]) || !isset($row[5])) {
            return null;
        }

        $invoiceNumber = trim($row[1]);
        $customerName = trim($row[2]);

        if (empty($invoiceNumber) || empty($customerName)) {
            return null;
        }

        // 2. دالة مساعدة مطورة ومحصنة لتنظيف الأرقام المحسوبة تلقائياً من المعادلات
        $parseMoney = function($value) {
            if ($value === null || $value === '') return 0.00;

            // تحويل القيمة لنص صريح للتعامل معها بدقة
            $strValue = trim($value);

            // خط دفاع احتياطي: إذا لم يستطع محرك الإكسل حسابها لسبب ما وبقيت كمعادلة نصية
            if (strpos($strValue, '=') === 0) {
                return 0.00;
            }

            // تنظيف الفواصل العشريّة والمسافات الناتجة عن تنسيق الإكسل
            $cleaned = str_replace([',', ' '], '', $strValue);

            return is_numeric($cleaned) ? (float)$cleaned : 0.00;
        };

        // 3. جلب القيم المحسوبة من المعادلات مباشرة (أعمدة F, G, L)
        $invoiceAmount         = $parseMoney($row[5]);  // القيمة الصافية لفاتورة السطر
        $customerTotalInvoices = $parseMoney($row[6]);  // إجمالي فواتير الزبون المحسوبة بالمعادلة
        $finalBalance          = $parseMoney($row[11]); // الرصيد الإجمالي النهائي المحسوب بالمعادلة

        // === [ تعديل الخطوة الثالثة: السماح بالفواتير الصفرية والهدايا ] ===

        // الكود القديم الذي كان يحذف الفاتورة تم استبداله بهذا الفحص الذكي:
        // يتجاهل السطر فقط إذا كان اسم الزبون ورقم الفاتورة فارغين،
        // أو إذا كان السطر بالكامل لا يحتوي على أي قيمة مالية ولا رقم فاتورة حقيقي
        if (empty($invoiceNumber) && $invoiceAmount == 0 && $customerTotalInvoices == 0) {
            return null;
        }

        // ===================================================================

        // 4. فك تشفير النمط (العمود H) سواء كان قيمة نصية أو ناتج معادلة إكسل مثل =LEFT()
        $rawPattern = isset($row[7]) ? trim($row[7]) : '';

        // تحويل النص لحروف صغيرة لحمايتها، وإذا كان الناتج يحتوي على = أو فارغ، يتم تفعيل خط الدفاع الذكي
        if (strpos($rawPattern, '=') === 0 || empty($rawPattern)) {
            if (preg_match('/[mMaAbB]/i', $invoiceNumber, $matches)) {
                $invoicePattern = strtolower($matches[0]);
            } else {
                $invoicePattern = 'a'; // النمط الافتراضي الآمن (آجل)
            }
        } else {
            $invoicePattern = strtolower($rawPattern);
        }

        // 5. التقاط المنطقة ديناميكياً من العمود I (الترتيب الصِفري للمصفوفة هو 8)
        $customerArea = isset($row[8]) ? trim($row[8]) : null;

        // 6. إدخال البيانات الصافية والمحسوبة تلقائياً إلى قاعدة البيانات
        return new Invoice([
            'distribution_sheet_id'   => $this->sheetId,
            'invoice_number'          => $invoiceNumber,
            'customer_name'           => $customerName,
            'area'                    => $customerArea, // [تم حقن التعديل بنجاح لحفظ المنطقة]
            'statement'               => isset($row[4]) ? trim($row[4]) : null, // العمود E
            'invoice_amount'          => $invoiceAmount,
            'customer_total_invoices' => $customerTotalInvoices,
            'invoice_pattern'         => $invoicePattern,
            'final_balance'           => $finalBalance,
            'collected_amount'        => 0,
            'notes'                   => null,
        ]);
    }
}
