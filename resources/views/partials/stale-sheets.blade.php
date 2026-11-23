{{-- تنبيه: كشوف مفتوحة (pending/in_progress) تاريخ توزيعها قبل اليوم --}}
@php $staleSheets = app(\App\Services\StaleSheetsService::class)->list(); @endphp
@if ($staleSheets->isNotEmpty())
    <div class="alert alert-warning mb-3" id="stale-sheets">
        <div class="fw-bold mb-2"><i class="fas fa-triangle-exclamation me-1"></i> كشوف مفتوحة منذ أكثر من يوم ({{ $staleSheets->count() }}) — أغلقها حتى لا تُربط بها دفعات خارجية جديدة</div>
        <ul class="mb-0 small">
            @foreach ($staleSheets as $item)
                <li class="stale-sheet-row">
                    <a href="{{ route(auth()->user()->hasRole(\App\Enums\Role::Accountant) ? 'accountant.sheet.show' : 'supervisor.sheet.show', $item['sheet']->id) }}" class="fw-bold">
                        أمر التسليم #{{ $item['sheet']->orderLabel() }}
                    </a>
                    — {{ $item['sheet']->distribution_date->format('Y-m-d') }}
                    — متأخر {{ $item['days_late'] }} يوم
                    — {{ $item['payments'] }} دفعة
                    — {{ $item['sheet']->distributorsLabel() }}
                </li>
            @endforeach
        </ul>
    </div>
@endif
