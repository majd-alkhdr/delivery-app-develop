@extends('layouts.panel')

@section('title', 'الطلبيات غير المسددة')

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $statusBadges = ['unpaid' => 'text-bg-danger', 'partial' => 'text-bg-warning'];
    $t = $report['totals'];
@endphp

@section('content')
    <x-page-header title="الطلبيات غير المسددة" :subtitle="$report['from'] === $report['to'] ? 'تاريخ التوزيع ' . $report['from'] : 'من ' . $report['from'] . ' إلى ' . $report['to']" icon="fa-file-invoice-dollar">
        <a href="{{ route('finance.unpaid.export', request()->query()) }}" class="btn btn-success fw-bold btn-sm"><i class="fas fa-file-excel"></i> تصدير Excel</a>
    </x-page-header>
    @include('partials.rep-scope-line')3

    <div class="alert alert-info small py-2"><i class="fas fa-circle-info me-1"></i> أرقام حيّة وقد تختلف عن لقطة تصدير سابق إن عُدّلت الفواتير.</div>

    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">فواتير غير مسددة</div><div class="fw-bold fs-5" id="t-count">{{ $t['count'] }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">قيمتها</div><div class="fw-bold fs-5 font-monospace" id="t-unpaid">{{ $money($t['unpaid']) }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">المحصّل (ليرة / دولار)</div><div class="fw-bold font-monospace">{{ $money($t['collected_syp']) }} / {{ $money($t['collected_usd']) }}$</div><div class="small text-muted">المكافئ الكلي: <span class="font-monospace">{{ $money($t['collected_total_syp']) }}</span></div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">الفائض</div><div class="fw-bold fs-5 font-monospace">{{ ! empty($report['scope_label']) ? '—' : $money($t['surplus']) }}</div>@if (! empty($report['scope_label']))<div class="small text-muted" data-surplus-hidden>الفائض على مستوى الكشف كاملاً لا يظهر في العرض المقيَّد.</div>@endif @if ($t['awaiting_rate'])<div class="small text-danger">{{ $t['awaiting_rate'] }} دفعة دولار بانتظار السعر</div>@endif</div></div>
    </div>

    <form method="GET" action="{{ route('finance.unpaid') }}" class="card card-body mb-3">
        <div class="row g-2 align-items-end small">
            <div class="col-6 col-md-2"><label class="form-label fw-bold mb-1" for="u_from">من</label><input type="date" name="from" id="u_from" class="form-control form-control-sm" value="{{ $report['from'] }}"></div>
            <div class="col-6 col-md-2"><label class="form-label fw-bold mb-1" for="u_to">إلى</label><input type="date" name="to" id="u_to" class="form-control form-control-sm" value="{{ $report['to'] }}"></div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="u_cc">مركز الكلفة</label>
                <select name="cost_center" id="u_cc" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($report['options']['cost_centers'] as $cc)<option value="{{ $cc }}" @selected($filters['cost_center'] === $cc)>{{ $cc }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="u_area">المنطقة</label>
                <select name="area_key" id="u_area" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($report['options']['areas'] as $key => $label)<option value="{{ $key }}" @selected($filters['area_key'] === (string) $key)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="u_dist">الموزع</label>
                <select name="distributor_id" id="u_dist" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($report['options']['distributors'] as $id => $name)<option value="{{ $id }}" @selected((int) $filters['distributor_id'] === (int) $id)>{{ $name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="u_status">الحالة</label>
                <select name="status" id="u_status" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach (\App\Services\Finance\UnpaidReportService::STATUSES as $k => $label)<option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="u_order">أمر التسليم</label>
                <select name="order" id="u_order" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($report['options']['orders'] as $o)<option value="{{ $o }}" @selected((string) $filters['order'] === (string) $o)>#{{ $o }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-3"><label class="form-label fw-bold mb-1" for="u_q">بحث</label><input type="search" name="q" id="u_q" class="form-control form-control-sm" value="{{ $filters['q'] }}" placeholder="صيدلية، فاتورة، مركز..."></div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold mb-1" for="u_group">تجميع</label>
                <select name="group" id="u_group" class="form-select form-select-sm">
                    <option value="">بلا تجميع</option>
                    <option value="area" @selected($group === 'area')>حسب المنطقة</option>
                    <option value="cost_center" @selected($group === 'cost_center')>حسب مركز الكلفة</option>
                </select>
            </div>
            <div class="col-6 col-md-2 d-grid"><button class="btn btn-sm btn-primary fw-bold"><i class="fas fa-filter"></i> تطبيق</button></div>
        </div>
    </form>

    @if ($group)
        <div class="card mb-3 overflow-hidden">
            <div class="card-header bg-white fw-bold py-2 small">الإجماليات {{ $group === 'area' ? 'حسب المنطقة' : 'حسب مركز الكلفة' }}</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead class="table-light"><tr><th class="ps-3">{{ $group === 'area' ? 'المنطقة' : 'مركز الكلفة' }}</th><th>الفواتير</th><th>الإجمالي</th><th>الموزَّع</th><th class="pe-3">غير المسدد</th></tr></thead>
                    <tbody>
                        @foreach ($groups as $g)
                            <tr class="group-row"><td class="ps-3 fw-bold">{{ $g['label'] }}</td><td>{{ $g['count'] }}</td><td class="font-monospace">{{ $money($g['total']) }}</td><td class="font-monospace">{{ $money($g['allocated']) }}</td><td class="pe-3 font-monospace fw-bold">{{ $money($g['unpaid']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr><th class="ps-3">التاريخ</th><th>الأمر</th><th>الصيدلية</th><th>المنطقة</th><th>الفاتورة</th><th>مركز الكلفة</th><th>الإجمالي</th><th>الموزَّع</th><th>غير المسدد</th><th>الحالة</th><th>الزيارة</th><th class="pe-3">الموزع</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        <tr class="unpaid-row">
                            <td class="ps-3 font-monospace text-nowrap">{{ $r['date'] }}</td>
                            <td class="font-monospace">#{{ $r['order'] }}</td>
                            <td class="fw-bold">{{ $r['pharmacy'] }}</td>
                            <td>{{ $r['area'] }}</td>
                            <td class="font-monospace" dir="ltr">{{ $r['invoice'] }}</td>
                            <td>{{ $r['cost_center'] ?? '—' }}</td>
                            <td class="font-monospace">{{ $money($r['total']) }}</td>
                            <td class="font-monospace">{{ $money($r['allocated']) }}</td>
                            <td class="font-monospace fw-bold">{{ $money($r['unpaid']) }}</td>
                            <td><span class="badge {{ $statusBadges[$r['status']] }}">{{ \App\Services\Finance\UnpaidReportService::STATUSES[$r['status']] }}</span>@if ($r['awaiting_rate'])<span class="badge text-bg-light border">بانتظار السعر</span>@endif</td>
                            <td>{{ $r['visit'] }}</td>
                            <td class="pe-3">{{ $r['distributor'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="12"><x-empty-state title="لا طلبيات غير مسددة" message="لا توجد فواتير غير مسددة تطابق الفلترة." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $rows->links() }}</div>
        @endif
    </div>
@endsection
