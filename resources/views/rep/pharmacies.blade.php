@extends('layouts.panel')

@section('title', 'صيدلياتي')
@section('body_class', 'touch-ui')

@php $money = fn ($cents) => number_format(((int) $cents) / 100, 2); @endphp

@section('content')
    <x-page-header title="صيدلياتي" :subtitle="'الصيدليات التي بعتها (' . $total . ')، مرتبة بحصتك الأعلى'" icon="fa-store" />

    <form method="GET" action="{{ route('rep.pharmacies.index') }}" class="row g-2 mb-3">
        <div class="col-8 col-md-10"><input type="search" name="q" class="form-control" value="{{ request('q') }}" placeholder="ابحث بالاسم أو المنطقة..."></div>
        <div class="col-4 col-md-2 d-grid"><button class="btn btn-primary fw-bold"><i class="fas fa-search"></i> بحث</button></div>
    </form>

    @forelse ($rows as $r)
        <div class="card mb-2 rep-pharmacy-row" data-customer="{{ $r['customer']->id }}">
            <div class="card-body p-3 d-flex flex-wrap align-items-center gap-2">
                <div class="min-w-0 flex-grow-1">
                    <div class="fw-bold text-break-word">{{ $r['customer']->name }}</div>
                    <div class="small text-muted">{{ $r['customer']->area ?: 'بلا منطقة' }}</div>
                </div>
                <div class="d-flex gap-3 text-center">
                    <div>
                        <span class="d-block small text-muted">الرصيد الكامل</span>
                        <span class="fw-bold font-monospace" dir="ltr">{{ $money($r['balance_cents']) }}</span>
                    </div>
                    <div>
                        <span class="d-block small text-muted">حصتي</span>
                        <span class="fw-bold font-monospace {{ $r['share_cents'] > 0 ? 'text-danger' : '' }}" dir="ltr" data-share>{{ $money($r['share_cents']) }}</span>
                    </div>
                </div>
                <a href="{{ route('rep.pharmacies.show', $r['customer']) }}" class="btn btn-primary fw-bold"><i class="fas fa-file-invoice-dollar"></i> كشف الصيدلية</a>
            </div>
        </div>
    @empty
        <div class="card"><x-empty-state icon="fa-store" title="لا صيدليات" message="تظهر هنا الصيدليات التي لك فيها فاتورة واحدة على الأقل." /></div>
    @endforelse

    @if ($rows->hasPages())
        <div class="d-flex justify-content-center mt-3">{{ $rows->links() }}</div>
    @endif
@endsection
