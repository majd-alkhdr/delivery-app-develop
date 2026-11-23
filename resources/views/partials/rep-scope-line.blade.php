{{--
    المرحلة 8A: سطر النطاق لمشرف المندوبين. الحصر مفعّل: «النطاق: … (N صيدلية)» كي يفهم لماذا الأرقام جزئية.
    الحصر مُطفأ: شارة «الحصر غير مفعّل: ترى كل المندوبين». لا يظهر لغير rep_supervisor.
    $scopeNote (اختياري): نص إضافي للشاشة (مثل توزيع التسديدات بالأقدم).
--}}
@php
    $__user = auth()->user();
    $__scope = \App\Services\Reps\RepScope::current($__user);
@endphp
@if ($__user?->role === \App\Enums\Role::RepSupervisor)
    @if ($__scope)
        <div class="small text-primary-emphasis bg-primary-subtle rounded px-2 py-1 mb-3 d-inline-block" data-rep-scope-line>
            <i class="fas fa-filter"></i> {{ $__scope->label() }}
            @isset($scopeNote)<div class="text-muted" data-rep-scope-note>{{ $scopeNote }}</div>@endisset
        </div>
    @else
        <div class="mb-3"><span class="badge text-bg-secondary" data-rep-scope-off>الحصر غير مفعّل: ترى كل المندوبين</span></div>
    @endif
@endif
