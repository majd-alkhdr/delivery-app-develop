@extends('layouts.panel')

@section('title', 'التعديلات اللاحقة')

@php
    $fmt = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $signed = fn ($v) => \App\Services\Balances\BalanceDeltaExplainer::signed((float) $v);
    $label = fn ($number) => \App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $number);
@endphp

@section('content')
    <x-page-header title="التعديلات اللاحقة" subtitle="تصحيح فاتورة بعد إغلاق كشفها وأثره على ذمة المندوب — لا شيء بصمت" icon="fa-file-pen" />
    @include('partials.rep-scope-line')3

    <div class="alert alert-info small py-2">
        <i class="fas fa-circle-info me-1"></i>
        الكشف المكتمل لا يُعاد فتحه ولا تُنقل منه الفاتورة. «من الملف» يحدّث أسطر الفاتورة ويحفظ نسختها القديمة، و«يدوي» يضيف فرقاً إلى قيمتها الفعالة دون مسّ الأسطر.
        الدفعات والتوزيعات والتصديرات السابقة لا تتغيّر، وما حُصِّل فوق القيمة الجديدة يظهر «زيادة تحصيل» تحتاج قراراً.
        رصيد الصيدلية المعروض لا يتغيّر (يأتي من المحاسبة).
    </div>

    <ul class="nav nav-tabs mb-3" id="adjustment-tabs">
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'pending' ? 'active fw-bold' : '' }}" href="{{ route('finance.adjustments', ['tab' => 'pending'] + request()->except('tab', 'page')) }}">
                بانتظار التطبيق <span class="badge text-bg-danger" id="pending-count">{{ $pending->count() }}</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link {{ $tab === 'applied' ? 'active fw-bold' : '' }}" href="{{ route('finance.adjustments', ['tab' => 'applied'] + request()->except('tab', 'page', 'proposal')) }}">
                المطبَّقة <span class="badge text-bg-secondary">{{ $applied->total() }}</span>
            </a>
        </li>
    </ul>

    <form method="GET" action="{{ route('finance.adjustments') }}" class="row g-2 align-items-end small mb-3">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div class="col-6 col-md-3">
            <label class="form-label fw-bold mb-1" for="adj_rep">المندوب</label>
            <select name="rep_id" id="adj_rep" class="form-select form-select-sm">
                <option value="">الكل</option>
                @foreach ($reps as $r)<option value="{{ $r->id }}" @selected((string) request('rep_id') === (string) $r->id)>{{ $r->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-6 col-md-3"><label class="form-label fw-bold mb-1" for="adj_q">الزبون أو الفاتورة</label><input type="search" name="q" id="adj_q" class="form-control form-control-sm" value="{{ request('q') }}"></div>
        <div class="col-4 col-md-1 d-grid"><button class="btn btn-sm btn-primary fw-bold">تصفية</button></div>
        @if (request('proposal'))
            <div class="col-8 col-md-3"><a href="{{ route('finance.adjustments', ['tab' => $tab]) }}" class="btn btn-sm btn-light border">عرض الكل</a></div>
        @endif
    </form>

    @if ($tab === 'pending')
        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small" id="pending-adjustments">
                    <thead class="table-light">
                        <tr><th>الفاتورة</th><th>الكشف</th><th>الزبون</th><th>المندوب (مركز الكلفة)</th><th>القديم ← الجديد</th><th>الفرق</th><th>ذمة المندوب قبل ← بعد</th><th>لقطة الرصيد</th><th>السبب</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($pending as $row)
                            @php $p = $row['preview']; @endphp
                            <tr class="pending-adjustment" data-proposal-id="{{ $row['proposal']->id }}">
                                <td class="font-monospace text-nowrap"><a href="{{ route('invoices.lines', $row['invoice']->id) }}">{{ $label($row['invoice']->invoice_number) }}</a></td>
                                <td class="text-nowrap">{{ $row['sheet']?->shortLabel() }}<span class="d-block text-muted fs-8">{{ $row['sheet']?->statusLabel() }} — {{ $row['sheet']?->distribution_date?->format('Y-m-d') }}</span></td>
                                <td>{{ $row['invoice']->customer?->name ?? $row['invoice']->customer_name }}</td>
                                <td>{{ $row['rep']?->name ?? 'بلا مندوب' }}<span class="d-block text-muted fs-8">{{ trim(($row['invoice']->cost_center_code ?? '').'-'.($row['invoice']->cost_center_name ?? ''), '-') ?: '—' }}</span></td>
                                <td class="font-monospace text-nowrap">{{ $fmt($row['old']) }} ← <strong>{{ $fmt($row['new']) }}</strong>@if ($row['new'] == 0)<span class="d-block badge text-bg-dark">ألغيت في المحاسبة</span>@endif</td>
                                <td class="font-monospace fw-bold {{ $row['delta'] < 0 ? 'text-success' : 'text-danger' }}">{{ $signed($row['delta']) }}</td>
                                <td class="font-monospace text-nowrap">
                                    @if ($p)
                                        {{ $fmt($p['debt_before']) }} ← <strong>{{ $fmt($p['debt_after']) }}</strong>
                                        @if ($p['surplus'] > 0)<span class="d-block text-danger">زيادة تحصيل {{ $fmt($p['surplus']) }}</span>@endif
                                    @else
                                        {{ $fmt($row['debt_now']) }} (الآن)
                                    @endif
                                </td>
                                <td>@if ($row['snapshot']){{ $fmt($row['snapshot']->prev_balance) }} ← {{ $fmt($row['snapshot']->balance) }} <span class="text-muted">({{ $row['snapshot']->as_of?->format('Y-m-d') }})</span>@else<span class="text-muted">{{ \App\Models\InvoiceAdjustment::NO_SNAPSHOT }}</span>@endif</td>
                                <td style="min-width: 220px;">
                                    تعديل في المحاسبة وصل في ملف
                                    @if ($row['reason'])<details><summary class="text-primary">التفاصيل</summary>{{ $row['reason'] }}</details>@endif
                                    @if ($row['manual_applied'])<span class="d-block text-danger">تنبيه: على هذه الفاتورة تعديل يدوي مطبَّق؛ التطبيق قد يكرّر الفرق.</span>@endif
                                    @if ($row['error'])<span class="d-block text-danger">{{ $row['error'] }}</span>@endif
                                </td>
                                <td class="text-end" style="min-width: 200px;">
                                    @if ($canApply && ! $row['error'])
                                        <form action="{{ route('finance.adjustments.apply', $row['proposal']) }}" method="POST" class="m-0 mb-1"
                                            onsubmit="return confirm(@js('تطبيق التعديل على '.$label($row['invoice']->invoice_number).': '.($p['message'] ?? '').'. متابعة؟'))">
                                            @csrf
                                            <input type="text" name="note" class="form-control form-control-sm mb-1" placeholder="ملاحظة (اختيارية)" aria-label="ملاحظة">
                                            <button class="btn btn-sm btn-success fw-bold w-100" title="{{ $p['message'] ?? '' }}"><i class="fas fa-check"></i> تطبيق</button>
                                            @if ($p)<span class="d-block text-muted fs-8 adjustment-impact">{{ $p['message'] }}</span>@endif
                                        </form>
                                        <details>
                                            <summary class="btn btn-sm btn-outline-secondary fw-bold w-100">تجاهل مع سبب</summary>
                                            <form action="{{ route('finance.adjustments.ignore', $row['proposal']) }}" method="POST" class="mt-1 d-flex gap-1">
                                                @csrf
                                                <input type="text" name="reason" class="form-control form-control-sm" placeholder="السبب (إلزامي)" required aria-label="سبب التجاهل">
                                                <button class="btn btn-sm btn-outline-danger fw-bold">تأكيد</button>
                                            </form>
                                        </details>
                                    @elseif (! $canApply)
                                        <span class="text-muted">للعرض فقط</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="10"><x-empty-state title="لا تعديلات بانتظار التطبيق" message="تظهر هنا التغييرات التي وصلت في ملف على فواتير كشوف مكتملة." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($ignored->isNotEmpty())
            <div class="card">
                <div class="card-header bg-white fw-bold small">تُجوهلت بقرار ({{ $ignored->count() }})</div>
                <ul class="list-group list-group-flush small" id="ignored-adjustments">
                    @foreach ($ignored as $p)
                        <li class="list-group-item">
                            <span class="font-monospace">{{ $label($p->invoice?->invoice_number) }}</span> — {{ $p->invoice?->customer_name }}:
                            {{ $fmt($p->invoice?->invoice_amount) }} ← {{ $fmt($p->payload['invoice_amount'] ?? null) }}
                            — السبب: {{ $p->decision_note }} <span class="text-muted">({{ $p->decider?->name }}، {{ $p->decided_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }})</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @else
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small" id="applied-adjustments">
                    <thead class="table-light">
                        <tr><th>الفاتورة</th><th>الكشف</th><th>الزبون</th><th>المندوب (مركز الكلفة)</th><th>القديم ← الجديد</th><th>الفرق</th><th>ذمة المندوب قبل ← بعد</th><th>الفائض</th><th>لقطة الرصيد</th><th>السبب</th><th>من ومتى</th><th></th></tr>
                    </thead>
                    <tbody>
                        @forelse ($applied as $a)
                            <tr class="applied-adjustment {{ $a->isApplied() ? '' : 'text-muted' }}" data-adjustment-id="{{ $a->id }}">
                                <td class="font-monospace text-nowrap"><a href="{{ route('invoices.lines', $a->invoice_id) }}">{{ $label($a->invoice?->invoice_number) }}</a>
                                    <span class="d-block badge text-bg-light border">{{ $a->kindLabel() }}</span></td>
                                <td>{{ $a->sheet?->shortLabel() ?? 'أمر #'.$a->sheet_id }}</td>
                                <td>{{ $a->customer?->name ?? $a->invoice?->customer_name }}</td>
                                <td>{{ $a->rep?->name ?? 'بلا مندوب' }}<span class="d-block text-muted fs-8">{{ $a->cost_center_code ?: '—' }}</span></td>
                                <td class="font-monospace text-nowrap">{{ $fmt($a->old_amount) }} ← <strong>{{ $fmt($a->new_amount) }}</strong>@if ((float) $a->new_amount === 0.0)<span class="d-block badge text-bg-dark">ألغيت في المحاسبة</span>@endif</td>
                                <td class="font-monospace fw-bold">{{ $signed($a->delta) }}</td>
                                <td class="font-monospace text-nowrap">{{ $fmt($a->rep_debt_before) }} ← <strong>{{ $fmt($a->rep_debt_after) }}</strong></td>
                                <td class="font-monospace {{ (float) $a->surplus > 0 ? 'text-danger fw-bold' : '' }}">{{ (float) $a->surplus > 0 ? $fmt($a->surplus).' (يحتاج قراراً)' : '—' }}</td>
                                <td>@if ($a->snapshot){{ $fmt($a->snapshot->prev_balance) }} ← {{ $fmt($a->snapshot->balance) }}@else<span class="text-muted">{{ $a->snapshot_note ?? '—' }}</span>@endif</td>
                                <td style="min-width: 160px;">{{ $a->reasonLabel() }}</td>
                                <td class="text-nowrap">{{ $a->applier?->name }}<span class="d-block text-muted">{{ $a->applied_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</span>
                                    @unless ($a->isApplied())
                                        <span class="d-block badge text-bg-warning">تُراجع عنه</span>
                                        <span class="d-block">{{ $a->reverter?->name }} — {{ $a->reverted_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d') }}: {{ $a->revert_reason }}</span>
                                    @endunless
                                </td>
                                <td style="min-width: 170px;">
                                    @if ($canRevert && $a->isApplied())
                                        <details>
                                            <summary class="btn btn-sm btn-outline-danger fw-bold">تراجع عن التعديل</summary>
                                            <form action="{{ route('finance.adjustments.revert', $a) }}" method="POST" class="mt-1 d-flex gap-1">
                                                @csrf
                                                <input type="text" name="reason" class="form-control form-control-sm" placeholder="السبب (إلزامي)" required aria-label="سبب التراجع">
                                                <button class="btn btn-sm btn-danger fw-bold">تأكيد</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="12"><x-empty-state title="لا تعديلات مطبَّقة" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($applied->hasPages())
                <div class="p-3 d-flex justify-content-center border-top">{{ $applied->links() }}</div>
            @endif
        </div>
    @endif
@endsection
