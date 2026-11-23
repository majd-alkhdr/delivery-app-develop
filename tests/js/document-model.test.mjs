// node --test tests/js/  (بلا أي اعتماديات)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { buildReceipt, buildMergedInvoice, buildInvoice, itemBlocks, localDueBefore } from '../../public/js/print/document-model.js';

const opts = { headerTitle: 'مستودع نيو هبة للأدوية', footerText: 'شكراً لتعاملكم معنا', markDuplicates: true, duplicateNumber: 1 };
const texts = doc => doc.blocks.map(b => [b.text, b.key, b.value, ...(b.cells || []).map(c => c.text)].filter(Boolean).join(' ')).join('\n');
const kv = (doc, key) => doc.blocks.find(b => b.key === key)?.value;

const base = {
    receipt_number: 'ABC-20261013-1', date: '2026-10-13 10:00', pharmacy: 'صيدلية الشمال', area: 'منطقة الشمال',
    delivery_order_no: '3918', entered_by: 'الموزع الأول', note: null, pending_sync: false,
};

test('إيصال بالليرة: المبلغ والرصيد المستحق قبل وبعد', () => {
    const doc = buildReceipt({ ...base, amount: 1500, currency: 'SYP', amount_syp: 1500, due_before: 94444.43, due_after: 92944.43 }, opts);
    assert.equal(doc.kind, 'receipt');
    assert.equal(doc.blocks.find(b => b.type === 'total').value, '1,500.00 ل.س');
    assert.equal(kv(doc, 'الرصيد المستحق قبل'), '94,444.43');
    assert.equal(kv(doc, 'الرصيد المستحق بعد'), '92,944.43');
    assert.equal(kv(doc, 'رقم الإيصال'), 'ABC-20261013-1');
    assert.equal(kv(doc, 'أمر التسليم'), '#3918');
    assert.ok(texts(doc).includes('إيصال قبض'));
    assert.ok(texts(doc).includes('شكراً لتعاملكم معنا'));
    assert.ok(!texts(doc).includes('سعر الصرف'));
    assert.ok(!texts(doc).includes('نسخة مكررة'));
});

test('إيصال دولار بسعر: السعر وما يعادلها بالليرة والرصيد بعد', () => {
    const doc = buildReceipt({ ...base, amount: 100, currency: 'USD', exchange_rate: 140, amount_syp: 14000, due_before: 50000, due_after: 36000 }, opts);
    assert.equal(doc.blocks.find(b => b.type === 'total').value, '100.00 دولار');
    assert.equal(kv(doc, 'سعر الصرف'), '140');
    assert.equal(kv(doc, 'ما يعادلها بالليرة'), '14,000.00 ل.س');
    assert.equal(kv(doc, 'الرصيد المستحق بعد'), '36,000.00');
    assert.ok(!texts(doc).includes('يُحسب بسعر الصرف لاحقاً'));
});

test('إيصال دولار بلا سعر: «يُحسب لاحقاً» ولا رصيد «بعد»', () => {
    const doc = buildReceipt({ ...base, amount: 50, currency: 'USD', exchange_rate: null, amount_syp: null, due_before: 50000, due_after: 50000 }, opts);
    assert.ok(texts(doc).includes('يُحسب بسعر الصرف لاحقاً'));
    assert.equal(kv(doc, 'الرصيد المستحق قبل'), '50,000.00');
    assert.equal(kv(doc, 'الرصيد المستحق بعد'), undefined);
    assert.equal(kv(doc, 'ما يعادلها بالليرة'), undefined);
});

test('إيصال دون اتصال بلا نسخة مخزّنة: بلا سطري الرصيد ومع «بانتظار المزامنة»', () => {
    const doc = buildReceipt({ ...base, amount: 700, currency: 'SYP', amount_syp: 700, due_before: null, due_after: null, pending_sync: true }, opts);
    assert.equal(kv(doc, 'الرصيد المستحق قبل'), undefined);
    assert.equal(kv(doc, 'الرصيد المستحق بعد'), undefined);
    assert.ok(texts(doc).includes('بانتظار المزامنة'));
});

test('وسم النسخة المكررة أعلى المستند وحسب الإعداد', () => {
    const dup = buildReceipt({ ...base, amount: 1, currency: 'SYP', amount_syp: 1 }, { ...opts, duplicateNumber: 2 });
    assert.equal(dup.blocks[0].text, 'نسخة مكررة (رقم 2)');
    assert.equal(dup.blocks[0].inverse, true);

    const off = buildReceipt({ ...base, amount: 1, currency: 'SYP', amount_syp: 1 }, { ...opts, duplicateNumber: 3, markDuplicates: false });
    assert.ok(!texts(off).includes('نسخة مكررة'));

    const first = buildReceipt({ ...base, amount: 1, currency: 'SYP', amount_syp: 1 }, { ...opts, duplicateNumber: 1 });
    assert.ok(!texts(first).includes('نسخة مكررة'));
});

