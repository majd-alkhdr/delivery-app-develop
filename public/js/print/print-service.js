/*
 * خدمة الطباعة الموحدة: print(document, { kind, ref }).
 * - الأوضاع: ble (الافتراضي) / browser / image / rawbt (معطّل، انظر rawbtSupported) / preview.
 * - كل فشل يُعرض برسالة عربية مع «معاينة / مشاركة كصورة / إعادة المحاولة / طريقة أخرى»، ولا استثناء خام.
 * - أحداث الطباعة تُحفظ في طابور محلي (printQueue:v1) وتُرسل لـ POST /print-events (idempotent).
 * - «نسخة مكررة»: من عدد الطباعات الناجحة على الخادم + الأحداث المحلية غير المرسلة.
 * - الدفعة محفوظة دائماً قبل أي طباعة، وفشل الطباعة لا يمسها.
 */
import { buildReceipt, buildInvoice, buildMergedInvoice, localDueBefore } from './document-model.js';
import { renderDocument, slicesToMonochrome, slicesToPngBlobs } from './renderer.js';
import { buildEscPos } from './escpos.js';
import { BlePrinter, printer, friendlyError } from './ble-printer.js';

const KEYS = {
    config: 'printConfig:v1',        // إعدادات الخادم (تُمسح عند الخروج)
    overrides: 'printOverrides:v1',  // تجاوزات الجهاز (تبقى)
    mode: 'printMode:v1',            // وضع الجهاز المفضّل (يبقى)
    queue: 'printQueue:v1',          // أحداث لم تُرسل (تبقى)
    counts: 'printCounts:v1',        // آخر عدد طباعات معروف من الخادم لكل مرجع
};

const DEFAULTS = {
    printing_enabled: false, print_paper_dots: 576, print_chunk_bytes: 100, print_chunk_delay_ms: 20,
    print_slice_rows: 128, print_threshold: 160, print_feed_lines: 4, print_cut: false,
    print_header_title: 'مستودع نيو هبة للأدوية', print_footer_text: 'شكراً لتعاملكم معنا', print_mark_duplicates: true,
};

// RawBT: لم نتمكن من التحقق من صيغة المخطط/الـintent في توثيقه الحالي، فالخيار معطّل عمداً (انظر التقرير).
export const rawbtSupported = false;

const page = () => window.PrintPage || {};
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

function store(key, fallback) { try { const v = localStorage.getItem(key); return v === null ? fallback : JSON.parse(v); } catch (e) { return fallback; } }
function save(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* */ } }

function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); });
}

function escapeHtml(text) { const d = document.createElement('div'); d.textContent = text ?? ''; return d.innerHTML; }

// ------------------------------------------------------------------
// الإعدادات
// ------------------------------------------------------------------
let serverConfig = store(KEYS.config, null);

export async function loadConfig() {
    const url = page().configUrl;
    if (!url || !navigator.onLine) return effectiveConfig();
    try {
        const res = await fetch(url, { headers: Object.assign({ Accept: 'application/json' }, serverConfig?.etag ? { 'If-None-Match': serverConfig.etag } : {}), credentials: 'same-origin', cache: 'no-cache' });
        if (res.ok) {
            const data = await res.json();
            serverConfig = { etag: res.headers.get('ETag') || data.etag, settings: data.settings, ranges: data.ranges };
            save(KEYS.config, serverConfig);
        }
    } catch (e) { /* نكمل بالمخزَّن */ }
    return effectiveConfig();
}

export function serverSettings() {
    return Object.assign({}, DEFAULTS, page().settings || {}, serverConfig?.settings || {});
}

export function ranges() {
    return Object.assign({}, page().ranges || {}, serverConfig?.ranges || {});
}

export function overrides() { return store(KEYS.overrides, {}) || {}; }

export function setOverrides(values) { save(KEYS.overrides, values); }

export function clearOverrides() { try { localStorage.removeItem(KEYS.overrides); } catch (e) { /* */ } }

/** إعدادات الخادم مع تجاوزات هذا الجهاز (تغلبها) */
export function effectiveConfig() {
    return Object.assign({}, serverSettings(), overrides());
}

export function preferredMode() { return store(KEYS.mode, 'ble') || 'ble'; }

export function setPreferredMode(mode) { save(KEYS.mode, mode); }

