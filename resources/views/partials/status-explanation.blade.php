{{-- سطر يشرح حالة الكشف تحت شارة الحالة (صفحة الكشف للمشرف ومسؤول الكشوف) --}}
@php
    $statusHint = \App\Models\DistributionSheet::STATUS_HINTS[$sheet->status] ?? null;
    $needsAssignment = $sheet->status === 'pending' && $sheet->isV2() && $sheet->assignments->isEmpty();
@endphp
@if ($statusHint)
    <p class="small text-muted mb-3" id="status-explanation">
        <i class="fas fa-circle-info me-1"></i>{{ $statusHint }}
        @if ($needsAssignment)
            <strong class="text-warning-emphasis">ابدأ بإسناد المناطق لموزعين.</strong>
        @endif
    </p>
@endif
