{{-- شارة إسناد واحد بعدد صيدلياته، وزرّا النقل والإنهاء (شاشة إسناد المندوبين) --}}
@php
    $count = $counts[$a->id] ?? 0;
    $plans = $planCounts[$a->id] ?? null;
    $summary = $a->scopeText().' للمندوب '.$a->rep?->name.' — عند '.$a->supervisor?->name.' — يشمل '.$count.' صيدلية'
        .($plans !== null ? ' و'.$plans.' بند خطة' : '').'.';
@endphp
<span class="border rounded px-2 py-1 small d-inline-flex align-items-center gap-1" data-assignment="{{ $a->id }}">
    @if (! empty($withSupervisor))<span class="text-muted">{{ $a->supervisor?->name }}:</span>@endif
    <span class="fw-bold">{{ $a->scopeText() }}</span>
    <span class="text-muted">({{ $count }} صيدلية)</span>
    <button type="button" class="btn btn-link btn-sm p-0 ms-1" title="نقل لمشرف آخر" aria-label="نقل لمشرف آخر"
        data-bs-toggle="modal" data-bs-target="#moveModal" data-move-url="{{ route('admin.rep-assignments.move', $a) }}" data-move-summary="{{ $summary }}">
        <i class="fas fa-right-left"></i>
    </button>
    <form method="POST" action="{{ route('admin.rep-assignments.end', $a) }}" class="d-inline m-0" onsubmit="return confirm({{ \Illuminate\Support\Js::from('إنهاء الإسناد: '.$a->scopeText().'؟'.($plans ? ' سيتعطّل '.$plans.' بنداً من خطة المندوب لهذا النطاق (لا تُحذف).' : '')) }})">
        @csrf
        <button class="btn btn-link btn-sm p-0 text-danger" title="إنهاء الإسناد" aria-label="إنهاء الإسناد"><i class="fas fa-xmark"></i></button>
    </form>
</span>
