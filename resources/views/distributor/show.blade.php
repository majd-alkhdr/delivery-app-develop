@extends('layouts.panel')

@section('title', 'كشف: ' . $sheet->displayTitle())
@section('body_class', 'touch-ui')

@php
    $statusLabels = ['not_visited' => ['لم يُزَر', 'text-bg-secondary'], 'visited' => ['مزار', 'text-bg-primary'], 'paid' => ['مدفوع', 'text-bg-success']];
@endphp

@section('content')
    <x-page-header :title="'كشف: ' . $sheet->displayTitle()" icon="fa-file-invoice">
        <x-status-badge :status="$sheet->status" class="align-self-center" />
        <span class="badge text-bg-warning align-self-center d-none" data-queue-box>
            <i class="fas fa-cloud-arrow-up me-1"></i> معلّق: <span data-queue-count>0</span>
        </span>
        @if ($config['printingEnabled'])
            <span class="badge text-bg-secondary align-self-center" data-printer-status>الطابعة: غير متصل</span>
            <span class="badge text-bg-info align-self-center d-none" data-print-queue-box><i class="fas fa-print me-1"></i> طباعة معلّقة: <span data-print-queue-count>0</span></span>
            <a href="{{ route('print.test') }}" class="btn btn-outline-secondary fw-bold"><i class="fas fa-print"></i> الطابعة</a>
        @endif
        <a href="{{ route('distributor.customers') }}" class="btn btn-outline-primary fw-bold">
            <i class="fas fa-address-book"></i> العملاء
        </a>
        <a href="{{ route('distributor.index') }}" class="btn btn-light border fw-bold">
            <i class="fas fa-arrow-right"></i> رجوع
        </a>
    </x-page-header>

    @include('partials.balances-stale')
    @include('partials.supplement-line', ['sheet' => $sheet, 'route' => null])
    @include('partials.sheet-notices', ['sheet' => $sheet, 'mode' => 'unseen'])

    <div id="distributorToasts" class="position-sticky top-0" style="z-index: 1080;"></div>

    @unless ($sheet->isV2())
        <div class="alert alert-secondary small"><i class="fas fa-lock me-1"></i> كشف بالصيغة القديمة: للعرض فقط، ولا يقبل تسجيل زيارة أو دفعة.</div>
    @endunless
    @if ($sheet->isV2() && ! $canRecord)
        <div class="alert alert-secondary small"><i class="fas fa-lock me-1"></i> هذا الكشف مغلق ولا يقبل التسجيل.</div>
    @endif

    {{-- مؤشرات اليوم (من السجلات غير الملغاة) --}}
    <div class="row g-2 mb-2">
        <div class="col-4"><x-stat-card label="الصيدليات" icon="fa-store" :value="$stats['total']" variant="dark" value-id="stat-total-pharmacies" /></div>
        <div class="col-4"><x-stat-card label="المزارة" icon="fa-check-circle" :value="$stats['visited']" variant="success" value-id="stat-visited-pharmacies" /></div>
        <div class="col-4"><x-stat-card label="غير المزارة" icon="fa-clock" :value="$stats['not_visited']" variant="secondary" value-id="stat-not-visited-pharmacies" /></div>
    </div>
    <div class="row g-2 mb-3">
        <div class="col-4"><x-stat-card label="المحصّل (ل.س)" :value="number_format($stats['syp'], 0)" variant="success" value-id="stat-syp" /></div>
        <div class="col-4"><x-stat-card label="المحصّل (دولار)" :value="number_format($stats['usd'], 2)" variant="primary" value-id="stat-usd" /></div>
        <div class="col-4"><x-stat-card label="نسبة الإنجاز" :value="$stats['progress'] . '%'" variant="dark" value-id="stat-progress" /></div>
    </div>

    <div class="alert alert-info small d-none" id="linesNotice">
        <i class="fas fa-wifi me-1"></i> افتح هذا الكشف مرة واحدة بوجود الإنترنت لتحميل تفاصيل الفواتير.
    </div>

    <div class="row g-2 mb-3">
        <div class="col-md-6 col-12">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                <input type="text" id="customerSearchInput" class="form-control" placeholder="ابحث باسم الصيدلية...">
            </div>
        </div>
        <div class="col-md-3 col-6">
            <select id="areaFilterInput" class="form-select" aria-label="المنطقة">
                <option value="">كل المناطق</option>
                @foreach ($areas as $area)
                    <option value="{{ $area }}">{{ $area }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3 col-6">
            <select id="statusFilterInput" class="form-select" aria-label="الحالة">
                <option value="">كل الحالات</option>
                <option value="not_visited">لم يُزَر</option>
                <option value="visited">مزار</option>
                <option value="paid">مدفوع</option>
            </select>
        </div>
    </div>

    <div id="customersContainer">
        @forelse ($cards as $card)
            @php [$statusText, $statusClass] = $statusLabels[$card['status']]; @endphp
            <div class="card mb-3 customer-card" id="card-{{ $card['key'] }}" data-search="{{ $card['search'] }}"
                data-area="{{ $card['area'] }}" data-status="{{ $card['status'] }}" data-customer-id="{{ $card['customer_id'] }}">
                <div class="card-body p-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                        <h2 class="h6 fw-bold mb-0 text-break-word">
                            <i class="fas fa-store text-primary"></i> {{ $card['name'] }}
                            <small class="text-muted fw-normal fs-7">({{ $card['area'] }})</small>
                            @include('distributor.partials.ref-badge', ['state' => $card['ref_state'], 'customerId' => $card['customer_id']])
                        </h2>
                        <span class="badge {{ $statusClass }} p-2" data-card-status>{{ $statusText }}</span>
                    </div>

                    @if ($card['balance_change'])
                        @php $bc = $card['balance_change']->meta ?? []; @endphp
                        <div class="alert alert-info py-2 small mb-2 d-flex flex-wrap align-items-center justify-content-between gap-2 balance-change-badge" data-balance-change="{{ $card['customer_id'] }}">
                            <span><i class="fas fa-scale-balanced me-1"></i> تغيّر الرصيد من {{ number_format((float) ($bc['old'] ?? 0), 0) }} إلى {{ number_format((float) ($bc['new'] ?? 0), 0) }}@if (! empty($bc['reason'])) ({{ $bc['reason'] }})@endif
                                @if (! empty($bc['as_of']))<span class="text-muted"> — الرصيد حتى {{ \Illuminate\Support\Carbon::parse($bc['as_of'])->format('d-m') }}</span>@endif
                            </span>
                            <form action="{{ route('distributor.balance-seen', [$sheet, $card['customer_id']]) }}" method="POST" class="m-0">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary fw-bold py-0">اطلعت</button>
                            </form>
                        </div>
                    @endif

                    {{-- المرحلة 7H: اطلاع إلزامي قبل أي تسجيل على هذه الصيدلية --}}
                    @if ($canRecord && $card['pending_acks']->isNotEmpty())
                        <div class="alert alert-danger py-2 small mb-2 ack-required" data-ack-box="{{ $card['customer_id'] }}">
                            <div class="fw-bold mb-1"><i class="fas fa-bell me-1"></i> {{ \App\Services\NoticeAckService::message($card['pending_acks']) }}</div>
                            <ul class="mb-2 ps-3" data-ack-details>
                                @foreach ($card['pending_acks'] as $n)
                                    <li>{{ \App\Services\NoticeAckService::detail($n) }}</li>
                                @endforeach
                            </ul>
                            <button type="button" class="btn btn-sm btn-danger fw-bold" data-ack-customer="{{ $card['customer_id'] }}"
                                data-ack-url="{{ route('distributor.notices.ack', [$sheet, $card['customer_id']]) }}"><i class="fas fa-check"></i> اطلعت</button>
                        </div>
                    @endif

                    @if ($card['locked_by_other'])
                        <div class="alert alert-warning py-2 small mb-2"><i class="fas fa-lock me-1"></i> تمت زيارتها من قِبل {{ $card['locked_by_other'] }}</div>
                    @endif

                    <div class="row g-2 p-2 bg-light rounded-3 border text-center small mb-2 mx-0">
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block small">إجمالي الفواتير المستحقة</span>
                            <span class="fw-bold font-monospace" dir="ltr">{{ number_format($card['total'], 0) }}</span>
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block small">الرصيد المستحق
                                @if ($card['balance'])<span class="badge rounded-pill text-bg-light border" title="{{ $card['balance']->publicBreakdown() }}" tabindex="0" role="button" aria-label="{{ $card['balance']->publicBreakdown() }}">؟</span>@endif
                            </span>
                            <span class="fw-bold text-danger font-monospace" dir="ltr">{{ number_format($card['due'], 0) }}</span>
                            @if ($card['balance'])<span class="d-block text-muted fs-8">{{ $card['balance']->asOfLabel() }}</span>@endif
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block small">محصّل اليوم</span>
                            <span class="fw-bold text-success font-monospace" dir="ltr">{{ number_format($card['syp'], 0) }} ل.س</span>
                            @if ($card['usd'] > 0)
                                <span class="d-block fw-bold text-primary font-monospace" dir="ltr">{{ number_format($card['usd'], 2) }} $</span>
                            @endif
                        </div>
                        <div class="col-6 col-md-3">
                            <span class="text-muted d-block small">المتبقي</span>
                            <span class="fw-bold font-monospace" dir="ltr">{{ number_format($card['remaining'], 0) }}</span>
                        </div>
                    </div>

                    @if ($card['visit'])
                        <div class="small text-muted mb-2">
                            @if ($card['visit']->status === 'visited')
                                <i class="fas fa-check text-success"></i> تمت الزيارة {{ $card['visit']->visited_at?->format('g:i A') }} — {{ $card['visit']->user?->name }}
                            @else
                                <i class="fas fa-xmark text-secondary"></i> غير مزارة: {{ $card['visit']->reason }}
                            @endif
                            @if ($card['visit']->location_flag === 'far')
                                <span class="badge bg-warning-subtle text-warning-emphasis">بعيد عن موقع الصيدلية</span>
                            @endif
                        </div>
                    @endif

                    @if ($card['payments']->isNotEmpty())
                        <span class="badge stamp-reminder p-2 fs-7 mb-2"><i class="fas fa-stamp me-1"></i> تذكير: اختم الإيصال</span>
                        <ul class="list-unstyled small mb-2">
                            @foreach ($card['payments'] as $payment)
                                <li class="d-flex flex-wrap align-items-center gap-2 border-bottom py-1">
                                    <span class="font-monospace" dir="ltr">{{ $payment->receipt_number }}</span>
                                    <strong dir="ltr">{{ number_format($payment->amount, $payment->currency === 'USD' ? 2 : 0) }} {{ $payment->currency === 'USD' ? '$' : 'ل.س' }}</strong>
                                    @if ($payment->currency === 'USD')
                                        <span class="text-muted">{{ $payment->exchange_rate ? 'على سعر ' . rtrim(rtrim(number_format($payment->exchange_rate, 4, '.', ''), '0'), '.') : 'بلا سعر' }}</span>
                                    @endif
                                    @if ($payment->source === 'external')
                                        <span class="badge bg-warning-subtle text-warning-emphasis">خارجية</span>
                                    @endif
                                    @if ((int) $payment->entered_by === auth()->id())
                                        <button type="button" class="btn btn-sm btn-link p-0" data-print-receipt="{{ $payment->client_uuid }}"
                                            data-payment-id="{{ $payment->id }}" data-receipt-url="{{ route('payments.receipt-data', $payment) }}"
                                            data-receipt-number="{{ $payment->receipt_number }}" data-customer-id="{{ $payment->customer_id }}" data-customer-name="{{ $card['name'] }}"
                                            data-amount="{{ $payment->amount }}" data-currency="{{ $payment->currency }}" data-rate="{{ $payment->exchange_rate }}"
                                            data-amount-syp="{{ $payment->amount_syp }}" data-date="{{ $payment->collected_at?->format('Y-m-d H:i') }}"
                                            @disabled(! $config['printingEnabled']) @unless ($config['printingEnabled']) title="قريباً" @endunless>
                                            <i class="fas fa-print"></i> طباعة / إعادة طباعة
                                        </button>
                                    @endif
                                    @if ((int) $payment->entered_by === auth()->id() && ! $payment->isExported() && $payment->collected_at?->isToday())
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-auto" data-void-url="{{ route('payments.void', $payment) }}">إلغاء</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- فواتير الزبون (ملخص فقط؛ الأسطر تُجلب لاحقاً وتُحفظ للعمل دون اتصال) --}}
                    <details class="mb-2">
                        <summary class="small fw-bold text-primary">الفواتير ({{ $card['invoices']->count() }})</summary>
                        <ul class="list-group list-group-flush small mt-1">
                            @foreach ($card['invoices'] as $inv)
                                <li class="list-group-item d-flex flex-wrap align-items-center gap-2 px-1" role="button" data-invoice-id="{{ $inv['id'] }}">
                                    <x-series-badge :series="$inv['series']" />
                                    <span class="font-monospace" dir="ltr">{{ $inv['number'] }}</span>
                                    <span class="text-muted font-monospace" dir="ltr">{{ $inv['date'] }}</span>
                                    @if ($inv['added_after_send'])
                                        <span class="badge text-bg-warning added-after-send">أُضيفت بعد الإرسال</span>
                                    @endif
                                    @if ($inv['is_zero_value'])
                                        <span class="badge bg-info-subtle text-info-emphasis"><i class="fas fa-gift me-1"></i>هدية / صفر</span>
                                    @endif
                                    <strong class="ms-auto font-monospace" dir="ltr">{{ number_format($inv['amount'], 2) }}</strong>
                                    <div class="w-100">@include('partials.invoice-meta', ['costCenter' => $inv['cost_center'], 'note' => $inv['note']])</div>
                                </li>
                            @endforeach
                        </ul>
                    </details>

                    <div class="d-flex flex-wrap gap-2">
                        @if ($canRecord && $card['customer_id'])
                            <button type="button" class="btn btn-primary fw-bold" @disabled($card['locked_by_other'] || $card['pending_acks']->isNotEmpty()) data-ack-gated="{{ $card['customer_id'] }}"
                                data-open-visit data-customer-id="{{ $card['customer_id'] }}" data-customer-name="{{ $card['name'] }}" data-ref-state="{{ $card['ref_state'] }}">
                                <i class="fas fa-location-dot"></i> تسجيل زيارة
                            </button>
                            <button type="button" class="btn btn-success fw-bold" @disabled($card['locked_by_other'] || $card['pending_acks']->isNotEmpty()) data-ack-gated="{{ $card['customer_id'] }}"
                                data-open-payment data-customer-id="{{ $card['customer_id'] }}" data-customer-name="{{ $card['name'] }}" data-due="{{ $card['remaining'] }}" data-ref-state="{{ $card['ref_state'] }}">
                                <i class="fas fa-money-bill-wave"></i> تسجيل دفعة
                            </button>
                        @endif
                        @if ($card['customer_id'])
                            <button type="button" class="btn btn-outline-secondary fw-bold" data-print-customer="{{ $card['customer_id'] }}"
                                @disabled(! $config['printingEnabled'] || $card['invoices']->isEmpty())
                                @unless ($config['printingEnabled']) title="قريباً" data-bs-toggle="tooltip" @endunless>
                                <i class="fas fa-print"></i> طباعة فاتورة مجمّعة للصيدلية
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="card">
                <x-empty-state icon="fa-file-circle-xmark" title="لا توجد فواتير لك في هذا الكشف" />
            </div>
        @endforelse
    </div>
@endsection

@push('modals')
    @include('distributor.partials.payment-modal', ['canEnterUsd' => $config['canEnterUsd']])
    @include('distributor.partials.pin-modal')

    {{-- نافذة تسجيل الزيارة --}}
    <div class="modal fade" id="visitModal" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content border-0">
                <div class="modal-header text-bg-primary">
                    <h3 class="modal-title fw-bold fs-6"><i class="fas fa-location-dot me-1"></i> تسجيل زيارة</h3>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <form id="visitForm" novalidate>
                    <input type="hidden" name="customer_id">
                    <div class="modal-body p-4 bg-light">
                        <div class="form-control bg-white fw-bold mb-3" id="visit_customer_name"></div>
                        <div class="btn-group w-100 mb-3" role="group" aria-label="الحالة">
                            <input type="radio" class="btn-check" name="status" id="visit_yes" value="visited">
                            <label class="btn btn-outline-success fw-bold" for="visit_yes">مزار</label>
                            <input type="radio" class="btn-check" name="status" id="visit_no" value="not_visited">
                            <label class="btn btn-outline-secondary fw-bold" for="visit_no">غير مزار</label>
                        </div>
                        <div class="mb-3 d-none" id="visit_reason_box">
                            <label for="visit_reason" class="form-label small fw-bold text-secondary">السبب</label>
                            <select name="reason" id="visit_reason" class="form-select">
                                <option value="">— اختر —</option>
                                @foreach (\App\Models\SheetVisit::REASONS as $reason)
                                    <option value="{{ $reason }}">{{ $reason }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2 d-none" id="visit_other_box">
                            <label for="visit_other" class="form-label small fw-bold text-secondary">اكتب السبب</label>
                            <input type="text" name="reason_other" id="visit_other" class="form-control" maxlength="200">
                        </div>
                    </div>
                    <div class="modal-footer bg-white d-flex gap-2">
                        <button type="button" class="btn btn-light border fw-bold flex-grow-1" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-primary fw-bold flex-grow-1"><i class="fas fa-save"></i> حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- نافذة تفاصيل الفاتورة (مشتركة مع كشوف المندوب) --}}
    @include('partials.invoice-modal', ['printable' => true, 'printingEnabled' => $config['printingEnabled']])
@endpush

@push('scripts')
    <script>
        window.DistributorConfig = @json($config);
    </script>
    <script src="{{ asset('js/distributor-app.js') }}?v={{ @filemtime(public_path('js/distributor-app.js')) }}"></script>
    <script type="module" src="{{ asset('js/invoice-view.js') }}?v={{ @filemtime(public_path('js/invoice-view.js')) }}"></script>
    @if ($config['printingEnabled'])
        @include('print.partials.bootstrap', ['deliveryOrderNo' => $sheet->delivery_order_no])
    @endif
    <script>
        (function () {
            const search = document.getElementById('customerSearchInput');
            const area = document.getElementById('areaFilterInput');
            const status = document.getElementById('statusFilterInput');

            function filterCustomers() {
                const q = window.DistributorApp.normalizeArabic(search.value);
                document.querySelectorAll('.customer-card').forEach(card => {
                    const ok = (!q || card.dataset.search.includes(q))
                        && (!area.value || card.dataset.area === area.value)
                        && (!status.value || card.dataset.status === status.value);
                    card.style.setProperty('display', ok ? '' : 'none', 'important');
                });
            }
            [search, area, status].forEach(el => el.addEventListener(el === search ? 'input' : 'change', filterCustomers));

            // تحديث البطاقة فور الحفظ المحلي، ثم إعادة التحميل بعد نجاح المزامنة لعرض الأرقام من الخادم
            document.addEventListener('distributor:queued', e => {
                const card = document.querySelector(`.customer-card[data-customer-id="${e.detail.body.customer_id}"]`);
                if (!card) return;
                const badge = card.querySelector('[data-card-status]');
                if (e.detail.kind === 'payment' || e.detail.body.status === 'visited') {
                    card.dataset.status = e.detail.kind === 'payment' ? 'paid' : 'visited';
                    badge.className = 'badge text-bg-warning p-2';
                    badge.textContent = 'محفوظ في الجهاز — بانتظار الشبكة';
                }
            });
            let reloadTimer = null;
            document.addEventListener('distributor:synced', () => {
                clearTimeout(reloadTimer);
                reloadTimer = setTimeout(() => { if (window.DistributorApp.Queue.all().length === 0 && !window.DistributorApp.pinOpen) location.reload(); }, 1200);
            });
        })();
    </script>
@endpush
