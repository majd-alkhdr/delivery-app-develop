/*
 * تفاصيل الفاتورة في كشف المندوب (PHASE_6B_FIX_REP_LINES): قراءة فقط، بلا طباعة ولا دفع.
 * الأسطر تُجلب من GET /rep/sheets/{sheet}/lines عند أول ضغطة (لا مع تحميل الصفحة) وتُحفظ في متغير للضغطات التالية.
 * العرض مشترك مع الموزع (invoice-view.js). لا IndexedDB ولا عمل دون اتصال لهذه الصفحة.
 */
import { renderInvoiceDetails } from '../invoice-view.js';

let cache = null;      // نتيجة الـendpoint بعد أول نجاح
let pending = null;    // طلب جارٍ (لا طلبان متوازيان)

async function loadLines(url) {
    if (cache) return cache;
    if (!pending) {
        pending = fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(res => { if (!res.ok) throw new Error(String(res.status)); return res.json(); })
            .then(data => { cache = data; return data; })
            .finally(() => { pending = null; });
    }
    return pending;
}

function errorHtml() {
    return `<div class="alert alert-warning mb-0 d-flex flex-wrap align-items-center gap-2">
        <span>تعذّر تحميل التفاصيل، تحقق من الاتصال.</span>
        <button type="button" class="btn btn-sm btn-outline-dark fw-bold" data-rep-lines-retry>إعادة المحاولة</button>
    </div>`;
}

async function show(invoiceId, url, modal, body) {
    body.innerHTML = '<div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span> جارٍ تحميل التفاصيل…</div>';
    modal.show();
    try {
        const data = await loadLines(url);
        // النافذة تعرض فقط ما أرجعه الـendpoint (فواتير مركز كلفة المندوب في هذا الكشف)
        const invoice = (data.invoices || []).find(i => String(i.id) === String(invoiceId));
        body.innerHTML = invoice ? renderInvoiceDetails(invoice) : '<div class="alert alert-secondary mb-0">هذه الفاتورة غير متاحة لك.</div>';
    } catch (e) {
        body.innerHTML = errorHtml();
        body.querySelector('[data-rep-lines-retry]')?.addEventListener('click', () => show(invoiceId, url, modal, body));
    }
}

function setup() {
    const root = document.querySelector('[data-rep-lines-url]');
    const modalEl = document.getElementById('invoiceModal');
    if (!root || !modalEl || !window.bootstrap) return;
    const url = root.dataset.repLinesUrl;
    const body = document.getElementById('invoiceModalBody');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    root.querySelectorAll('[data-rep-invoice-id]').forEach(el => {
        const open = () => show(el.dataset.repInvoiceId, url, modal, body);
        // زر «المزيد» داخل الصف (قصّ البيان) لا يفتح النافذة
        el.addEventListener('click', e => { if (!e.target.closest('button, a')) open(); });
        el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } });
    });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup); else setup();
