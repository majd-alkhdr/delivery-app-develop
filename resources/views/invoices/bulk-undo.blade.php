@extends('layouts.panel')

@section('title', 'إعادة إدراج عملية جماعية')

@section('content')
    <x-page-header title="إعادة إدراج عملية جماعية" :subtitle="$operation->label() . ' — رقم ' . substr($operation->uuid, 0, 8) . ' — ' . $operation->created_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') . ($operation->user ? ' بواسطة ' . $operation->user->name : '')" icon="fa-rotate-left" />

    <div class="card card-body mb-3 small" id="undo-summary">
        <div>السبب الأصلي: <strong>{{ $operation->reason }}</strong></div>
        <div>عدد فواتير العملية: <strong>{{ $preview['total'] }}</strong> — ستُعاد: <strong class="text-success" data-undo-eligible>{{ $preview['eligible']->count() }}</strong>
            {{ $operation->action === \App\Models\InvoiceBulkOperation::EXCLUDE ? '(إلى «بلا كشف»)' : '(إلى «مؤجلة»)' }}</div>
    </div>

    @if ($preview['skipped'])
        <div class="card border-warning mb-3">
            <div class="card-header bg-warning-subtle fw-bold small">لن تُعاد</div>
            <ul class="list-group list-group-flush small">
                @foreach ($preview['skipped'] as $s)
                    <li class="list-group-item">{{ $s['text'] }} <strong>{{ $s['count'] }}</strong>@if ($s['examples']) <span class="text-muted">— مثل: {{ implode('، ', $s['examples']) }}</span>@endif</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($operation->isUndoable() && $preview['eligible']->isNotEmpty())
        <form method="POST" action="{{ route('bulk-invoices.undo', $operation) }}" class="card card-body">
            @csrf
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="undo-confirm" required><label class="form-check-label" for="undo-confirm">أؤكد إعادة إدراج {{ $preview['eligible']->count() }} فاتورة</label></div>
            <div><button class="btn btn-warning fw-bold"><i class="fas fa-rotate-left"></i> إعادة الإدراج</button></div>
        </form>
    @endif
@endsection
