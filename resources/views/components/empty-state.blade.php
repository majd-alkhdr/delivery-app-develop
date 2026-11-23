{{-- حالة فارغة: لا توجد بيانات للعرض --}}
@props([
    'title',
    'message' => null,
    'icon' => 'fa-folder-open',
])

<div {{ $attributes->merge(['class' => 'text-center py-5 px-3 text-muted']) }}>
    <i class="fas {{ $icon }} d-block mb-3 text-secondary opacity-50" style="font-size: 2.75rem;"></i>
    <p class="fw-bold text-secondary mb-1">{{ $title }}</p>
    @if ($message)
        <p class="small mb-0">{{ $message }}</p>
    @endif
    {{ $slot }}
</div>
