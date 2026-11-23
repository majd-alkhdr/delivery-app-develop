// عرض تفاصيل الفاتورة المشترك بين الموزع وكشوف المندوب (PHASE_6B_FIX_REP_LINES)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderInvoiceDetails, escapeHtml } from '../../public/js/invoice-view.js';

const base = {
    id: 7, number: 'ع G: 13722', series: 'G', series_label: 'General', series_color: '#1d4ed8',
    invoice_date: '2026-10-12', delivery_date: '2026-10-13', cost_center: '655231-كرم الخالدي', cost_center_name: 'كرم الخالدي',
    invoice_note: null, entered_by: 'مدخل', total: 2945.75, is_zero_value: false, mismatch: false,
};

test('فاتورة بحسم: عمود الحسم % والتذييل «المجموع قبل الحسم/حسم الأقلام/الصافي» والترويسة كاملة', () => {
    const html = renderInvoiceDetails({
        ...base, has_discount: true, gross_total: 3095, discount_total: 149.25, lines_total: 2945.75,
        lines: [
            { item_name: 'صنف 1', item_group: 'مجموعة', quantity: 3, gift_quantity: 0, unit_price: 102, line_total: 306, discount_percent: 10, discount_amount: 30.6 },
            { item_name: 'صنف 3', item_group: null, quantity: 4, gift_quantity: 1, unit_price: 255, line_total: 1020, discount_percent: 0, discount_amount: 0 },
        ],
    });
    assert.ok(html.includes('<th class="px-1">الحسم %</th>'));
    assert.ok(html.includes('>10%<'));
    assert.ok(html.includes('المجموع قبل الحسم') && html.includes('3,095.00'));
    assert.ok(html.includes('حسم الأقلام') && html.includes('149.25'));
    assert.ok(html.includes('الصافي') && html.includes('2,945.75'));
    assert.ok(html.includes('هدية 1'));
    assert.ok(html.includes('مجموعة'));
    for (const text of ['ع G: 13722', 'تاريخ التسليم', '2026-10-13', '655231-كرم الخالدي', 'المدخِل', 'إجمالي الفاتورة']) {
        assert.ok(html.includes(text), text);
    }
    assert.ok(!html.includes('البيان:'));   // بلا بيان ⇒ لا سطر بيان
    assert.ok(!html.includes('طباعة'));     // لا أزرار طباعة داخل المحتوى
});

test('فاتورة بلا حسم: الشكل العادي، وصفرية وبيان وعدم تطابق، والنص مُهرَّب', () => {
    const html = renderInvoiceDetails({
        ...base, has_discount: false, lines_total: 50, total: 60, mismatch: true, is_zero_value: true,
        invoice_note: '<b>عاجل</b>', lines: [{ item_name: 'أ', quantity: 1, gift_quantity: 0, unit_price: 50, line_total: 50 }],
    });
    assert.ok(!html.includes('الحسم %'));
    assert.ok(!html.includes('حسم الأقلام'));
    assert.ok(html.includes('مجموع الأسطر'));
    assert.ok(html.includes('هدية / صفر'));
    assert.ok(html.includes('مجموع الأسطر لا يطابق إجمالي الفاتورة'));
    assert.ok(html.includes('البيان: &lt;b&gt;عاجل&lt;/b&gt;'));
    assert.equal(escapeHtml('"a" & <b>'), '&quot;a&quot; &amp; &lt;b&gt;');
});
