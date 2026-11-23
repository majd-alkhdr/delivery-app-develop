/*
 * صفحات المندوب (المرحلة 6): نموذج التحصيل بصورة إيصال إلزامية، فوق طابور distributor-app.js نفسه.
 * - الحفظ: الصورة المضغوطة في IndexedDB «rep_uploads» + حقول الدفعة في الطابور المحلي، ثم إرسالهما معاً.
 * - بلا IndexedDB وبلا اتصال: يُمنع الحفظ (لا تحصيل بلا صورته في أي حال).
 * - لا طباعة في صفحات المندوب.
 */
import { repFormState, MISSING_LABELS, compressImage, amountSyp, exceedsKnownDebt } from './rep-form.js';

const App = window.DistributorApp;
const cfg = window.DistributorConfig || {};

function money(v) { return App.money(v); }

function setup() {
    const form = document.getElementById('repPaymentForm');
    if (!form || !App) return;
    const modalEl = document.getElementById('repPaymentModal');
    const amountInput = document.getElementById('rep_amount');
    const rateBox = document.getElementById('rep_rate_box');
    const rateInput = document.getElementById('rep_rate');
    const bookInput = document.getElementById('rep_book_no');
    const imageInput = document.getElementById('rep_image');
    const preview = document.getElementById('rep_image_preview');
    const missingLine = document.getElementById('rep_missing');
    const exceedsLine = document.getElementById('rep_exceeds');
    const submit = document.getElementById('rep_submit');
    let compressed = null;

    const currency = () => form.querySelector('[name="currency"]:checked')?.value || 'SYP';

    function render() {
        const rate = currency() === 'USD' ? App.parseNumber(rateInput.value) : null;
        const amount = App.parseNumber(amountInput.value);
        const state = repFormState({ amount, currency: currency(), rate, bookNo: bookInput.value, hasImage: !!compressed, canRecord: cfg.canRecord !== false });
        submit.disabled = !state.ready;
        missingLine.textContent = state.ready ? '' : 'مطلوب: ' + state.missing.map(m => MISSING_LABELS[m]).join('، ');
        const syp = amountSyp(amount, currency(), rate);
        const exceeds = exceedsKnownDebt(syp, form.dataset.debt);
        exceedsLine.classList.toggle('d-none', !exceeds);
        if (exceeds) exceedsLine.textContent = `أكبر من الدَين المعروف (${money(form.dataset.debt)} ل.س). يمكنك الحفظ، وسيظهر التنبيه للمحاسب.`;
    }

    document.querySelectorAll('[data-open-rep-payment]').forEach(btn => btn.addEventListener('click', () => {
        form.reset();
        compressed = null;
        preview.classList.add('d-none');
        preview.removeAttribute('src');
        form.querySelector('[name="customer_id"]').value = btn.dataset.customerId;
        form.dataset.label = btn.dataset.customerName || '';
        form.dataset.debt = btn.dataset.debt || '0';
        form.dataset.refState = btn.dataset.refState || 'none';
        document.getElementById('rep_customer_name').textContent = btn.dataset.customerName || '';
        document.getElementById('rep_debt').textContent = Number(btn.dataset.debt) > 0 ? money(btn.dataset.debt) : 'لا دَين معروف لك';
        rateBox.classList.add('d-none');
        rateInput.required = false;
        render();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }));

    form.querySelectorAll('[name="currency"]').forEach(r => r.addEventListener('change', () => {
        const usd = currency() === 'USD';
        rateBox.classList.toggle('d-none', !usd);
        rateInput.required = usd;
        if (usd && !rateInput.value && cfg.usdRateDefault) rateInput.value = String(cfg.usdRateDefault);
        render();
    }));
    [amountInput, rateInput, bookInput].forEach(el => el.addEventListener('input', render));

    imageInput.addEventListener('change', async () => {
        compressed = null;
        render();
        const file = imageInput.files && imageInput.files[0];
        if (!file) return;
        try {
            compressed = await compressImage(file);
            preview.src = URL.createObjectURL(compressed);
            preview.classList.remove('d-none');
        } catch (e) {
            App.toast('تعذّرت قراءة الصورة. التقطها من جديد.', 'danger');
        }
        render();
    });

    form.addEventListener('submit', async e => {
        e.preventDefault();
        render();
        if (submit.disabled) return;

        const cur = currency();
        const rate = cur === 'USD' ? App.parseNumber(rateInput.value) : null;
        if (rate !== null && (rate < cfg.usdRateMin || rate > cfg.usdRateMax)
            && !confirm(`سعر الصرف ${rate} خارج النطاق المعتاد (${cfg.usdRateMin} – ${cfg.usdRateMax}). هل تؤكد؟`)) return;

        // لا حفظ بلا صورة في أي حال: بلا IndexedDB وبلا اتصال يُمنع
        const stored = await App.RepUploads.available();
        if (!stored && !navigator.onLine) {
            App.toast('لا يمكن حفظ تحصيل بلا اتصال على هذا الجهاز.', 'danger');
            return;
        }

        submit.disabled = true;
        const pos = await App.position();
        const body = {
            client_uuid: App.uuid(),
            receipt_number: App.receiptNumber(),
            customer_id: Number(form.querySelector('[name="customer_id"]').value),
            amount: App.parseNumber(amountInput.value),
            currency: cur,
            exchange_rate: rate,
            form_version: 6,
            book_receipt_no: bookInput.value.trim(),
            note: form.querySelector('[name="note"]').value.trim() || null,
            latitude: pos ? pos.lat : null,
            longitude: pos ? pos.lng : null,
            accuracy_m: pos ? pos.accuracy : null,
        };
        if (rate !== null && (rate < cfg.usdRateMin || rate > cfg.usdRateMax)) body.rate_confirmed = 1;

        await App.RepUploads.put(body.client_uuid, compressed);
        const item = { id: body.client_uuid, kind: 'rep_payment', multipart: true, url: cfg.paymentUrl, body, label: form.dataset.label, createdAt: Date.now() };
        App.Queue.push(item);
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        App.toast(navigator.onLine ? 'جارٍ حفظ التحصيل…' : 'حُفظ التحصيل مع صورته في الجهاز، وسيُرسل عند عودة الاتصال.', navigator.onLine ? 'info' : 'success');

        const pending = App.Queue.all().filter(i => i.kind === 'rep_payment').length;
        if (pending > (cfg.maxPending || 30)) App.toast(`لديك ${pending} تحصيلاً معلّقاً بصورها على الجهاز. اتصل بالإنترنت لإرسالها.`, 'warning');

        if (pos && form.dataset.refState !== 'confirmed') App.askPin(item); else App.sync();
    });

    // بعد الحفظ: كيف خُصمت من فواتيره (الأقدم أولاً) والمتبقي
    document.addEventListener('distributor:synced', e => {
        const { item, data } = e.detail;
        if (item.kind !== 'rep_payment' || !data || !data.payment) return;
        const b = data.breakdown || { rows: [] };
        const lines = (b.rows || []).map(r => `${r.invoice}: ${money(r.allocated)} (المتبقي ${money(r.remaining)})`);
        let message = `تم الحفظ (${item.label || ''}).`;
        if (lines.length) message += ' خُصمت من: ' + lines.join('، ') + '.';
        if (b.surplus > 0) message += ` زائد عن الدَين المعروف: ${money(b.surplus)}.`;
        message += ` المتبقي على الصيدلية: ${money(b.remaining)}.`;
        App.toast(message, 'success');
        setTimeout(() => {
            if (App.Queue.all().length === 0 && !App.pinOpen) location.reload();
        }, 2500);
    });

    // بحث وفلترة وترتيب قائمة «ذممي» محلياً
    const list = document.getElementById('repList');
    if (list) {
        const search = document.getElementById('rep_search');
        const area = document.getElementById('rep_area');
        const sort = document.getElementById('rep_sort');
        const apply = () => {
            const q = App.normalizeArabic(search.value);
            const cards = [...list.querySelectorAll('[data-rep-card]')];
            cards.forEach(c => {
                const show = (!q || c.dataset.search.includes(q)) && (!area.value || c.dataset.area === area.value);
                c.classList.toggle('d-none', !show);
            });
            const key = sort.value === 'oldest' ? 'age' : 'debt';
            cards.sort((a, b) => Number(b.dataset[key]) - Number(a.dataset[key])).forEach(c => list.appendChild(c));
        };
        [search, area, sort].forEach(el => el && el.addEventListener('input', apply));
        apply();
    }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup); else setup();
