/*
 * طابعة حرارية عبر Web Bluetooth (BLE). يتطلب Chrome/Edge على Android عبر HTTPS (أو localhost).
 * لا يعمل على iOS ولا Firefox.
 */
import { chunk } from './escpos.js';

// خدمات الطابعات الحرارية الشائعة
export const PRINTER_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000fee7-0000-1000-8000-00805f9b34fb',
];

const DEVICE_KEY = 'printerDevice:v1';
const sleep = ms => new Promise(r => setTimeout(r, ms));

function storeGet(key) { try { return JSON.parse(localStorage.getItem(key)); } catch (e) { return null; } }
function storeSet(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* ذاكرة فقط */ } }

export class BlePrinter {
    constructor() {
        this.device = null;
        this.characteristic = null;
        this.status = 'disconnected';      // disconnected | connecting | connected | error
        this.lastError = null;
        this.listeners = new Set();
    }

    /**
     * سبب عدم الدعم (null = مدعوم).
     */
    static unsupportedReason() {
        if (typeof window === 'undefined' || !window.isSecureContext) return 'الطباعة المباشرة تحتاج Chrome على Android عبر HTTPS.';
        if (!navigator.bluetooth) return 'الطباعة المباشرة تحتاج Chrome على Android عبر HTTPS (هذا المتصفح لا يدعم Bluetooth).';
        return null;
    }

    onStatus(fn) { this.listeners.add(fn); return () => this.listeners.delete(fn); }

    setStatus(status, error = null) {
        this.status = status;
        this.lastError = error;
        this.listeners.forEach(fn => { try { fn(status, error, this.deviceLabel()); } catch (e) { /* */ } });
    }

    deviceLabel() {
        return this.device?.name || storeGet(DEVICE_KEY)?.name || null;
    }

    /**
     * الاتصال: بالجهاز المصرّح به سابقاً دون نافذة اختيار إن أمكن، وإلا (interactive) نافذة الاختيار.
     */
    async connect({ interactive = true } = {}) {
        const reason = BlePrinter.unsupportedReason();
        if (reason) throw new Error(reason);
        if (this.characteristic && this.device?.gatt?.connected) return;

        this.setStatus('connecting');
        try {
            let device = this.device;
            if (!device && navigator.bluetooth.getDevices) {
                const remembered = storeGet(DEVICE_KEY);
                const devices = await navigator.bluetooth.getDevices();
                device = devices.find(d => remembered && d.id === remembered.id) || null;
            }
            if (!device) {
                if (!interactive) throw new Error('لا توجد طابعة محفوظة. اضغط «اتصال» لاختيار الطابعة.');
                device = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: PRINTER_SERVICES });
            }
            this.device = device;
            storeSet(DEVICE_KEY, { id: device.id, name: device.name || 'طابعة' });
            device.addEventListener('gattserverdisconnected', () => {
                this.characteristic = null;
                if (this.status !== 'error') this.setStatus('disconnected');
            });

            const server = await device.gatt.connect();
            this.characteristic = await this.findWritable(server);
            if (!this.characteristic) throw new Error('لم يُعثر في الطابعة على خاصية تقبل الكتابة.');
            this.setStatus('connected');
        } catch (e) {
            this.characteristic = null;
            this.setStatus('error', friendlyError(e));
            throw new Error(friendlyError(e));
        }
    }

    async findWritable(server) {
        const services = await server.getPrimaryServices();
        let fallback = null;
        for (const service of services) {
            let characteristics = [];
            try { characteristics = await service.getCharacteristics(); } catch (e) { continue; }
            for (const c of characteristics) {
                if (c.properties.writeWithoutResponse) return c;           // المفضّل
                if (c.properties.write && !fallback) fallback = c;
            }
        }
        return fallback;
    }

    disconnect() {
        try { this.device?.gatt?.disconnect(); } catch (e) { /* */ }
        this.characteristic = null;
        this.setStatus('disconnected');
    }

    forget() {
        this.disconnect();
        this.device = null;
        try { localStorage.removeItem(DEVICE_KEY); } catch (e) { /* */ }
    }

    /**
     * الكتابة حزمة بحزمة، مع إعادة اتصال ومحاولة واحدة عند انقطاع GATT أثناء الطباعة.
     */
    async write(bytes, { chunkBytes = 100, delayMs = 20, onProgress } = {}) {
        const packets = chunk(bytes, chunkBytes);
        let start = 0;
        let retried = false;

        for (;;) {
            try {
                if (!this.characteristic || !this.device?.gatt?.connected) await this.connect({ interactive: false });
                for (let i = start; i < packets.length; i++) {
                    const c = this.characteristic;
                    if (c.properties.writeWithoutResponse && c.writeValueWithoutResponse) await c.writeValueWithoutResponse(packets[i]);
                    else if (c.writeValueWithResponse) await c.writeValueWithResponse(packets[i]);
                    else await c.writeValue(packets[i]);
                    start = i + 1;
                    if (onProgress) onProgress(start, packets.length);
                    if (delayMs > 0) await sleep(delayMs);
                }
                return;
            } catch (e) {
                if (retried) {
                    this.setStatus('error', friendlyError(e));
                    throw new Error(friendlyError(e));
                }
                retried = true;
                this.characteristic = null;
                await sleep(500);
            }
        }
    }
}

export function friendlyError(e) {
    const name = e?.name || '';
    const msg = String(e?.message || '');
    if (name === 'NotFoundError') return 'لم يُختر أي جهاز (أُلغيت نافذة الاختيار).';
    if (name === 'SecurityError') return 'الطباعة المباشرة تحتاج Chrome على Android عبر HTTPS.';
    if (name === 'NetworkError' || /GATT|disconnected/i.test(msg)) return 'انقطع الاتصال بالطابعة. تأكد أنها مشغّلة وقريبة ثم أعد المحاولة.';
    if (name === 'NotSupportedError') return 'هذه الطابعة لا تدعم الكتابة المباشرة.';
    if (/[؀-ۿ]/.test(msg)) return msg;   // رسالة عربية من الكود نفسه
    return 'تعذّر الاتصال بالطابعة.';
}

// نسخة واحدة مشتركة للصفحة
export const printer = new BlePrinter();
