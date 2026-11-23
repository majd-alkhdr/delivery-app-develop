@extends('layouts.panel')

@section('title', 'العملاء')
@section('body_class', 'touch-ui')

@section('content')
    <x-page-header title="العملاء" subtitle="عملاء مناطقك اليوم برصيدهم المستحق، ودفعة خارجية لأي عميل" icon="fa-address-book">
        <span class="badge text-bg-warning align-self-center d-none" data-queue-box>
            <i class="fas fa-cloud-arrow-up me-1"></i> معلّق: <span data-queue-count>0</span>
        </span>
        @if ($config['printingEnabled'])
            <span class="badge text-bg-secondary align-self-center" data-printer-status>الطابعة: غير متصل</span>
            <span class="badge text-bg-info align-self-center d-none" data-print-queue-box><i class="fas fa-print me-1"></i> طباعة معلّقة: <span data-print-queue-count>0</span></span>
            <a href="{{ route('print.test') }}" class="btn btn-outline-secondary fw-bold"><i class="fas fa-print"></i> الطابعة</a>
        @endif
        <a href="{{ route('distributor.index') }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> كشوفاتي</a>
    </x-page-header>

    <div id="distributorToasts" class="position-sticky top-0" style="z-index: 1080;"></div>

    @unless ($hasOpenSheet)
        <div class="alert alert-warning small"><i class="fas fa-circle-info me-1"></i> لا يوجد لديك كشف مفتوح، فلا يمكن تسجيل دفعة خارجية الآن.</div>
    @endunless

    <form method="GET" action="{{ route('distributor.customers') }}" class="row g-2 mb-3">
        <div class="col-md-6">
            <input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="ابحث باسم العميل...">
        </div>
        <div class="col-8 col-md-4">
            <select name="area" class="form-select" aria-label="المنطقة">
                <option value="">كل المناطق</option>
                @foreach ($areas as $area)
                    <option value="{{ $area }}" @selected(request('area') === $area)>{{ $area }}</option>
                @endforeach
                <option value="__none__" @selected(request('area') === '__none__')>بلا منطقة</option>
            </select>
        </div>
        <div class="col-4 col-md-2 d-grid">
            <button class="btn btn-primary fw-bold"><i class="fas fa-filter"></i> بحث</button>
        </div>
    </form>

    @forelse ($customers as $customer)
        <div class="card mb-2">
            <div class="card-body p-3 d-flex flex-wrap align-items-center gap-2">
                <div class="min-w-0 flex-grow-1">
                    <div class="fw-bold text-break-word">{{ $customer->name }}</div>
                    <div class="small text-muted">{{ $customer->area ?? 'بلا منطقة' }}
                        @include('distributor.partials.ref-badge', ['state' => $customer->referenceState(), 'customerId' => $customer->id])
                    </div>
                </div>
                <div class="text-center">
                    <span class="d-block small text-muted">الرصيد المستحق</span>
                    <span class="fw-bold text-danger font-monospace" dir="ltr">{{ number_format($due[$customer->id] ?? 0, 0) }}</span>
                </div>
                @if ($hasOpenSheet)
                    <button type="button" class="btn btn-warning fw-bold" data-open-payment
                        data-url="{{ route('distributor.payments.external') }}"
                        data-customer-id="{{ $customer->id }}" data-customer-name="{{ $customer->name }}" data-due="{{ $due[$customer->id] ?? 0 }}"
                        data-ref-state="{{ $customer->referenceState() }}">
                        <i class="fas fa-plus-circle"></i> دفعة خارجية
                    </button>
                @endif
            </div>
        </div>
    @empty
        <div class="card"><x-empty-state icon="fa-address-book" title="لا يوجد عملاء في مناطقك اليوم" /></div>
    @endforelse

    @if ($customers->hasPages())
        <div class="d-flex justify-content-center mt-3">{{ $customers->links() }}</div>
    @endif
@endsection

@push('modals')
    @include('distributor.partials.payment-modal', ['canEnterUsd' => $config['canEnterUsd'], 'title' => 'دفعة خارجية'])
    @include('distributor.partials.pin-modal')
@endpush

@push('scripts')
    <script>
        window.DistributorConfig = @json($config);
    </script>
    <script src="{{ asset('js/distributor-app.js') }}?v={{ @filemtime(public_path('js/distributor-app.js')) }}"></script>
    @if ($config['printingEnabled'])
        @include('print.partials.bootstrap')
    @endif
    <script>
        document.addEventListener('distributor:synced', () => setTimeout(() => {
            if (window.DistributorApp.Queue.all().length === 0 && !window.DistributorApp.pinOpen) location.reload();
        }, 1200));
    </script>
@endpush