// ------------------------------------------------------------------
// أحداث الطباعة والنسخة المكررة
// ------------------------------------------------------------------
export function refKey(kind, ref) {
    if (kind === 'receipt') return `receipt:${ref.payment_client_uuid || ref.payment_id}`;
    if (kind === 'invoice') return `invoice:${ref.invoice_id}`;
    return `merged:${ref.distribution_sheet_id}:${ref.customer_id}`;
}

function queue() { return store(KEYS.queue, []) || []; }

export function pendingCount() { return queue().length; }

/** رقم هذه الطباعة إن تمت الآن (2 فأكثر = نسخة مكررة) */
export function duplicateNumber(key, serverCount = 0) {
    const known = Math.max(Number(serverCount) || 0, Number((store(KEYS.counts, {}) || {})[key]) || 0);
    const pending = queue().filter(e => e.ref_key === key && e.status === 'success' && e.mode !== 'preview' && !e.synced).length;
    return known + pending + 1;
}

export function rememberServerCount(key, count) {
    const counts = store(KEYS.counts, {}) || {};
    counts[key] = Math.max(Number(counts[key]) || 0, Number(count) || 0);
    save(KEYS.counts, counts);
}

function recordEvent(kind, ref, mode, status, error = null) {
    const event = Object.assign({
        client_uuid: uuid(), kind, mode, status, error: error ? String(error).slice(0, 480) : null,
        device_label: mode === 'ble' ? printer.deviceLabel() : null, printed_at: new Date().toISOString(),
        ref_key: refKey(kind, ref),
    }, ref);
    const items = queue();
    items.push(event);
    save(KEYS.queue, items);
    updateBadge();
    syncEvents();
}

let syncing = false;
export async function syncEvents() {
    const url = page().eventsUrl;
    if (!url || syncing || !navigator.onLine) return;
    syncing = true;
    try {
        for (const event of queue()) {
            let res;
            try {
                res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                    credentials: 'same-origin',
                    body: JSON.stringify(event),
                });
            } catch (e) { break; }
            const data = await res.json().catch(() => ({}));
            const remaining = queue();
            if (res.ok) {
                save(KEYS.queue, remaining.filter(e => e.client_uuid !== event.client_uuid));
                if (data.print_count !== undefined) rememberServerCount(event.ref_key, data.print_count);
            } else if (res.status === 404 && event.kind === 'receipt') {
                continue;   // الدفعة لم تُزامَن بعد: نعيد المحاولة لاحقاً
            } else if (res.status >= 400 && res.status < 500 && res.status !== 419) {
                save(KEYS.queue, remaining.filter(e => e.client_uuid !== event.client_uuid));
            } else {
                break;
            }
        }
    } finally {
        syncing = false;
        updateBadge();
    }
}

function updateBadge() {
    const count = pendingCount();
    document.querySelectorAll('[data-print-queue-count]').forEach(el => {
        el.textContent = count;
        el.closest('[data-print-queue-box]')?.classList.toggle('d-none', count === 0);
    });
}

// ------------------------------------------------------------------
// الطباعة
// ------------------------------------------------------------------
/**
 * @param doc    مستند من document-model
 * @param target { kind, ref } — ref: payment_id/payment_client_uuid | invoice_id | distribution_sheet_id + customer_id (null لصفحة الاختبار)
 */
export async function print(doc, target = {}, mode = preferredMode()) {
    const cfg = effectiveConfig();
    const log = target.kind ? (status, m, err) => recordEvent(target.kind, target.ref, m, status, err) : () => {};

    if (mode === 'preview') {
        await openPreview(doc, target);
        log('success', 'preview');
        return { ok: true, mode };
    }

    try {
        const slices = await renderDocument(doc, { width: cfg.print_paper_dots });
        if (mode === 'ble') {
            const reason = BlePrinter.unsupportedReason();
            if (reason) throw new Error(reason);
            const bytes = buildEscPos(slicesToMonochrome(slices, cfg.print_threshold), {
                sliceRows: cfg.print_slice_rows, feedLines: cfg.print_feed_lines, cut: cfg.print_cut,
            });
            await printer.write(bytes, { chunkBytes: cfg.print_chunk_bytes, delayMs: cfg.print_chunk_delay_ms });
        } else if (mode === 'browser') {
            await browserPrint(slices);
        } else if (mode === 'image') {
            await shareImage(slices, doc.kind);
        } else {
            throw new Error('هذه الطريقة غير متاحة.');
        }
        log('success', mode);
        notify('تمت الطباعة.', 'success');
        return { ok: true, mode };
    } catch (e) {
        const message = friendlyError(e);
        log('failed', mode, message);
        showFailure(doc, target, message);
        return { ok: false, mode, error: message };
    }
}

