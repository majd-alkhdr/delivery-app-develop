@extends('layouts.panel')

@section('title', 'ذممي')
@section('body_class', 'touch-ui')

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')
    @include('partials.balances-stale')
    <x-page-header title="ذممي" :subtitle="auth()->user()->cost_center_label ?: 'مندوب'" icon="fa-hand-holding-dollar">
        @include('rep.partials.header-tools')
        <a href="{{ route('rep.sheets.index') }}" class="btn btn-light border fw-bold"><i class="fas fa-clipboard-list"></i> كشوفي</a>
    </x-page-header>

    <div id="distributorToasts" class="position-sticky top-0" style="z-index: 1080;"></div>

    @include('rep.partials.adjustment-alerts')

    @unless ($config['canRecord'])
        <div class="alert alert-danger fw-bold" id="rep-no-cost-center"><i class="fas fa-circle-exclamation me-1"></i> لم يُضبط مركز الكلفة لحسابك، راجع الإدارة.</div>
    @endunless

    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3"><div class="card card-body py-2"><div class="small text-muted">الدَين المتبقي</div><div class="fw-bold fs-5 font-monospace" dir="ltr" id="rep-total">{{ $money($totals['remaining']) }}</div></div></div>
        <div class="col-6 col-md-3"><div class="card card-body py-2"><div class="small text-muted">محصّل اليوم (ليرة)</div><div class="fw-bold fs-5 font-monospace" dir="ltr">{{ $money($totals['today_syp']) }}</div></div></div>
        <div class="col-6 col-md-3"><div class="card card-body py-2"><div class="small text-muted">محصّل اليوم (دولار)</div><div class="fw-bold fs-5 font-monospace" dir="ltr">{{ $money($totals['today_usd']) }}</div></div></div>
        <div class="col-6 col-md-3"><div class="card card-body py-2"><div class="small text-muted">الصيدليات</div><div class="fw-bold fs-5">{{ $totals['pharmacies'] }}</div></div></div>
    </div>

    @if ($pharmacies->isNotEmpty())
        <div class="row g-2 mb-3">
            <div class="col-md-6"><input type="search" id="rep_search" class="form-control" placeholder="ابحث باسم الصيدلية..."></div>
            <div class="col-7 col-md-3">
                <select id="rep_area" class="form-select" aria-label="المنطقة">
                    <option value="">كل المناطق</option>
                    @foreach ($areas as $area)<option value="{{ $area }}">{{ $area }}</option>@endforeach
                </select>
            </div>
            <div class="col-5 col-md-3">
                <select id="rep_sort" class="form-select" aria-label="الترتيب">
                    <option value="debt">الأكبر دَيناً</option>
                    <option value="oldest">الأقدم</option>
                </select>
            </div>
        </div>
    @endif

    <div id="repList">
        @forelse ($pharmacies as $p)
            <div class="card mb-2" data-rep-card data-search="{{ \App\Support\ArabicNormalizer::normalize($p['name']) }}" data-area="{{ $p['area'] }}"
                data-debt="{{ $p['remaining_cents'] / 100 }}" data-age="{{ $p['oldest_days'] ?? 0 }}">
                <div class="card-body p-3">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="min-w-0 flex-grow-1">
                            <div class="fw-bold text-break-word">{{ $p['name'] }}</div>
                            <div class="small text-muted">{{ $p['area'] }}
                                — أقدم دَين: {{ $p['oldest_days'] ?? 0 }} يوم
                                @if ($p['last_collected_at']) — آخر تحصيل: <span dir="ltr">{{ $p['last_collected_at']->copy()->setTimezone('Asia/Damascus')->format('Y-m-d') }}</span>@endif
                            </div>
                        </div>
                        <div class="text-center">
                            <span class="d-block small text-muted">الدَين المتبقي</span>
                            <span class="fw-bold text-danger font-monospace" dir="ltr">{{ $money($p['remaining_cents'] / 100) }}</span>
                        </div>
                        @if ($statementUrl = \App\Services\Reps\PharmacyStatementService::linkFor(auth()->user(), $p['customer_id']))
                            <a href="{{ $statementUrl }}" class="btn btn-outline-primary fw-bold" data-statement-link><i class="fas fa-file-invoice-dollar"></i> كشف الصيدلية الكامل</a>
                        @endif
                        @if ($config['canRecord'])
                            <button type="button" class="btn btn-success fw-bold" data-open-rep-payment
                                data-customer-id="{{ $p['customer_id'] }}" data-customer-name="{{ $p['name'] }}" data-debt="{{ $p['remaining_cents'] / 100 }}"
                                data-ref-state="{{ $p['customer']?->referenceState() }}">
                                <i class="fas fa-plus-circle"></i> تسجيل تحصيل
                            </button>
                        @endif
                    </div>
                    {{-- المرحلة 7C: زيادة تحصيل بعد تخفيض فاتورة (لا تُصفَّر بصمت) --}}
                    @foreach ($p['over_collected'] ?? [] as $line)
                        <div class="alert alert-danger py-1 px-2 small mt-2 mb-0 over-collected">{{ $line['text'] }}</div>
                    @endforeach
                    <details class="mt-2">
                        <summary class="small fw-bold">الفواتير غير المسددة ({{ $p['invoices']->where('remaining_cents', '>', 0)->count() }})</summary>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm small mb-0 align-middle">
                                <thead class="table-light"><tr><th>الفاتورة</th><th>التاريخ</th><th>الإجمالي</th><th>المتبقي</th></tr></thead>
                                <tbody>
                                    @foreach ($p['invoices']->where('remaining_cents', '>', 0) as $i)
                                        <tr class="rep-invoice-row">
                                            <td><x-series-badge :series="$i['series']" /> <span class="font-monospace" dir="ltr">{{ $i['number'] }}</span></td>
                                            <td class="font-monospace" dir="ltr">{{ $i['date'] }}</td>
                                            <td class="font-monospace" dir="ltr">{{ $money($i['total_cents'] / 100) }}</td>
                                            <td class="font-monospace fw-bold" dir="ltr">{{ $money($i['remaining_cents'] / 100) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>
        @empty
            <div class="card"><x-empty-state icon="fa-hand-holding-dollar" title="لا ذمم متبقية لمركزك" message="يمكنك التحصيل لأي زبون من «تحصيل لأي زبون»." /></div>
        @endforelse
    </div>

    {{-- المرحلة 7C --}}
    @if ($adjustments->isNotEmpty())
        <div class="card mt-3" id="my-adjustments">
            <div class="card-header bg-white fw-bold small">تعديلات لاحقة على ذمتي</div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0 align-middle">
                    <thead class="table-light"><tr><th>الفاتورة</th><th>الصيدلية</th><th>القيمة</th><th>ذمتي قبل ← بعد</th><th>الفائض</th><th>السبب</th><th>التاريخ</th></tr></thead>
                    <tbody>
                        @foreach ($adjustments as $a)
                            <tr class="my-adjustment {{ $a->isApplied() ? '' : 'text-muted' }}">
                                <td class="font-monospace" dir="ltr">{{ \App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $a->invoice?->invoice_number) }}</td>
                                <td>{{ $a->customer?->name }}</td>
                                <td class="font-monospace">{{ $money($a->old_amount) }} ← {{ $money($a->new_amount) }}@if ((float) $a->new_amount === 0.0) <span class="badge text-bg-dark">ألغيت في المحاسبة</span>@endif</td>
                                <td class="font-monospace">{{ $a->rep_debt_before !== null ? $money($a->rep_debt_before) : '—' }} ← {{ $a->rep_debt_after !== null ? $money($a->rep_debt_after) : '—' }}</td>
                                <td class="font-monospace">{{ (float) $a->surplus > 0 ? $money($a->surplus) : '—' }}</td>
                                <td>{{ $a->reasonLabel() }}@unless ($a->isApplied()) <span class="badge text-bg-secondary">تُراجع عنه</span>@endunless</td>
                                <td dir="ltr">{{ $a->applied_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection

@push('modals')
    @include('rep.partials.payment-modal')
    @include('distributor.partials.pin-modal')
@endpush

@push('scripts')
    @include('rep.partials.scripts')
@endpush
