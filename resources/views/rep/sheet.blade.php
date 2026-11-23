@extends('layouts.panel')

@section('title', 'أمر التسليم #' . $sheet->orderLabel())
@section('body_class', 'touch-ui')

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')
    @include('partials.balances-stale')
    <x-page-header :title="'أمر التسليم #' . $sheet->orderLabel()" :subtitle="'تاريخ التوزيع ' . ($sheet->distribution_date?->format('Y-m-d') ?? '—')" icon="fa-clipboard-list">
        <x-status-badge :status="$sheet->status" class="align-self-center" />
        <a href="{{ route('rep.sheets.index') }}" class="btn btn-light border fw-bold btn-sm"><i class="fas fa-arrow-right"></i> كشوفي</a>
    </x-page-header>

    <div class="alert alert-info small py-2"><i class="fas fa-circle-info me-1"></i> فواتيرك فقط في هذا الكشف. الأرقام حتى الآن وتُعتمد عند إغلاق الكشف.</div>

    <div data-rep-lines-url="{{ route('rep.sheets.lines', $sheet) }}">
    @foreach ($pharmacies as $p)
        <div class="card mb-3 rep-sheet-pharmacy" data-customer-id="{{ $p['customer']->id }}">
            <div class="card-body p-3">
                <div class="d-flex flex-wrap align-items-start gap-2 mb-2">
                    <div class="min-w-0 flex-grow-1">
                        <div class="fw-bold text-break-word">{{ $p['customer']->name }}</div>
                        <div class="small text-muted">{{ $p['area'] }}</div>
                    </div>
                    <div class="text-center small">
                        <div class="text-muted">رصيد الصيدلية الكلي (المستحق)
                            <span class="badge rounded-pill text-bg-light border" title="{{ $p['balance']->publicBreakdown() }}" tabindex="0" role="button" aria-label="{{ $p['balance']->publicBreakdown() }}">؟</span>
                        </div>
                        <div class="fw-bold font-monospace" dir="ltr">{{ $p['due_balance'] !== null ? $money($p['due_balance']) : '—' }}</div>
                        <div class="text-muted fs-8">{{ $p['balance']->asOfLabel() }}</div>
                    </div>
                </div>

                @if ($p['balance_change'])
                    <div class="alert alert-info py-2 small mb-2 d-flex flex-wrap align-items-center justify-content-between gap-2 balance-change-badge">
                        <span><i class="fas fa-scale-balanced me-1"></i> {{ $p['balance_change']->changeLabel() }}
                            @if ($reason = app(\App\Services\SheetChangeNotifier::class)->shortReason($p['balance_change'])) ({{ $reason }})@endif
                            <span class="text-muted">— {{ $p['balance_change']->as_of?->format('Y-m-d') }}</span>
                        </span>
                        <form action="{{ route('rep.balance-seen', $p['balance_change']) }}" method="POST" class="m-0">
                            @csrf
                            <button class="btn btn-sm btn-outline-primary fw-bold py-0">اطلعت</button>
                        </form>
                    </div>
                @endif

                <div class="d-flex flex-wrap gap-2 small mb-2">
                    <span class="badge {{ str_starts_with($p['visit']['label'], 'زُيرت') ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $p['visit']['label'] }}</span>
                    @if ($p['visit']['reason'])<span class="text-muted">السبب: {{ $p['visit']['reason'] }}</span>@endif
                </div>
                @if ($p['notes'])
                    <div class="small mb-2"><span class="fw-bold">ملاحظات الموزع:</span> @foreach ($p['notes'] as $note)<span class="d-block rep-distributor-note">{{ $note }}</span>@endforeach</div>
                @endif

                @include('partials.pharmacy-invoices', ['rows' => $p['invoices']])

                <div class="d-flex flex-wrap gap-3 small mt-2">
                    <span>المنسوب لمركز كلفتك (حُصِّل): <span class="fw-bold font-monospace text-success" dir="ltr">{{ $money($p['attributed']) }}</span></span>
                    <span>المتبقي من فواتيرك: <span class="fw-bold font-monospace text-danger" dir="ltr">{{ $money($p['remaining']) }}</span></span>
                </div>
            </div>
        </div>
    @endforeach
    </div>
@endsection

@push('modals')
    {{-- نافذة تفاصيل الفاتورة نفسها التي عند الموزع، بلا أزرار طباعة أو دفع --}}
    @include('partials.invoice-modal', ['printable' => false])
@endpush

@push('scripts')
    <script type="module" src="{{ asset('js/rep/rep-sheet.js') }}?v={{ @filemtime(public_path('js/rep/rep-sheet.js')) }}"></script>
@endpush