async function browserPrint(slices) {
    const blobs = await slicesToPngBlobs(slices);
    const urls = blobs.map(b => URL.createObjectURL(b));
    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;width:0;height:0;border:0;left:-1000px';
    document.body.appendChild(iframe);
    const docHtml = `<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>طباعة</title>
<style>@page { size: 80mm auto; margin: 2mm } html,body{margin:0;padding:0;background:#fff} img{display:block;width:100%;}</style></head>
<body>${urls.map(u => `<img src="${u}" alt="">`).join('')}</body></html>`;
    iframe.contentDocument.open();
    iframe.contentDocument.write(docHtml);
    iframe.contentDocument.close();
    await new Promise(resolve => {
        const imgs = [...iframe.contentDocument.images];
        let left = imgs.length;
        if (!left) resolve();
        imgs.forEach(img => { if (img.complete) { if (--left === 0) resolve(); } else img.onload = img.onerror = () => { if (--left === 0) resolve(); }; });
    });
    iframe.contentWindow.focus();
    iframe.contentWindow.print();
    setTimeout(() => { iframe.remove(); urls.forEach(u => URL.revokeObjectURL(u)); }, 60000);
}

async function shareImage(slices, name) {
    const blobs = await slicesToPngBlobs(slices);
    const files = blobs.map((b, i) => new File([b], `${name || 'print'}${blobs.length > 1 ? '-' + (i + 1) : ''}.png`, { type: 'image/png' }));
    if (navigator.canShare && navigator.canShare({ files })) {
        await navigator.share({ files, title: 'طباعة' });
        return;
    }
    files.forEach(file => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(file);
        a.download = file.name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 5000);
    });
}

// ------------------------------------------------------------------
// المعاينة ورسائل الفشل (نافذة واحدة تُنشأ عند الحاجة)
// ------------------------------------------------------------------
function modal() {
    let el = document.getElementById('printPreviewModal');
    if (el) return el;
    el = document.createElement('div');
    el.className = 'modal fade';
    el.id = 'printPreviewModal';
    el.tabIndex = -1;
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = `
        <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title fw-bold fs-6"><i class="fas fa-print me-1"></i> <span data-pp-title>معاينة الطباعة</span></h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body bg-light">
                    <div data-pp-alert></div>
                    <div class="mx-auto bg-white shadow-sm" style="max-width: 340px" data-pp-canvas></div>
                </div>
                <div class="modal-footer flex-wrap gap-2" data-pp-actions></div>
            </div>
        </div>`;
    document.body.appendChild(el);
    return el;
}

function actionButton(label, icon, cls, handler, disabled = false, title = '') {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = `btn ${cls} fw-bold`;
    b.innerHTML = `<i class="fas ${icon}"></i> ${escapeHtml(label)}`;
    b.disabled = disabled;
    if (title) b.title = title;
    b.addEventListener('click', handler);
    return b;
}

function fillActions(el, doc, target, { includePrint }) {
    const actions = el.querySelector('[data-pp-actions]');
    actions.innerHTML = '';
    const close = () => bootstrap.Modal.getOrCreateInstance(el).hide();
    if (includePrint) actions.append(actionButton('طباعة', 'fa-print', 'btn-primary', () => { close(); print(doc, target, preferredMode() === 'preview' ? 'ble' : preferredMode()); }));
    actions.append(
        actionButton('حفظ صورة PNG', 'fa-image', 'btn-outline-secondary', () => { close(); print(doc, target, 'image'); }),
        actionButton('طباعة المتصفح', 'fa-file-lines', 'btn-outline-secondary', () => { close(); print(doc, target, 'browser'); }),
        actionButton('RawBT', 'fa-mobile-screen', 'btn-outline-secondary', () => {}, !rawbtSupported, 'غير متاح حالياً: لم يُتحقق من صيغة RawBT'),
    );
}

