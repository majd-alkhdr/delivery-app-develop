// المرحلة 6: نموذج تحصيل المندوب (دوال نقية بلا متصفح)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { fitWithin, repFormState, amountSyp, exceedsKnownDebt, MAX_EDGE } from '../../public/js/rep/rep-form.js';

test('fitWithin يحفظ النسبة ولا يكبّر ويحترم الحد', () => {
    assert.equal(MAX_EDGE, 1600);
    assert.deepEqual(fitWithin(4000, 3000), { width: 1600, height: 1200 });
    assert.deepEqual(fitWithin(3000, 4000), { width: 1200, height: 1600 });
    assert.deepEqual(fitWithin(1200, 800), { width: 1200, height: 800 });     // أصغر من الحد: كما هو
    assert.deepEqual(fitWithin(1600, 1600), { width: 1600, height: 1600 });   // على الحد تماماً
    assert.deepEqual(fitWithin(5000, 10, 1600), { width: 1600, height: 3 });
    assert.deepEqual(fitWithin(10, 5000, 1600), { width: 3, height: 1600 });
    assert.deepEqual(fitWithin(4000, 2000, 2000), { width: 2000, height: 1000 });
    assert.deepEqual(fitWithin(0, 100), { width: 0, height: 0 });
    const r = fitWithin(4032, 3024);
    assert.ok(Math.max(r.width, r.height) <= 1600);
});

test('repFormState: المبلغ ورقم الدفتر والصورة مطلوبة، والسعر للدولار', () => {
    assert.deepEqual(repFormState({ amount: 1000, currency: 'SYP', bookNo: '12', hasImage: true }), { ready: true, missing: [] });
    assert.deepEqual(repFormState({ amount: 1000, currency: 'SYP', bookNo: '12', hasImage: false }).missing, ['image']);
    assert.deepEqual(repFormState({ amount: 1000, currency: 'SYP', bookNo: '  ', hasImage: true }).missing, ['book_no']);
    assert.deepEqual(repFormState({ amount: 0, currency: 'SYP', bookNo: '1', hasImage: true }).missing, ['amount']);
    assert.deepEqual(repFormState({ amount: 10, currency: 'USD', rate: null, bookNo: '1', hasImage: true }).missing, ['rate']);
    assert.equal(repFormState({ amount: 10, currency: 'USD', rate: 139, bookNo: '1', hasImage: true }).ready, true);
    assert.deepEqual(repFormState({}).missing, ['amount', 'book_no', 'image']);
    assert.deepEqual(repFormState({ amount: 5, bookNo: '1', hasImage: true, canRecord: false }).missing, ['account']);
});

test('المكافئ بالليرة وتنبيه «أكبر من الدَين المعروف»', () => {
    assert.equal(amountSyp(100, 'USD', 139), 13900);
    assert.equal(amountSyp(100, 'USD', null), null);
    assert.equal(amountSyp(250.5, 'SYP'), 250.5);
    assert.equal(amountSyp(0, 'SYP'), null);
    assert.equal(exceedsKnownDebt(1000.01, 1000), true);
    assert.equal(exceedsKnownDebt(1000, 1000), false);
    assert.equal(exceedsKnownDebt(50, 0), true);
    assert.equal(exceedsKnownDebt(null, 0), false);
});
