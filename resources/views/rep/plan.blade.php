@extends('layouts.panel')

@section('title', 'خطتي')
@section('body_class', 'touch-ui')

@php
    $dayName = \App\Services\Reps\RepPlanService::dayName($date);
    $pct = $summary['planned'] > 0 ? round(($summary['visited'] + $summary['not_visited']) * 100 / $summary['planned']) : 0;
    $stateOf = function ($visit) {
        if (! $visit) return ['remaining', 'secondary', 'متبقية'];
        if ($visit->status === \App\Models\RepPlanVisit::NOT_VISITED) return ['not_visited', 'warning', $visit->statusText()];
        $variant = match ($visit->location_status) { 'matched' => 'success', 'mismatch' => 'danger', default => 'info' };
        return [$visit->location_status === 'mismatch' ? 'mismatch' : 'visited', $variant, $visit->statusText()];
    };
@endphp

@push('styles')
    <style>
        .plan-week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .25rem; }
        .plan-week a { text-decoration: none; padding: .35rem 0; border-radius: .5rem; font-size: .8rem; line-height: 1.2; }
        .plan-row .btn { min-height: 44px; }
        .plan-row { overflow-wrap: anywhere; }
    </style>
@endpush

@section('content')
    <x-page-header title="خطتي" :subtitle="$dayName . ' ' . $date . ($isToday ? ' (اليوم)' : '')" icon="fa-route" />

    {{-- إشعارات تعديل الخطة حتى «اطلعت» --}}
    @foreach ($notices as $notice)
        <div class="alert alert-info d-flex gap-2 align-items-start py-2 plan-notice">
            <i class="fas fa-bell mt-1"></i>
            <div class="flex-grow-1 small fw-bold">{{ $notice->text }}</div>
            <form method="POST" action="{{ route('rep.plan.notices.seen', $notice) }}" class="m-0">
                @csrf
                <button class="btn btn-sm btn-outline-primary fw-bold text-nowrap">اطلعت</button>
            </form>
        </div>
    @endforeach

    {{-- شريط الأيام --}}
    <div class="d-flex align-items-center gap-1 mb-2">
        <a href="{{ route('rep.plan', ['date' => $prevWeek]) }}" class="btn btn-light border btn-sm" aria-label="الأسبوع السابق"><i class="fas fa-chevron-right"></i></a>
        <div class="plan-week flex-grow-1 text-center">
            @foreach ($week as $d)
                <a href="{{ route('rep.plan', ['date' => $d]) }}" class="{{ $d === $date ? 'bg-primary text-white fw-bold' : ($d === $today ? 'border border-primary text-primary' : 'bg-body-tertiary text-body') }}">
                    {{ \App\Services\Reps\RepPlanService::dayName($d) }}<br><span dir="ltr">{{ substr($d, 8, 2) }}</span>
                </a>
            @endforeach
        </div>
        <a href="{{ route('rep.plan', ['date' => $nextWeek]) }}" class="btn btn-light border btn-sm" aria-label="الأسبوع التالي"><i class="fas fa-chevron-left"></i></a>
    </div>
    @unless ($isToday)
        <a href="{{ route('rep.plan') }}" class="small d-inline-block mb-2"><i class="fas fa-calendar-day"></i> العودة إلى اليوم</a>
    @endunless

    {{-- ملخص اليوم --}}
    <div class="card mb-3" id="plan-summary">
        <div class="card-body p-3">
            @if ($plan === [])
                <div class="fw-bold" id="plan-empty">{{ $isToday ? 'لا خطة لك اليوم.' : 'لا خطة لك يوم ' . $dayName . ' ' . $date . '.' }}</div>
            @else
                <div class="fw-bold mb-2" id="plan-summary-text">
                    {{ $isToday ? 'اليوم' : 'يوم' }} {{ $dayName }}:
                    {{ collect($plan)->map(fn ($i) => $i['label'] . ' (المشرف ' . ($i['supervisor_name'] ?? '—') . ')')->implode('، ') }}
                    — <span data-sum="planned">{{ $summary['planned'] }}</span> صيدلية:
                    تمت زيارة <span data-sum="visited">{{ $summary['visited'] }}</span>،
                    لم تتم <span data-sum="not_visited">{{ $summary['not_visited'] }}</span>،
                    متبقٍّ <span data-sum="remaining">{{ $summary['remaining'] }}</span>
                </div>
                <div class="progress" role="progressbar" aria-label="نسبة الإنجاز" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100" style="height: .75rem">
                    <div class="progress-bar bg-success" data-progress style="width: {{ $pct }}%"></div>
                </div>
            @endif
            @if ($isFuture)
                <div class="small text-muted mt-2"><i class="fas fa-eye"></i> يوم قادم: للاطلاع فقط، لا تسجيل زيارة بتاريخ مستقبلي.</div>
            @elseif (! $canRecord)
                <div class="small text-muted mt-2"><i class="fas fa-lock"></i> انتهى وقت التسجيل لهذا اليوم (للاطلاع فقط). التصحيح عند المشرف.</div>
            @endif
        </div>
    </div>

    @if ($plan !== [] || $orphans->isNotEmpty())
        {{-- بحث ومرشّح --}}
        <div class="row g-2 mb-2">
            <div class="col-12 col-md-6"><input type="search" class="form-control" placeholder="ابحث باسم الصيدلية..." data-plan-search aria-label="بحث"></div>
            <div class="col-12 col-md-6">
                <div class="btn-group w-100 flex-wrap" role="group" aria-label="مرشّح">
                    @foreach (['all' => 'الكل', 'remaining' => 'المتبقي', 'visited' => 'تمت', 'not_visited' => 'لم تتم', 'mismatch' => 'غير مطابقة'] as $key => $label)
                        <button type="button" class="btn btn-sm btn-outline-secondary {{ $key === 'all' ? 'active' : '' }}" data-plan-filter="{{ $key }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
        </div>

        <div id="plan-list">
            @foreach ($plan as $item)
                <h2 class="h6 fw-bold mt-3 mb-2 d-flex flex-wrap gap-2 align-items-center plan-group">
                    <span><i class="fas fa-map-location-dot text-primary"></i> {{ $item['text'] }}</span>
                    <span class="badge text-bg-light border">المشرف {{ $item['supervisor_name'] ?? '—' }}</span>
                    <span class="badge text-bg-secondary">{{ count($item['customer_ids']) }} صيدلية</span>
                    @if ($item['source'] === 'override')<span class="badge text-bg-info">لهذا اليوم فقط</span>@endif
                </h2>
                @foreach (collect($item['customer_ids'])->map(fn ($id) => $customers[$id] ?? null)->filter()->sortBy('name', SORT_NATURAL) as $customer)
                    @include('rep.partials.plan-row', ['customer' => $customer, 'visit' => $visits[$customer->id] ?? null])
                @endforeach
            @endforeach

            @if ($orphans->isNotEmpty())
                <h2 class="h6 fw-bold mt-3 mb-2 text-muted"><i class="fas fa-circle-info"></i> زيارات مسجَّلة لصيدليات أُزيلت من خطة هذا اليوم (تبقى محتسبة)</h2>
                @foreach ($orphans as $visit)
                    @include('rep.partials.plan-row', ['customer' => $customers[$visit->customer_id], 'visit' => $visit, 'readonly' => true, 'orphan' => true])
                @endforeach
            @endif
        </div>
    @endif
