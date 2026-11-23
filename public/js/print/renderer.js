/*
 * رسم المستند على canvas بعرض print_paper_dots (المتصفح فقط).
 * - الخط المحلي Tajawal (من app-panel.css، بلا CDN)، وننتظر document.fonts.load قبل الرسم.
 * - RTL، والأرقام اللاتينية LTR، والتفاف النص داخل العرض.
 * - المستندات الطويلة تُرسم على شرائح لا يزيد ارتفاع الواحدة عن 1600 بكسل.
 */
import { toMonochrome, concatMonochrome } from './escpos.js';

export const MAX_SLICE_HEIGHT = 1600;
const FONT = 'Tajawal';

export async function ensureFont() {
    try {
        if (document.fonts && document.fonts.load) {
            await Promise.all([
                document.fonts.load(`400 24px ${FONT}`, 'عربي 0123'),
                document.fonts.load(`700 24px ${FONT}`, 'عربي 0123'),
            ]);
        }
    } catch (e) { /* نكمل بالخط الاحتياطي */ }
}

function metrics(width) {
    const s = width / 576;
    return {
        margin: Math.round(8 * s),
        sizes: { sm: Math.round(20 * s), md: Math.round(24 * s), lg: Math.round(28 * s), xl: Math.round(34 * s) },
        gap: Math.round(6 * s),
    };
}

function font(size, bold) {
    return `${bold ? 700 : 400} ${size}px ${FONT}, Tahoma, Arial, sans-serif`;
}

function wrap(ctx, text, maxWidth) {
    const words = String(text ?? '').split(/\s+/).filter(Boolean);
    const lines = [];
    let current = '';
    for (const word of words) {
        const candidate = current ? `${current} ${word}` : word;
        if (ctx.measureText(candidate).width <= maxWidth || !current) {
            current = candidate;
        } else {
            lines.push(current);
            current = word;
        }
    }
    if (current) lines.push(current);
    return lines.length ? lines : [''];
}

/**
 * يحوّل الكتل إلى أوامر رسم بإحداثيات y مطلقة، ويعيد الارتفاع الكلي.
 */
function layout(doc, width) {
    const m = metrics(width);
    const measure = document.createElement('canvas').getContext('2d');
    const inner = width - 2 * m.margin;
    const ops = [];
    let y = m.margin;

    const line = (size) => Math.round(size * 1.45);

    for (const block of doc.blocks) {
        const size = m.sizes[block.size || (block.type === 'total' ? 'lg' : 'md')] || m.sizes.md;
        switch (block.type) {
            case 'title': {
                measure.font = font(size, true);
                const lines = wrap(measure, block.text, inner - (block.inverse ? 2 * m.margin : 0));
                const h = lines.length * line(size) + (block.inverse ? m.gap * 2 : 0);
                if (block.inverse) ops.push({ op: 'rect', x: 0, y, w: width, h });
                lines.forEach((t, i) => ops.push({ op: 'text', text: t, x: width / 2, y: y + (block.inverse ? m.gap : 0) + (i + 1) * line(size) - Math.round(size * 0.35), size, bold: true, align: 'center', color: block.inverse ? '#fff' : '#000' }));
                y += h + m.gap;
                break;
            }
            case 'text': {
                measure.font = font(size, !!block.bold);
                const lines = wrap(measure, block.text, inner);
                const align = block.align || 'right';
                const x = align === 'center' ? width / 2 : align === 'left' ? m.margin : width - m.margin;
                lines.forEach((t, i) => ops.push({ op: 'text', text: t, x, y: y + (i + 1) * line(size) - Math.round(size * 0.35), size, bold: !!block.bold, align, ltr: !!block.ltr }));
                y += lines.length * line(size) + Math.round(m.gap / 2);
                break;
            }
            case 'kv':
            case 'total': {
                const bold = block.type === 'total' || !!block.bold;
                measure.font = font(size, bold);
                const keyW = measure.measureText(block.key).width;
                const valW = measure.measureText(String(block.value)).width;
                if (keyW + valW + m.margin * 2 <= inner) {
                    ops.push({ op: 'text', text: block.key, x: width - m.margin, y: y + line(size) - Math.round(size * 0.35), size, bold, align: 'right' });
                    ops.push({ op: 'text', text: String(block.value), x: m.margin, y: y + line(size) - Math.round(size * 0.35), size, bold, align: 'left', ltr: !!block.ltr });
                    y += line(size);
                } else {
                    // القيمة الطويلة تنزل تحت المفتاح وتلتف
                    ops.push({ op: 'text', text: block.key, x: width - m.margin, y: y + line(size) - Math.round(size * 0.35), size, bold, align: 'right' });
                    y += line(size);
                    const lines = wrap(measure, String(block.value), inner);
                    lines.forEach((t, i) => ops.push({ op: 'text', text: t, x: block.ltr ? m.margin : width - m.margin, y: y + (i + 1) * line(size) - Math.round(size * 0.35), size, bold, align: block.ltr ? 'left' : 'right', ltr: !!block.ltr }));
                    y += lines.length * line(size);
                }
                y += Math.round(m.gap / 2);
                break;
            }
            case 'table-row': {
                const cells = block.cells || [];
                cells.forEach(cell => {
                    const x = cell.align === 'left' ? m.margin : cell.align === 'center' ? width / 2 : width - m.margin;
                    ops.push({ op: 'text', text: cell.text, x, y: y + line(size) - Math.round(size * 0.35), size, bold: false, align: cell.align || 'right', ltr: !!cell.ltr });
                });
                y += line(size) + Math.round(m.gap / 2);
                break;
            }
            case 'divider':
                y += m.gap;
                ops.push({ op: 'dash', x1: m.margin, x2: width - m.margin, y });
                y += m.gap * 2;
                break;
            case 'feed':
                y += (block.lines || 1) * line(m.sizes.md);
                break;
            case 'calibration': {
                // خطوط على الحافتين، مسطرة بالمليمتر التقريبي (8 نقاط ≈ 1 مم عند 203dpi)، ومربع
                const h = Math.round(width * 0.6);
                ops.push({ op: 'calibration', y, h, width });
                y += h + m.gap;
                break;
            }
            default:
                break;
        }
    }
    return { ops, height: Math.ceil(y + m.margin) };
}

