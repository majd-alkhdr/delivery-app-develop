@extends('layouts.panel')

@section('title', 'كشف الصيدلية')
@section('body_class', 'touch-ui' . ($pharmacyMode ? ' ps-pharmacy-mode' : ''))

@php
    $money = fn ($cents) => number_format(((int) $cents) / 100, 2);
    $customer = $s['customer'];
    $internal = ! $pharmacyMode;
    $url = fn (array $extra) => route($baseRoute, array_merge(['customer' => $customer->id], request()->except(['page', 'pp', 'mode']), $extra));
    $shareWord = $isRep ? 'حصتي' : 'الحصة';
@endphp

@push('styles')
    <style>
        #pharmacy-statement .ps-num { font-family: var(--bs-font-monospace); direction: ltr; unicode-bidi: embed; white-space: nowrap; }
        #pharmacy-statement .ps-card-value { font-size: 1.15rem; font-weight: 700; }
        @media (max-width: 767.98px) {
            #pharmacy-statement .ps-stack thead { display: none; }
            #pharmacy-statement .ps-stack, #pharmacy-statement .ps-stack tbody { display: block; width: 100%; }
            #pharmacy-statement .ps-stack tr { display: block; border: 1px solid var(--bs-border-color); border-radius: .5rem; margin: 0 0 .5rem; padding: .35rem .65rem; background: var(--bs-body-bg); }
            #pharmacy-statement .ps-stack td { display: flex; justify-content: space-between; align-items: center; gap: .75rem; border: 0; padding: .2rem 0; min-width: 0; overflow-wrap: anywhere; }
            #pharmacy-statement .ps-stack td::before { content: attr(data-label); font-weight: 600; color: var(--bs-secondary-color); flex-shrink: 0; }
            #pharmacy-statement .ps-stack td:not([data-label])::before { display: none; }
        }
        body.ps-pharmacy-mode .app-navbar { display: none !important; }
        @media print {
            .app-navbar, .app-topbar, .no-print, .pagination, #distributorToasts { display: none !important; }
            body { background: #fff !important; }
            #pharmacy-statement .card { border: 1px solid #ccc !important; box-shadow: none !important; break-inside: avoid; }
            #pharmacy-statement .ps-stack thead { display: table-header-group !important; }
        }
    </style>
@endpush

@section('content')
<div id="pharmacy-statement" data-mode="{{ $pharmacyMode ? 'pharmacy' : 'internal' }}">
    <x-page-header :title="$customer->name" :subtitle="trim(($customer->area ?: 'بلا منطقة') . ($customer->block ? ' — الكتلة: ' . $customer->block : ''))" icon="fa-file-invoice-dollar">
        <div class="d-flex flex-wrap gap-2 no-print">
            @if ($pharmacyMode)
                <a href="{{ $url(['mode' => 'internal']) }}" class="btn btn-light border fw-bold" data-mode-toggle="internal"><i class="fas fa-eye"></i> العرض الداخلي</a>
                <button type="button" class="btn btn-primary fw-bold" onclick="window.print()"><i class="fas fa-print"></i> طباعة</button>
            @else
                <a href="{{ $url(['mode' => 'pharmacy']) }}" class="btn btn-outline-primary fw-bold" data-mode-toggle="pharmacy"><i class="fas fa-store"></i> عرض للصيدلية</a>
                @if ($isRep)
                    <a href="{{ route('rep.pharmacies.index') }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> صيدلياتي</a>
                @endif
            @endif
        </div>
    </x-page-header>

    @if ($pharmacyMode)
        <p class="small text-muted mb-2">كشف حساب الصيدلية — {{ now('Asia/Damascus')->format('Y-m-d') }}</p>
    @elseif (! $s['full'])
        <div class="alert alert-secondary small py-2" data-statement-restricted>يعرض هذا الكشف {{ $isRep ? 'فواتيرك ودفعاتك' : 'فواتير مندوبيك ودفعاتهم' }} فقط (الكشف الكامل موقوف من الإدارة).</div>
    @endif

    {{-- 1) الملخص --}}
    <div class="row g-2 mb-3" id="ps-summary">
        <div class="col-12 col-md-4">
            <div class="card card-body py-2 h-100" data-card="balance">
                <div class="small text-muted">رصيد الصيدلية الكامل</div>
                <div class="ps-card-value ps-num {{ $s['balance_cents'] < 0 ? 'text-success' : '' }}">{{ $money($s['balance_cents']) }}</div>
                <div class="small text-muted">{{ $s['balance']->asOfLabel() }}</div>
                @if ($s['balance_cents'] < 0)
                    <div class="small text-success">رصيد سالب: للصيدلية مبلغ زائد (دفعات أكثر من المستحق أو سلفة).</div>
                @elseif ($s['balance_cents'] === 0)
                    <div class="small text-muted">لا رصيد مستحق على الصيدلية.</div>
                @endif
            </div>
        </div>
        @if ($internal)
            @if ($isRep)
                <div class="col-6 col-md-4">
                    <div class="card card-body py-2 h-100 border-primary" data-card="my-share">
                        <div class="small text-muted">حصتي من الرصيد (ذممي)</div>
                        <div class="ps-card-value ps-num text-danger" id="ps-my-share">{{ $money($s['shares_cents']) }}</div>
                        <div class="small text-muted">ما تبقّى من فواتيري عليها</div>
                    </div>
                </div>
            @else
                @foreach ($s['rep_cards'] as $card)
                    <div class="col-6 col-md-4">
                        <div class="card card-body py-2 h-100" data-card="rep-share" data-rep="{{ $card['rep']->id }}">
                            <div class="small text-muted text-truncate">ذمم {{ $card['rep']->name }}</div>
                            <div class="ps-card-value ps-num text-danger">{{ $money($card['remaining_cents']) }}</div>
                            <div class="small text-muted">حصته من الدفعات: <span class="ps-num">{{ $money($card['payments_share_cents']) }}</span></div>
                        </div>
                    </div>
                @endforeach
                @if ($s['rep_cards']->isEmpty())
                    <div class="col-6 col-md-4"><div class="card card-body py-2 h-100 small text-muted">لا مندوبين ضمن نطاقك على هذه الصيدلية.</div></div>
                @endif
            @endif
            @if ($s['full'])
                <div class="col-6 col-md-4">
                    <div class="card card-body py-2 h-100" data-card="rest">
                        <div class="small text-muted">{{ $isRep ? 'الباقي (لغيري ومبالغ أخرى)' : 'الباقي (خارج هذه الحصص)' }}</div>
                        <div class="ps-card-value ps-num" id="ps-rest">{{ $s['rest_cents'] < 0 ? '−' : '' }}{{ $money(abs($s['rest_cents'])) }}</div>
                        <div class="small text-muted">يشمل ما تبقّى على فواتير مندوبين آخرين، وأرصدة غير منسوبة لمندوب.</div>
                        @if ($s['rest_cents'] < 0)
                            <div class="small text-warning-emphasis" data-rest-negative>سالب لأن {{ $isRep ? 'ذممك' : 'الحصص' }} أكبر من الرصيد الكامل: غالباً دفعات معلّقة لم تنعكس في الأرصدة بعد، أو سلفة/دفعة زائدة على الصيدلية، أو فواتير لم تدخل ملف الأرصدة بعد.</div>
                        @endif
                    </div>
                </div>
            @endif
        @endif
    </div>

    @if ($internal)
        @foreach ($s['rep_cards'] as $card)
            @foreach ($card['over_collected'] as $line)
                <div class="alert alert-danger py-1 px-2 small over-collected">{{ $line['text'] }}</div>
            @endforeach
        @endforeach
    @endif

    {{-- 2) الفواتير --}}
    <div class="card mb-3" id="ps-invoices">
        <div class="card-header bg-white fw-bold py-2 d-flex flex-wrap justify-content-between gap-2">
            <span><i class="fas fa-file-invoice text-primary me-1"></i> الفواتير</span>
            <span class="small text-muted fw-normal">{{ $s['invoices']->total() }} من {{ $s['invoice_count'] }}</span>
        </div>
        <div class="card-body pb-0 no-print">
            <form method="GET" action="{{ route($baseRoute, $customer) }}" class="row g-2 mb-2" id="ps-filters">
                @if ($pharmacyMode)<input type="hidden" name="mode" value="pharmacy">@endif
                <div class="col-12 col-md-3"><input type="search" name="q" value="{{ $filters['q'] }}" class="form-control" placeholder="رقم الفاتورة..." aria-label="رقم الفاتورة"></div>
                @if ($internal)
                    <div class="col-6 col-md-2">
                        <select name="who" class="form-select" aria-label="الفواتير">
                            <option value="all" @selected($filters['who'] === 'all')>كل الفواتير</option>
                            <option value="mine" @selected($filters['who'] === 'mine')>{{ $isRep ? 'فواتيري' : 'فواتير مندوبيّ' }}</option>
                        </select>
                    </div>
                @endif
                <div class="col-6 col-md-2">
                    <select name="period" class="form-select" aria-label="الفترة">
                        <option value="default" @selected($filters['period'] === 'default')>آخر {{ \App\Services\Reps\PharmacyStatementService::DEFAULT_DAYS }} يوماً{{ $internal ? ' + غير المسدَّد' : '' }}</option>
                        <option value="all" @selected($filters['period'] === 'all')>عرض الكل</option>
                        <option value="range" @selected($filters['period'] === 'range')>بين تاريخين</option>
                    </select>
                </div>
                <div class="col-6 col-md-2"><input type="date" name="from" value="{{ $filters['from'] }}" class="form-control" aria-label="من"></div>
                <div class="col-6 col-md-2"><input type="date" name="to" value="{{ $filters['to'] }}" class="form-control" aria-label="إلى"></div>
                <div class="col-12 col-md-1 d-grid"><button class="btn btn-primary fw-bold"><i class="fas fa-filter"></i></button></div>
            </form>
            @if ($filters['period'] === 'default')
                <p class="small text-muted mb-2">الافتراضي: فواتير آخر {{ \App\Services\Reps\PharmacyStatementService::DEFAULT_DAYS }} يوماً (منذ <span class="ps-num">{{ $s['since'] }}</span>)، وما هو قيد التسليم@if ($internal)، و{{ $isRep ? 'فواتيري' : 'فواتير مندوبيّ' }} غير المسدَّدة مهما كان عمرها@endif.</p>
            @endif
        </div>
        <div class="px-2 px-md-0">
            <table class="table table-sm align-middle mb-0 small ps-stack" id="ps-invoices-table">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">الفاتورة</th><th>التاريخ</th><th>القيمة</th>
                        @if ($internal)<th>المندوب</th><th>الكشف</th><th>المسدَّد</th><th class="pe-3">المتبقي</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($s['invoices'] as $i)
                        <tr class="ps-invoice-row" data-invoice="{{ $i['invoice_id'] }}" @if ($internal && $i['mine']) data-mine="1" @endif>
                            <td class="ps-3" data-label="الفاتورة"><span class="ps-num">{{ $i['label'] }}</span>
                                @if ($internal && $i['mine'] && $isRep)<span class="badge text-bg-primary ms-1" data-my-badge>فاتورتي</span>@endif
                            </td>
                            <td data-label="التاريخ"><span class="ps-num">{{ $i['date'] ?? '—' }}</span></td>
                            <td data-label="القيمة"><span class="ps-num fw-bold">{{ $money($i['amount_cents']) }}</span></td>
                            @if ($internal)
                                <td data-label="المندوب">{{ $i['rep_name'] }}</td>
                                <td data-label="الكشف">{{ $i['order'] }} <span class="badge {{ $i['delivered'] ? 'text-bg-success' : 'text-bg-warning' }}">{{ $i['status_label'] }}</span></td>
                                <td data-label="المسدَّد">@if ($i['mine'] && ($i['paid_cents'] ?? null) !== null)<span class="ps-num">{{ $money($i['paid_cents']) }}</span>@else<span class="text-muted">—</span>@endif</td>
                                <td class="pe-3" data-label="المتبقي">@if ($i['mine'] && ($i['remaining_cents'] ?? null) !== null)<span class="ps-num fw-bold {{ $i['remaining_cents'] > 0 ? 'text-danger' : 'text-success' }}">{{ $money($i['remaining_cents']) }}</span>@elseif ($i['mine'])<span class="text-muted">قيد التسليم</span>@else<span class="text-muted">—</span>@endif</td>
                            @endif
                        </tr>
                    @empty
                        <tr><td class="ps-3 text-muted" colspan="7">لا فواتير ضمن هذا العرض. جرّب «عرض الكل».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($s['invoices']->hasPages())
            <div class="card-footer bg-white d-flex justify-content-center no-print">{{ $s['invoices']->links() }}</div>
        @endif
    </div>

    {{-- 3) الدفعات --}}
    <div class="card mb-3" id="ps-payments">
        <div class="card-header bg-white fw-bold py-2 d-flex flex-wrap justify-content-between gap-2">
            <span><i class="fas fa-money-bill-wave text-primary me-1"></i> الدفعات</span>
            <span class="small text-muted fw-normal">{{ $s['payments']->total() }} دفعة — <span class="ps-num">{{ $money($s['payments_total_cents']) }}</span></span>
        </div>
        <div class="px-2 px-md-0">
            <table class="table table-sm align-middle mb-0 small ps-stack" id="ps-payments-table">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">التاريخ</th>@if ($internal)<th>الجهة المحصِّلة</th>@endif<th>النوع</th><th>المبلغ</th>
                        @if ($internal)<th>{{ $shareWord }}</th><th class="pe-3">التوزيع</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($s['payments'] as $p)
                        <tr class="ps-payment-row" data-payment="{{ $p['payment_id'] }}">
                            <td class="ps-3" data-label="التاريخ"><span class="ps-num">{{ $p['date'] }}</span></td>
                            @if ($internal)<td data-label="الجهة المحصِّلة">{{ $p['collector'] ?? '—' }}</td>@endif
                            <td data-label="النوع">{{ $p['type'] }}</td>
                            <td data-label="المبلغ">
                                @if ($p['awaiting_rate'])
                                    <span class="ps-num">{{ number_format($p['amount'], 2) }} $</span> <span class="text-muted">بانتظار السعر</span>
                                @else
                                    <span class="ps-num fw-bold">{{ $money($p['amount_cents']) }}</span>@if ($p['currency'] === 'USD') <span class="text-muted ps-num">({{ number_format($p['amount'], 2) }} $)</span>@endif
                                @endif
                            </td>
                            @if ($internal)
                                <td data-label="{{ $shareWord }}">
                                    @if ($isRep)
                                        <span class="ps-num">{{ $money(array_sum($p['shares'])) }}</span>
                                    @elseif ($p['shares'] === [])
                                        <span class="ps-num text-muted">0.00</span>
                                    @else
                                        @foreach ($p['shares'] as $repId => $cents)
                                            <div>{{ $s['rep_cards']->firstWhere('rep.id', $repId)['rep']->name ?? '' }}: <span class="ps-num">{{ $money($cents) }}</span></div>
                                        @endforeach
                                    @endif
                                </td>
                                <td class="pe-3" data-label="التوزيع"><span class="badge {{ $p['pending'] ? 'text-bg-warning' : 'text-bg-success' }}">{{ $p['status_label'] }}</span></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td class="ps-3 text-muted" colspan="6">لا دفعات مسجّلة على هذه الصيدلية.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($s['payments']->hasPages())
            <div class="card-footer bg-white d-flex justify-content-center no-print">{{ $s['payments']->links() }}</div>
        @endif
        @if ($internal)
            <div class="card-footer bg-white small text-muted">{{ $shareWord }} من دفعة الموزع = ما وزّعه التوزيع على {{ $isRep ? 'فواتيري' : 'فواتير المندوب' }} (إن جمع التوزيع أكثر من دفعة في الكشف نفسه تُقسَم الحصة بينها بالتناسب). دفعة المندوب لصاحبها كاملة.</div>
        @endif
    </div>

    {{-- 4) ذممي / ذمم المندوبين --}}
    @if ($internal)
        @foreach ($s['rep_cards'] as $card)
            <div class="card mb-3 ps-debt-card" data-rep="{{ $card['rep']->id }}">
                <div class="card-header bg-white fw-bold py-2 d-flex flex-wrap justify-content-between gap-2">
                    <span><i class="fas fa-hand-holding-dollar text-primary me-1"></i> {{ $isRep ? 'ذممي' : 'ذمم ' . $card['rep']->name }}</span>
                    <span class="ps-num text-danger">{{ $money($card['remaining_cents']) }}</span>
                </div>
                <div class="px-2 px-md-0">
                    <table class="table table-sm align-middle mb-0 small ps-stack">
                        <thead class="table-light"><tr><th class="ps-3">الفاتورة</th><th>التاريخ</th><th>القيمة</th><th class="pe-3">المتبقي</th></tr></thead>
                        <tbody>
                            @forelse ($card['open_invoices'] as $i)
                                <tr class="ps-debt-row">
                                    <td class="ps-3" data-label="الفاتورة"><span class="ps-num">{{ $i['label'] }}</span></td>
                                    <td data-label="التاريخ"><span class="ps-num">{{ $i['date'] }}</span></td>
                                    <td data-label="القيمة"><span class="ps-num">{{ $money($i['amount_cents']) }}</span></td>
                                    <td class="pe-3" data-label="المتبقي"><span class="ps-num fw-bold text-danger">{{ $money($i['remaining_cents']) }}</span></td>
                                </tr>
                            @empty
                                <tr><td class="ps-3 text-muted" colspan="4">لا فواتير متبقية.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endif
</div>
@endsection
