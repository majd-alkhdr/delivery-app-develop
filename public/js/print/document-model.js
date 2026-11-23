/*
 * نموذج المستند المطبوع (دوال نقية: لا DOM ولا شبكة، تُختبر بـ Node).
 *
 * المستند = { kind, blocks: [...], warnings: [...] } حيث كل كتلة:
 *   { type: 'title' | 'text' | 'kv' | 'divider' | 'table-row' | 'total' | 'feed', align, size, bold, ... }
 *   - title:     { text, size: 'lg'|'xl', inverse }       عنوان في الوسط (inverse = خلفية سوداء، لوسم «نسخة مكررة»)
 *   - text:      { text, align: 'right'|'center'|'left', size: 'sm'|'md', bold, ltr }
 *   - kv:        { key, value, ltr }                       المفتاح يميناً والقيمة يساراً
 *   - divider:   {}
 *   - table-row: { cells: [{ text, align, ltr }] }         سطر تفاصيل الصنف «الكمية × الإفرادي = الإجمالي»
 *   - total:     { key, value }                            سطر إجمالي بارز
 *   - feed:      { lines }
 *
 * كل ما يُطبع للزبون يعرض الرصيد المستحق فقط (لا الخام ولا المؤجل).
 */

export function money(value) {
    const n = Number(value || 0);
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function qty(value) {
    return Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 3 });
}

function rate(value) {
    return Number(value).toLocaleString('en-US', { maximumFractionDigits: 4 });
}

function currencyLabel(currency) {
    return currency === 'USD' ? 'دولار' : 'ل.س';
}

/**
 * وسم النسخة المكررة أعلى المستند: N = رقم هذه الطباعة (2 فأكثر).
 */
function duplicateBlocks(opts) {
    const n = Number(opts.duplicateNumber || 1);
    if (!opts.markDuplicates || n < 2) return [];
    return [{ type: 'title', text: `نسخة مكررة (رقم ${n})`, size: 'lg', bold: true, inverse: true }];
}

function headerBlocks(opts, title) {
    const blocks = [];
    if (opts.headerTitle) blocks.push({ type: 'title', text: opts.headerTitle, size: 'lg', bold: true });
    blocks.push({ type: 'title', text: title, size: 'xl', bold: true });
    return blocks;
}

function footerBlocks(opts) {
    const blocks = [];
    if (opts.footerText) blocks.push({ type: 'divider' }, { type: 'text', text: opts.footerText, align: 'center', size: 'sm' });
    blocks.push({ type: 'feed', lines: 1 });
    return blocks;
}

/**
 * سطرا الصنف بنمط الإيصالات: الاسم (يلتف إن طال)، وتحته «الكمية × الإفرادي = الإجمالي». الهدية بوسم «هدية».
 */
export function itemBlocks(line) {
    const total = Number(line.line_total || 0);
    const isGift = line.is_gift === true || (Math.abs(total) < 0.005 && Number(line.gift_quantity || 0) > 0);
    const blocks = [{ type: 'text', text: line.item_name, align: 'right', bold: true }];

    if (isGift) {
        const giftQty = Number(line.gift_quantity || 0) > 0 ? line.gift_quantity : line.quantity;
        blocks.push({ type: 'table-row', cells: [{ text: 'هدية', align: 'right' }, { text: `× ${qty(giftQty)}`, align: 'left', ltr: true }] });
        return blocks;
    }

    blocks.push({
        type: 'table-row',
        cells: [
            { text: `${qty(line.quantity)} × ${money(line.unit_price)}`, align: 'right', ltr: true },
            { text: `= ${money(total)}`, align: 'left', ltr: true },
        ],
    });
    if (Number(line.gift_quantity || 0) > 0) {
        blocks.push({ type: 'text', text: `+ هدية ${qty(line.gift_quantity)}`, align: 'right', size: 'sm' });
    }
    // المرحلة 6B: سطر الحسم الصغير تحت السطر الذي عليه حسم
    const discount = Number(line.discount_amount || 0);
    if (discount > 0) {
        blocks.push({
            type: 'table-row',
            size: 'sm',
            cells: [
                { text: `حسم ${percent(line.discount_percent)}`, align: 'right' },
                { text: `−${money(discount)}`, align: 'left', ltr: true },
            ],
        });
    }
    return blocks;
}

