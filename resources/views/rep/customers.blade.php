@extends('layouts.panel')

@section('title', 'تحصيل لأي زبون')
@section('body_class', 'touch-ui')

@section('content')
    <x-page-header title="تحصيل لأي زبون" subtitle="ابحث عن الصيدلية وسجّل التحصيل حتى لو لم يُعرف لها دَين" icon="fa-address-book">
        @include('rep.partials.header-tools')
    </x-page-header>

    <div id="distributorToasts" class="position-sticky top-0" style="z-index: 1080;"></div>

    @unless ($config['canRecord'])
        <div class="alert alert-danger fw-bold"><i class="fas fa-circle-exclamation me-1"></i> لم يُضبط مركز الكلفة لحسابك، راجع الإدارة.</div>
    @endunless

    <form method="GET" action="{{ route('rep.customers') }}" class="row g-2 mb-3">
        <div class="col-8 col-md-10"><input type="search" name="q" class="form-control" value="{{ request('q') }}" placeholder="ابحث باسم الصيدلية..."></div>
        <div class="col-4 col-md-2 d-grid"><button class="btn btn-primary fw-bold"><i class="fas fa-search"></i> بحث</button></div>
    </form>

    @forelse ($customers as $customer)
        @php $debt = $debts[$customer->id] ?? 0; @endphp
        <div class="card mb-2 rep-customer-row">
            <div class="card-body p-3 d-flex flex-wrap align-items-center gap-2">
                <div class="min-w-0 flex-grow-1">
                    <div class="fw-bold text-break-word">{{ $customer->name }}</div>
                    <div class="small text-muted">{{ $customer->area ?? 'بلا منطقة' }}</div>
                </div>
                <div class="text-center">
                    @if ($debt > 0)
                        <span class="d-block small text-muted">دَينك المعروف</span>
                        <span class="fw-bold text-danger font-monospace" dir="ltr">{{ number_format($debt, 2) }}</span>
                    @else
                        <span class="small text-muted">لا دَين معروف لك</span>
                    @endif
                </div>
                @if ($statementUrl = \App\Services\Reps\PharmacyStatementService::linkFor(auth()->user(), $customer->id))
                    <a href="{{ $statementUrl }}" class="btn btn-outline-primary fw-bold" data-statement-link><i class="fas fa-file-invoice-dollar"></i> كشف الصيدلية الكامل</a>
                @endif
                @if ($config['canRecord'])
                    <button type="button" class="btn btn-success fw-bold" data-open-rep-payment
                        data-customer-id="{{ $customer->id }}" data-customer-name="{{ $customer->name }}" data-debt="{{ $debt }}"
                        data-ref-state="{{ $customer->referenceState() }}">
                        <i class="fas fa-plus-circle"></i> تسجيل تحصيل
                    </button>
                @endif
            </div>
        </div>
    @empty
        <div class="card"><x-empty-state icon="fa-address-book" title="لا نتائج" message="جرّب اسماً آخر." /></div>
    @endforelse

    @if ($customers->hasPages())
        <div class="d-flex justify-content-center mt-3">{{ $customers->links() }}</div>
    @endif
@endsection

@push('modals')
    @include('rep.partials.payment-modal')
    @include('distributor.partials.pin-modal')
@endpush

@push('scripts')
    @include('rep.partials.scripts')
@endpush
