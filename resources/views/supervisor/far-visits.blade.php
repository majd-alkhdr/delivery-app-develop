@extends('layouts.panel')

@section('title', 'زيارات بعيدة عن الموقع')

@php
    $flagLabels = ['far' => ['بعيد عن الموقع', 'text-bg-danger'], 'no_reference' => ['بلا مرجع مؤكد', 'text-bg-warning']];
@endphp

@section('content')
    <x-page-header title="زيارات بعيدة عن الموقع" subtitle="زيارات ودفعات سُجّلت بعيداً عن موقع الصيدلية المرجعي أو بلا مرجع مؤكد" icon="fa-location-crosshairs" />

    <form method="GET" action="{{ route('supervisor.far-visits') }}" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label for="f_date" class="form-label small fw-bold mb-1">التاريخ</label>
                <input type="date" name="date" id="f_date" class="form-control" value="{{ request('date') }}">
            </div>
            <div class="col-md-4">
                <label for="f_user" class="form-label small fw-bold mb-1">الموزع</label>
                <select name="user_id" id="f_user" class="form-select">
                    <option value="">كل الموزعين</option>
                    @foreach ($distributors as $d)
                        <option value="{{ $d->id }}" @selected(request('user_id') == $d->id)>{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label for="f_flag" class="form-label small fw-bold mb-1">العلامة</label>
                <select name="flag" id="f_flag" class="form-select">
                    <option value="">الكل</option>
                    <option value="far" @selected(request('flag') === 'far')>بعيد عن الموقع</option>
                    <option value="no_reference" @selected(request('flag') === 'no_reference')>بلا مرجع مؤكد</option>
                </select>
            </div>
            <div class="col-md-2 d-grid"><button class="btn btn-primary fw-bold"><i class="fas fa-filter"></i> تطبيق</button></div>
            <div class="col-12 d-flex flex-wrap gap-3 align-items-center small">
                <label class="form-check-label fw-bold">
                    <input type="checkbox" name="recent_pins" value="1" class="form-check-input" @checked(request()->boolean('recent_pins'))>
                    مراجع ثبّتها الموزعون حديثاً
                </label>
                <label class="d-flex align-items-center gap-1">خلال آخر
                    <input type="number" name="days" min="1" max="365" class="form-control form-control-sm" style="width: 80px" value="{{ request('days', 7) }}"> يوماً
                </label>
            </div>
        </div>
    </form>

    @isset($recentPins)
        <div class="card mb-4 overflow-hidden" id="recent-pins">
            <div class="card-header bg-white fw-bold py-3">مراجع ثبّتها الموزعون خلال آخر {{ $days }} يوماً ({{ $recentPins->total() }})</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="table-light"><tr><th class="ps-3">الصيدلية</th><th>المنطقة</th><th>ثبّته</th><th>متى</th><th>الدقة</th><th>الموقع</th><th class="pe-3"></th></tr></thead>
                    <tbody>
                        @forelse ($recentPins as $c)
                            @php
                                $lastVisit = $lastVisits->get($c->id);
                                $lastPayment = $lastPayments->get($c->id);
                                $source = collect([$lastVisit ? ['visit', $lastVisit, $lastVisit->visited_at] : null, $lastPayment ? ['payment', $lastPayment, $lastPayment->collected_at] : null])
                                    ->filter()->sortByDesc(fn ($s) => $s[2])->first();
                            @endphp
                            <tr class="recent-pin-row">
                                <td class="ps-3 fw-bold">{{ $c->name }}</td>
                                <td>{{ $c->area ?: '—' }}</td>
                                <td>{{ $c->pinnedBy?->name ?? '—' }}</td>
                                <td class="font-monospace text-nowrap">{{ $c->location_pinned_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d g:i A') }}</td>
                                <td class="font-monospace">{{ $c->location_accuracy_m !== null ? $c->location_accuracy_m . ' م' : '—' }}</td>
                                <td><a href="https://maps.google.com/?q={{ $c->latitude }},{{ $c->longitude }}" target="_blank" rel="noopener" class="font-monospace" dir="ltr">{{ number_format((float) $c->latitude, 5) }}, {{ number_format((float) $c->longitude, 5) }}</a></td>
                                <td class="pe-3 text-end">
                                    @if ($source)
                                        <form action="{{ route('supervisor.locations.pin') }}" method="POST" class="m-0">
                                            @csrf
                                            <input type="hidden" name="type" value="{{ $source[0] }}">
                                            <input type="hidden" name="id" value="{{ $source[1]->id }}">
                                            <button class="btn btn-sm btn-outline-primary fw-bold" onclick="return confirm('إعادة تثبيت المرجع من آخر سجل بموقع لهذه الصيدلية؟')">
                                                <i class="fas fa-thumbtack"></i> إعادة تثبيت
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted ps-3">لا مراجع ثبّتها الموزعون في هذه المدة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($recentPins->hasPages())
                <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $recentPins->links() }}</div>
            @endif
        </div>
    @endisset

    @foreach (isset($recentPins) ? [] : [['الزيارات', $visits, 'visit'], ['الدفعات', $payments, 'payment']] as [$title, $rows, $type])
        <div class="card mb-4 overflow-hidden">
            <div class="card-header bg-white fw-bold py-3">{{ $title }} ({{ $rows->total() }})</div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th class="ps-3">الصيدلية</th><th>الموزع</th><th>الوقت</th><th>الكشف</th><th>المسافة</th><th>العلامة</th><th class="pe-3"></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @php [$flagText, $flagClass] = $flagLabels[$row->location_flag] ?? [$row->location_flag, 'text-bg-light']; @endphp
                            <tr>
                                <td class="ps-3 fw-bold">
                                    {{ $row->customer?->name }}
                                    @if ($row->customer?->location_confirmed)
                                        <span class="badge bg-success-subtle text-success-emphasis">مرجع مؤكد</span>
                                    @endif
                                </td>
                                <td>{{ $type === 'visit' ? $row->user?->name : $row->enteredBy?->name }}</td>
                                <td class="font-monospace text-nowrap">{{ ($type === 'visit' ? $row->visited_at : $row->collected_at)?->format('Y-m-d g:i A') }}</td>
                                <td>{{ $row->sheet ? '#' . $row->sheet->orderLabel() : '—' }}</td>
                                <td class="font-monospace">{{ $row->distance_m !== null ? number_format($row->distance_m) . ' م' : '—' }}</td>
                                <td><span class="badge {{ $flagClass }}">{{ $flagText }}</span></td>
                                <td class="pe-3 text-end">
                                    <form action="{{ route('supervisor.locations.pin') }}" method="POST" class="m-0">
                                        @csrf
                                        <input type="hidden" name="type" value="{{ $type }}">
                                        <input type="hidden" name="id" value="{{ $row->id }}">
                                        <button class="btn btn-sm btn-outline-primary fw-bold" onclick="return confirm('تثبيت موقع هذا السجل كمرجع مؤكد للصيدلية؟')">
                                            <i class="fas fa-thumbtack"></i> تثبيت هذا الموقع كمرجع
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted ps-3">لا توجد سجلات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())
                <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $rows->links() }}</div>
            @endif
        </div>
    @endforeach
@endsection
