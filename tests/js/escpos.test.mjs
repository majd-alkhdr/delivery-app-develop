// node --test tests/js/  (بلا أي اعتماديات)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { toMonochrome, rasterHeader, rasterSlices, buildEscPos, chunk, concatMonochrome } from '../../public/js/print/escpos.js';

function image(width, height, fill = 255) {
    const data = new Uint8ClampedArray(width * height * 4).fill(fill);
    for (let i = 3; i < data.length; i += 4) data[i] = 255;   // معتم
    return { width, height, data };
}

function setPixel(img, x, y, v) {
    const i = (y * img.width + x) * 4;
    img.data[i] = img.data[i + 1] = img.data[i + 2] = v;
    img.data[i + 3] = 255;
}

test('ترويسة GS v 0 صحيحة لأبعاد مختلفة', () => {
    assert.deepEqual([...rasterHeader(72, 128)], [0x1d, 0x76, 0x30, 0x00, 72, 0, 128, 0]);
    assert.deepEqual([...rasterHeader(48, 300)], [0x1d, 0x76, 0x30, 0x00, 48, 0, 300 & 0xff, 1]);
    assert.deepEqual([...rasterHeader(260, 513)], [0x1d, 0x76, 0x30, 0x00, 4, 1, 1, 2]);
});

test('التحويل بالعتبة إلى 1-bit (والشفاف أبيض)', () => {
    const img = image(10, 2);
    setPixel(img, 0, 0, 0);      // أسود
    setPixel(img, 7, 0, 150);    // أغمق من العتبة 160
    setPixel(img, 8, 0, 200);    // أفتح
    setPixel(img, 9, 1, 0);
    const transparentIdx = (1 * 10 + 1) * 4;
    img.data[transparentIdx] = img.data[transparentIdx + 1] = img.data[transparentIdx + 2] = 0;
    img.data[transparentIdx + 3] = 0;   // شفاف ⇒ أبيض

    const mono = toMonochrome(img, 160);
    assert.equal(mono.bytesPerRow, 2);
    assert.equal(mono.bits[0], 0b10000001);   // x=0 وx=7
    assert.equal(mono.bits[1], 0b00000000);   // x=8 أفتح
    assert.equal(mono.bits[2], 0b00000000);   // الصف الثاني: x=1 شفاف
    assert.equal(mono.bits[3], 0b01000000);   // x=9

    assert.equal(toMonochrome(img, 100).bits[0], 0b10000000);   // عتبة أقل ⇒ 150 أبيض
});

test('التقطيع إلى شرائح بالارتفاع المطلوب', () => {
    const mono = toMonochrome(image(16, 300, 0), 160);   // أسود كامل
    const slices = rasterSlices(mono, 128);
    assert.equal(slices.length, 3);
    assert.deepEqual([...slices[0].subarray(0, 8)], [0x1d, 0x76, 0x30, 0x00, 2, 0, 128, 0]);
    assert.deepEqual([...slices[2].subarray(0, 8)], [0x1d, 0x76, 0x30, 0x00, 2, 0, 44, 0]);
    assert.equal(slices[0].length, 8 + 2 * 128);
    assert.equal(slices[2].length, 8 + 2 * 44);
    assert.ok(slices[1].subarray(8).every(b => b === 0xff));
});

test('المستند: ESC @ في البداية، والتغذية ESC d n، والقص الاختياري GS V 1', () => {
    const mono = toMonochrome(image(8, 4), 160);
    const withCut = buildEscPos(mono, { sliceRows: 128, feedLines: 4, cut: true });
    assert.deepEqual([...withCut.subarray(0, 2)], [0x1b, 0x40]);
    assert.deepEqual([...withCut.subarray(2, 10)], [0x1d, 0x76, 0x30, 0x00, 1, 0, 4, 0]);
    assert.deepEqual([...withCut.subarray(-6)], [0x1b, 0x64, 4, 0x1d, 0x56, 0x01]);

    const noCut = buildEscPos(mono, { sliceRows: 2, feedLines: 0, cut: false });
    assert.equal(noCut.length, 2 + 2 * (8 + 2));   // شريحتان بلا تغذية ولا قص
    assert.notDeepEqual([...noCut.subarray(-3)], [0x1d, 0x56, 0x01]);
});

test('ضم الشرائح الأحادية عمودياً', () => {
    const a = toMonochrome(image(8, 2, 0), 160);
    const b = toMonochrome(image(8, 3, 255), 160);
    const joined = concatMonochrome([a, b]);
    assert.equal(joined.height, 5);
    assert.deepEqual([...joined.bits], [0xff, 0xff, 0, 0, 0]);
});

test('chunk يقسم بالحجم الصحيح ولا يفقد بايتاً ويحفظ الترتيب', () => {
    const bytes = Uint8Array.from({ length: 1003 }, (_, i) => i % 256);
    for (const size of [1, 20, 100, 512, 2000]) {
        const parts = chunk(bytes, size);
        assert.equal(parts.length, Math.ceil(1003 / size));
        parts.slice(0, -1).forEach(p => assert.equal(p.length, size));
        const rebuilt = Uint8Array.from(parts.flatMap(p => [...p]));
        assert.deepEqual(rebuilt, bytes);
    }
    assert.deepEqual(chunk(new Uint8Array(0), 100), []);
});