@endsection

@push('modals')
    {{-- «لم تتم»: السبب --}}
    <div class="modal fade" id="planReasonModal" tabindex="-1" aria-labelledby="planReasonTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2"><h2 class="modal-title h6 fw-bold" id="planReasonTitle">لم تتم الزيارة: <span data-modal-name></span></h2>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body">
                    <div class="d-grid gap-2 mb-2">
                        @foreach (\App\Models\RepPlanVisit::REASONS as $code => $label)
                            <input type="radio" class="btn-check" name="plan_reason" id="reason-{{ $code }}" value="{{ $code }}" autocomplete="off">
                            <label class="btn btn-outline-secondary text-start fw-bold" for="reason-{{ $code }}">{{ $label }}</label>
                        @endforeach
                    </div>
                    <textarea class="form-control d-none" rows="2" maxlength="500" placeholder="اكتب السبب" data-reason-text></textarea>
                    <div class="text-danger small mt-2 d-none" data-modal-error></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">إلغاء</button>
                    <button type="button" class="btn btn-warning fw-bold" data-reason-save>حفظ «لم تتم»</button></div>
            </div>
        </div>
    </div>

    {{-- تأكيد (بعيد / دقة ضعيفة / بلا موقع / تغيير القرار) --}}
    <div class="modal fade" id="planConfirmModal" tabindex="-1" aria-labelledby="planConfirmTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2"><h2 class="modal-title h6 fw-bold" id="planConfirmTitle">تأكيد</h2></div>
                <div class="modal-body fw-bold" data-confirm-text></div>
                <div class="modal-footer flex-wrap">
                    <button type="button" class="btn btn-light border" data-bs-dismiss="modal" data-confirm-cancel>إلغاء</button>
                    <button type="button" class="btn btn-outline-primary fw-bold d-none" data-confirm-retry><i class="fas fa-rotate"></i> أعد المحاولة</button>
                    <button type="button" class="btn btn-primary fw-bold" data-confirm-ok>نعم، سجّل الزيارة</button>
                </div>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
    <script>window.RepPlanConfig = @json($config);</script>
    <script type="module" src="{{ asset('js/rep/rep-plan.js') }}?v={{ @filemtime(public_path('js/rep/rep-plan.js')) }}"></script>
@endpush
