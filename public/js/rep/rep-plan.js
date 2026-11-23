/*
 * «خطتي» للمندوب (المرحلة 8B).
 * الخادم وحده يصنّف الموقع (بدالة المسافة نفسها لزيارة الموزع)؛ هنا: طلب الموقع بنفس خيارات تطبيق الموزع
 * (enableHighAccuracy، مهلة 8 ث)، ونصوص أخطائه، والتأكيدات التي يطلبها الخادم (409 needs_confirmation).
 * اتصال فقط في هذه المرحلة: فشل الإرسال ⇒ «تعذّر الحفظ، تحقق من الاتصال» مع «إعادة المحاولة»، وclient_uuid يمنع التكرار.
 * الدوال المصدَّرة نقية وتُختبر بـ Node (tests/js/rep-plan.test.mjs).
 */

/** رسائل أخطاء الموقع (كما في distributor-app.js: PIN_ERRORS) */
export const GEO_ERRORS = {
    1: 'إذن الموقع مرفوض. فعّل إذن الموقع لهذا الموقع في إعدادات المتصفح ثم أعد المحاولة.',
    2: 'تعذّر تحديد الموقع حالياً. تأكد من تشغيل GPS (خدمة الموقع) ثم أعد المحاولة.',
    3: 'انتهت مهلة قراءة الموقع. أعد المحاولة.',
};

export const INSECURE_MESSAGE = 'تحديد الموقع يتطلب اتصالاً آمناً (https). افتح التطبيق بعنوان يبدأ بـ https، وإلا تُسجَّل الزيارة بلا تحقق من الموقع.';

export const NETWORK_MESSAGE = 'تعذّر الحفظ، تحقق من الاتصال.';

/** نص خطأ الموقع (لا GPS في المتصفح، اتصال غير آمن، أو رمز خطأ geolocation) */
export function geoErrorMessage({ code = 2, secure = true, supported = true } = {}) {
    if (!secure) return INSECURE_MESSAGE;
    if (!supported) return GEO_ERRORS[2];
    return GEO_ERRORS[code] || GEO_ERRORS[2];
}

/**
 * قرار الواجهة من ردّ الخادم.
 * @returns {{ kind: 'saved'|'confirm'|'exists'|'error', retry?: boolean, okLabel?: string }}
 */
export function decide(status, body = {}) {
    if (status === 201 || status === 200) return { kind: 'saved' };
    if (status === 409 && body && body.confirm) {
        return {
            kind: 'confirm',
            retry: body.confirm === 'low_accuracy',
            okLabel: body.confirm === 'no_gps' ? 'سجّل الزيارة بلا تحقق من الموقع'
                : body.confirm === 'low_accuracy' ? 'سجّل بدقة ضعيفة' : 'نعم، سجّل الزيارة',
        };
    }
    if (status === 409) return { kind: 'exists' };
    return { kind: 'error' };
}

/** حالة الصف للمرشّح بعد الحفظ */
export function rowState(visit) {
    if (!visit) return 'remaining';
    if (visit.status === 'not_visited') return 'not_visited';
    return visit.location_status === 'mismatch' ? 'mismatch' : 'visited';
}

/** هل يظهر الصف مع المرشّح؟ («تمت» تشمل غير المطابقة) */
export function matchesFilter(state, filter) {
    if (filter === 'all') return true;
    if (filter === 'visited') return state === 'visited' || state === 'mismatch';
    return state === filter;
}

export function badgeVariant(visit) {
    if (!visit) return 'secondary';
    if (visit.status === 'not_visited') return 'warning';
    return visit.location_status === 'matched' ? 'success' : visit.location_status === 'mismatch' ? 'danger' : 'info';
}

/** ملخص اليوم من حالات الصفوف */
export function summarize(states) {
    const s = { planned: states.length, visited: 0, not_visited: 0, remaining: 0 };
    for (const st of states) {
        if (st === 'visited' || st === 'mismatch') s.visited++;
        else if (st === 'not_visited') s.not_visited++;
        else s.remaining++;
    }
    return s;
}

