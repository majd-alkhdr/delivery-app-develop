{{-- المرحلة 7H: كشف «ملحق» ← رابط الكشف الأصل، والكشف الأصل ← ملاحقه. $route: مسار عرض الكشف لهذا الدور (null = بلا رابط) --}}
@php
    $route = $route ?? null;
    $supplements = $sheet->isSupplement() ? collect() : \App\Models\DistributionSheet::where('parent_sheet_id', $sheet->id)->orderBy('supplement_no')->get();
    $link = fn ($s) => $route ? '<a href="' . e(route($route, $s->id)) . '">' . e($s->shortLabel()) . '</a>' : e($s->shortLabel());
@endphp
@if ($sheet->isSupplement() && $sheet->parent)
    <div class="alert alert-info py-2 small mb-3 supplement-line">
        <i class="fas fa-paperclip me-1"></i> ملحق {{ $sheet->supplement_no }} لكشف {!! $link($sheet->parent) !!} ({{ $sheet->parent->statusLabel() }}): أُنشئ لفواتير جديدة وصلت بعد اكتمال الكشف الأصل.
    </div>
@elseif ($supplements->isNotEmpty())
    <div class="alert alert-light border py-2 small mb-3 supplement-line">
        <i class="fas fa-paperclip me-1"></i> ملاحق هذا الأمر:
        @foreach ($supplements as $s){!! $link($s) !!} ({{ $s->statusLabel() }})@if (! $loop->last)، @endif @endforeach
    </div>
@endif
