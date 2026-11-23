{{-- شارة حالة الكشف: preparing / pending / in_progress / completed / draft --}}
@props(['status', 'reason' => null])

@php
    $map = [
        'preparing' => ['bg-info-subtle text-info-emphasis border-info-subtle', 'fa-screwdriver-wrench', 'قيد التجهيز'],
        'pending' => ['bg-secondary-subtle text-secondary-emphasis border-secondary-subtle', 'fa-hourglass-start', 'بانتظار البدء'],
        'in_progress' => ['bg-primary-subtle text-primary-emphasis border-primary-subtle', 'fa-truck-fast', 'قيد التحصيل'],
        'completed' => ['bg-success-subtle text-success-emphasis border-success-subtle', 'fa-lock', 'مكتمل ومغلق'],
        'draft' => ['bg-warning-subtle text-warning-emphasis border-warning-subtle', 'fa-file-pen', 'مسودة'],
    ];
    [$classes, $icon, $label] = $map[$status] ?? ['bg-light text-dark border-light-subtle', 'fa-circle-question', $status];
    // التلميح: شرح الحالة (title يعمل على الجوال أيضاً)، ومعه سبب المسودة إن وُجد
    $hint = \App\Models\DistributionSheet::STATUS_HINTS[$status] ?? null;
    $tooltip = trim(($hint ?? '') . ($reason ? ' السبب: ' . $reason : ''));
@endphp

<span {{ $attributes->merge(['class' => "badge border fw-bold px-2 py-2 {$classes}"]) }}
    @if ($tooltip !== '') title="{{ $tooltip }}" data-bs-toggle="tooltip" @endif>
    <i class="fas {{ $icon }} me-1"></i>{{ $label }}
</span>
