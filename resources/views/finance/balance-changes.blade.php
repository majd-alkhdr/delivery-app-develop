@extends('layouts.panel')

@section('title', 'تغيّرات الأرصدة')

@php
    $fmt = fn ($v) => number_format((float) $v, 2);
@endphp

@section('content')
    <x-page-header title="تغيّرات الأرصدة" subtitle="كل تغيّر في رصيد صيدلية بين ملفي أرصدة مع تفسيره التقديري" icon="fa-scale-balanced" />
    @include('partials.rep-scope-line')3

    <div class="alert alert-info small py-2"><i class="fas fa-circle-info me-1"></i> {{ \App\Models\CustomerBalanceSnapshot::METHOD_NOTE }}</div>

    <div class="row g-2 mb-3" id="balance-changes-summary">
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">عدد التغيّرات</div><div class="fw-bold fs-5" id="s-count">{{ $summary['count'] }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">مجموع الزيادات</div><div class="fw-bold fs-5 font-monospace text-danger" id="s-increase">{{ $fmt($summary['increase']) }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">مجموع النقصان</div><div class="fw-bold fs-5 font-monospace text-success" id="s-decrease">{{ $fmt($summary['decrease']) }}</div></div></div>
        <div class="col-6 col-lg-3"><div class="card card-body py-2"><div class="small text-muted">غير مفسَّرة (تحتاج تحققاً)</div><div class="fw-bold fs-5 text-danger" id="s-flagged">{{ $summary['flagged'] }}</div></div></div>
    </div>
    <p class="small text-muted mb-3" id="scope-note">
        اللقطات الخارجية لا تحتاج تحققاً لأنها تغيّر من مبيعات أو تحصيل خارج التطبيق.
        تغيّرات خارجية ضمن المرشّح: <span class="fw-bold" id="s-external">{{ $summary['external'] }}</span>
        @unless (in_array(request('scope'), ['external', 'all'], true))
            — <a href="{{ route('finance.balance-changes', array_merge(request()->query(), ['scope' => 'external'])) }}">عرضها</a>
        @endunless
    </p>

    @include('partials.unreflected-payments', ['unreflected' => $unreflected])

    <form method="GET" action="{{ route('finance.balance-changes') }}" class="card card-body mb-3">
        <div class="row g-2 align-items-end small">
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="bc_status">الحالة</label>
                <select name="status" id="bc_status" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    <option value="flagged" @selected(request('status') === 'flagged')>تحتاج تحققاً</option>
                    <option value="ok" @selected(request('status') === 'ok')>مفسَّرة</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="bc_scope">النطاق</label>
                <select name="scope" id="bc_scope" class="form-select form-select-sm">
                    <option value="">مرتبط بالتطبيق + المشتبه بها</option>
                    <option value="app" @selected(request('scope') === 'app')>مرتبط بالتطبيق</option>
                    <option value="external" @selected(request('scope') === 'external')>خارجي</option>
                    <option value="all" @selected(request('scope') === 'all')>الكل</option>
                </select>
            </div>
            @if (request('kind') || request('import'))
                <input type="hidden" name="kind" value="{{ request('kind') }}">
                <input type="hidden" name="import" value="{{ request('import') }}">
            @endif
            <div class="col-6 col-md-2"><label class="form-label fw-bold mb-1" for="bc_from">من</label><input type="date" name="from" id="bc_from" class="form-control form-control-sm" value="{{ request('from') }}"></div>
            <div class="col-6 col-md-2"><label class="form-label fw-bold mb-1" for="bc_to">إلى</label><input type="date" name="to" id="bc_to" class="form-control form-control-sm" value="{{ request('to') }}"></div>
            <div class="col-6 col-md-2"><label class="form-label fw-bold mb-1" for="bc_q">الزبون</label><input type="search" name="q" id="bc_q" class="form-control form-control-sm" value="{{ request('q') }}"></div>
            <div class="col-6 col-md-2">
                <label class="form-label fw-bold mb-1" for="bc_rep">المندوب</label>
                <select name="rep_id" id="bc_rep" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($reps as $rep)
                        <option value="{{ $rep->id }}" @selected((string) request('rep_id') === (string) $rep->id)>{{ $rep->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-1 form-check ms-2">
                <input type="checkbox" class="form-check-input" name="unreviewed" value="1" id="bc_unreviewed" @checked(request('unreviewed'))>
                <label class="form-check-label" for="bc_unreviewed">غير مراجَع</label>
            </div>
            <div class="col-12 col-md-1 d-grid"><button class="btn btn-sm btn-primary fw-bold">تصفية</button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small" id="balance-changes-table">
                <thead class="table-light">
                    <tr><th>الزبون</th><th>من ← إلى</th><th>الرصيد حتى</th><th>التفسير</th><th>المندوب / الفواتير المحتملة</th><th>المراجعة</th></tr>
                </thead>
                <tbody>
                    @forelse ($snapshots as $s)
                        @php $delta = (float) $s->delta; @endphp
                        <tr class="balance-change {{ $s->isFlagged() ? 'table-warning' : '' }}" data-snapshot-id="{{ $s->id }}">
                            <td class="fw-bold">{{ $s->customer?->name }}
                                <span class="d-block text-muted fw-normal">{{ \App\Models\CustomerBalanceSnapshot::KINDS[$s->kind] ?? $s->kind }}@if ($s->scope === 'external') — خارجي@endif</span>
                            </td>
                            <td class="font-monospace text-nowrap">
                                {{ $s->prev_balance !== null ? $fmt($s->prev_balance) : '—' }} ← {{ $fmt($s->balance) }}
                                <span class="d-block fw-bold {{ $delta < 0 ? 'text-success' : ($delta > 0 ? 'text-danger' : 'text-muted') }}">{{ \App\Services\Balances\BalanceDeltaExplainer::signed($delta) }}</span>
                            </td>
                            <td class="font-monospace text-nowrap">{{ $s->as_of?->format('Y-m-d') }}</td>
                            <td style="min-width: 280px;">
                                @if ($s->isFlagged())<span class="badge text-bg-danger">تحتاج تحققاً</span>@endif
                                {{ $s->explanation }}
                                @foreach (collect($s->details ?? [])->whereNotNull('invoice_id') as $d)
                                    <span class="d-block">
                                        <a href="{{ route('invoices.lines', $d['invoice_id']) }}">{{ $d['label'] }}</a>
                                        <span class="font-monospace">{{ \App\Services\Balances\BalanceDeltaExplainer::signed((float) $d['amount']) }}</span>
                                        @if ($d['type'] === 'proposed_change')<span class="badge text-bg-warning">مقترح من الملف</span>@endif
                                        @if ($d['type'] === 'post_close_adjustment')<span class="badge text-bg-primary">تعديل لاحق</span>@endif
                                    </span>
                                @endforeach
                            </td>
                            <td style="min-width: 220px;">
                                @forelse ($candidates->get($s->customer_id, collect())->take(6) as $c)
                                    <div class="d-flex align-items-center gap-1 flex-wrap">
                                        <a href="{{ route('invoices.lines', $c['invoice_id']) }}" class="font-monospace">{{ $c['label'] }}</a>
                                        <span>{{ $c['rep'] ?? ($c['cost_center'] ?? 'بلا مندوب') }}</span>
                                        <span class="font-monospace text-muted">{{ $fmt($c['amount']) }}</span>
                                        {{-- المرحلة 7C: يفتح نموذج التعديل اللاحق بالفاتورة وفرق اللقطة مُعبَّأين --}}
                                        @if ($canAdjust)
                                            <a href="{{ route('finance.adjustments.create', ['invoice' => $c['invoice_id']] + ($s->prev_balance !== null ? ['delta' => (float) $s->delta] : []) + ['snapshot' => $s->id]) }}" class="btn btn-sm btn-outline-primary py-0 post-close-adjust">تعديل لاحق</a>
                                        @endif
                                    </div>
                                @empty
                                    <span class="text-muted">لا فواتير في كشوف مغلقة خلال 60 يوماً</span>
                                @endforelse
                            </td>
                            <td style="min-width: 170px;">
                                @if ($s->reviewed_at)
                                    <span class="badge text-bg-success">تمّت المراجعة</span>
                                    <span class="d-block text-muted">{{ $s->reviewer?->name }} — {{ $s->reviewed_at->copy()->setTimezone('Asia/Damascus')->format('Y-m-d') }}</span>
                                    @if ($s->review_note)<span class="d-block">{{ $s->review_note }}</span>@endif
                                @elseif ($canReview)
                                    <form action="{{ route('finance.balance-changes.review', $s) }}" method="POST" class="d-flex gap-1 m-0">
                                        @csrf
                                        <input type="text" name="note" class="form-control form-control-sm" placeholder="ملاحظة (اختيارية)" aria-label="ملاحظة المراجعة">
                                        <button class="btn btn-sm btn-success fw-bold text-nowrap">تمّت المراجعة</button>
                                    </form>
                                @else
                                    <span class="text-muted">غير مراجَع</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state title="لا توجد تغيّرات أرصدة تطابق المرشّح" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($snapshots->hasPages())
            <div class="p-3 d-flex justify-content-center border-top">{{ $snapshots->links() }}</div>
        @endif
    </div>
@endsection