// ----------------------------------------------------------------------
// المتصفح فقط
// ----------------------------------------------------------------------
if (typeof window !== 'undefined' && window.RepPlanConfig) {
    const cfg = window.RepPlanConfig;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));
    const modal = id => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));

    function position() {
        return new Promise(resolve => {
            if (!window.isSecureContext) return resolve({ error: geoErrorMessage({ secure: false }) });
            if (!navigator.geolocation) return resolve({ error: geoErrorMessage({ supported: false }) });
            navigator.geolocation.getCurrentPosition(
                p => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy || 0) }),
                err => resolve({ error: geoErrorMessage({ code: err?.code }) }),
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
            );
        });
    }

    async function send(payload) {
        const res = await fetch(cfg.url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(payload),
        });
        let body = {};
        try { body = await res.json(); } catch (e) { /* ردّ غير JSON */ }
        return { status: res.status, body };
    }

    // نافذة تأكيد عامة: تعيد 'ok' | 'retry' | 'cancel'
    function ask(text, { okLabel = 'نعم، سجّل الزيارة', retry = false } = {}) {
        const el = document.getElementById('planConfirmModal');
        el.querySelector('[data-confirm-text]').textContent = text;
        el.querySelector('[data-confirm-ok]').textContent = okLabel;
        el.querySelector('[data-confirm-retry]').classList.toggle('d-none', !retry);
        return new Promise(resolve => {
            let answer = 'cancel';
            const ok = el.querySelector('[data-confirm-ok]');
            const again = el.querySelector('[data-confirm-retry]');
            const onOk = () => { answer = 'ok'; modal('planConfirmModal').hide(); };
            const onRetry = () => { answer = 'retry'; modal('planConfirmModal').hide(); };
            ok.addEventListener('click', onOk, { once: true });
            again.addEventListener('click', onRetry, { once: true });
            el.addEventListener('hidden.bs.modal', () => {
                ok.removeEventListener('click', onOk);
                again.removeEventListener('click', onRetry);
                resolve(answer);
            }, { once: true });
            modal('planConfirmModal').show();
        });
    }

    function showError(row, text, retry) {
        const box = row.querySelector('[data-row-error]');
        box.innerHTML = '';
        box.append(text + ' ');
        if (retry) {
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm btn-outline-danger fw-bold ms-1';
            b.textContent = 'إعادة المحاولة';
            b.addEventListener('click', retry, { once: true });
            box.append(b);
        }
        box.classList.remove('d-none');
    }

    function applyVisit(row, visit) {
        const badge = row.querySelector('[data-row-status]');
        badge.textContent = visit.text;
        badge.className = 'badge text-wrap text-start text-bg-' + badgeVariant(visit);
        row.dataset.state = rowState(visit);
        row.querySelector('[data-row-error]').classList.add('d-none');
        refreshSummary();
        applyFilter();
    }

    function refreshSummary() {
        // صفوف الصيدليات المزالة من الخطة (data-orphan) لا تدخل ملخص اليوم
        const states = [...document.querySelectorAll('#plan-list .plan-row:not([data-orphan])')].map(r => r.dataset.state);
        const s = summarize(states);
        for (const k of ['visited', 'not_visited', 'remaining']) {
            const el = document.querySelector(`[data-sum="${k}"]`);
            if (el) el.textContent = s[k];
        }
        const bar = document.querySelector('[data-progress]');
        if (bar && s.planned) bar.style.width = Math.round((s.visited + s.not_visited) * 100 / s.planned) + '%';
    }

    // الإرسال مع حلقة التأكيد: الخادم يطلب التأكيد، والواجهة تعيد الإرسال بنفس client_uuid
    async function submit(row, payload) {
        const buttons = row.querySelectorAll('button');
        buttons.forEach(b => { b.disabled = true; });
        try {
            for (;;) {
                let res;
                try {
                    res = await send(payload);
                } catch (e) {
                    showError(row, NETWORK_MESSAGE, () => submit(row, payload));
                    return;
                }
                const d = decide(res.status, res.body);
                if (d.kind === 'saved') { applyVisit(row, res.body.visit); return; }
                if (d.kind === 'exists') {
                    // مسجَّلة مسبقاً: تغيير القرار بنافذة تأكيد صريحة
                    const answer = await ask((res.body.message || '') + ' هل تريد تغيير القرار؟', { okLabel: 'نعم، غيّر القرار' });
                    if (answer !== 'ok') return;
                    payload = Object.assign({}, payload, { correction: true, client_uuid: uuid() });
                    continue;
                }
                if (d.kind === 'confirm') {
                    const answer = await ask(res.body.message, d);
                    if (answer === 'cancel') return;
                    if (answer === 'retry') {
                        const pos = await position();
                        payload = Object.assign({}, payload, pos.error ? { lat: null, lng: null, accuracy: null } : { lat: pos.lat, lng: pos.lng, accuracy: pos.accuracy });
                        continue;
                    }
                    payload = Object.assign({}, payload, { confirm: res.body.confirm });
                    continue;
                }
                const msg = res.body?.errors ? Object.values(res.body.errors).flat().join(' ') : (res.body?.message || NETWORK_MESSAGE);
                showError(row, msg, res.status >= 500 ? () => submit(row, payload) : null);
                return;
            }
        } finally {
            buttons.forEach(b => { b.disabled = false; });
        }
    }

    function base(row, status) {
        return { client_uuid: uuid(), customer_id: Number(row.dataset.customerId), plan_date: cfg.date, status, correction: row.dataset.state !== 'remaining' };
    }

    document.addEventListener('click', async e => {
        const visitBtn = e.target.closest('[data-plan-visit]');
        const skipBtn = e.target.closest('[data-plan-skip]');
        if (visitBtn) {
            const row = visitBtn.closest('.plan-row');
            if (row.dataset.state !== 'remaining') {
                const answer = await ask('سُجّلت حالة هذه الصيدلية اليوم. تغيير القرار إلى «تمت الزيارة»؟', { okLabel: 'نعم، غيّر القرار' });
                if (answer !== 'ok') return;
            }
            visitBtn.disabled = true;
            const pos = await position();
            const payload = base(row, 'visited');
            if (pos.error) {
                const answer = await ask(pos.error + ' سيُسجَّل أنه لم يُتحقق من الموقع.', { okLabel: 'سجّل الزيارة بلا تحقق من الموقع' });
                visitBtn.disabled = false;
                if (answer !== 'ok') return;
                Object.assign(payload, { lat: null, lng: null, accuracy: null, confirm: 'no_gps' });
            } else {
                Object.assign(payload, { lat: pos.lat, lng: pos.lng, accuracy: pos.accuracy });
            }
            visitBtn.disabled = false;
            submit(row, payload);
        }
        if (skipBtn) {
            const row = skipBtn.closest('.plan-row');
            const el = document.getElementById('planReasonModal');
            el.querySelector('[data-modal-name]').textContent = row.dataset.name;
            el.querySelectorAll('input[name="plan_reason"]').forEach(i => { i.checked = false; });
            el.querySelector('[data-reason-text]').value = '';
            el.querySelector('[data-reason-text]').classList.add('d-none');
            el.querySelector('[data-modal-error]').classList.add('d-none');
            el.dataset.customerId = row.dataset.customerId;
            modal('planReasonModal').show();
        }
    });

    document.addEventListener('change', e => {
        if (e.target.name === 'plan_reason') {
            document.querySelector('[data-reason-text]').classList.toggle('d-none', e.target.value !== 'other');
        }
    });

    document.querySelector('[data-reason-save]')?.addEventListener('click', async () => {
        const el = document.getElementById('planReasonModal');
        const code = el.querySelector('input[name="plan_reason"]:checked')?.value;
        const text = el.querySelector('[data-reason-text]').value.trim();
        const err = el.querySelector('[data-modal-error]');
        if (!code || (code === 'other' && !text)) {
            err.textContent = !code ? 'اختر سبباً.' : 'اكتب السبب عند اختيار «أخرى».';
            err.classList.remove('d-none');
            return;
        }
        const row = document.querySelector(`.plan-row[data-customer-id="${el.dataset.customerId}"]`);
        if (row.dataset.state !== 'remaining') {
            modal('planReasonModal').hide();
            const answer = await ask('سُجّلت حالة هذه الصيدلية اليوم. تغيير القرار إلى «لم تتم»؟', { okLabel: 'نعم، غيّر القرار' });
            if (answer !== 'ok') return;
        } else {
            modal('planReasonModal').hide();
        }
        submit(row, Object.assign(base(row, 'not_visited'), { reason_code: code, reason_text: text || null }));
    });

    // البحث والمرشّح
    let filter = 'all';
    function applyFilter() {
        const q = (document.querySelector('[data-plan-search]')?.value || '').trim();
        document.querySelectorAll('#plan-list .plan-row').forEach(row => {
            const show = matchesFilter(row.dataset.state, filter) && (!q || row.dataset.name.includes(q));
            row.classList.toggle('d-none', !show);
        });
    }
    document.querySelector('[data-plan-search]')?.addEventListener('input', applyFilter);
    document.querySelectorAll('[data-plan-filter]').forEach(b => b.addEventListener('click', () => {
        filter = b.dataset.planFilter;
        document.querySelectorAll('[data-plan-filter]').forEach(x => x.classList.toggle('active', x === b));
        applyFilter();
    }));
}