export async function openPreview(doc, target = {}) {
    const el = modal();
    el.querySelector('[data-pp-title]').textContent = 'معاينة الطباعة';
    const alert = el.querySelector('[data-pp-alert]');
    alert.innerHTML = (doc.warnings || []).includes('mismatch')
        ? '<div class="alert alert-warning py-2 small">تنبيه: مجموع الأسطر لا يطابق إجمالي الفواتير (لا يظهر هذا على الورقة).</div>'
        : '';
    const box = el.querySelector('[data-pp-canvas]');
    box.innerHTML = '<div class="text-muted small p-3">جارٍ التحضير…</div>';
    bootstrap.Modal.getOrCreateInstance(el).show();

    const slices = await renderDocument(doc, { width: effectiveConfig().print_paper_dots });
    box.innerHTML = '';
    slices.forEach(c => { c.style.width = '100%'; c.style.display = 'block'; box.appendChild(c); });
    fillActions(el, doc, target, { includePrint: true });
}

function showFailure(doc, target, message) {
    const el = modal();
    el.querySelector('[data-pp-title]').textContent = 'تعذّرت الطباعة';
    el.querySelector('[data-pp-alert]').innerHTML = `<div class="alert alert-danger fw-bold">${escapeHtml(message)}</div>
        <p class="small text-muted mb-0">الدفعة/البيانات محفوظة ولم تتأثر. اختر طريقة أخرى:</p>`;
    el.querySelector('[data-pp-canvas]').innerHTML = '';
    const actions = el.querySelector('[data-pp-actions]');
    actions.innerHTML = '';
    const close = () => bootstrap.Modal.getOrCreateInstance(el).hide();
    actions.append(
        actionButton('إعادة المحاولة', 'fa-rotate-right', 'btn-primary', () => { close(); print(doc, target, 'ble'); }, !!BlePrinter.unsupportedReason()),
        actionButton('معاينة', 'fa-eye', 'btn-outline-secondary', () => openPreview(doc, target)),
        actionButton('مشاركة كصورة', 'fa-share-nodes', 'btn-outline-secondary', () => { close(); print(doc, target, 'image'); }),
        actionButton('طباعة المتصفح', 'fa-file-lines', 'btn-outline-secondary', () => { close(); print(doc, target, 'browser'); }),
    );
    bootstrap.Modal.getOrCreateInstance(el).show();
}

function notify(message, type) {
    if (window.DistributorApp?.toast) window.DistributorApp.toast(message, type);
}

// ------------------------------------------------------------------
// حالة الطابعة في الواجهة
// ------------------------------------------------------------------
const STATUS_LABELS = { disconnected: 'غير متصل', connecting: 'يتصل…', connected: 'متصل', error: 'خطأ' };
const STATUS_CLASSES = { disconnected: 'text-bg-secondary', connecting: 'text-bg-warning', connected: 'text-bg-success', error: 'text-bg-danger' };

export function bindStatus() {
    const render = (status, error, label) => {
        document.querySelectorAll('[data-printer-status]').forEach(el => {
            el.className = `badge ${STATUS_CLASSES[status] || 'text-bg-secondary'}`;
            el.textContent = `الطابعة: ${STATUS_LABELS[status] || status}${label ? ' — ' + label : ''}`;
            el.title = error || '';
        });
    };
    printer.onStatus(render);
    render(printer.status, null, printer.deviceLabel());
    // إعادة اتصال صامتة بالطابعة المصرّح بها سابقاً
    if (!BlePrinter.unsupportedReason() && navigator.bluetooth.getDevices) printer.connect({ interactive: false }).catch(() => {});
}

// ------------------------------------------------------------------
// ربط أزرار واجهة الموزع
// ------------------------------------------------------------------
function docOptions(cfg, duplicate) {
    return { headerTitle: cfg.print_header_title, footerText: cfg.print_footer_text, markDuplicates: cfg.print_mark_duplicates, duplicateNumber: duplicate };
}

function linesData() { return window.DistributorApp?.linesData || null; }

function distributorQueue() { return store('distributorQueue:v3', []) || []; }

function formatDate(ts) {
    const d = new Date(ts);
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
}

/**
 * إيصال من السجل المحلي (دون اتصال أو الدفعة معلّقة): «قبل» = merged.due_balance ناقص دفعات الزبون المحلية المعلّقة السابقة.
 */
