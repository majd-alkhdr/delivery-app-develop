@extends('layouts.panel')

@section('title', 'خطط المندوبين')

@section('content')
    <x-page-header title="خطط المندوبين" subtitle="خطة أسبوعية متكررة لكل مندوب ضمن نطاقاتك، مع تعديل بتاريخ محدد" icon="fa-route">
        <a href="{{ route('rep-supervisor.coverage') }}" class="btn btn-outline-primary fw-bold"><i class="fas fa-chart-column"></i> التغطية</a>
    </x-page-header>

    @if ($disabled > 0)
        <div class="alert alert-warning fw-bold" id="plan-disabled-alert">
            <i class="fas fa-triangle-exclamation"></i> {{ $disabled }} بنداً في الخطط تعطّل لأن نطاقه بلا مشرف (لم يُحذف). أعد إسناد النطاق من «إسناد المندوبين» ثم أضفه للخطة.
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0" id="plan-reps">
                <thead class="table-light"><tr><th>المندوب</th><th>بنود الخطة الأسبوعية</th><th>اليوم: المخطَّط / المزارة</th><th></th></tr></thead>
                <tbody>
                    @forelse ($reps as $rep)
                        @php $t = $today[$rep->id] ?? null; @endphp
                        <tr data-rep="{{ $rep->id }}">
                            <td class="fw-bold">{{ $rep->name }}</td>
                            <td>{{ $items[$rep->id] ?? 0 }}</td>
                            <td>@if ($t){{ $t['planned'] }} / {{ $t['visited'] }}@else<span class="text-muted">لا خطة اليوم</span>@endif</td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('rep-supervisor.plan.show', $rep) }}" class="btn btn-sm btn-primary fw-bold">الخطة</a>
                                <a href="{{ route('rep-supervisor.coverage', ['rep_id' => $rep->id]) }}" class="btn btn-sm btn-outline-secondary fw-bold">التغطية</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">لا مندوبين مسنَدين إليك. الإسناد من الأدمن في «إسناد المندوبين».</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
