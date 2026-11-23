@extends('layouts.panel')

@php
    $action = $preview['action'];
    $title = \App\Models\InvoiceBulkOperation::LABELS[$action];
    $eligible = $preview['eligible'];
    $needsReason = $action !== \App\Models\InvoiceBulkOperation::RESTORE;
@endphp

@section('title', $title)

@section('content')
    <x-page-header :title="$title" subtitle="معاينة قبل التنفيذ: ما سيُنفَّذ عليه، وما سيُستثنى وسببه" icon="fa-layer-group">
        <a href="{{ $back }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> رجوع</a>
    </x-page-header>

    <div class="row g-2 mb-3" id="bulk-summary">
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">المحدد</div><div class="fw-bold fs-5">{{ $preview['total'] }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">سيُنفَّذ عليه</div><div class="fw-bold fs-5 text-success" data-bulk-eligible>{{ $eligible->count() }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">سيُستثنى</div><div class="fw-bold fs-5 text-danger">{{ collect($preview['skipped'])->sum('count') }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">قيمة المنفَّذ عليه</div><div class="fw-bold fs-5 font-monospace">{{ number_format($preview['summary']['amount'], 2) }}</div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-bold small">حسب الحالة الحالية</div>
                <ul class="list-group list-group-flush small">
                    @foreach ($preview['summary']['by_placement'] as $label => $count)<li class="list-group-item d-flex justify-content-between"><span>{{ $label }}</span><span class="fw-bold">{{ $count }}</span></li>@endforeach
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-bold small">المنفَّذ عليه حسب المنطقة (الأكثر)</div>
                <ul class="list-group list-group-flush small">
                    @forelse ($preview['summary']['by_area'] as $label => $count)<li class="list-group-item d-flex justify-content-between"><span>{{ $label }}</span><span class="fw-bold">{{ $count }}</span></li>@empty<li class="list-group-item text-muted">—</li>@endforelse
                </ul>
            </div>
        </div>
    </div>

    @if ($preview['skipped'])
        <div class="card border-warning mb-3" id="bulk-skipped">
            <div class="card-header bg-warning-subtle fw-bold small">ما سيُستثنى (لا استثناء صامت)</div>
            <ul class="list-group list-group-flush small">
                @foreach ($preview['skipped'] as $s)
                    <li class="list-group-item">{{ $s['text'] }} <strong>{{ $s['count'] }}</strong>
                        @if ($s['examples'])<span class="text-muted">— مثل: {{ implode('، ', $s['examples']) }}</span>@endif</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($eligible->isEmpty())
        <div class="alert alert-secondary">لا توجد فواتير مؤهَّلة لهذا الإجراء في التحديد.</div>
    @else
        <form method="POST" action="{{ route('bulk-invoices.store') }}" class="card card-body" id="bulk-confirm">
            @csrf
            <input type="hidden" name="scope" value="{{ $scope }}">
            @foreach ($filters as $key => $value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
            @if ($ids === null)
                <input type="hidden" name="all" value="1">
                <p class="small text-muted mb-2">التحديد: كل نتائج التصفية الحالية — يُعاد حسابها على الخادم عند التنفيذ بالمرشّحات نفسها.</p>
            @else
                @foreach ($ids as $id)<input type="hidden" name="ids[]" value="{{ $id }}">@endforeach
            @endif

            @if ($needsReason)
                <label class="form-label fw-bold small" for="reason_choice">السبب المشترك (إلزامي)</label>
                <select name="reason_choice" id="reason_choice" class="form-select mb-2" required>
                    <option value="">— اختر —</option>
                    @foreach ($reasons as $r)<option value="{{ $r }}" @selected(old('reason_choice') === $r)>{{ $r }}</option>@endforeach
                    <option value="__other" @selected(old('reason_choice') === '__other')>سبب آخر…</option>
                </select>
                <input type="text" name="reason_other" class="form-control mb-2" maxlength="1000" placeholder="اكتب السبب (إلزامي عند «سبب آخر»)" value="{{ old('reason_other') }}">
            @endif
            <div class="alert alert-info small py-2 mb-2">
                يُنفَّذ على {{ $eligible->count() }} فاتورة في معاملة واحدة (كلها أو لا شيء)، ويُسجَّل لكل فاتورة سجلها (قبل/بعد والسبب ورقم العملية) وسجل ملخَّص واحد.
                @if ($action !== \App\Models\InvoiceBulkOperation::RESTORE) ويمكن إعادة إدراجها جماعياً لاحقاً برقم العملية. @endif
            </div>
            <div><button class="btn btn-danger fw-bold"><i class="fas fa-check"></i> تنفيذ على {{ $eligible->count() }} فاتورة</button></div>
        </form>
    @endif
@endsection
