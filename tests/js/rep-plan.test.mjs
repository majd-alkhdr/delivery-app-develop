// المرحلة 8B: «خطتي» — دوال الواجهة النقية (الخادم وحده يصنّف الموقع)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { geoErrorMessage, decide, rowState, matchesFilter, badgeVariant, summarize, GEO_ERRORS, INSECURE_MESSAGE } from '../../public/js/rep/rep-plan.js';

test('رسائل الموقع: اتصال غير آمن، رفض الإذن، تعذّر، مهلة', () => {
    assert.equal(geoErrorMessage({ secure: false }), INSECURE_MESSAGE);
    assert.equal(geoErrorMessage({ code: 1 }), GEO_ERRORS[1]);
    assert.equal(geoErrorMessage({ code: 3 }), GEO_ERRORS[3]);
    assert.equal(geoErrorMessage({ supported: false }), GEO_ERRORS[2]);
    assert.equal(geoErrorMessage({ code: 99 }), GEO_ERRORS[2]);
    assert.match(INSECURE_MESSAGE, /https/);
});

test('قرار الواجهة من ردّ الخادم', () => {
    assert.deepEqual(decide(201, {}), { kind: 'saved' });
    assert.deepEqual(decide(200, { result: 'duplicate' }), { kind: 'saved' });
    assert.equal(decide(409, { confirm: 'mismatch' }).kind, 'confirm');
    assert.equal(decide(409, { confirm: 'mismatch' }).retry, false);
    assert.equal(decide(409, { confirm: 'low_accuracy' }).retry, true);
    assert.match(decide(409, { confirm: 'no_gps' }).okLabel, /بلا تحقق/);
    assert.deepEqual(decide(409, { result: 'exists' }), { kind: 'exists' });
    assert.deepEqual(decide(422, {}), { kind: 'error' });
    assert.deepEqual(decide(500, {}), { kind: 'error' });
});

test('حالة الصف والمرشّح والملخص', () => {
    assert.equal(rowState(null), 'remaining');
    assert.equal(rowState({ status: 'not_visited' }), 'not_visited');
    assert.equal(rowState({ status: 'visited', location_status: 'mismatch' }), 'mismatch');
    assert.equal(rowState({ status: 'visited', location_status: 'no_gps' }), 'visited');
    assert.equal(matchesFilter('mismatch', 'visited'), true);
    assert.equal(matchesFilter('mismatch', 'mismatch'), true);
    assert.equal(matchesFilter('visited', 'mismatch'), false);
    assert.equal(matchesFilter('remaining', 'all'), true);
    assert.equal(badgeVariant({ status: 'visited', location_status: 'matched' }), 'success');
    assert.equal(badgeVariant({ status: 'visited', location_status: 'low_accuracy' }), 'info');
    assert.equal(badgeVariant({ status: 'not_visited' }), 'warning');
    assert.deepEqual(summarize(['visited', 'mismatch', 'not_visited', 'remaining', 'remaining']),
        { planned: 5, visited: 2, not_visited: 1, remaining: 2 });
});
