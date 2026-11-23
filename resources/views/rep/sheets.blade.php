@extends('layouts.panel')

@section('title', 'كشوفي')
@section('body_class', 'touch-ui')

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')
    @include('partials.balances-stale')
    <x-page-header title="كشوفي" subtitle="الكشوف التي فيها فواتير لمركز كلفتك (قراءة فقط)" icon="fa-clipboard-list" />

    <p class="small text-muted"><i class="fas fa-circle-info me-1"></i> الأرقام حتى الآن وتُعتمد عند إغلاق الكشف.</p>

    @include('rep.partials.adjustment-alerts')

    @if ($changedCount ?? 0)
        <div class="alert alert-info py-2 small fw-bold" id="rep-balance-changes"><i class="fas fa-scale-balanced me-1"></i> {{ $changedCount }} صيدليات تغيّر رصيدها — افتح الكشف لترى التفاصيل واضغط «اطلعت».</div>
    @endif

    @forelse ($rows as $r)
        <a href="{{ route('rep.sheets.show', $r['sheet']) }}" class="card mb-2 text-decoration-none text-body rep-sheet-row">
            <div class="card-body p-3 d-flex flex-wrap align-items-center gap-2">
                <div class="min-w-0 flex-grow-1">
                    <div class="fw-bold">أمر التسليم #{{ $r['sheet']->orderLabel() }}</div>
                    <div class="small text-muted"><span dir="ltr">{{ $r['sheet']->distribution_date?->format('Y-m-d') }}</span> — <x-status-badge :status="$r['sheet']->status" /></div>
                </div>
                <div class="small text-center"><div class="text-muted">فواتيري</div><div class="fw-bold">{{ $r['count'] }}</div></div>
                <div class="small text-center"><div class="text-muted">قيمتها</div><div class="fw-bold font-monospace" dir="ltr">{{ $money($r['total']) }}</div></div>
                <div class="small text-center"><div class="text-muted">حُصِّل</div><div class="fw-bold font-monospace text-success" dir="ltr">{{ $money($r['collected']) }}</div></div>
                <div class="small text-center"><div class="text-muted">المتبقي</div><div class="fw-bold font-monospace text-danger" dir="ltr">{{ $money($r['remaining']) }}</div></div>
            </div>
        </a>
    @empty
        <div class="card"><x-empty-state icon="fa-clipboard-list" title="لا كشوف فيها فواتير لمركز كلفتك" /></div>
    @endforelse
@endsection