/** «10%» أو «2.5%» بلا أصفار زائدة */
export function percent(value) {
    const n = Math.round(Number(value || 0) * 1000) / 1000;
    return `${n}%`;
}

/**
 * تذييل المبلغ: عند وجود حسم «المجموع قبل الحسم» و«حسم الأقلام» ثم «الصافي»، وإلا «الإجمالي» كما كان.
 */
export function amountFooterBlocks(net, gross, discount) {
    if (Number(discount || 0) > 0) {
        return [
            { type: 'kv', key: 'المجموع قبل الحسم', value: money(gross), ltr: true },
            { type: 'kv', key: 'حسم الأقلام', value: money(discount), ltr: true },
            { type: 'total', key: 'الصافي', value: money(net) },
        ];
    }
    return [{ type: 'total', key: 'الإجمالي', value: money(net) }];
}

const round2 = v => Math.round(Number(v) * 100) / 100;

/**
 * «الرصيد المستحق قبل» للإيصال المحلي دون اتصال (المرحلة 5):
 * due_balance (من الفاتورة المجمّعة المخزّنة) − paid_today_syp (دفعات اليوم على الخادم) − pending_syp (دفعات محلية معلّقة سابقة).
 * يعيد null إن لم يتوفر due_balance.
 */
export function localDueBefore({ due_balance, paid_today_syp = 0, pending_syp = 0 } = {}) {
    if (due_balance === null || due_balance === undefined || Number.isNaN(Number(due_balance))) return null;
    return round2(Number(due_balance) - Number(paid_today_syp || 0) - Number(pending_syp || 0));
}

/**
 * إيصال القبض.
 * data: { receipt_number, date, pharmacy, area, delivery_order_no, entered_by, amount, currency, exchange_rate,
 *         amount_syp, due_before, due_after, note, pending_sync }
 *   أو بدل due_before: { due_balance, paid_today_syp, pending_syp } فيُحسب «قبل» و«بعد» محلياً.
 * opts: { headerTitle, footerText, markDuplicates, duplicateNumber }
 */
export function buildReceipt(data, opts = {}) {
    if (data.due_before === undefined && data.due_balance !== undefined) {
        const before = localDueBefore(data);
        const hasSyp = data.amount_syp !== null && data.amount_syp !== undefined;
        data = { ...data, due_before: before, due_after: before !== null && hasSyp ? round2(before - Number(data.amount_syp)) : null };
    }
    const blocks = [...duplicateBlocks(opts), ...headerBlocks(opts, 'إيصال قبض')];

    blocks.push({ type: 'kv', key: 'رقم الإيصال', value: data.receipt_number || '—', ltr: true });
    if (data.date) blocks.push({ type: 'kv', key: 'التاريخ', value: data.date, ltr: true });
    blocks.push({ type: 'kv', key: 'الصيدلية', value: data.pharmacy || '—' });
    if (data.area) blocks.push({ type: 'kv', key: 'المنطقة', value: data.area });
    if (data.delivery_order_no) blocks.push({ type: 'kv', key: 'أمر التسليم', value: `#${data.delivery_order_no}`, ltr: true });
    if (data.entered_by) blocks.push({ type: 'kv', key: 'الموزع', value: data.entered_by });
    blocks.push({ type: 'divider' });

    const isUsd = data.currency === 'USD';
    blocks.push({ type: 'total', key: 'المبلغ', value: `${money(data.amount)} ${currencyLabel(data.currency)}` });
    const hasSyp = data.amount_syp !== null && data.amount_syp !== undefined;
    if (isUsd) {
        if (data.exchange_rate) blocks.push({ type: 'kv', key: 'سعر الصرف', value: rate(data.exchange_rate), ltr: true });
        if (hasSyp) {
            blocks.push({ type: 'kv', key: 'ما يعادلها بالليرة', value: `${money(data.amount_syp)} ل.س` });
        } else {
            blocks.push({ type: 'text', text: 'يُحسب بسعر الصرف لاحقاً', align: 'right', size: 'sm' });
        }
    }

    // الرصيد المستحق فقط؛ «بعد» يُحذف للدفعة الدولارية بلا سعر (لا يُقدَّر)
    const hasBefore = data.due_before !== null && data.due_before !== undefined;
    if (hasBefore) {
        blocks.push({ type: 'divider' });
        blocks.push({ type: 'kv', key: 'الرصيد المستحق قبل', value: money(data.due_before), ltr: true });
        const showAfter = data.due_after !== null && data.due_after !== undefined && !(isUsd && !hasSyp);
        if (showAfter) blocks.push({ type: 'kv', key: 'الرصيد المستحق بعد', value: money(data.due_after), ltr: true });
        // المرحلة 7B: الإيصال الأصلي ثابت؛ رصيد اليوم إن اختلف سطر مستقل، والدفعات القديمة «محسوب عند الطباعة»
        if (data.computed_at_print) blocks.push({ type: 'text', text: 'محسوب عند الطباعة', align: 'right', size: 'sm' });
        if (data.current_balance !== null && data.current_balance !== undefined) {
            blocks.push({ type: 'kv', key: 'الرصيد الحالي الآن', value: money(data.current_balance), ltr: true });
        }
    }

    if (data.note) blocks.push({ type: 'divider' }, { type: 'text', text: `ملاحظة: ${data.note}`, align: 'right', size: 'sm' });
    if (data.pending_sync) blocks.push({ type: 'text', text: 'بانتظار المزامنة', align: 'center', size: 'sm', bold: true });

    blocks.push(...footerBlocks(opts));
    return { kind: 'receipt', blocks, warnings: [] };
}