const merged = {
    customer_id: 7, customer_name: 'صيدلية الشمال', area: 'منطقة الشمال', delivery_order_no: '3918', distribution_date: '2026-10-13',
    invoice_labels: ['g-1', 'a-2', 'b-3'],
    lines: [
        { item_name: 'بانادول', item_group: 'أ', unit_price: 100, is_gift: false, quantity: 2, gift_quantity: 0, line_total: 200 },
        { item_name: 'شراب سعال', item_group: 'ب', unit_price: 0, is_gift: true, quantity: 0, gift_quantity: 1, line_total: 0 },
    ],
    lines_total: 200, source_total: 200, mismatch: false,
    series_subtotals: [{ series: 'G', label: 'General', count: 1, total: 150 }, { series: 'A', label: 'Hammers Team', count: 2, total: 50 }],
    due_balance: 94444.43,
};

test('الفاتورة المجمّعة: «تشمل»، الهدية، المجاميع الجزئية، والرصيد المستحق وحده', () => {
    const doc = buildMergedInvoice(merged, opts);
    const all = texts(doc);
    assert.equal(doc.kind, 'merged');
    assert.ok(all.includes('تشمل الفواتير: g-1 · a-2 · b-3'));
    assert.ok(doc.blocks.some(b => b.type === 'title' && b.text === 'فاتورة'));
    assert.ok(!all.includes('مجمّعة'));                       // عنوان عادي بلا وسم يميّزها
    assert.ok(all.includes('هدية'));
    assert.ok(all.includes('2 × 100.00'));
    assert.equal(kv(doc, 'General'), '150.00');
    assert.equal(kv(doc, 'Hammers Team'), '50.00');
    assert.equal(doc.blocks.find(b => b.type === 'total').value, '200.00');
    assert.equal(kv(doc, 'الرصيد المستحق'), '94,444.43');
    assert.deepEqual(doc.warnings, []);
});

test('عدم التطابق تنبيه في المعاينة فقط لا على الورقة', () => {
    const doc = buildMergedInvoice({ ...merged, mismatch: true }, opts);
    assert.deepEqual(doc.warnings, ['mismatch']);
    assert.ok(!texts(doc).includes('لا يطابق'));
});

test('الفاتورة الفردية وسطرا الصنف بنمط الإيصالات', () => {
    const doc = buildInvoice({ number: 'ع G: 1', series_label: 'General', invoice_date: '2026-10-12', total: 300, mismatch: false,
        lines: [{ item_name: 'صنف', quantity: 3, gift_quantity: 1, unit_price: 100, line_total: 300 }] }, { ...opts, pharmacy: 'صيدلية' });
    assert.ok(texts(doc).includes('3 × 100.00'));
    assert.ok(texts(doc).includes('= 300.00'));
    assert.ok(texts(doc).includes('+ هدية 1'));
    assert.equal(itemBlocks({ item_name: 'هدية فقط', quantity: 0, gift_quantity: 2, unit_price: 0, line_total: 0 })[1].cells[0].text, 'هدية');
});

// المرحلة 5-H1: «الرصيد قبل» دون اتصال = المستحق − المدفوع اليوم على الخادم − الدفعات المحلية المعلّقة
test('localDueBefore يطرح paid_today_syp والدفعات المحلية المعلّقة', () => {
    assert.equal(localDueBefore({ due_balance: 10000, paid_today_syp: 1500, pending_syp: 250.5 }), 8249.5);
    assert.equal(localDueBefore({ due_balance: 10000 }), 10000);
    assert.equal(localDueBefore({ due_balance: null, paid_today_syp: 5 }), null);
    assert.equal(localDueBefore({ due_balance: 0.3, paid_today_syp: 0.1, pending_syp: 0.1 }), 0.1);   // بلا أخطاء float
});

test('buildReceipt يحسب «قبل» و«بعد» محلياً من due_balance وpaid_today_syp وpending_syp', () => {
    const doc = buildReceipt({ ...base, amount: 1000, currency: 'SYP', amount_syp: 1000, due_balance: 98765.43, paid_today_syp: 1500, pending_syp: 265.43, pending_sync: true }, opts);
    assert.equal(kv(doc, 'الرصيد المستحق قبل'), '97,000.00');
    assert.equal(kv(doc, 'الرصيد المستحق بعد'), '96,000.00');
    assert.ok(texts(doc).includes('بانتظار المزامنة'));

    // دولار بسعر: «بعد» بالمكافئ بالليرة
    const usd = buildReceipt({ ...base, amount: 10, currency: 'USD', exchange_rate: 139, amount_syp: 1390, due_balance: 5000, paid_today_syp: 0, pending_syp: 0 }, opts);
    assert.equal(kv(usd, 'الرصيد المستحق قبل'), '5,000.00');
    assert.equal(kv(usd, 'الرصيد المستحق بعد'), '3,610.00');
    assert.equal(kv(usd, 'سعر الصرف'), '139');

    // due_before الصريح (من الخادم) يتقدّم على الحساب المحلي
    const server = buildReceipt({ ...base, amount: 1, currency: 'SYP', amount_syp: 1, due_before: 50, due_after: 49, due_balance: 999, paid_today_syp: 0 }, opts);
    assert.equal(kv(server, 'الرصيد المستحق قبل'), '50.00');
});

