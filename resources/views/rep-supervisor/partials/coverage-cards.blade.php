{{-- المرحلة 8B: بطاقة لكل (مندوب، نطاق): المخطَّط والمزارة ولم تُزَر والمتبقية والنسبة، وحالات الموقع الخمس كلٌّ بعدّاده، وأسباب عدم الزيارة --}}
@php $pct = fn ($a, $b) => \App\Services\Reps\RepCoverageService::pct($a, $b); @endphp
<div class="row g-2 mb-3" id="coverage-cards">
    @forelse ($cards as $c)
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100 coverage-card" data-rep="{{ $c['rep_id'] }}" data-scope="{{ $c['scope_key'] }}">
                <div class="card-header bg-white py-2">
                    <span class="fw-bold">{{ $reps[$c['rep_id']]->name ?? '#' . $c['rep_id'] }}</span>
                    <span class="text-muted small">— {{ $c['scope'] }}</span>
                </div>
                <div class="card-body p-2 small">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <span class="badge text-bg-light border">المخطَّط <b data-k="planned">{{ $c['planned'] }}</b></span>
                        <span class="badge text-bg-success">المزارة <b data-k="visited">{{ $c['visited'] }}</b></span>
                        <span class="badge text-bg-warning">لم تُزَر <b data-k="not_visited">{{ $c['not_visited'] }}</b></span>
                        <span class="badge text-bg-secondary">المتبقية <b data-k="remaining">{{ $c['remaining'] }}</b></span>
                    </div>
                    <div class="fw-bold mb-1">نسبة التغطية: <span data-k="coverage">{{ $pct($c['visited'], $c['planned']) ?? '—' }}</span>%</div>
                    <div class="progress mb-2" style="height:.5rem" role="progressbar" aria-label="نسبة التغطية"><div class="progress-bar bg-success" style="width: {{ $pct($c['visited'], $c['planned']) ?? 0 }}%"></div></div>
                    <div class="mb-1">المطابقة للموقع: <b data-k="matched">{{ $c['location']['matched'] }}</b> (<span data-k="match_pct">{{ $pct($c['location']['matched'], $c['visited']) ?? '—' }}</span>% من المزارة)</div>
                    <div class="d-flex flex-wrap gap-1">
                        @foreach (\App\Models\RepPlanVisit::LOCATION_LABELS as $key => $label)
                            @continue($key === 'matched')
                            <span class="badge text-bg-{{ $key === 'mismatch' ? 'danger' : 'info' }}" data-loc="{{ $key }}">{{ $label }}: {{ $c['location'][$key] }}</span>
                        @endforeach
                    </div>
                    @if ($c['reasons'])
                        <div class="mt-2 text-muted">أسباب عدم الزيارة:
                            {{ collect($c['reasons'])->map(fn ($n, $code) => (\App\Models\RepPlanVisit::REASONS[$code] ?? $code) . ' ' . $n)->implode('، ') }}</div>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="col-12"><div class="card card-body text-muted">لا خطة ولا زيارات في هذه الفترة.</div></div>
    @endforelse
</div>