/**
 * فاتورة فردية (من نسخة invoiceLines المخزّنة).
 * invoice: { number, series_label, invoice_date, cost_center_name, invoice_note, total, lines, mismatch, is_zero_value }
 * ctx: { pharmacy, area, delivery_order_no, distribution_date } + opts
 */
export function buildInvoice(invoice, ctx = {}) {
    const blocks = [...duplicateBlocks(ctx), ...headerBlocks(ctx, 'فاتورة')];

    blocks.push({ type: 'kv', key: 'رقم الفاتورة', value: invoice.number, ltr: true });
    if (invoice.series_label) blocks.push({ type: 'kv', key: 'السلسلة', value: invoice.series_label });
    if (invoice.invoice_date) blocks.push({ type: 'kv', key: 'التاريخ', value: invoice.invoice_date, ltr: true });
    if (ctx.pharmacy) blocks.push({ type: 'kv', key: 'الصيدلية', value: ctx.pharmacy });
    if (ctx.area) blocks.push({ type: 'kv', key: 'المنطقة', value: ctx.area });
    if (ctx.delivery_order_no) blocks.push({ type: 'kv', key: 'أمر التسليم', value: `#${ctx.delivery_order_no}`, ltr: true });
    if (invoice.cost_center_name) blocks.push({ type: 'kv', key: 'مركز الكلفة', value: invoice.cost_center_name });
    if (invoice.invoice_note) blocks.push({ type: 'text', text: `البيان: ${invoice.invoice_note}`, align: 'right', size: 'sm' });
    if (invoice.is_zero_value) blocks.push({ type: 'text', text: 'هدية / صفر', align: 'center', bold: true });
    blocks.push({ type: 'divider' });

    for (const line of invoice.lines || []) blocks.push(...itemBlocks(line));

    blocks.push({ type: 'divider' });
    blocks.push(...amountFooterBlocks(invoice.total, invoice.gross_total, invoice.discount_total));
    blocks.push(...footerBlocks(ctx));

    // عدم التطابق تنبيه في المعاينة فقط، لا على الورقة
    return { kind: 'invoice', blocks, warnings: invoice.mismatch ? ['mismatch'] : [] };
}

/**
 * الفاتورة المجمّعة للصيدلية (كتلة merged الجاهزة من الخادم؛ لا إعادة حساب للدمج هنا).
 * merged: { customer_name, area, delivery_order_no, distribution_date, invoice_labels, lines, lines_total,
 *           series_subtotals, due_balance, mismatch }
 */