// المرحلة 6B: الحسم في الطباعة
import { percent, amountFooterBlocks } from '../../public/js/print/document-model.js';

test('سطر عليه حسم يطبع سطراً صغيراً «حسم 10%  −30.60» والسطر بلا حسم كما كان', () => {
    const blocks = itemBlocks({ item_name: 'صنف', quantity: 3, gift_quantity: 0, unit_price: 102, line_total: 306, discount_percent: 10, discount_amount: 30.6 });
    const last = blocks[blocks.length - 1];
    assert.equal(last.type, 'table-row');
    assert.equal(last.size, 'sm');
    assert.equal(last.cells[0].text, 'حسم 10%');
    assert.equal(last.cells[1].text, '−30.60');
    assert.equal(last.cells[1].ltr, true);

    const plain = itemBlocks({ item_name: 'صنف', quantity: 1, gift_quantity: 0, unit_price: 5, line_total: 5, discount_percent: 0, discount_amount: 0 });
    assert.equal(plain.length, 2);
    assert.equal(percent(2.5), '2.5%');
    assert.equal(percent(16), '16%');
});

test('تذييل الفاتورة: المجموع قبل الحسم وحسم الأقلام والصافي عند وجود حسم، وإلا «الإجمالي»', () => {
    const withDiscount = amountFooterBlocks(2945.75, 3095, 149.25);
    assert.deepEqual(withDiscount.map(b => [b.key, b.value]), [['المجموع قبل الحسم', '3,095.00'], ['حسم الأقلام', '149.25'], ['الصافي', '2,945.75']]);
    assert.equal(withDiscount[2].type, 'total');
    assert.deepEqual(amountFooterBlocks(200, 200, 0).map(b => [b.type, b.key, b.value]), [['total', 'الإجمالي', '200.00']]);

    const doc = buildMergedInvoice({ ...merged, lines_total: 2945.75, gross_total: 3095, discount_total: 149.25,
        lines: [{ item_name: 'صنف', quantity: 3, gift_quantity: 0, unit_price: 102, line_total: 306, discount_percent: 10, discount_amount: 30.6 }] }, opts);
    assert.equal(doc.blocks.find(b => b.type === 'total').value, '2,945.75');
    assert.equal(kv(doc, 'حسم الأقلام'), '149.25');
    assert.equal(kv(doc, 'الرصيد المستحق'), '94,444.43');   // الرصيد المستحق فقط كما كان
    const inv = buildInvoice({ number: 'ع G: 13722', total: 2945.75, gross_total: 3095, discount_total: 149.25, lines: [] }, opts);
    assert.equal(inv.blocks.find(b => b.type === 'total').key, 'الصافي');
});

test('المرحلة 7B: إعادة الطباعة بالقيم المخزّنة مع «الرصيد الحالي الآن»، والقديمة «محسوب عند الطباعة»', () => {
    const doc = buildReceipt({ ...base, amount: 900, currency: 'SYP', amount_syp: 900, due_before: 5000, due_after: 4100, current_balance: 3900, computed_at_print: false }, opts);
    assert.equal(kv(doc, 'الرصيد المستحق قبل'), '5,000.00');
    assert.equal(kv(doc, 'الرصيد المستحق بعد'), '4,100.00');
    assert.equal(kv(doc, 'الرصيد الحالي الآن'), '3,900.00');
    assert.ok(!texts(doc).includes('محسوب عند الطباعة'));

    const legacy = buildReceipt({ ...base, amount: 900, currency: 'SYP', amount_syp: 900, due_before: 5000, due_after: 4100, current_balance: null, computed_at_print: true }, opts);
    assert.ok(texts(legacy).includes('محسوب عند الطباعة'));
    assert.equal(kv(legacy, 'الرصيد الحالي الآن'), undefined);
});

test('المرحلة 7B: «قبل» دون اتصال لدفعة مُزامَنة لا يطرح دفعات اليوم مرة ثانية', () => {
    // due_balance (4100) طُرحت منه هذه الدفعة المُزامَنة (900) ⇒ «قبل» = 5000
    assert.equal(localDueBefore({ due_balance: 4100, paid_today_syp: -900, pending_syp: 0 }), 5000);
});