export function localReceiptData(uuidValue, button) {
    const item = distributorQueue().find(i => i.id === uuidValue && i.kind === 'payment');
    const ds = button?.dataset || {};
    const body = item?.body || {
        receipt_number: ds.receiptNumber, customer_id: Number(ds.customerId), amount: Number(ds.amount), currency: ds.currency,
        exchange_rate: ds.rate ? Number(ds.rate) : null, note: ds.note || null,
    };
    const customerId = Number(body.customer_id);
    const card = document.querySelector(`.customer-card[data-customer-id="${customerId}"]`);
    const data = linesData();
    const merged = data?.merged?.find(m => Number(m.customer_id) === customerId);

    const amountSyp = body.currency === 'USD'
        ? (body.exchange_rate ? Math.round(body.amount * body.exchange_rate * 100) / 100 : (ds.amountSyp ? Number(ds.amountSyp) : null))
        : Number(body.amount);

    // «قبل» = المستحق − دفعات اليوم على الخادم (paid_today_syp، بلا هذه الدفعة إن كانت مُزامَنة) − الدفعات المحلية المعلّقة السابقة
    let balance = {};
    if (merged) {
        const createdAt = item?.createdAt ?? Date.now();
        const earlier = distributorQueue().filter(i => i.kind === 'payment' && i.id !== uuidValue && Number(i.body.customer_id) === customerId && i.createdAt < createdAt
            && new Date(i.createdAt).toDateString() === new Date(createdAt).toDateString());
        const pendingSyp = earlier.reduce((s, i) => s + (i.body.currency === 'USD' ? (i.body.exchange_rate ? i.body.amount * i.body.exchange_rate : 0) : Number(i.body.amount)), 0);
        // المرحلة 7B: due_balance هو الرصيد المعروض وقد طُرحت منه كل الدفعات المُزامَنة غير المنعكسة (ومنها دفعات اليوم)،
        // فلا تُطرح «دفعات اليوم» مرة ثانية؛ وإن كانت هذه الدفعة مُزامَنة تُضاف لاسترجاع «قبل».
        const selfSynced = (merged.paid_today_uuids || []).includes(uuidValue);
        balance = {
            due_balance: Number(merged.due_balance),
            paid_today_syp: selfSynced && amountSyp !== null ? -amountSyp : 0,
            pending_syp: pendingSyp,
        };
    }

    return {
        receipt_number: body.receipt_number,
        date: formatDate(item?.createdAt || ds.date || Date.now()),
        pharmacy: item?.label || ds.customerName || merged?.customer_name || '',
        area: card?.dataset.area || merged?.area || ds.area || null,
        delivery_order_no: data?.delivery_order_no || page().deliveryOrderNo || null,
        entered_by: page().userName || null,
        amount: Number(body.amount), currency: body.currency || 'SYP', exchange_rate: body.exchange_rate || null,
        amount_syp: amountSyp,
        ...(merged ? balance : { due_before: null, due_after: null }),
        note: body.note || null,
        pending_sync: !!item,
    };
}

/**
 * المرحلة 7B: ما طبعه الجهاز دون اتصال («قبل» و«بعد») يُرسل مع الدفعة عند المزامنة (device_balance_before/after)،
 * فيُحفظ كأصل على الخادم ويُسجَّل فرق إن كان الخادم يحسب رقماً مختلفاً (لا يتغيّر الإيصال المطبوع).
 */
export function rememberDeviceBalance(uuidValue, data) {
    const before = data.due_before !== undefined ? data.due_before : localDueBefore(data);
    if (before === null || before === undefined) return;
    const hasSyp = data.amount_syp !== null && data.amount_syp !== undefined;
    const after = data.due_after !== undefined && data.due_after !== null ? data.due_after : (hasSyp ? Math.round((before - Number(data.amount_syp)) * 100) / 100 : before);
    const items = distributorQueue();
    let changed = false;
    for (const item of items) {
        if (item.id === uuidValue && item.kind === 'payment' && item.body && item.body.device_balance_before === undefined) {
            item.body.device_balance_before = before;
            item.body.device_balance_after = after;
            changed = true;
        }
    }
    if (changed) save('distributorQueue:v3', items);
}

