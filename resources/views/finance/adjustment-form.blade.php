@extends('layouts.panel')

@section('title', 'تعديل لاحق')

@php
    $fmt = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $label = \App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $invoice->invoice_number);
@endphp

@section('content')
    <x-page-header :title="'تعديل لاحق على الفاتورة ' . $label" subtitle="تعديل يدوي بلا أسطر: يغيّر القيمة الفعالة للفاتورة وذمة مندوبها فقط" icon="fa-file-pen">
        <a href="{{ route('finance.adjustments') }}" class="btn btn-light border fw-bold btn-sm"><i class="fas fa-arrow-right"></i> التعديلات اللاحقة</a>
    </x-page-header>

    <div class="card card-body mb-3 small" id="adjustment-invoice">
        <div class="row g-2">
            <div class="col-6 col-md-3"><span class="text-muted d-block">الزبون</span><strong>{{ $invoice->customer?->name ?? $invoice->customer_name }}</strong></div>
            <div class="col-6 col-md-3"><span class="text-muted d-block">الكشف</span>{{ $invoice->distributionSheet?->shortLabel() ?? 'أمر —' }} ({{ $invoice->distributionSheet?->statusLabel() ?? $invoice->placementLabel() }})</div>
            <div class="col-6 col-md-3"><span class="text-muted d-block">المندوب (مركز الكلفة)</span>{{ $rep?->name ?? 'بلا مندوب' }} <span class="text-muted">{{ trim(($invoice->cost_center_code ?? '').'-'.($invoice->cost_center_name ?? ''), '-') }}</span></div>
            <div class="col-6 col-md-3"><span class="text-muted d-block">القيمة الفعالة الحالية</span><strong class="font-monospace">{{ $fmt($current) }}</strong>
                @if ((float) $invoice->invoice_amount !== $current)<span class="d-block text-muted">(في الملف {{ $fmt($invoice->invoice_amount) }})</span>@endif</div>
            <div class="col-6 col-md-3"><span class="text-muted d-block">ذمة المندوب على الصيدلية الآن</span><span class="font-monospace">{{ $fmt($now['debt']) }}</span></div>
            @if ($snapshot)
                <div class="col-12 col-md-9"><span class="text-muted d-block">لقطة الرصيد</span>{{ $fmt($snapshot->prev_balance) }} ← {{ $fmt($snapshot->balance) }} ({{ $snapshot->as_of?->format('Y-m-d') }}) — {{ $snapshot->explanation }}</div>
            @endif
        </div>
    </div>

    @unless ($closed)
        <div class="alert alert-warning fw-bold">الفاتورة ليست في كشف مكتمل: التعديل اللاحق لفواتير الكشوف المكتملة فقط، والكشف المفتوح يُعدَّل من مراجعة الفروق.</div>
    @endunless

    @if ($error)
        <div class="alert alert-danger">{{ $error }}</div>
    @endif
    @if ($preview)
        <div class="alert alert-warning fw-bold" id="adjustment-preview">
            <i class="fas fa-calculator me-1"></i> الأثر: {{ $fmt($preview['old']) }} ← {{ $fmt($preview['new']) }} — {{ $preview['message'] }}.
            @if ($preview['new'] == 0)<span class="d-block">ستُسجَّل الفاتورة «ألغيت في المحاسبة» (لا حذف).</span>@endif
        </div>
    @endif

    <form method="GET" action="{{ route('finance.adjustments.create') }}" class="card card-body mb-3" id="adjustment-preview-form">
        <input type="hidden" name="invoice" value="{{ $invoice->id }}">
        @if ($snapshot)<input type="hidden" name="snapshot" value="{{ $snapshot->id }}">@endif
        <div class="row g-2 align-items-end small">
            <div class="col-6 col-md-3"><label class="form-label fw-bold mb-1" for="pv_delta">مقدار الفرق (− للتخفيض)</label>
                <input type="number" step="0.01" name="delta" id="pv_delta" class="form-control form-control-sm" value="{{ $delta }}"></div>
            <div class="col-6 col-md-3 d-grid"><button class="btn btn-sm btn-light border fw-bold">معاينة الأثر</button></div>
        </div>
    </form>

    @if ($closed)
        <form method="POST" action="{{ route('finance.adjustments.store') }}" class="card card-body" id="adjustment-form"
            @if ($preview) onsubmit="return confirm(@js('تطبيق التعديل: '.$preview['message'].'. متابعة؟'))" @endif>
            @csrf
            <input type="hidden" name="invoice" value="{{ $invoice->id }}">
            @if ($snapshot)<input type="hidden" name="snapshot" value="{{ $snapshot->id }}">@endif
            <div class="row g-3 small">
                <div class="col-12">
                    <span class="fw-bold d-block mb-1">التعديل</span>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="mode" id="mode_delta" value="delta" @checked(old('mode', 'delta') === 'delta')>
                        <label class="form-check-label" for="mode_delta">مقدار الفرق</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="mode" id="mode_new" value="new_amount" @checked(old('mode') === 'new_amount')>
                        <label class="form-check-label" for="mode_new">المبلغ الجديد</label>
                    </div>
                </div>
                <div class="col-6 col-md-3"><label class="form-label fw-bold mb-1" for="f_delta">مقدار الفرق</label>
                    <input type="number" step="0.01" name="delta" id="f_delta" class="form-control form-control-sm @error('delta') is-invalid @enderror" value="{{ old('delta', $delta) }}">
                    @error('delta')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6 col-md-3"><label class="form-label fw-bold mb-1" for="f_new">المبلغ الجديد</label>
                    <input type="number" step="0.01" min="0" name="new_amount" id="f_new" class="form-control form-control-sm @error('new_amount') is-invalid @enderror" value="{{ old('new_amount', $delta !== null ? round($current + $delta, 2) : '') }}">
                    @error('new_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-6 col-md-3"><label class="form-label fw-bold mb-1" for="f_reason">السبب</label>
                    <select name="reason_code" id="f_reason" class="form-select form-select-sm @error('reason_code') is-invalid @enderror" required>
                        <option value="">— اختر —</option>
                        @foreach (\App\Models\InvoiceAdjustment::MANUAL_REASONS as $code)
                            <option value="{{ $code }}" @selected(old('reason_code') === $code)>{{ \App\Models\InvoiceAdjustment::REASONS[$code] }}</option>
                        @endforeach
                    </select>
                    @error('reason_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-12 col-md-6"><label class="form-label fw-bold mb-1" for="f_text">نص السبب (إلزامي مع «أخرى»)</label>
                    <input type="text" name="reason_text" id="f_text" class="form-control form-control-sm @error('reason_text') is-invalid @enderror" value="{{ old('reason_text') }}" maxlength="500">
                    @error('reason_text')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-12"><button class="btn btn-success fw-bold"><i class="fas fa-check"></i> تطبيق التعديل اللاحق</button></div>
            </div>
        </form>
    @endif

    @if ($history->isNotEmpty())
        <div class="card mt-3">
            <div class="card-header bg-white fw-bold small">تعديلات سابقة على هذه الفاتورة</div>
            <ul class="list-group list-group-flush small">
                @foreach ($history as $h)
                    <li class="list-group-item">{{ $h->tagLabel() }} — {{ $h->kindLabel() }} — {{ $h->reasonLabel() }} — {{ $h->applier?->name }} {{ $h->applied_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d') }} @unless ($h->isApplied())<span class="badge text-bg-warning">تُراجع عنه</span>@endunless</li>
                @endforeach
            </ul>
        </div>
    @endif
@endsection
