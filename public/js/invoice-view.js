/*
 * عرض تفاصيل الفاتورة في نافذة (مشترك بين صفحة الموزع وكشوف المندوب، PHASE_6B_FIX_REP_LINES).
 * دالة نقية: تأخذ فاتورة بصيغة InvoiceLinesView::payload وترجع HTML النافذة (بلا DOM ولا شبكة)، فتُختبر بـ Node.
 * لا أزرار طباعة ولا دفع هنا: الأزرار في تذييل النافذة الخاص بكل صفحة.
 */

export function escapeHtml(text) {
    return String(text ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

export function money(value) {
    return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function qty(value) {
    return Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 3 });
}

export function pct(value) {
    return `${Math.round(Number(value || 0) * 1000) / 1000}%`;
}

/**
 * HTML تفاصيل الفاتورة: الترويسة (السلسلة والرقم، التاريخ، تاريخ التسليم، مركز الكلفة، البيان، المدخِل، إجمالي الفاتورة،
 * «هدية / صفر»)، والجدول (الإفرادي والإجمالي قبل الحسم، وعمود الحسم % وتذييل «المجموع قبل الحسم/حسم الأقلام/الصافي» عند وجود حسم)،
 * وتنبيه عدم تطابق الإجمالي.
 */
export function renderInvoiceDetails(invoice) {
    const hasDiscount = !!invoice.has_discount;
    const lines = invoice.lines || [];
    const rows = lines.map(l => `
                <tr>
                    <td class="text-start">${escapeHtml(l.item_name)}${l.item_group ? `<div class="text-muted fs-8">${escapeHtml(l.item_group)}</div>` : ''}</td>
                    <td dir="ltr">${qty(l.quantity)}</td>
                    <td dir="ltr">${l.gift_quantity > 0 ? `<span class="badge bg-info-subtle text-info-emphasis">هدية ${qty(l.gift_quantity)}</span>` : '—'}</td>
                    <td dir="ltr">${money(l.unit_price)}</td>
                    ${hasDiscount ? `<td dir="ltr" class="text-nowrap px-1">${Number(l.discount_percent) > 0 ? pct(l.discount_percent) : '—'}</td>` : ''}
                    <td dir="ltr" class="fw-bold">${money(l.line_total)}</td>
                </tr>`).join('');
    const span = hasDiscount ? 5 : 4;
    const footer = hasDiscount
        ? `<tr class="fw-bold"><td colspan="${span}" class="text-start">المجموع قبل الحسم</td><td dir="ltr">${money(invoice.gross_total)}</td></tr>
                   <tr class="fw-bold"><td colspan="${span}" class="text-start">حسم الأقلام</td><td dir="ltr">${money(invoice.discount_total)}</td></tr>
                   <tr class="fw-bold"><td colspan="${span}" class="text-start">الصافي</td><td dir="ltr">${money(invoice.lines_total)}</td></tr>`
        : `<tr class="fw-bold"><td colspan="${span}" class="text-start">مجموع الأسطر</td><td dir="ltr">${money(invoice.lines_total)}</td></tr>`;

    return `
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <span class="badge" style="background:${escapeHtml(invoice.series_color)}">${escapeHtml(invoice.series_label || invoice.series || '')}</span>
                    <strong dir="ltr">${escapeHtml(invoice.number)}</strong>
                    ${invoice.is_zero_value ? '<span class="badge bg-info-subtle text-info-emphasis">هدية / صفر</span>' : ''}
                </div>
                <div class="row g-1 small text-muted mb-2">
                    <div class="col-6">التاريخ: <span dir="ltr">${escapeHtml(invoice.invoice_date || '—')}</span></div>
                    <div class="col-6">تاريخ التسليم: <span dir="ltr">${escapeHtml(invoice.delivery_date || '—')}</span></div>
                    <div class="col-12 text-break-word">مركز الكلفة: ${escapeHtml(invoice.cost_center || invoice.cost_center_name || '—')}</div>
                    ${invoice.invoice_note ? `<div class="col-12 text-break-word">البيان: ${escapeHtml(invoice.invoice_note)}</div>` : ''}
                    <div class="col-6">المدخِل: ${escapeHtml(invoice.entered_by || '—')}</div>
                    <div class="col-6">إجمالي الفاتورة: <span dir="ltr" class="fw-bold">${money(invoice.total)}</span></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle text-center small mb-1">
                        <thead class="table-light"><tr><th class="text-start">الصنف</th><th>الكمية</th><th>الهدايا</th><th>الإفرادي</th>${hasDiscount ? '<th class="px-1">الحسم %</th>' : ''}<th>الإجمالي</th></tr></thead>
                        <tbody>${rows}</tbody>
                        <tfoot>${footer}
                        <tr class="fw-bold"><td colspan="${span}" class="text-start">إجمالي الفاتورة</td><td dir="ltr">${money(invoice.total)}</td></tr></tfoot>
                    </table>
                </div>
                ${invoice.mismatch ? '<div class="alert alert-warning py-2 small mb-0">تنبيه: مجموع الأسطر لا يطابق إجمالي الفاتورة.</div>' : ''}`;
}

// للصفحات ذات السكربت العادي (distributor-app.js)
if (typeof globalThis !== 'undefined') {
    globalThis.InvoiceView = { renderInvoiceDetails, escapeHtml, money, qty, pct };
}