async function printReceipt(button) {
    const cfg = effectiveConfig();
    const paymentUuid = button.dataset.printReceipt;
    const key = refKey('receipt', { payment_client_uuid: paymentUuid });
    const ref = { payment_client_uuid: paymentUuid, payment_id: button.dataset.paymentId ? Number(button.dataset.paymentId) : null };
    const pending = distributorQueue().some(i => i.id === paymentUuid);

    let data = null;
    let serverCount = 0;
    if (!pending && navigator.onLine && button.dataset.receiptUrl) {
        try {
            const res = await fetch(button.dataset.receiptUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (res.ok) {
                data = await res.json();
                serverCount = data.print_count || 0;
                rememberServerCount(key, serverCount);
            }
        } catch (e) { /* محلي */ }
    }
    if (!data) {
        data = localReceiptData(paymentUuid, button);
        if (pending) rememberDeviceBalance(paymentUuid, data);
    }

    const doc = buildReceipt(data, docOptions(cfg, duplicateNumber(key, serverCount)));
    return print(doc, { kind: 'receipt', ref });
}

function requireLines() {
    const data = linesData();
    if (!data) notify('افتح هذا الكشف مرة واحدة بوجود الإنترنت لتحميل تفاصيل الفواتير.', 'warning');
    return data;
}

function printInvoice(invoiceId) {
    const data = requireLines();
    if (!data) return;
    const invoice = (data.invoices || []).find(i => String(i.id) === String(invoiceId));
    if (!invoice) { notify('هذه الفاتورة غير متاحة في النسخة المخزّنة.', 'warning'); return; }
    const merged = (data.merged || []).find(m => Number(m.customer_id) === Number(invoice.customer_id));
    const key = refKey('invoice', { invoice_id: invoice.id });
    const cfg = effectiveConfig();
    const doc = buildInvoice(invoice, Object.assign({
        pharmacy: invoice.customer_name, area: merged?.area, delivery_order_no: data.delivery_order_no, distribution_date: data.distribution_date,
    }, docOptions(cfg, duplicateNumber(key, data.print_counts?.invoices?.[invoice.id]))));
    return print(doc, { kind: 'invoice', ref: { invoice_id: invoice.id } });
}

function printMerged(customerId) {
    const data = requireLines();
    if (!data) return;
    const merged = (data.merged || []).find(m => Number(m.customer_id) === Number(customerId));
    if (!merged || !merged.invoice_ids.length) { notify('لا توجد فواتير مرئية لهذه الصيدلية.', 'warning'); return; }
    const ref = { distribution_sheet_id: data.sheet_id, customer_id: Number(customerId) };
    const cfg = effectiveConfig();
    const doc = buildMergedInvoice(merged, docOptions(cfg, duplicateNumber(refKey('merged', ref), data.print_counts?.merged?.[customerId])));
    return print(doc, { kind: 'merged', ref });
}

function showSavedPanel(paymentUuid) {
    let panel = document.getElementById('paymentSavedPanel');
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'paymentSavedPanel';
        panel.className = 'alert alert-success shadow position-fixed bottom-0 start-50 translate-middle-x mb-3 d-flex align-items-center gap-2 fw-bold';
        panel.style.zIndex = 1080;
        document.body.appendChild(panel);
    }
    panel.innerHTML = `<i class="fas fa-circle-check"></i> تمّ الحفظ
        <button type="button" class="btn btn-sm btn-success fw-bold" data-print-receipt="${escapeHtml(paymentUuid)}"><i class="fas fa-print"></i> طباعة الإيصال</button>
        <button type="button" class="btn-close" aria-label="إغلاق"></button>`;
    panel.querySelector('.btn-close').addEventListener('click', () => panel.remove());
    setTimeout(() => panel.isConnected && panel.remove(), 20000);
}

export function bindDistributorPage() {
    const enabled = !!effectiveConfig().printing_enabled && !!page().printingEnabled;

    document.addEventListener('click', e => {
        const target = e.target.closest('[data-print-receipt], [data-print-customer], [data-print-invoice]');
        if (!target || target.disabled || !enabled) return;
        e.preventDefault();
        if (target.dataset.printReceipt) printReceipt(target);
        else if (target.dataset.printCustomer) printMerged(target.dataset.printCustomer);
        else if (target.dataset.printInvoice) printInvoice(target.dataset.printInvoice);
    });

    // بعد حفظ الدفعة محلياً: لوحة «تمّ الحفظ» بزر طباعة الإيصال (الطباعة لا تحجب الحفظ)
    if (enabled) {
        document.addEventListener('distributor:queued', e => {
            if (e.detail.kind === 'payment') showSavedPanel(e.detail.body.client_uuid);
        });
    }

    bindStatus();
    updateBadge();
    syncEvents();
    window.addEventListener('online', syncEvents);
    setInterval(syncEvents, 30000);
}

export { printer, BlePrinter };
