{{-- شارة سلسلة الفاتورة: الاسم من جدول invoice_series ولون ثابت لكل سلسلة --}}
@props(['series'])

@php
    $label = \App\Models\InvoiceSeries::labelFor($series);
    $color = \App\Models\InvoiceSeries::colorFor($series);
@endphp

@if ($series)
    <span {{ $attributes->merge(['class' => 'badge text-white text-wrap']) }} style="background-color: {{ $color }};"
        title="السلسلة: {{ $series }} — {{ $label }}" data-bs-toggle="tooltip">{{ $label }}</span>
@else
    <span {{ $attributes->merge(['class' => 'badge text-bg-secondary']) }}>-</span>
@endif
