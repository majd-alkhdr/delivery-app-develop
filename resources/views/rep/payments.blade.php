@extends('layouts.panel')

@section('title', 'دفعاتي')
@section('body_class', 'touch-ui')

@php
    $reviewLabels = ['none' => ['بانتظار المراجعة', 'text-bg-light border'], 'verified' => ['تم التحقق', 'text-bg-success'], 'needs_review' => ['بحاجة مراجعة', 'text-bg-warning']];
@endphp

@section('content')
    <x-page-header title="دفعاتي" subtitle="تحصيلاتك خلال آخر 30 يوماً" icon="fa-receipt">
        @include('rep.partials.header-tools')
    </x-page-header>

    <div id="distributorToasts" class="position-sticky top-0" style="z-index: 1080;"></div>

    @forelse ($payments as $p)
        @php [$rt, $rc] = $reviewLabels[$p->review_status] ?? [$p->review_status, 'text-bg-light']; @endphp
        <div class="card mb-2 rep-payment-row {{ $p->isVoided() ? 'opacity-75' : '' }}">
            <div class="card-body p-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <div class="min-w-0 flex-grow-1">
                        <div class="fw-bold">{{ $p->customer?->name }}</div>
                        <div class="small text-muted">
                            دفتر: <span class="font-monospace" dir="ltr">{{ $p->book_receipt_no }}</span>
                            — <span dir="ltr">{{ $p->collected_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</span>
                        </div>
                    </div>
                    <div class="fw-bold font-monospace {{ $p->isVoided() ? 'text-decoration-line-through' : '' }}" dir="ltr">
                        {{ number_format((float) $p->amount, 2) }} {{ $p->currency === 'USD' ? '$' : 'ل.س' }}
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-2 small">
                    @if ($p->isVoided())
                        <span class="badge text-bg-dark">ملغاة: {{ $p->void_reason }}</span>
                    @else
                        <span class="badge {{ $rc }}">{{ $rt }}</span>
                        <span class="badge {{ $p->status === 'pending' ? 'text-bg-secondary' : 'text-bg-primary' }}">{{ $p->status === 'pending' ? 'غير مُصدَّرة' : 'مُصدَّرة' }}</span>
                    @endif
                    @if ($p->review_status === 'needs_review' && $p->review_note)
                        <span class="text-warning-emphasis">ملاحظة المحاسب: {{ $p->review_note }}</span>
                    @endif
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <a href="{{ route('payments.image', $p) }}" target="_blank" rel="noopener" class="btn btn-sm btn-light border"><i class="fas fa-image"></i> الصورة</a>
                    @if (! $p->isVoided() && ! $p->isExported())
                        <form action="{{ route('rep.payments.image', $p) }}" method="POST" enctype="multipart/form-data" class="d-flex gap-1 m-0">
                            @csrf
                            <input type="file" name="image" accept="image/*" capture="environment" class="form-control form-control-sm" style="max-width: 200px" required>
                            <button class="btn btn-sm btn-outline-primary fw-bold">استبدال الصورة</button>
                        </form>
                    @endif
                    @if ($voidable[$p->id] ?? false)
                        <button type="button" class="btn btn-sm btn-outline-danger fw-bold" data-void-url="{{ route('payments.void', $p) }}"><i class="fas fa-ban"></i> إلغاء</button>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="card"><x-empty-state icon="fa-receipt" title="لا دفعات خلال آخر 30 يوماً" /></div>
    @endforelse
@endsection

@push('modals')
    @include('distributor.partials.pin-modal')
@endpush

@push('scripts')
    @include('rep.partials.scripts')
@endpush