function drawOps(ctx, ops, offsetY, width) {
    for (const o of ops) {
        if (o.op === 'rect') {
            ctx.fillStyle = '#000';
            ctx.fillRect(o.x, o.y - offsetY, o.w, o.h);
        } else if (o.op === 'text') {
            ctx.fillStyle = o.color || '#000';
            ctx.font = font(o.size, o.bold);
            ctx.textAlign = o.align === 'center' ? 'center' : o.align === 'left' ? 'left' : 'right';
            ctx.direction = o.ltr ? 'ltr' : 'rtl';
            // في RTL تنقلب left/right في textAlign؛ نثبتها بالمحاذاة الفعلية
            if (!o.ltr && o.align !== 'center') ctx.textAlign = o.align === 'left' ? 'end' : 'start';
            ctx.fillText(o.text, o.x, o.y - offsetY);
        } else if (o.op === 'dash') {
            ctx.strokeStyle = '#000';
            ctx.lineWidth = 2;
            ctx.setLineDash([8, 6]);
            ctx.beginPath();
            ctx.moveTo(o.x1, o.y - offsetY);
            ctx.lineTo(o.x2, o.y - offsetY);
            ctx.stroke();
            ctx.setLineDash([]);
        } else if (o.op === 'calibration') {
            const y = o.y - offsetY;
            ctx.fillStyle = '#000';
            ctx.fillRect(0, y, 4, o.h);                    // الحافة اليسرى
            ctx.fillRect(width - 4, y, 4, o.h);            // الحافة اليمنى
            const rulerY = y + 20;
            ctx.fillRect(0, rulerY, width, 2);
            for (let x = 0, mm = 0; x < width; x += 8, mm++) {
                const tall = mm % 10 === 0 ? 24 : mm % 5 === 0 ? 14 : 7;
                ctx.fillRect(x, rulerY, 2, tall);
                if (mm % 10 === 0 && mm > 0) {
                    ctx.font = font(16, true);
                    ctx.direction = 'ltr';
                    ctx.textAlign = 'center';
                    ctx.fillText(`${mm}`, x, rulerY + 44);
                }
            }
            const box = Math.round(width * 0.3);
            ctx.lineWidth = 3;
            ctx.strokeRect((width - box) / 2, y + 70, box, box);
            ctx.font = font(18, false);
            ctx.textAlign = 'center';
            ctx.fillText(`${width} نقطة`, width / 2, y + 70 + box / 2);
        }
    }
}

/**
 * يرسم المستند ويعيد شرائح canvas (لا يتجاوز ارتفاع أي منها MAX_SLICE_HEIGHT).
 */
export async function renderDocument(doc, { width = 576 } = {}) {
    await ensureFont();
    const { ops, height } = layout(doc, width);
    const slices = [];
    for (let offset = 0; offset < height; offset += MAX_SLICE_HEIGHT) {
        const h = Math.min(MAX_SLICE_HEIGHT, height - offset);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = h;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, width, h);
        ctx.textBaseline = 'alphabetic';
        drawOps(ctx, ops, offset, width);
        slices.push(canvas);
    }
    return slices;
}

/**
 * صورة أحادية واحدة من كل الشرائح (للتحويل إلى ESC/POS).
 */
export function slicesToMonochrome(slices, threshold) {
    return concatMonochrome(slices.map(c => toMonochrome(c.getContext('2d').getImageData(0, 0, c.width, c.height), threshold)));
}

/**
 * دمج الشرائح في Blob PNG واحد (للحفظ/المشاركة). يرجع قائمة إن تجاوز الطول حدود canvas.
 */
export async function slicesToPngBlobs(slices) {
    const total = slices.reduce((s, c) => s + c.height, 0);
    const toBlob = c => new Promise(resolve => c.toBlob(resolve, 'image/png'));
    if (total <= 16000) {
        const canvas = document.createElement('canvas');
        canvas.width = slices[0].width;
        canvas.height = total;
        const ctx = canvas.getContext('2d');
        let y = 0;
        for (const s of slices) { ctx.drawImage(s, 0, y); y += s.height; }
        return [await toBlob(canvas)];
    }
    return Promise.all(slices.map(toBlob));
}
