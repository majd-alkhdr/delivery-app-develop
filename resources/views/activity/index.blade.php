@extends('layouts.panel')

@section('title', $compact ? 'نشاطي' : 'سجل النشاط')

@php
    $viewer = auth()->user();
    $subtitles = [
        'admin' => 'كل العمليات المسجلة في النظام، بما فيها الآلية',
        'supervisor' => 'عملياتك وعمليات الموزعين',
        'rep_supervisor' => 'عملياتك وعمليات المندوبين المسنَدين لك',
        'sheet_manager' => 'عملياتك وعمليات مشرف التوزيع والموزعين',
        'accountant' => 'عملياتك وعمليات كل الأدوار عدا مدير النظام',
        'rep' => 'عملياتك أنت فقط',
        'distributor' => 'عملياتك أنت فقط',
    ];
    $actionColors = [
        'auth.' => 'bg-secondary-subtle text-secondary-emphasis',
        'user.' => 'bg-info-subtle text-info-emphasis',
        'sheet.' => 'bg-primary-subtle text-primary-emphasis',
        'payment.' => 'bg-success-subtle text-success-emphasis',
        'invoice.' => 'bg-warning-subtle text-warning-emphasis',
        'batch.' => 'bg-danger-subtle text-danger-emphasis',
    ];
    $colorOf = function ($action) use ($actionColors) {
        foreach ($actionColors as $prefix => $cls) {
            if (str_starts_with($action, $prefix)) return $cls;
        }
        return 'bg-light text-dark';
    };
    $actorOf = fn ($log) => $log->user->name ?? ($log->user_id ? '#' . $log->user_id . ' (محذوف)' : 'النظام');
@endphp

@section('content')
    <x-page-header :title="$compact ? 'نشاطي' : 'سجل النشاط'" :subtitle="$subtitles[$viewer->role->value] ?? ''" icon="fa-clock-rotate-left" />

    <div class="card mb-3">
        <div class="card-body p-3 p-md-4">
            <form method="GET" action="{{ route('activity.index') }}" class="row g-2 g-md-3">
                @if ($users->count() > 1)
                    <div class="col-12 col-sm-6 col-lg-3">
                        <label for="f_user" class="form-label small fw-bold text-secondary mb-1">المستخدم:</label>
                        <select name="user_id" id="f_user" class="form-select">
                            <option value="">الكل</option>
                            @foreach ($users as $u)
                                <option value="{{ $u->id }}" @selected($userId === $u->id)>{{ $u->name }} — {{ $u->role->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="col-12 col-sm-6 col-lg-3">
                    <label for="f_action" class="form-label small fw-bold text-secondary mb-1">نوع الحدث:</label>
                    <select name="action" id="f_action" class="form-select">
                        <option value="">الكل</option>
                        @foreach ($actions as $key => $label)
                            <option value="{{ $key }}" @selected(request('action') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-lg-2">
                    <label for="f_from" class="form-label small fw-bold text-secondary mb-1">من تاريخ:</label>
                    <input type="date" name="date_from" id="f_from" class="form-control" value="{{ request('date_from') }}">
                </div>
                <div class="col-6 col-lg-2">
                    <label for="f_to" class="form-label small fw-bold text-secondary mb-1">إلى تاريخ:</label>
                    <input type="date" name="date_to" id="f_to" class="form-control" value="{{ request('date_to') }}">
                </div>
                <div class="col-12 col-lg-2 d-flex gap-2 align-items-end">
                    <button type="submit" class="btn btn-primary fw-bold flex-grow-1"><i class="fas fa-filter"></i> تطبيق</button>
                    <a href="{{ route('activity.index') }}" class="btn btn-light border" title="مسح الفلاتر" aria-label="مسح الفلاتر"><i class="fas fa-xmark"></i></a>
                </div>
            </form>
            <div class="small text-muted mt-2" id="activity-count">{{ number_format($logs->total()) }} حدث</div>
        </div>
    </div>

    @if ($compact)
        {{-- الجوال أولاً للموزع والمندوب: بطاقات مقروءة --}}
        <div class="d-grid gap-2" id="activity-cards">
            @forelse ($logs as $log)
                <a href="{{ route('activity.show', $log) }}" class="card text-decoration-none text-body activity-item" data-log-id="{{ $log->id }}">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                            <span class="badge {{ $colorOf($log->action) }} text-wrap text-start">{{ $log->actionLabel() }}</span>
                            <span class="small text-muted font-monospace text-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</span>
                        </div>
                        <div class="small">@include('activity.partials.details', ['log' => $log])</div>
                    </div>
                </a>
            @empty
                <x-empty-state icon="fa-clock-rotate-left" title="لا توجد أحداث" message="لا توجد أحداث تطابق الفلاتر الحالية." />
            @endforelse
        </div>
        @if ($logs->hasPages())
            <div class="mt-3 d-flex justify-content-center">{{ $logs->links() }}</div>
        @endif
    @else
        <div class="card overflow-hidden">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3 py-3">الوقت</th>
                                <th>المستخدم</th>
                                <th>الحدث</th>
                                <th>التفاصيل</th>
                                <th class="pe-3">IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($logs as $log)
                                <tr class="activity-item" data-log-id="{{ $log->id }}">
                                    <td class="ps-3 text-nowrap font-monospace text-muted">
                                        <a href="{{ route('activity.show', $log) }}" class="text-muted">{{ $log->created_at?->format('Y-m-d H:i') }}</a>
                                    </td>
                                    <td class="text-nowrap fw-semibold">
                                        {{ $actorOf($log) }}
                                        @if ($log->user)<span class="d-block fs-8 text-muted fw-normal">{{ $log->user->role->label() }}</span>@endif
                                    </td>
                                    <td class="text-nowrap">
                                        <span class="badge {{ $colorOf($log->action) }}">{{ $log->actionLabel() }}</span>
                                        @if ($log->subject_id)
                                            <span class="text-muted d-block fs-8 mt-1">#{{ $log->subject_id }}</span>
                                        @endif
                                    </td>
                                    <td style="min-width: 240px;">@include('activity.partials.details', ['log' => $log])</td>
                                    <td class="pe-3 text-muted font-monospace text-nowrap" title="{{ $log->user_agent }}">{{ $log->ip }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <x-empty-state icon="fa-clock-rotate-left" title="لا توجد أحداث" message="لا توجد أحداث تطابق الفلاتر الحالية." />
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($logs->hasPages())
                    <div class="p-3 bg-light d-flex justify-content-center border-top">
                        {{ $logs->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection
