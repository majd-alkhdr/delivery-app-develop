{{-- ترويسة الصفحة: عنوان ووصف اختياري، والأزرار تُمرَّر داخل المكوّن --}}
@props([
    'title',
    'subtitle' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'd-flex flex-wrap align-items-center justify-content-between gap-2 mb-3']) }}>
    <div class="min-w-0">
        <h1 class="h5 fw-bold mb-0 text-break-word">
            @if ($icon)
                <i class="fas {{ $icon }} text-primary me-1"></i>
            @endif
            {{ $title }}
        </h1>
        @if ($subtitle)
            <p class="text-muted small mb-0 mt-1 text-break-word">{{ $subtitle }}</p>
        @endif
    </div>

    @if (trim($slot) !== '')
        <div class="d-flex flex-wrap gap-2">
            {{ $slot }}
        </div>
    @endif
</div>
