@extends('layouts.panel')

@section('title', 'اختبار الطابعة')
@section('body_class', 'touch-ui')

@section('content')
    <x-page-header title="اختبار الطابعة" subtitle="اتصال الطابعة ومعايرتها بلا أي بيانات حقيقية. التعديلات هنا تخص هذا الجهاز فقط." icon="fa-print" />

    <div class="alert alert-warning small d-none" id="bleUnsupported"></div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header bg-white fw-bold py-3"><i class="fas fa-bluetooth-b me-1"></i> الاتصال</div>
                <div class="card-body">
                    <div class="mb-3"><span class="badge text-bg-secondary" data-printer-status>الطابعة: غير متصل</span></div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-primary fw-bold" id="btnConnect"><i class="fas fa-link"></i> اتصال</button>
                        <button type="button" class="btn btn-outline-secondary fw-bold" id="btnDisconnect"><i class="fas fa-link-slash"></i> قطع الاتصال</button>
                        <button type="button" class="btn btn-outline-danger fw-bold" id="btnForget"><i class="fas fa-trash"></i> نسيان الطابعة</button>
                    </div>
                    <div class="mt-3">
                        <label for="printMode" class="form-label small fw-bold">طريقة الطباعة المفضّلة لهذا الجهاز</label>
                        <select id="printMode" class="form-select">
                            <option value="ble">طابعة Bluetooth (الافتراضي)</option>
                            <option value="browser">طباعة المتصفح</option>
                            <option value="image">حفظ/مشاركة صورة PNG</option>
                            <option value="rawbt" disabled>RawBT (غير متاح حالياً)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header bg-white fw-bold py-3"><i class="fas fa-vial me-1"></i> اختبارات</div>
                <div class="card-body">
                    @foreach ([
                        'text' => ['نص عربي وأرقام مختلطة', 'fa-font'],
                        'calibration' => ['نمط المعايرة (الحافتان والمسطرة)', 'fa-ruler-horizontal'],
                        'receipt' => ['إيصال تجريبي كامل', 'fa-receipt'],
                        'long' => ['فاتورة تجريبية طويلة (60 سطراً)', 'fa-file-invoice'],
                    ] as $test => [$label, $icon])
                        <div class="d-flex flex-wrap align-items-center gap-2 border-bottom py-2">
                            <span class="fw-bold flex-grow-1"><i class="fas {{ $icon }} text-primary me-1"></i> {{ $label }}</span>
                            <button type="button" class="btn btn-sm btn-primary fw-bold" data-test="{{ $test }}" data-test-mode="print">طباعة</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" data-test="{{ $test }}" data-test-mode="preview">معاينة بلا طابعة</button>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card mb-3">
                <div class="card-header bg-white fw-bold py-3"><i class="fas fa-sliders me-1"></i> ضبط هذا الجهاز (تجاوز محلي)</div>
                <div class="card-body">
                    <form id="overridesForm" class="row g-2">
                        @foreach (['print_chunk_bytes' => 'حجم الحزمة (بايت)', 'print_chunk_delay_ms' => 'التأخير بين الحزم (ms)', 'print_slice_rows' => 'ارتفاع الشريحة (صفوف)', 'print_threshold' => 'عتبة الأبيض/الأسود', 'print_paper_dots' => 'عرض الصورة (نقاط)'] as $key => $label)
                            <div class="col-6">
                                <label for="ov_{{ $key }}" class="form-label small fw-bold">{{ $label }}</label>
                                <input type="number" class="form-control" dir="ltr" id="ov_{{ $key }}" name="{{ $key }}"
                                    min="{{ $ranges[$key]['min'] }}" max="{{ $ranges[$key]['max'] }}" value="{{ $config[$key] }}">
                                <div class="form-text">{{ $ranges[$key]['min'] }}–{{ $ranges[$key]['max'] }} (الخادم: {{ $config[$key] }})</div>
                            </div>
                        @endforeach
                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-success fw-bold"><i class="fas fa-save"></i> حفظ لهذا الجهاز</button>
                            <button type="button" class="btn btn-outline-secondary fw-bold" id="btnRestore"><i class="fas fa-rotate-left"></i> استعادة قيم الخادم</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-white fw-bold py-3"><i class="fas fa-list me-1"></i> سجل الأحداث والأخطاء</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush small" id="printLog" style="max-height: 320px; overflow-y: auto;">
                        <li class="list-group-item text-muted">لا أحداث بعد.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @include('print.partials.bootstrap', ['bind' => 'test'])
    <script type="module">
        import { buildTestText, buildCalibration, buildReceipt, buildInvoice, sampleReceiptData, sampleLongInvoice } from '{{ asset('js/print/document-model.js') }}';

        document.addEventListener('print:ready', () => {
            const PS = window.PrintService;
            const logEl = document.getElementById('printLog');
            const log = (message, type = 'info') => {
                if (logEl.querySelector('.text-muted')) logEl.innerHTML = '';
                const li = document.createElement('li');
                li.className = `list-group-item list-group-item-${type}`;
                li.textContent = `${new Date().toLocaleTimeString('en-GB')} — ${message}`;
                logEl.prepend(li);
            };

            const unsupported = PS.BlePrinter.unsupportedReason();
            if (unsupported) {
                const box = document.getElementById('bleUnsupported');
                box.textContent = unsupported + ' استخدم المعاينة أو الصورة أو طباعة المتصفح.';
                box.classList.remove('d-none');
            }

            PS.bindStatus();
            PS.printer.onStatus((status, error, label) => log(`الحالة: ${status}${label ? ' — ' + label : ''}${error ? ' — ' + error : ''}`, error ? 'danger' : 'info'));

            const modeSelect = document.getElementById('printMode');
            modeSelect.value = PS.preferredMode();
            modeSelect.addEventListener('change', () => { PS.setPreferredMode(modeSelect.value); log(`طريقة الطباعة: ${modeSelect.value}`); });

            document.getElementById('btnConnect').addEventListener('click', () => PS.printer.connect({ interactive: true }).catch(e => log(e.message, 'danger')));
            document.getElementById('btnDisconnect').addEventListener('click', () => PS.printer.disconnect());
            document.getElementById('btnForget').addEventListener('click', () => { PS.printer.forget(); log('نُسيت الطابعة المحفوظة.'); });

            const docs = () => {
                const cfg = PS.effectiveConfig();
                const opts = { headerTitle: cfg.print_header_title, footerText: cfg.print_footer_text, markDuplicates: false, duplicateNumber: 1 };
                return {
                    text: buildTestText(opts),
                    calibration: buildCalibration(),
                    receipt: buildReceipt(sampleReceiptData(), opts),
                    long: buildInvoice(sampleLongInvoice(60), Object.assign({ pharmacy: 'صيدلية تجريبية', area: 'منطقة تجريبية', delivery_order_no: '0000' }, opts)),
                };
            };

            document.querySelectorAll('[data-test]').forEach(btn => btn.addEventListener('click', async () => {
                const doc = docs()[btn.dataset.test];
                const started = performance.now();
                const result = btn.dataset.testMode === 'preview' ? await PS.print(doc, {}, 'preview') : await PS.print(doc, {});
                const ms = Math.round(performance.now() - started);
                log(result.ok ? `نجح «${btn.dataset.test}» (${result.mode}) خلال ${ms} ms` : `فشل «${btn.dataset.test}»: ${result.error}`, result.ok ? 'success' : 'danger');
            }));

            // التجاوزات المحلية لهذا الجهاز (printOverrides:v1)
            const form = document.getElementById('overridesForm');
            const fill = () => {
                const cfg = PS.effectiveConfig();
                form.querySelectorAll('input').forEach(input => { input.value = cfg[input.name]; });
            };
            fill();
            form.addEventListener('submit', e => {
                e.preventDefault();
                const values = {};
                for (const input of form.querySelectorAll('input')) {
                    const v = Number(input.value);
                    if (!Number.isFinite(v) || v < Number(input.min) || v > Number(input.max)) { log(`قيمة خارج المجال: ${input.name}`, 'danger'); return; }
                    values[input.name] = Math.round(v);
                }
                PS.setOverrides(values);
                log('حُفظت قيم هذا الجهاز.', 'success');
            });
            document.getElementById('btnRestore').addEventListener('click', () => { PS.clearOverrides(); fill(); log('استُعيدت قيم الخادم.'); });
        });
    </script>
@endpush
