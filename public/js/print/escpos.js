/*
 * أوامر ESC/POS (دوال نقية تُختبر بـ Node، بلا DOM):
 * - تحويل صورة (ImageData أو ما يشبهها: { width, height, data RGBA }) إلى 1-bit بالعتبة.
 * - تغليفها بأوامر GS v 0 على شرائح، مع ESC @ في البداية، والتغذية ESC d n، والقص الاختياري GS V 1.
 * - chunk(): تقسيم البايتات لحزم BLE.
 */

export const ESC = 0x1b;
export const GS = 0x1d;

/**
 * 1-bit بالعتبة: البكسل أسود إن كانت إضاءته (بعد مزجه مع خلفية بيضاء حسب الشفافية) أقل من العتبة.
 * @returns {{ width: number, height: number, bytesPerRow: number, bits: Uint8Array }}
 */
export function toMonochrome(image, threshold = 160) {
    const { width, height, data } = image;
    const bytesPerRow = Math.ceil(width / 8);
    const bits = new Uint8Array(bytesPerRow * height);

    for (let y = 0; y < height; y++) {
        for (let x = 0; x < width; x++) {
            const i = (y * width + x) * 4;
            const alpha = data[i + 3] === undefined ? 255 : data[i + 3];
            const lum = 0.299 * data[i] + 0.587 * data[i + 1] + 0.114 * data[i + 2];
            const onWhite = 255 - (alpha / 255) * (255 - lum);
            if (onWhite < threshold) {
                bits[y * bytesPerRow + (x >> 3)] |= 0x80 >> (x & 7);
            }
        }
    }
    return { width, height, bytesPerRow, bits };
}

/**
 * ضم صور أحادية بنفس العرض عمودياً (شرائح canvas المتتالية).
 */
export function concatMonochrome(parts) {
    if (!parts.length) return { width: 0, height: 0, bytesPerRow: 0, bits: new Uint8Array(0) };
    const { width, bytesPerRow } = parts[0];
    const height = parts.reduce((s, p) => s + p.height, 0);
    const bits = new Uint8Array(bytesPerRow * height);
    let offset = 0;
    for (const p of parts) {
        if (p.bytesPerRow !== bytesPerRow) throw new Error('عرض الشرائح غير متساوٍ');
        bits.set(p.bits, offset);
        offset += p.bits.length;
    }
    return { width, height, bytesPerRow, bits };
}

/**
 * ترويسة GS v 0: 1D 76 30 00 xL xH yL yH (x = البايتات لكل صف، y = عدد الصفوف).
 */
export function rasterHeader(bytesPerRow, rows) {
    return Uint8Array.of(GS, 0x76, 0x30, 0x00, bytesPerRow & 0xff, (bytesPerRow >> 8) & 0xff, rows & 0xff, (rows >> 8) & 0xff);
}

/**
 * تقسيم الصورة إلى شرائح أوامر GS v 0 بارتفاع sliceRows.
 * @returns {Uint8Array[]}
 */
export function rasterSlices(mono, sliceRows = 128) {
    const rowsPer = Math.max(1, Math.floor(sliceRows));
    const slices = [];
    for (let y = 0; y < mono.height; y += rowsPer) {
        const rows = Math.min(rowsPer, mono.height - y);
        const header = rasterHeader(mono.bytesPerRow, rows);
        const body = mono.bits.subarray(y * mono.bytesPerRow, (y + rows) * mono.bytesPerRow);
        const out = new Uint8Array(header.length + body.length);
        out.set(header, 0);
        out.set(body, header.length);
        slices.push(out);
    }
    return slices;
}

/**
 * المستند الكامل: ESC @ + الشرائح + ESC d n (تغذية) + GS V 1 (قص جزئي اختياري).
 */
export function buildEscPos(mono, { sliceRows = 128, feedLines = 4, cut = false } = {}) {
    const parts = [Uint8Array.of(ESC, 0x40), ...rasterSlices(mono, sliceRows)];
    const feed = Math.max(0, Math.min(255, Math.floor(feedLines)));
    if (feed > 0) parts.push(Uint8Array.of(ESC, 0x64, feed));
    if (cut) parts.push(Uint8Array.of(GS, 0x56, 0x01));
    return concatBytes(parts);
}

export function concatBytes(parts) {
    const total = parts.reduce((s, p) => s + p.length, 0);
    const out = new Uint8Array(total);
    let offset = 0;
    for (const p of parts) {
        out.set(p, offset);
        offset += p.length;
    }
    return out;
}

/**
 * تقسيم البايتات إلى حزم بحجم size (الأخيرة قد تكون أصغر)، دون فقد أو تغيير ترتيب.
 * @returns {Uint8Array[]}
 */
export function chunk(bytes, size) {
    const n = Math.max(1, Math.floor(size));
    const out = [];
    for (let i = 0; i < bytes.length; i += n) out.push(bytes.subarray(i, Math.min(i + n, bytes.length)));
    return out;
}
