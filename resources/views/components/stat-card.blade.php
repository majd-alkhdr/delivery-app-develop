{{-- بطاقة إحصائية صغيرة. valueId يُستخدم عندما تحدّث السكربتات القيمة مباشرة --}}
@props([
    'label',
    'value',
    'icon' => null,
    'variant' => 'primary',
    'valueId' => null,
])

<div {{ $attributes->merge(['class' => "card stat-card text-bg-{$variant}"]) }}>
    <div class="card-body p-2 d-flex flex-column justify-content-center">
        <span class="stat-label">
            @if ($icon)
                <i class="fas {{ $icon }} me-1"></i>
            @endif
            {{ $label }}
        </span>
        <p class="stat-value" @if ($valueId) id="{{ $valueId }}" @endif>{{ $value }}</p>
    </div>
</div>
