/*
 * نموذج تحصيل المندوب (المرحلة 6): دوال نقية تُختبر بـ Node، وضغط الصورة على الجهاز (المتصفح فقط).
 * - fitWithin: أبعاد الصورة داخل حد للضلع الأطول مع الحفاظ على النسبة (بلا تكبير).
 * - repFormState: هل النموذج جاهز للحفظ، وما الناقص (المبلغ، السعر للدولار، رقم الإيصال الورقي، الصورة).
 * - compressImage: canvas → JPEG بجودة ~0.72 وضلع أطول ≤ 1600 (يتخلص من EXIF).
 */

export const MAX_EDGE = 1600;
export const JPEG_QUALITY = 0.72;

/**
 * @returns {{ width: number, height: number }}
 */
export function fitWithin(width, height, max = MAX_EDGE) {
    const w = Math.max(0, Math.round(Number(width) || 0));
    const h = Math.max(0, Math.round(Number(height) || 0));
    const limit = Math.max(1, Math.round(Number(max) || MAX_EDGE));
    if (w === 0 || h === 0) return { width: 0, height: 0 };
    const longest = Math.max(w, h);
    if (longest <= limit) return { width: w, height: h };
    const scale = limit / longest;
    return {
        width: Math.max(1, Math.round(w * scale)),
        height: Math.max(1, Math.round(h * scale)),
    };
}

/**
 * حالة نموذج التحصيل.
 * @param {{ amount: ?number, currency: string, rate: ?number, bookNo: string, hasImage: boolean, canRecord?: boolean }} s
 * @returns {{ ready: boolean, missing: string[] }}
 *   missing من: 'account' (لا مركز كلفة)، 'amount'، 'rate'، 'book_no'، 'image'
 */
export function repFormState(s = {}) {
    const missing = [];
    if (s.canRecord === false) missing.push('account');
    const amount = Number(s.amount);
    if (!(amount > 0)) missing.push('amount');
    if ((s.currency || 'SYP') === 'USD' && !(Number(s.rate) > 0)) missing.push('rate');
    if (!String(s.bookNo ?? '').trim()) missing.push('book_no');
    if (!s.hasImage) missing.push('image');
    return { ready: missing.length === 0, missing };
}

export const MISSING_LABELS = {
    account: 'لم يُضبط مركز الكلفة لحسابك',
    amount: 'المبلغ',
    rate: 'سعر الصرف',
    book_no: 'رقم الإيصال الورقي',
    image: 'صورة الإيصال',
};

/**
 * المبلغ بالليرة (المكافئ للدولار بسعره)، أو null إن لم يكتمل.
 */
export function amountSyp(amount, currency, rate) {
    const a = Number(amount);
    if (!(a > 0)) return null;
    if ((currency || 'SYP') !== 'USD') return Math.round(a * 100) / 100;
    const r = Number(rate);
    return r > 0 ? Math.round(a * r * 100) / 100 : null;
}

/**
 * تنبيه غير مانع: المبلغ أكبر من الدَين المعروف.
 */
export function exceedsKnownDebt(syp, knownDebt) {
    if (syp === null || syp === undefined) return false;
    return Math.round(Number(syp) * 100) > Math.round(Number(knownDebt || 0) * 100);
}

/**
 * ضغط صورة الكاميرا إلى JPEG (المتصفح فقط). يعيد Blob.
 */
export async function compressImage(file, max = MAX_EDGE, quality = JPEG_QUALITY) {
    const bitmap = await loadImage(file);
    const { width, height } = fitWithin(bitmap.width, bitmap.height, max);
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, width, height);
    ctx.drawImage(bitmap, 0, 0, width, height);
    if (bitmap.close) bitmap.close();
    return new Promise((resolve, reject) => canvas.toBlob(b => (b ? resolve(b) : reject(new Error('compress'))), 'image/jpeg', quality));
}

async function loadImage(file) {
    if (window.createImageBitmap) {
        try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { /* بديل */ }
    }
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('image')); };
        img.src = url;
    });
}