export function buildMergedInvoice(merged, opts = {}) {
    // عنوان «فاتورة» عادي بلا ختم أو وسم يميّزها (هي الفاتورة الأصلية للصيدلي)
    const blocks = [...duplicateBlocks(opts), ...headerBlocks(opts, 'فاتورة')];

    blocks.push({ type: 'kv', key: 'الصيدلية', value: merged.customer_name || '—' });
    if (merged.area) blocks.push({ type: 'kv', key: 'المنطقة', value: merged.area });
    if (merged.delivery_order_no) blocks.push({ type: 'kv', key: 'أمر التسليم', value: `#${merged.delivery_order_no}`, ltr: true });
    if (merged.distribution_date) blocks.push({ type: 'kv', key: 'تاريخ التوزيع', value: merged.distribution_date, ltr: true });
    const labels = merged.invoice_labels || [];
    blocks.push({ type: 'text', text: `تشمل الفواتير: ${labels.join(' · ')}`, align: 'right', size: 'sm' });
    blocks.push({ type: 'divider' });

    for (const line of merged.lines || []) blocks.push(...itemBlocks(line));

    blocks.push({ type: 'divider' });
    blocks.push(...amountFooterBlocks(merged.lines_total, merged.gross_total, merged.discount_total));
    for (const sub of merged.series_subtotals || []) {
        blocks.push({ type: 'kv', key: sub.label || sub.series || '—', value: money(sub.total), ltr: true, size: 'sm' });
    }
    blocks.push({ type: 'divider' });
    blocks.push({ type: 'kv', key: 'الرصيد المستحق', value: money(merged.due_balance), ltr: true, bold: true });
    blocks.push(...footerBlocks(opts));

    return { kind: 'merged', blocks, warnings: merged.mismatch ? ['mismatch'] : [] };
}

/**
 * مستندات صفحة الاختبار (بلا بيانات حقيقية).
 */
export function buildTestText(opts = {}) {
    return {
        kind: 'test',
        warnings: [],
        blocks: [
            ...headerBlocks(opts, 'اختبار نص عربي'),
            { type: 'text', text: 'نص عربي طويل لاختبار الالتفاف داخل عرض الورقة: صيدلية الشفاء المركزية — حي الميدان، دمشق.', align: 'right' },
            { type: 'kv', key: 'أرقام لاتينية', value: '0123456789', ltr: true },
            { type: 'kv', key: 'مبلغ', value: money(1234567.89), ltr: true },
            { type: 'text', text: 'فاتورة ع G: 12830 بتاريخ 2026-10-13 بقيمة 3,154.00 ل.س', align: 'right' },
            { type: 'text', text: 'Latin + عربي mixed 42 ×3', align: 'center' },
            ...footerBlocks(opts),
        ],
    };
}

export function buildCalibration() {
    // نمط المعايرة يُرسم خاصاً في renderer (خطوط الحافتين ومسطرة ومربع)
    return { kind: 'calibration', warnings: [], blocks: [{ type: 'calibration' }, { type: 'feed', lines: 1 }] };
}

export function sampleReceiptData() {
    return {
        receipt_number: 'TST-20261013-1', date: '2026-10-13 10:30', pharmacy: 'صيدلية تجريبية', area: 'منطقة تجريبية',
        delivery_order_no: '0000', entered_by: 'موزع تجريبي', amount: 100, currency: 'USD', exchange_rate: 139,
        amount_syp: 13900, due_before: 250000, due_after: 236100, note: 'إيصال اختبار', pending_sync: false,
    };
}

export function sampleLongInvoice(lines = 60) {
    const items = [];
    for (let i = 1; i <= lines; i++) {
        items.push({ item_name: `صنف تجريبي رقم ${i} باسم طويل نسبياً لاختبار الالتفاف`, item_group: 'مجموعة', quantity: i % 7 + 1, gift_quantity: i % 10 === 0 ? 1 : 0, unit_price: 1250.5, line_total: (i % 7 + 1) * 1250.5 });
    }
    const total = items.reduce((s, l) => s + l.line_total, 0);
    return { number: 'ع T: 1', series_label: 'اختبار', invoice_date: '2026-10-13', total, lines: items, mismatch: false };
}
