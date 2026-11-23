// المرحلة 7H: طابور الموزع مع الاطلاع الإلزامي — لا يُحذف عنصر رُفض بـ409، و«من الطابور دون اتصال» يُعلَّم offline=1
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/js/distributor-app.js', import.meta.url), 'utf8');

// بيئة متصفح دنيا: localStorage وfetch وdocument بلا DOM فعلي
function load(responses) {
    const store = {};
    const sent = [];
    const toasts = [];
    const doc = {
        querySelector: () => null,
        querySelectorAll: () => [],
        getElementById: () => null,
        addEventListener: () => {},
        dispatchEvent: () => true,
    };
    const window = {
        DistributorConfig: { sheetId: 1, lastNoticeId: 7, ackUrl: '/ack/__CUSTOMER__' },
        addEventListener: () => {},
    };
    const context = {
        window, document: doc, console,
        navigator: { onLine: true },
        localStorage: {
            getItem: k => (k in store ? store[k] : null),
            setItem: (k, v) => { store[k] = String(v); },
        },
        CustomEvent: class { constructor(type, init) { this.type = type; this.detail = init?.detail; } },
        alert: m => toasts.push(m),
        confirm: () => true,
        setTimeout: () => 0, setInterval: () => 0,
        fetch: async (url, options) => {
            const body = JSON.parse(options.body);
            sent.push({ url, body });
            const next = responses.shift();
            if (next === 'network') throw new Error('offline');
            return { ok: next.status < 300, status: next.status, json: async () => next.data || {} };
        },
    };
    context.globalThis = context;
    vm.createContext(context);
    vm.runInContext(source, context);
    return { app: window.DistributorApp, sent, toasts, context };
}

const item = (id, customerId, extra = {}) => ({ id, kind: 'payment', url: '/pay', body: { client_uuid: id, customer_id: customerId, amount: 100 }, label: 'ص', createdAt: Date.now(), ...extra });

test('409 «اطّلع أولاً» لا يحذف الدفعة من الطابور، ولا تُعاد قبل «اطلعت»', async () => {
    const { app, sent } = load([
        { status: 409, data: { ack_required: true, customer_id: 5, message: 'تغيّرت بيانات هذه الصيدلية (أُضيفت فاتورة). اطّلع على التعديل أولاً.', notices: [] } },
        { status: 201, data: { success: true } },
    ]);
    app.Queue.push(item('a', 5));
    app.Queue.push(item('b', 6));
    await app.sync();

    const queue = app.Queue.all();
    assert.equal(queue.length, 1, 'الدفعة المرفوضة باقية والأخرى أُرسلت');
    assert.equal(queue[0].id, 'a');
    assert.equal(queue[0].awaitingAck, true);
    assert.equal(queue[0].liveRejected, true);
    assert.equal(sent[0].body.offline, undefined, 'الطلب الأول حي');

    await app.sync();
    assert.equal(sent.length, 2, 'لا إعادة إرسال قبل «اطلعت»');
});

test('فشل الاتصال ⇒ المحاولة التالية offline=1، والمسجّلة دون اتصال offline=1 من البداية', async () => {
    const { app, sent } = load(['network', { status: 201, data: {} }, { status: 201, data: {} }]);
    app.Queue.push(item('c', 5));
    await app.sync();
    assert.equal(app.Queue.all()[0].failedAttempts, 1);
    assert.equal(sent[0].body.offline, undefined);

    app.Queue.push(item('d', 6, { recordedOffline: true }));
    await app.sync();
    assert.equal(sent[1].body.offline, 1, 'إعادة المحاولة بعد فشل الاتصال من الطابور');
    assert.equal(sent[2].body.offline, 1, 'سُجّلت والجهاز غير متصل');
    assert.equal(app.Queue.all().length, 0);
});

test('العنصر المرفوض حياً يبقى حياً بعد «اطلعت» (لا يتجاوز المنع بعلامة الطابور)', async () => {
    const { app, sent } = load([{ status: 201, data: {} }]);
    app.Queue.push(item('e', 5, { awaitingAck: false, liveRejected: true, failedAttempts: 2 }));
    await app.sync();
    assert.equal(sent[0].body.offline, undefined);
});

test('ختم التسجيل: وقت الجهاز وآخر إشعار عرفه', () => {
    const { app } = load([]);
    const stamp = app.ackStamp();
    assert.equal(stamp.known_notice_id, 7);
    assert.ok(!Number.isNaN(Date.parse(stamp.recorded_at)));
});
