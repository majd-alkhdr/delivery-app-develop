@extends('layouts.panel')

@section('title', 'تفاصيل يوم')

@php
    $maps = fn ($lat, $lng) => 'https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $lng;
@endphp

@section('content')
    <x-page-header :title="'تفاصيل يوم ' . $rep->name" :subtitle="\App\Services\Reps\RepPlanService::dayName($date) . ' ' . $date" icon="fa-list-check">
        <a href="{{ route('rep-supervisor.coverage', ['rep_id' => $rep->id, 'period' => 'range', 'from' => $date, 'to' => $date]) }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> التغطية</a>
    </x-page-header>

    @include('rep-supervisor.partials.coverage-cards', ['cards' => $cards, 'reps' => $reps])

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small" id="day-rows">
                <thead class="table-light"><tr><th>الصيدلية</th><th>النطاق</th><th>الحالة</th><th>الوقت</th><th>المسافة</th><th>الخريطة</th><th>تصحيح</th></tr></thead>
                <tbody>
                    @foreach ($rows as $r)
                        @php $v = $r['visit']; $c = $customers[$r['customer_id']] ?? null; @endphp
                        <tr data-customer="{{ $r['customer_id'] }}">
                            <td class="fw-bold">@if ($statementUrl = \App\Services\Reps\PharmacyStatementService::linkFor(auth()->user(), $r['customer_id']))<a href="{{ $statementUrl }}" data-statement-link>{{ $c?->name }}</a>@else{{ $c?->name }}@endif<div class="text-muted fw-normal">{{ $c?->area }}</div></td>
                            <td>{{ $r['scope'] }}</td>
                            <td>{{ $v ? $v->statusText() : 'متبقية (لم يُسجَّل لها شيء)' }}@if ($v?->corrected_at)<span class="badge text-bg-secondary ms-1">صُحّحت</span>@endif</td>
                            <td class="text-nowrap" dir="ltr">{{ $v?->visited_at?->copy()->setTimezone('Asia/Damascus')->format('H:i') ?? '—' }}</td>
                            <td>{{ $v?->distance_m !== null ? $v->distance_m . ' م' : '—' }}</td>
                            <td class="text-nowrap">
                                @if ($c?->latitude !== null && $c?->longitude !== null)<a href="{{ $maps($c->latitude, $c->longitude) }}" target="_blank" rel="noopener">الصيدلية</a>@endif
                                @if ($v?->lat !== null)
                                    · <a href="{{ $maps($v->lat, $v->lng) }}" target="_blank" rel="noopener">موقع الزيارة</a>
                                    @if ($v->pharmacy_lat !== null)
                                        · <a href="https://www.google.com/maps/dir/?api=1&origin={{ $v->lat }},{{ $v->lng }}&destination={{ $v->pharmacy_lat }},{{ $v->pharmacy_lng }}" target="_blank" rel="noopener">المسافة على الخريطة</a>
                                    @endif
                                @endif
                            </td>
                            <td>
                                @if ($v && ((int) $v->supervisor_user_id === auth()->id() || auth()->user()->isAdmin()))
                                    <form method="POST" action="{{ route('rep-supervisor.plan.visits.correct', $v) }}" class="d-flex flex-wrap gap-1 m-0">
                                        @csrf
                                        <select name="status" class="form-select form-select-sm" aria-label="الحالة">
                                            <option value="visited" @selected($v->status === 'visited')>تمت</option>
                                            <option value="not_visited" @selected($v->status === 'not_visited')>لم تتم</option>
                                        </select>
                                        <select name="reason_code" class="form-select form-select-sm" aria-label="السبب"><option value="">— السبب —</option>
                                            @foreach (\App\Models\RepPlanVisit::REASONS as $code => $label)<option value="{{ $code }}" @selected($v->reason_code === $code)>{{ $label }}</option>@endforeach
                                        </select>
                                        <input type="text" name="note" class="form-control form-control-sm" placeholder="سبب التصحيح (إلزامي)" required minlength="3" maxlength="500">
                                        <button class="btn btn-sm btn-outline-primary fw-bold">تصحيح</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
