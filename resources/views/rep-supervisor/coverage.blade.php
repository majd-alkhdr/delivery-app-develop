@extends('layouts.panel')

@section('title', 'تغطية المندوبين')

@php
    $pct = fn ($a, $b) => \App\Services\Reps\RepCoverageService::pct($a, $b);
    $query = array_filter($filters, fn ($v) => $v !== null && $v !== '');
@endphp

@section('content')
    <x-page-header title="تغطية المندوبين" :subtitle="'من ' . $from . ' إلى ' . $to . ($owner_id && auth()->user()->isAdmin() ? ' — مشرف محدد' : '')" icon="fa-chart-column">
        <a href="{{ route('rep-supervisor.coverage.export', $query) }}" class="btn btn-success fw-bold"><i class="fas fa-file-excel"></i> تصدير Excel</a>
    </x-page-header>

    {{-- المرشّحات --}}
    <form method="GET" class="card card-body mb-3 row g-2 flex-row align-items-end" id="coverage-filters">
        <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="cv-rep">المندوب</label>
            <select name="rep_id" id="cv-rep" class="form-select"><option value="">الكل</option>
                @foreach ($repOptions as $r)<option value="{{ $r->id }}" @selected($filters['rep_id'] == $r->id)>{{ $r->name }}</option>@endforeach
            </select></div>
        @if ($supervisorOptions->isNotEmpty())
            <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="cv-sup">المشرف</label>
                <select name="supervisor_id" id="cv-sup" class="form-select"><option value="">كل المشرفين</option>
                    @foreach ($supervisorOptions as $s)<option value="{{ $s->id }}" @selected($filters['supervisor_id'] == $s->id)>{{ $s->name }}</option>@endforeach
                </select></div>
        @endif
        <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="cv-period">الفترة</label>
            <select name="period" id="cv-period" class="form-select">
                @foreach ($periods as $k => $label)<option value="{{ $k }}" @selected($filters['period'] === $k)>{{ $label }}</option>@endforeach
            </select></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="cv-from">من (للنطاق)</label><input type="date" name="from" id="cv-from" class="form-control" value="{{ $filters['period'] === 'range' ? $from : '' }}"></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="cv-to">إلى</label><input type="date" name="to" id="cv-to" class="form-control" value="{{ $filters['period'] === 'range' ? $to : '' }}"></div>
        <div class="col-6 col-md-2"><label class="form-label small fw-bold" for="cv-scope">النطاق</label>
            <select name="scope" id="cv-scope" class="form-select"><option value="">الكل</option>
                @foreach ($scopeOptions as $k => $label)<option value="{{ $k }}" @selected($filters['scope'] === $k)>{{ $label }}</option>@endforeach
            </select></div>
        <div class="col-12 col-md-auto d-grid"><button class="btn btn-primary fw-bold">عرض</button></div>
    </form>

    {{-- التنبيهات --}}
    @foreach ($alerts as $alert)
        <div class="alert alert-{{ $alert['level'] }} py-2 fw-bold coverage-alert"><i class="fas fa-bell"></i> {{ $alert['text'] }}</div>
    @endforeach

    {{-- الإجمالي --}}
    <div class="card card-body mb-3 small" id="coverage-totals">
        <div class="d-flex flex-wrap gap-3">
            <span>المخطَّط: <b>{{ $totals['planned'] }}</b></span>
            <span>المزارة: <b>{{ $totals['visited'] }}</b></span>
            <span>لم تُزَر: <b>{{ $totals['not_visited'] }}</b></span>
            <span>المتبقية: <b>{{ $totals['remaining'] }}</b></span>
            <span>نسبة التغطية: <b>{{ $pct($totals['visited'], $totals['planned']) ?? '—' }}%</b></span>
            <span>المطابقة: <b>{{ $totals['location']['matched'] }}</b> ({{ $pct($totals['location']['matched'], $totals['visited']) ?? '—' }}% من المزارة)</span>
        </div>
    </div>

    @include('rep-supervisor.partials.coverage-cards', ['cards' => $cards, 'reps' => $reps])

    {{-- مقارنة الأسابيع (SVG بلا مكتبات) --}}
    @if (count($weekly) > 1)
        @php $barW = 48; $h = 120; @endphp
        <div class="card mb-3" id="coverage-weeks">
            <div class="card-header bg-white fw-bold py-2">نسبة التغطية لكل أسبوع</div>
            <div class="card-body overflow-auto">
                <svg width="{{ count($weekly) * ($barW + 16) }}" height="{{ $h + 40 }}" role="img" aria-label="نسبة التغطية لكل أسبوع">
                    @foreach ($weekly as $i => $w)
                        @php $p = $pct($w['visited'], $w['planned']) ?? 0; $bh = round($h * $p / 100); $x = $i * ($barW + 16) + 8; @endphp
                        <rect x="{{ $x }}" y="{{ $h - $bh + 14 }}" width="{{ $barW }}" height="{{ $bh }}" rx="4" fill="var(--bs-success)"><title>{{ $w['week'] }}: {{ $p }}%</title></rect>
                        <text x="{{ $x + $barW / 2 }}" y="{{ $h - $bh + 10 }}" text-anchor="middle" font-size="11" fill="currentColor">{{ $p }}%</text>
                        <text x="{{ $x + $barW / 2 }}" y="{{ $h + 30 }}" text-anchor="middle" font-size="10" fill="currentColor">{{ substr($w['week'], 5) }}</text>
                    @endforeach
                </svg>
            </div>
        </div>
    @endif

    {{-- الجدول اليومي --}}
    <div class="card mb-3">
        <div class="card-header bg-white fw-bold py-2">الجدول اليومي</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small" id="coverage-daily">
                <thead class="table-light"><tr><th>اليوم</th><th>المندوب</th><th>النطاق</th><th>المخطَّط</th><th>المزارة</th><th>النسبة</th><th>المطابقة</th><th></th></tr></thead>
                <tbody>
                    @forelse ($daily as $d)
                        <tr>
                            <td class="text-nowrap">{{ \App\Services\Reps\RepPlanService::dayName($d['date']) }} {{ $d['date'] }}</td>
                            <td>{{ $reps[$d['rep_id']]->name ?? '' }}</td>
                            <td>{{ implode('، ', $d['scopes']) }}</td>
                            <td>{{ $d['planned'] }}</td>
                            <td>{{ $d['visited'] }}</td>
                            <td>{{ $pct($d['visited'], $d['planned']) ?? '—' }}%</td>
                            <td>{{ $d['location']['matched'] }}</td>
                            <td><a href="{{ route('rep-supervisor.coverage.day', ['rep' => $d['rep_id'], 'date' => $d['date']] + ($filters['supervisor_id'] ? ['supervisor_id' => $filters['supervisor_id']] : [])) }}" class="text-nowrap">تفاصيل اليوم</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-muted">لا أيام بخطة في هذه الفترة.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($noLocation)
        <div class="card mb-3" id="coverage-no-location">
            <div class="card-header bg-white fw-bold py-2"><i class="fas fa-location-dot text-warning"></i> صيدليات في الخطة بلا موقع محفوظ ({{ count($noLocation) }})</div>
            <div class="card-body small">
                {{ collect($noLocation)->map(fn ($c) => $c['name'] . ($c['area'] ? ' (' . $c['area'] . ')' : ''))->implode('، ') }}
                <div class="text-muted mt-1">يثبّت الموقع الموزعُ أثناء زيارته، أو المشرف من «المواقع». المندوب لا يغيّر موقع صيدلية من خطته.</div>
            </div>
        </div>
    @endif
@endsection
