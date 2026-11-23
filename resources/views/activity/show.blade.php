@extends('layouts.panel')

@section('title', 'تفاصيل حدث')

@section('content')
    <x-page-header title="تفاصيل حدث" :subtitle="$log->actionLabel()" icon="fa-clock-rotate-left">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('activity.index') }}" class="btn btn-sm btn-light border fw-bold">
            <i class="fas fa-arrow-right"></i> {{ $compact ? 'نشاطي' : 'سجل النشاط' }}
        </a>
    </x-page-header>

    <div class="card">
        <div class="card-body p-3 p-md-4">
            <dl class="row mb-3 small">
                <dt class="col-4 col-md-2 text-muted">الوقت</dt>
                <dd class="col-8 col-md-10 font-monospace">{{ $log->created_at?->format('Y-m-d H:i:s') }}</dd>
                <dt class="col-4 col-md-2 text-muted">المنفّذ</dt>
                <dd class="col-8 col-md-10 fw-bold" id="log-actor">
                    {{ $log->user->name ?? ($log->user_id ? '#' . $log->user_id . ' (محذوف)' : 'النظام') }}
                    @if ($log->user)<span class="badge {{ $log->user->role->badgeClass() }}">{{ $log->user->role->label() }}</span>@endif
                </dd>
                <dt class="col-4 col-md-2 text-muted">الحدث</dt>
                <dd class="col-8 col-md-10">{{ $log->actionLabel() }} @if ($log->subject_id)<span class="text-muted">#{{ $log->subject_id }}</span>@endif</dd>
                @unless ($compact)
                    <dt class="col-4 col-md-2 text-muted">IP</dt>
                    <dd class="col-8 col-md-10 font-monospace text-break">{{ $log->ip ?? '—' }}</dd>
                @endunless
            </dl>
            <div class="small">@include('activity.partials.details', ['log' => $log])</div>
        </div>
    </div>
@endsection
