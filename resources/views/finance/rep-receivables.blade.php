@extends('layouts.panel')

@section('title', 'ذمم المندوبين')

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $statusLabels = ['paid' => ['مسددة', 'text-bg-success'], 'partial' => ['جزئية', 'text-bg-warning'], 'unpaid' => ['غير مسددة', 'text-bg-danger']];
    $t = $report['totals'];
@endphp

@section('content')
    <x-page-header title="ذمم المندوبين" subtitle="ما بقي غير مسدد بعد الموزع على فواتير مراكز كلفة المندوبين في الكشوف المغلقة، وما حصّله المندوب منها" icon="fa-hand-holding-dollar">
        <a href="{{ route('finance.rep-receivables.export', request()->query()) }}" class="btn btn-success fw-bold btn-sm"><i class="fas fa-file-excel"></i> تصدير Excel</a>
    </x-page-header>
    @include('partials.rep-scope-line')3

    <div class="row g-2 mb-3">
        <div class="col-6 col-lg-2"><div class="card card-body py-2"><div class="small text-muted">الدَين الناشئ</div><div class="fw-bold font-monospace">{{ $money($t['arising']) }}</div></div></div>
        <div class="col-6 col-lg-2"><div class="card card-body py-2"><div class="small text-muted">المحصّل المُصدَّر</div><div class="fw-bold font-monospace">{{ $money($t['exported']) }}</div></div></div>
        <div class="col-6 col-lg-2"><div class="card card-body py-2"><div class="small text-muted">المحصّل المعلّق</div><div class="fw-bold font-monospace">{{ $money($t['pending']) }}</div></div></div>
        <div class="col-6 col-lg-2"><div class="card card-body py-2"><div class="small text-muted">المتبقي</div><div class="fw-bold font-monospace text-danger" id="rr-remaining">{{ $money($t['remaining']) }}</div></div></div>
        <div class="col-6 col-lg-2"><div class="card card-body py-2"><div class="small text-muted">أكبر من الدَين المعروف</div><div class="fw-bold font-monospace">{{ $money($t['surplus']) }}</div></div></div>
        <div class="col-6 col-lg-2"><div class="card card-body py-2"><div class="small text-muted">الصيدليات</div><div class="fw-bold">{{ $t['pharmacies'] }}</div></div></div>
    </div>

    {{-- المرحلة 7C: زيادة تحصيل بعد تعديل لاحق (لا تُصفَّر بصمت) والتعديلات اللاحقة على الذمم --}}
    @php $overLines = $report['groups']->flatMap(fn ($g) => $g['pharmacies']->flatMap(fn ($p) => collect($p['over_collected'] ?? [])->map(fn ($l) => $l + ['rep' => $g['name'], 'pharmacy' => $p['name']]))); @endphp
    @if ($overLines->isNotEmpty())
        <div class="card border-danger mb-3" id="rr-over-collected">
            <div class="card-header bg-danger-subtle fw-bold small">زيادة تحصيل بعد تعديل لاحق: {{ $money($t['over_collected'] ?? 0) }}</div>
            <ul class="list-group list-group-flush small">
                @foreach ($overLines as $l)<li class="list-group-item over-collected">{{ $l['rep'] }} — {{ $l['pharmacy'] }}: {{ $l['text'] }}</li>@endforeach
            </ul>
        </div>
    @endif
    @if (($adjustments ?? collect())->isNotEmpty())
        <details class="card card-body mb-3 small" id="rr-adjustments">
            <summary class="fw-bold">تعديلات لاحقة على ذمم المندوبين ({{ $adjustments->count() }})</summary>
            <div class="table-responsive mt-2">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>المندوب</th><th>الفاتورة</th><th>الصيدلية</th><th>القيمة</th><th>الذمة قبل ← بعد</th><th>الفائض</th><th>السبب</th><th>التاريخ</th></tr></thead>
                    <tbody>
                        @foreach ($adjustments as $a)
                            <tr class="rr-adjustment {{ $a->isApplied() ? '' : 'text-muted' }}">
                                <td>{{ $a->rep?->name ?? 'بلا مندوب' }}</td>
                                <td class="font-monospace" dir="ltr">{{ \App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $a->invoice?->invoice_number) }}</td>
                                <td>{{ $a->customer?->name }}</td>
                                <td class="font-monospace">{{ $money($a->old_amount) }} ← {{ $money($a->new_amount) }}</td>
                                <td class="font-monospace">{{ $a->rep_debt_before !== null ? $money($a->rep_debt_before) : '—' }} ← {{ $a->rep_debt_after !== null ? $money($a->rep_debt_after) : '—' }}</td>
                                <td class="font-monospace">{{ (float) $a->surplus > 0 ? $money($a->surplus) : '—' }}</td>
                                <td>{{ $a->reasonLabel() }}@unless ($a->isApplied()) <span class="badge text-bg-secondary">تُراجع عنه</span>@endunless</td>
                                <td dir="ltr">{{ $a->applied_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endif

    <form method="GET" action="{{ route('finance.rep-receivables') }}" class="card card-body mb-3">
        <div class="row g-2 align-items-end small">
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold mb-1" for="rr_rep">المندوب</label>
                <select name="rep_id" id="rr_rep" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($reps as $r)<option value="{{ $r->id }}" @selected((string) $filters['rep_id'] === (string) $r->id)>{{ $r->name }}</option>@endforeach
                    @if (empty($report['scope_label']))<option value="none" @selected($filters['rep_id'] === 'none')>بلا مندوب</option>@endif
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="rr_area">المنطقة</label>
                <select name="area" id="rr_area" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($areas as $a)<option value="{{ $a }}" @selected($filters['area'] === $a)>{{ $a }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="rr_age">العمر</label>
                <select name="age" id="rr_age" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach (['0-7' => '0–7 أيام', '8-30' => '8–30 يوماً', '31-60' => '31–60 يوماً', '61+' => 'أكثر من 60 يوماً'] as $k => $l)<option value="{{ $k }}" @selected($filters['age'] === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="rr_status">الحالة</label>
                <select name="status" id="rr_status" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($statusLabels as $k => [$l])<option value="{{ $k }}" @selected($filters['status'] === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="col-8 col-md-2"><label class="form-label fw-bold mb-1" for="rr_q">بحث</label><input type="search" name="q" id="rr_q" class="form-control form-control-sm" value="{{ $filters['q'] }}"></div>
            <div class="col-4 col-md-1 d-grid"><button class="btn btn-sm btn-primary fw-bold"><i class="fas fa-filter"></i></button></div>
        </div>
    </form>

    <div class="card mb-3 overflow-hidden">
        <div class="card-header bg-white fw-bold py-2 small">ملخص بالمندوب</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 small">
                <thead class="table-light"><tr><th class="ps-3">المندوب</th><th>الدَين الناشئ</th><th>المُصدَّر</th><th>المعلّق</th><th>المتبقي</th><th>الصيدليات</th><th>الأقدم (يوم)</th><th class="pe-3">أكبر من المعروف</th></tr></thead>
                <tbody>
                    @foreach ($report['groups'] as $g)
                        <tr class="rr-summary-row">
                            <td class="ps-3 fw-bold">@if ($g['rep_id'] && auth()->user()->hasRole('admin', 'rep_supervisor'))<a href="{{ route('rep-supervisor.reps.show', $g['rep_id']) }}" class="text-reset" data-rep-profile-link>{{ $g['name'] }}</a>@else{{ $g['name'] }}@endif <span class="text-muted fw-normal">{{ $g['label'] }}</span></td>
                            <td class="font-monospace">{{ $money($g['arising_cents'] / 100) }}</td>
                            <td class="font-monospace">{{ $money($g['exported_cents'] / 100) }}</td>
                            <td class="font-monospace">{{ $money($g['pending_cents'] / 100) }}</td>
                            <td class="font-monospace fw-bold">{{ $money($g['remaining_cents'] / 100) }}</td>
                            <td>{{ $g['open_pharmacies'] }}</td>
                            <td>{{ $g['oldest_days'] ?? '—' }}</td>
                            <td class="pe-3 font-monospace">{{ $money($g['surplus_cents'] / 100) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr><th class="ps-3">المندوب</th><th>الصيدلية</th><th>المنطقة</th><th>الفاتورة</th><th>التاريخ</th><th>العمر</th><th>الإجمالي</th><th>بعد الموزع</th><th>سدّده المندوب</th><th>المتبقي</th><th>الحالة</th><th class="pe-3">سُدّد من</th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php [$sl, $sc] = $statusLabels[$r['invoice']['status']]; @endphp
                        <tr class="rr-invoice-row">
                            <td class="ps-3">{{ $r['group']['name'] }}</td>
                            <td class="fw-bold">@if ($statementUrl = \App\Services\Reps\PharmacyStatementService::linkFor(auth()->user(), $r['pharmacy']['customer_id']))<a href="{{ $statementUrl }}" class="text-reset" data-statement-link>{{ $r['pharmacy']['name'] }}</a>@else{{ $r['pharmacy']['name'] }}@endif</td>
                            <td>{{ $r['pharmacy']['area'] }}</td>
                            <td class="font-monospace" dir="ltr">{{ $r['invoice']['label'] }}</td>
                            <td class="font-monospace text-nowrap">{{ $r['invoice']['date'] }}</td>
                            <td>{{ $r['invoice']['age_days'] }}</td>
                            <td class="font-monospace">{{ $money($r['invoice']['total_cents'] / 100) }}</td>
                            <td class="font-monospace">{{ $money($r['invoice']['base_cents'] / 100) }}</td>
                            <td class="font-monospace">{{ $money($r['invoice']['rep_paid_cents'] / 100) }}</td>
                            <td class="font-monospace fw-bold">{{ $money($r['invoice']['remaining_cents'] / 100) }}</td>
                            <td><span class="badge {{ $sc }}">{{ $sl }}</span></td>
                            <td class="pe-3">
                                @foreach ($r['invoice']['paid_by'] as $pb)
                                    <div class="text-nowrap">{{ $pb['book_receipt_no'] ?: $pb['receipt_number'] }} <span class="text-muted" dir="ltr">{{ $pb['date'] }}</span> — <span class="font-monospace">{{ $money($pb['cents'] / 100) }}</span>@unless ($pb['exported']) <span class="badge text-bg-light border">معلّقة</span>@endunless</div>
                                @endforeach
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="12"><x-empty-state title="لا ذمم" message="لا فواتير تطابق الفلترة." /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($rows->hasPages())
            <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $rows->links() }}</div>
        @endif
    </div>
@endsection
