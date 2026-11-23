/*
 * تطبيق الموزع (المرحلة 3): طابور محلي للدفعات والزيارات يعمل دون اتصال، أرقام إيصالات محلية،
 * الموقع (GPS)، وتفاصيل الفواتير في IndexedDB.
 *
 * - كل دفعة/زيارة تُحفظ أولاً في localStorage مع client_uuid، ثم تُرسل للخادم وتُعاد المحاولة تلقائياً.
 *   الخادم يتجاهل التكرار بنفس الـuuid ويعيد السجل الموجود.
 * - رقم الإيصال: {device_prefix}-{yyyymmdd}-{seq}، والبادئة والعدّاد اليومي في localStorage.
 * - كل عمليات التخزين داخل try/catch مع بديل في الذاكرة.
 */
(function () {
    'use strict';

    const cfg = window.DistributorConfig || {};
    const QUEUE_KEY = 'distributorQueue:v3';
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // ------------------------------------------------------------------
    // تخزين آمن
    // ------------------------------------------------------------------
    const memory = {};
    const LS = {
        get(key, fallback) {
            try {
                const value = localStorage.getItem(key);
                return value === null ? fallback : JSON.parse(value);
            } catch (e) {
                return key in memory ? memory[key] : fallback;
            }
        },
        set(key, value) {
            memory[key] = value;
            try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* ذاكرة فقط */ }
        },
    };

    // ------------------------------------------------------------------
    // أدوات
    // ------------------------------------------------------------------
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function latinDigits(value) {
        return String(value ?? '')
            .replace(/[٠-٩]/g, d => String(d.charCodeAt(0) - 0x0660))
            .replace(/[۰-۹]/g, d => String(d.charCodeAt(0) - 0x06F0))
            .replace(/٫/g, '.')
            .replace(/[,٬\s]/g, '');
    }

    function parseNumber(value) {
        const text = latinDigits(value);
        return text !== '' && !isNaN(Number(text)) ? Number(text) : null;
    }

    function money(value) {
        return Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function qty(value) {
        return Number(value || 0).toLocaleString('en-US', { maximumFractionDigits: 3 });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    // تطبيع عربي مبسّط للبحث (مطابق لقواعد ArabicNormalizer الأساسية)
    function normalizeArabic(text) {
        return latinDigits(String(text || ''))
            .replace(/[‎‏؜‪-‮⁦-⁩]/g, '')
            .replace(/[ً-ْٰـ]/g, '')
            .replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه')
            .replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function today() {
        const d = new Date();
        return d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
    }

    function receiptNumber() {
        const length = Math.max(2, Math.min(8, cfg.receiptPrefixLength || 3));
        let prefix = LS.get('receiptPrefix', null);
        if (!prefix || prefix.length !== length) {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            prefix = '';
            for (let i = 0; i < length; i++) prefix += chars[Math.floor(Math.random() * chars.length)];
            LS.set('receiptPrefix', prefix);
        }
        const date = today();
        const seqKey = 'receiptSeq:' + date;
        const seq = (LS.get(seqKey, 0) || 0) + 1;
        LS.set(seqKey, seq);
        return `${prefix}-${date}-${seq}`;
    }

    // الموقع: إن رفض المستخدم أو تعذّر ⇒ null (يُسجَّل no_gps)
    function position() {
        return new Promise(resolve => {
            if (!navigator.geolocation) return resolve(null);
            navigator.geolocation.getCurrentPosition(
                p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy || 0) }),
                () => resolve(null),
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
            );
        });
    }

    // ------------------------------------------------------------------
    // الطابور المحلي
    // ------------------------------------------------------------------
    const Queue = {
        all() { return LS.get(QUEUE_KEY, []) || []; },
        save(items) { LS.set(QUEUE_KEY, items); updateBadge(); },
        push(item) { const items = this.all(); items.push(item); this.save(items); },
        remove(id) { this.save(this.all().filter(i => i.id !== id)); },
        update(id, patch) { this.save(this.all().map(i => i.id === id ? Object.assign(i, patch) : i)); },
    };

    function updateBadge() {
        const count = Queue.all().length;
        document.querySelectorAll('[data-queue-count]').forEach(el => {
            el.textContent = count;
            el.closest('[data-queue-box]')?.classList.toggle('d-none', count === 0);
        });
    }

    // ------------------------------------------------------------------
    // صور إيصالات المندوب المعلّقة (المرحلة 6): IndexedDB «rep_uploads» بمفتاح client_uuid
    // لا تُمسح عند تسجيل الخروج (كطابور الدفعات)، وتُحذف بعد نجاح الإرسال.
    // ------------------------------------------------------------------
    const memoryUploads = {};
    const RepUploads = {
        db: null,
        open() {
            if (this.db) return Promise.resolve(this.db);
            return new Promise(resolve => {
                try {
                    if (!window.indexedDB) return resolve(null);
                    const req = indexedDB.open('rep_uploads', 1);
                    req.onupgradeneeded = () => req.result.createObjectStore('images', { keyPath: 'client_uuid' });
                    req.onsuccess = () => { this.db = req.result; resolve(this.db); };
                    req.onerror = () => resolve(null);
                } catch (e) { resolve(null); }
            });
        },
        async available() { return !!(await this.open()); },
        async put(uuidValue, blob) {
            memoryUploads[uuidValue] = blob;
            const db = await this.open();
            if (!db) return false;
            return new Promise(resolve => {
                try {
                    const tx = db.transaction('images', 'readwrite');
                    tx.objectStore('images').put({ client_uuid: uuidValue, blob, saved_at: Date.now() });
                    tx.oncomplete = () => resolve(true);
                    tx.onerror = () => resolve(false);
                } catch (e) { resolve(false); }
            });
        },
        async get(uuidValue) {
            if (memoryUploads[uuidValue]) return memoryUploads[uuidValue];
            const db = await this.open();
            if (!db) return null;
            return new Promise(resolve => {
                try {
                    const req = db.transaction('images', 'readonly').objectStore('images').get(uuidValue);
                    req.onsuccess = () => resolve(req.result ? req.result.blob : null);
                    req.onerror = () => resolve(null);
                } catch (e) { resolve(null); }
            });
        },
        async del(uuidValue) {
            delete memoryUploads[uuidValue];
            const db = await this.open();
            if (!db) return;
            try { db.transaction('images', 'readwrite').objectStore('images').delete(uuidValue); } catch (e) { /* لا شيء */ }
        },
    };

    // طلب عنصر الطابور: JSON، أو multipart لتحصيل المندوب (الحقول + الصورة معاً في الطلب نفسه)
    async function requestOptions(item) {
        const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' };
        if (!item.multipart) {
            return { method: 'POST', headers: Object.assign({ 'Content-Type': 'application/json' }, headers), credentials: 'same-origin', body: JSON.stringify(item.body) };
        }
        const form = new FormData();
        Object.entries(item.body).forEach(([key, value]) => {
            if (value === null || value === undefined) return;
            form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
        });
        const blob = await RepUploads.get(item.body.client_uuid);
        if (blob) form.append('image', blob, 'receipt.jpg');
        return { method: 'POST', headers, credentials: 'same-origin', body: form };
    }

    let syncing = false;
    async function sync() {
        if (syncing || !navigator.onLine) return;
        syncing = true;
        let confirmedNow = false;
        try {
            for (const item of Queue.all()) {
                // المرحلة 7H: عنصر ينتظر «اطلعت» على صيدليته لا يُعاد إرساله قبل الاطلاع (ويبقى محفوظاً)
                if (item.awaitingAck) continue;
                // دفعة دولار قديمة في الطابور (قبل إلزام السعر) تُرسل بعلامة legacy_no_rate ويضبط المحاسب سعرها
                if (item.kind === 'payment' && !item.body.form_version && item.body.currency === 'USD' && !item.body.exchange_rate) {
                    item.body.legacy_no_rate = 1;
                }
                // المرحلة 7H: «من الطابور دون اتصال» = سُجّل والجهاز غير متصل أو فشلت محاولة إرساله سابقاً،
                // إلا إن رفضه الخادم حياً (409) فيبقى حياً حتى «اطلعت»
                if (!item.liveRejected && (item.recordedOffline || item.failedAttempts > 0)) item.body.offline = 1;
                let res;
                try {
                    res = await fetch(item.url, await requestOptions(item));
                } catch (e) {
                    Queue.update(item.id, { failedAttempts: (item.failedAttempts || 0) + 1 });
                    break; // لا اتصال: نحاول لاحقاً
                }

                let data = {};
                try { data = await res.json(); } catch (e) { /* لا JSON */ }

                if (res.status === 409 && data.ack_required) {
                    // المرحلة 7H: تعديل على الصيدلية لم يُطّلع عليه: لا يُحذف العنصر، ويُرسل بعد «اطلعت»
                    Queue.update(item.id, { awaitingAck: true, liveRejected: true, body: Object.assign(item.body, { offline: 0 }) });
                    toast(`لم يُرسل بعد (${item.label || ''}): ${data.message} وهو محفوظ في الجهاز ويُرسل بعد الاطلاع.`, 'warning');
                    showAckBox(data.customer_id, data.notices || [], data.ack_url);
                    continue;
                }

                if (res.ok) {
                    Queue.remove(item.id);
                    if (item.multipart) RepUploads.del(item.body.client_uuid);
                    document.dispatchEvent(new CustomEvent('distributor:synced', { detail: { item, data } }));
                    if (data.warning) toast(data.warning, 'warning');
                } else if (res.status === 422 && data.requires_confirmation) {
                    // حقل التأكيد من الخادم: rate_confirmed (سعر خارج المجال) أو confirm_duplicate_book_no (رقم دفتر مكرر)
                    const field = data.confirm_field || 'rate_confirmed';
                    if (confirm(data.message + '\n\nهل تؤكد؟')) {
                        item.body[field] = 1;
                        Queue.update(item.id, { body: item.body });
                        confirmedNow = true;
                    } else {
                        Queue.remove(item.id);
                        if (item.multipart) RepUploads.del(item.body.client_uuid);
                        document.dispatchEvent(new CustomEvent('distributor:rejected', { detail: { item, data } }));
                    }
                } else if (res.status === 419) {
                    Queue.update(item.id, { failedAttempts: (item.failedAttempts || 0) + 1 });
                    toast('انتهت الجلسة: أعد تحميل الصفحة ثم ستُرسل العناصر المحفوظة تلقائياً.', 'danger');
                    break;
                } else if (res.status === 429) {
                    Queue.update(item.id, { failedAttempts: (item.failedAttempts || 0) + 1 });
                    break; // حد الطلبات: نحاول لاحقاً
                } else if (res.status >= 400 && res.status < 500) {
                    Queue.remove(item.id);
                    if (item.multipart) RepUploads.del(item.body.client_uuid);
                    const message = data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : 'رُفض الطلب.');
                    toast(`لم يُحفظ (${item.label || ''}): ${message}`, 'danger');
                    document.dispatchEvent(new CustomEvent('distributor:rejected', { detail: { item, data } }));
                } else {
                    Queue.update(item.id, { failedAttempts: (item.failedAttempts || 0) + 1 });
                    break; // خطأ خادم: نحاول لاحقاً
                }
            }
            // التأكيد يحتاج إعادة إرسال فورية (مرة لكل تأكيد جديد، لا حلقة عند خطأ الخادم)
            if (confirmedNow) {
                syncing = false;
                return sync();
            }
        } finally {
            syncing = false;
            updateBadge();
        }
    }

    function toast(message, type) {
        const box = document.getElementById('distributorToasts');
        if (!box) { alert(message); return; }
        const el = document.createElement('div');
        el.className = `alert alert-${type || 'info'} alert-dismissible fade show shadow-sm mb-2 fw-bold`;
        el.innerHTML = `${escapeHtml(message)}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>`;
        box.appendChild(el);
        setTimeout(() => el.remove(), 9000);
    }

    // ------------------------------------------------------------------
    // المرحلة 7H: الاطلاع الإلزامي على تعديلات الصيدلية
    // ------------------------------------------------------------------
    let lastNoticeId = Number(cfg.lastNoticeId || 0);

    // ما يُرسل مع كل تسجيل: وقته على الجهاز وآخر إشعار عرفه الجهاز (لتمييز ما سبق التسجيل)
    function ackStamp() {
        return { recorded_at: new Date().toISOString(), known_notice_id: lastNoticeId };
    }

    function ackUrlFor(customerId, url) {
        return url || String(cfg.ackUrl || (linesData && linesData.ack_url) || '').replace('__CUSTOMER__', customerId);
    }

    // صندوق «اطلعت» على البطاقة وتعطيل التسجيل عليها (من الخادم: 409 أو نسخة الكشف المخزّنة)
    function showAckBox(customerId, notices, url) {
        if (!customerId) return;
        const card = document.querySelector(`.customer-card[data-customer-id="${customerId}"]`);
        if (!card) return;
        card.querySelectorAll('[data-ack-gated]').forEach(btn => { btn.disabled = true; });
        let box = card.querySelector('[data-ack-box]');
        if (!box) {
            box = document.createElement('div');
            box.className = 'alert alert-danger py-2 small mb-2 ack-required';
            box.dataset.ackBox = customerId;
            const types = [...new Set(notices.map(n => n.type))];
            box.innerHTML = `<div class="fw-bold mb-1"><i class="fas fa-bell me-1"></i> تغيّرت بيانات هذه الصيدلية. اطّلع على التعديل أولاً.</div>
                <ul class="mb-2 ps-3" data-ack-details>${notices.map(n => `<li>${escapeHtml(n.text)}</li>`).join('')}</ul>
                <button type="button" class="btn btn-sm btn-danger fw-bold" data-ack-customer="${escapeHtml(customerId)}" data-ack-url="${escapeHtml(ackUrlFor(customerId, url))}"><i class="fas fa-check"></i> اطلعت</button>`;
            box.dataset.types = types.join(',');
            card.querySelector('.card-body')?.insertBefore(box, card.querySelector('.card-body > .row'));
        }
    }

    function clearAckBox(customerId) {
        const card = document.querySelector(`.customer-card[data-customer-id="${customerId}"]`);
        if (!card) return;
        card.querySelector('[data-ack-box]')?.remove();
        card.querySelectorAll('[data-ack-gated]').forEach(btn => { btn.disabled = false; });
    }

    // من نسخة الكشف المخزّنة: إشعارات وصلت الجهاز عند آخر اتصال تُلزم التسجيلات التالية ولو دون اتصال
    function applyAckGates() {
        if (!linesData || !linesData.pending_acks) return;
        lastNoticeId = Math.max(lastNoticeId, Number(linesData.last_notice_id || 0));
        Object.entries(linesData.pending_acks).forEach(([customerId, notices]) => {
            if (notices && notices.length) showAckBox(customerId, notices, null);
        });
    }

    async function acknowledge(btn) {
        const customerId = btn.dataset.ackCustomer;
        const details = [...(btn.closest('[data-ack-box]')?.querySelectorAll('[data-ack-details] li') || [])].map(li => '• ' + li.textContent.trim());
        if (!confirm('تعديلات هذه الصيدلية:\n' + details.join('\n') + '\n\nاطلعت عليها؟')) return;
        if (!navigator.onLine) { toast('«اطلعت» يحتاج اتصالاً بالإنترنت.', 'warning'); return; }
        btn.disabled = true;
        try {
            const res = await fetch(btn.dataset.ackUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) { btn.disabled = false; toast('تعذّر تسجيل الاطلاع، حاول مجدداً.', 'danger'); return; }
            clearAckBox(customerId);
            document.querySelector(`[data-balance-change="${customerId}"]`)?.remove();
            if (linesData && linesData.pending_acks) delete linesData.pending_acks[customerId];
            // العناصر المحفوظة لهذه الصيدلية تُرسل الآن
            Queue.all().filter(i => i.awaitingAck && String(i.body.customer_id) === String(customerId))
                .forEach(i => Queue.update(i.id, { awaitingAck: false }));
            toast('تم الاطلاع. يمكنك التسجيل على هذه الصيدلية.', 'success');
            sync();
        } catch (e) {
            btn.disabled = false;
            toast('تعذّر تسجيل الاطلاع، حاول مجدداً.', 'danger');
        }
    }

    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-ack-customer]');
        if (btn) acknowledge(btn);
    });

    // ------------------------------------------------------------------
    // نماذج الدفعة والزيارة
    // ------------------------------------------------------------------
    function setupPaymentForm() {
        const form = document.getElementById('paymentForm');
        if (!form) return;
        const modalEl = document.getElementById('paymentModal');
        const rateBox = document.getElementById('pay_rate_box');
        const rateInput = document.getElementById('pay_rate');

        document.querySelectorAll('[data-open-payment]').forEach(btn => btn.addEventListener('click', () => {
            form.reset();
            form.querySelector('[name="customer_id"]').value = btn.dataset.customerId;
            form.dataset.url = btn.dataset.url || cfg.paymentUrl;
            form.dataset.label = btn.dataset.customerName || '';
            form.dataset.refState = btn.dataset.refState || 'none';
            document.getElementById('pay_customer_name').textContent = btn.dataset.customerName || '';
            document.getElementById('pay_due').textContent = btn.dataset.due ? money(btn.dataset.due) : '—';
            if (rateBox) rateBox.classList.add('d-none');
            if (rateInput) rateInput.required = false;
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }));

        form.querySelectorAll('[name="currency"]').forEach(r => r.addEventListener('change', () => {
            const usd = form.querySelector('[name="currency"]:checked')?.value === 'USD';
            if (rateBox) rateBox.classList.toggle('d-none', !usd);
            // المرحلة 5: السعر إلزامي للدولار، ويُعبّأ افتراضياً من usd_rate_default
            if (rateInput) {
                rateInput.required = usd;
                if (usd && !rateInput.value && cfg.usdRateDefault) rateInput.value = String(cfg.usdRateDefault);
            }
        }));

        const amountInput = document.getElementById('pay_amount');
        amountInput?.addEventListener('input', function () {
            const n = parseNumber(this.value);
            if (n !== null && !this.value.endsWith('.')) this.value = n.toLocaleString('en-US', { maximumFractionDigits: 2 });
        });

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const amount = parseNumber(amountInput.value);
            if (!amount || amount <= 0) { toast('أدخل مبلغاً أكبر من صفر.', 'danger'); return; }
            const currency = form.querySelector('[name="currency"]:checked')?.value || 'SYP';
            const rate = currency === 'USD' && rateInput ? parseNumber(rateInput.value) : null;
            if (currency === 'USD' && (!rate || rate <= 0)) { toast('أدخل سعر الصرف لدفعة الدولار.', 'danger'); rateInput?.focus(); return; }

            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            const pos = await position();
            submit.disabled = false;

            const body = {
                client_uuid: uuid(),
                receipt_number: receiptNumber(),
                customer_id: Number(form.querySelector('[name="customer_id"]').value),
                amount,
                currency,
                exchange_rate: rate,
                form_version: 5,
                note: form.querySelector('[name="note"]').value.trim() || null,
                latitude: pos ? pos.lat : null,
                longitude: pos ? pos.lng : null,
                accuracy_m: pos ? pos.accuracy : null,
            };
            if (rate !== null && (rate < cfg.usdRateMin || rate > cfg.usdRateMax)) {
                if (!confirm(`سعر الصرف ${rate} خارج النطاق المعتاد (${cfg.usdRateMin} – ${cfg.usdRateMax}). هل تؤكد؟`)) return;
                body.rate_confirmed = 1;
            }

            // الحفظ محلياً أولاً، ثم الإرسال
            Object.assign(body, ackStamp());
            const item = { id: body.client_uuid, kind: 'payment', url: form.dataset.url, body, label: form.dataset.label, createdAt: Date.now(), recordedOffline: !navigator.onLine };
            Queue.push(item);
            document.dispatchEvent(new CustomEvent('distributor:queued', { detail: { kind: 'payment', body } }));
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            toast(`حُفظت الدفعة في الجهاز (إيصال ${body.receipt_number}).`, 'success');
            // الدفعة تعني زيارة: سؤال تثبيت الموقع إن لم يكن مؤكداً (بعد الحفظ، دون حجبه)
            if (pos && form.dataset.refState !== 'confirmed') askPin(item); else sync();
        });
    }

    function setupVisitForm() {
        const form = document.getElementById('visitForm');
        if (!form) return;
        const modalEl = document.getElementById('visitModal');
        const reasonBox = document.getElementById('visit_reason_box');
        const otherBox = document.getElementById('visit_other_box');

        document.querySelectorAll('[data-open-visit]').forEach(btn => btn.addEventListener('click', () => {
            form.reset();
            form.querySelector('[name="customer_id"]').value = btn.dataset.customerId;
            form.dataset.label = btn.dataset.customerName || '';
            form.dataset.refState = btn.dataset.refState || 'none';
            document.getElementById('visit_customer_name').textContent = btn.dataset.customerName || '';
            reasonBox.classList.add('d-none');
            otherBox.classList.add('d-none');
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }));

        form.querySelectorAll('[name="status"]').forEach(r => r.addEventListener('change', () => {
            reasonBox.classList.toggle('d-none', form.querySelector('[name="status"]:checked')?.value !== 'not_visited');
        }));
        form.querySelector('[name="reason"]').addEventListener('change', function () {
            otherBox.classList.toggle('d-none', this.value !== 'أخرى');
        });

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const status = form.querySelector('[name="status"]:checked')?.value;
            if (!status) { toast('اختر حالة الزيارة.', 'danger'); return; }
            const reason = form.querySelector('[name="reason"]').value;
            const other = form.querySelector('[name="reason_other"]').value.trim();
            if (status === 'not_visited' && !reason) { toast('اختر سبب عدم الزيارة.', 'danger'); return; }
            if (reason === 'أخرى' && !other) { toast('اكتب السبب.', 'danger'); return; }

            const pos = await position();
            const body = {
                client_uuid: uuid(),
                customer_id: Number(form.querySelector('[name="customer_id"]').value),
                status,
                reason: status === 'not_visited' ? reason : null,
                reason_other: reason === 'أخرى' ? other : null,
                latitude: pos ? pos.lat : null,
                longitude: pos ? pos.lng : null,
                accuracy_m: pos ? pos.accuracy : null,
            };
            Object.assign(body, ackStamp());
            const item = { id: body.client_uuid, kind: 'visit', url: cfg.visitUrl, body, label: form.dataset.label, createdAt: Date.now(), recordedOffline: !navigator.onLine };
            Queue.push(item);
            document.dispatchEvent(new CustomEvent('distributor:queued', { detail: { kind: 'visit', body } }));
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            // «مزار» بإحداثيات لصيدلية بلا مرجع مؤكد ⇒ سؤال التثبيت (لا سؤال في «غير مزار» ولا بلا GPS)
            if (pos && status === 'visited' && form.dataset.refState !== 'confirmed') askPin(item); else sync();
        });
    }

    function setupVoid() {
        document.querySelectorAll('[data-void-url]').forEach(btn => btn.addEventListener('click', async () => {
            if (!navigator.onLine) { toast('إلغاء الدفعة يحتاج اتصالاً بالإنترنت.', 'warning'); return; }
            const reason = prompt('سبب إلغاء الدفعة (إلزامي):');
            if (!reason || reason.trim().length < 3) { if (reason !== null) toast('اكتب سبباً واضحاً (3 أحرف على الأقل).', 'danger'); return; }
            const res = await fetch(btn.dataset.voidUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                credentials: 'same-origin',
                body: JSON.stringify({ reason: reason.trim() }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok) { toast('أُلغيت الدفعة.', 'success'); setTimeout(() => location.reload(), 800); }
            else toast(data.message || 'تعذّر الإلغاء.', 'danger');
        }));
    }

    // ------------------------------------------------------------------
    // تثبيت الموقع المرجعي للصيدلية (بعد حفظ الزيارة/الدفعة، ولا يحجبه)
    // ------------------------------------------------------------------
    let pinState = null;   // { item, pos }

    function askPin(item) {
        const modalEl = document.getElementById('pinModal');
        if (!modalEl) { sync(); return; }
        Queue.update(item.id, { askedPin: true });
        pinState = { item, pos: { lat: item.body.latitude, lng: item.body.longitude, accuracy: item.body.accuracy_m } };
        document.getElementById('pin_customer_name').textContent = item.label || '';
        document.getElementById('pin_distance_line').classList.add('d-none');
        renderPinAccuracy();
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
        startPinWatch();
        // السجل محفوظ في الطابور؛ يُرسل الآن ليعيد الخادم المسافة وقراره (can_pin)
        sync();
    }

    // ---- مراقبة دقة GPS داخل النافذة (watchPosition) ----
    const PIN_WATCH_TIMEOUT_MS = 30000;
    const PIN_ERRORS = {
        1: 'إذن الموقع مرفوض. فعّل إذن الموقع لهذا الموقع في إعدادات المتصفح ثم اضغط «إعادة المحاولة».',
        2: 'تعذّر تحديد الموقع حالياً. تأكد من تشغيل GPS (خدمة الموقع) ثم اضغط «إعادة المحاولة».',
        3: 'انتهت مهلة قراءة الموقع. اضغط «إعادة المحاولة».',
    };
    const PIN_TIMEOUT_MESSAGE = 'تعذّر الحصول على دقة كافية. اخرج إلى مكان مفتوح أو قرب نافذة وفعّل الموقع بدقة عالية. إن كنت على حاسوب فالدقة غالباً أقل من المطلوب.';
    let pinWatch = null;   // { id, timer }

    function pinAccuracyOk() {
        return !!pinState && pinState.pos.accuracy !== null && pinState.pos.accuracy !== undefined
            && pinState.pos.accuracy <= (cfg.pinMaxAccuracy || 50);
    }

    // problem: null = لا مشكلة (المراقبة جارية)، أو نص رسالة الفشل/المهلة
    function renderPinAccuracy(problem = null) {
        const ok = pinAccuracyOk();
        const required = cfg.pinMaxAccuracy || 50;
        document.getElementById('pin_confirm_btn').disabled = !ok;

        const live = document.getElementById('pin_accuracy_text');
        const box = document.getElementById('pin_accuracy_live');
        if (live && box) {
            const acc = pinState?.pos?.accuracy;
            // أرقام لاتينية واتجاه LTR للرقم فقط (لا إحداثيات)
            live.innerHTML = acc !== null && acc !== undefined
                ? `دقة موقعك الآن: <bdi dir="ltr" class="font-monospace fw-bold">${Math.round(acc)}</bdi> م (المطلوب <bdi dir="ltr" class="font-monospace">${required}</bdi> م أو أفضل)`
                : `جارٍ تحديد موقعك… (المطلوب <bdi dir="ltr" class="font-monospace">${required}</bdi> م أو أفضل)`;
            box.classList.toggle('alert-success', ok);
            box.classList.toggle('alert-secondary', !ok);
        }

        const line = document.getElementById('pin_accuracy_line');
        const status = document.getElementById('pin_status_text');
        if (line) line.classList.toggle('d-none', ok || !problem);
        if (status && problem) status.textContent = problem;
    }

    // أفضل قراءة: الأدق، وعند التساوي الأحدث
    function takePinReading(p) {
        if (!pinState) return;
        const reading = { lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy || 0) };
        const current = pinState.pos;
        const hasCurrent = current && current.accuracy !== null && current.accuracy !== undefined && current.lat !== null && current.lat !== undefined;
        if (!hasCurrent || reading.accuracy <= current.accuracy) pinState.pos = reading;
        renderPinAccuracy();
        if (pinAccuracyOk()) stopPinWatch();   // بلغنا الدقة: لا حاجة لمزيد من القراءات
    }

    function startPinWatch() {
        stopPinWatch();
        if (!pinState) return;
        if (!navigator.geolocation) { renderPinAccuracy(PIN_ERRORS[2]); return; }
        renderPinAccuracy();
        if (pinAccuracyOk()) return;
        const id = navigator.geolocation.watchPosition(
            takePinReading,
            err => {
                // TIMEOUT لقراءة واحدة لا يوقف المراقبة؛ الرفض وعدم التوفر يوقفانها
                if (err && err.code === 3) { renderPinAccuracy(); return; }
                stopPinWatch();
                renderPinAccuracy(PIN_ERRORS[err?.code] || PIN_ERRORS[2]);
            },
            { enableHighAccuracy: true, maximumAge: 0, timeout: 15000 }
        );
        const timer = setTimeout(() => {
            stopPinWatch();
            if (!pinAccuracyOk()) renderPinAccuracy(PIN_TIMEOUT_MESSAGE);
        }, PIN_WATCH_TIMEOUT_MS);
        pinWatch = { id, timer };
    }

    function stopPinWatch() {
        if (!pinWatch) return;
        if (navigator.geolocation && pinWatch.id !== null && pinWatch.id !== undefined) navigator.geolocation.clearWatch(pinWatch.id);
        clearTimeout(pinWatch.timer);
        pinWatch = null;
    }

    // قرار التثبيت: يُعدَّل العنصر إن كان ما زال في الطابور، وإلا يُضاف عنصر متابعة بنفس client_uuid (idempotent)
    function sendPinDecision(pin) {
        if (!pinState) return;
        stopPinWatch();
        const { item, pos } = pinState;
        const patch = pin
            ? { pin_reference: 1, latitude: pos.lat, longitude: pos.lng, accuracy_m: pos.accuracy }
            : { pin_declined: 1 };
        const queued = Queue.all().find(i => i.id === item.id);
        if (queued) {
            Queue.update(item.id, { body: Object.assign({}, queued.body, patch) });
        } else {
            Queue.push({ id: item.id + '-pin', kind: item.kind, url: item.url, body: Object.assign({}, item.body, patch), label: item.label, createdAt: Date.now(), askedPin: true });
        }
        pinState = null;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('pinModal')).hide();
        sync();
    }

    function setRefState(customerId, state) {
        document.querySelectorAll(`[data-customer-id="${customerId}"][data-ref-state]`).forEach(b => { b.dataset.refState = state; });
        if (state === 'confirmed') {
            const badge = document.querySelector(`[data-ref-badge="${customerId}"]`);
            if (badge) badge.innerHTML = '<span class="badge bg-success-subtle text-success-emphasis fw-normal"><i class="fas fa-location-dot"></i> موقع مؤكد</span>';
        }
    }

    function setupPinModal() {
        if (!document.getElementById('pinModal')) return;
        document.getElementById('pin_confirm_btn').addEventListener('click', () => sendPinDecision(true));
        document.getElementById('pin_skip_btn').addEventListener('click', () => sendPinDecision(false));
        document.getElementById('pin_refresh_btn').addEventListener('click', () => startPinWatch());
        // إغلاق النافذة (بأي طريق) يوقف المراقبة
        document.getElementById('pinModal').addEventListener('hidden.bs.modal', () => stopPinWatch());

        // قرار الخادم: نتيجة التثبيت، والمسافة عن مرجع غير مؤكد، وسؤال لاحق إن رآه الخادم لازماً
        document.addEventListener('distributor:synced', e => {
            const { item, data } = e.detail;
            const customerId = item.body.customer_id;

            if (data.pin_result === 'pinned') {
                toast(data.pin_message || 'ثُبّت الموقع.', 'success');
            } else if (data.pin_result === 'inaccurate' || data.pin_result === 'ignored_confirmed') {
                toast(data.pin_message, 'warning');
            }
            if (data.reference_state) setRefState(customerId, data.reference_state);

            const open = pinState && pinState.item.id === item.id;
            if (open && data.can_pin === false) {
                // ثُبّت المرجع من غيره في الأثناء، أو لا حاجة للسؤال
                pinState = null;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('pinModal')).hide();
            } else if (open && data.reference_state === 'unconfirmed' && data.distance_m > (cfg.radius || 150)) {
                const line = document.getElementById('pin_distance_line');
                line.textContent = `موقعك يبعد نحو ${Math.round(data.distance_m)} م عن الموقع المسجّل سابقاً (غير مؤكد).`;
                line.classList.remove('d-none');
            } else if (!pinState && data.can_pin && !item.askedPin && !item.body.pin_reference && !item.body.pin_declined) {
                // لم تسأل الواجهة (حالتها المحلية قديمة) لكن الخادم يرى السؤال لازماً
                askPin(Object.assign({}, item, { askedPin: true }));
            }
        });
    }

    // ------------------------------------------------------------------
    // تفاصيل الفواتير: IndexedDB (قاعدة distributor_app، مخزن invoiceLines بمفتاح رقم الكشف)
    // ------------------------------------------------------------------
    const memoryLines = {};
    const IDB = {
        open() {
            return new Promise(resolve => {
                try {
                    if (!('indexedDB' in window)) return resolve(null);
                    const req = indexedDB.open('distributor_app', 1);
                    req.onupgradeneeded = () => { req.result.createObjectStore('invoiceLines', { keyPath: 'sheet_id' }); };
                    req.onsuccess = () => resolve(req.result);
                    req.onerror = () => resolve(null);
                } catch (e) { resolve(null); }
            });
        },
        async tx(mode, fn) {
            const db = await this.open();
            if (!db) return null;
            return new Promise(resolve => {
                try {
                    const t = db.transaction('invoiceLines', mode);
                    const req = fn(t.objectStore('invoiceLines'));
                    req.onsuccess = () => resolve(req.result ?? null);
                    req.onerror = () => resolve(null);
                } catch (e) { resolve(null); }
            });
        },
        async get(id) { return (await this.tx('readonly', s => s.get(id))) || memoryLines[id] || null; },
        async put(record) { memoryLines[record.sheet_id] = record; await this.tx('readwrite', s => s.put(record)); },
        async del(id) { delete memoryLines[id]; await this.tx('readwrite', s => s.delete(id)); },
    };

    let linesData = null;

    // المرحلة 6B: نسخة مخطط JSON الأسطر (حقول الحسم)؛ الكاش بنسخة أقدم يُتجاهل ويُجلب من جديد
    const LINES_SCHEMA = 2;

    async function loadLines() {
        if (!cfg.linesUrl) return;
        let cached = await IDB.get(cfg.sheetId);
        if (cached && (cached.data?.schema || 1) < LINES_SCHEMA) cached = null;
        if (cached) { linesData = cached.data; renderLinesState(); }

        if (!navigator.onLine) { renderLinesState(); return; }
        try {
            const res = await fetch(cfg.linesUrl, {
                headers: Object.assign({ 'Accept': 'application/json' }, cached ? { 'If-None-Match': cached.etag } : {}),
                credentials: 'same-origin',
                cache: 'no-cache',
            });
            if (res.status === 304) return;
            if (res.status === 403 || res.status === 404) { await IDB.del(cfg.sheetId); linesData = null; renderLinesState(); return; }
            if (res.ok) {
                const data = await res.json();
                // المرحلة 6B-D: الكشف عُدّل منذ آخر نسخة مخزّنة (الطابور المحلي للدفعات لا يُمس)
                if (cached && (cached.data?.revision ?? 0) !== (data.revision ?? 0)) toast('تم تحديث الكشف', 'info');
                linesData = data;
                await IDB.put({ sheet_id: cfg.sheetId, etag: res.headers.get('ETag') || data.etag, fetched_at: Date.now(), data });
                renderLinesState();
            }
        } catch (e) { renderLinesState(); }
    }

    function renderLinesState() {
        const notice = document.getElementById('linesNotice');
        if (notice) notice.classList.toggle('d-none', !!linesData);
        applyAckGates();
        // أزرار الطباعة المجمّعة: معطّلة إن لم توجد فواتير مرئية
        if (linesData) {
            document.querySelectorAll('[data-print-customer]').forEach(btn => {
                const merged = (linesData.merged || []).find(m => String(m.customer_id) === btn.dataset.printCustomer);
                btn.dataset.hasInvoices = merged && merged.invoice_ids.length ? '1' : '0';
                if (merged && merged.mismatch) btn.title = 'تنبيه: مجموع الأسطر لا يطابق إجمالي الفواتير';
            });
        }
    }

    function showInvoice(invoiceId) {
        const modalEl = document.getElementById('invoiceModal');
        const body = document.getElementById('invoiceModalBody');
        const printBtn = document.getElementById('invoicePrintBtn');
        if (printBtn) printBtn.dataset.printInvoice = invoiceId;
        const invoice = linesData ? (linesData.invoices || []).find(i => String(i.id) === String(invoiceId)) : null;

        if (!invoice) {
            body.innerHTML = '<div class="alert alert-warning mb-0">افتح هذا الكشف مرة واحدة بوجود الإنترنت لتحميل تفاصيل الفواتير.</div>';
        } else if (window.InvoiceView) {
            // عرض مشترك مع كشوف المندوب (public/js/invoice-view.js)
            body.innerHTML = window.InvoiceView.renderInvoiceDetails(invoice);
        } else {
            body.innerHTML = '<div class="alert alert-warning mb-0">تعذّر عرض التفاصيل. أعد تحميل الصفحة.</div>';
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    // ------------------------------------------------------------------
    // التشغيل
    // ------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', () => {
        setupPaymentForm();
        setupVisitForm();
        setupVoid();
        setupPinModal();
        updateBadge();

        document.querySelectorAll('[data-invoice-id]').forEach(el => el.addEventListener('click', () => showInvoice(el.dataset.invoiceId)));

        // عناصر من النسخة القديمة (قبل المرحلة 3) لا يمكن إرسالها لأن مساراتها أُزيلت
        const legacy = (LS.get('offlinePaymentsQueue', {}) && Object.keys(LS.get('offlinePaymentsQueue', {})).length)
            + (LS.get('externalOfflineQueue', []) || []).length;
        if (legacy > 0) toast(`يوجد ${legacy} عنصر محفوظ من النسخة السابقة لم يُرسل. سجّلها من جديد ثم أبلغ المشرف.`, 'warning');

        loadLines();
        sync();
        setInterval(sync, 30000);
    });
    window.addEventListener('online', () => { sync(); loadLines(); });

    // للاستخدام من صفحات الموزع
    window.DistributorApp = {
        sync, Queue, normalizeArabic, money, toast, loadLines, get linesData() { return linesData; }, get pinOpen() { return !!pinState; },
        ackStamp, showAckBox, clearAckBox,
        // للمندوب (المرحلة 6): الطابور نفسه مع صورة الإيصال
        RepUploads, uuid, receiptNumber, position, parseNumber, escapeHtml, askPin,
    };
})();
