@extends('layouts.panel')

@section('title', 'ملف المندوب: '.$rep->name)

@php $money = fn ($v) => number_format((float) $v, 2); @endphp

@section('content')
    <x-page-header :title="'ملف المندوب: '.$rep->name" :subtitle="$rep->cost_center_label ?: 'بلا مركز كلفة'" icon="fa-id-badge" />

    <div class="card mb-3" id="rp-header">
        <div class="card-body small">
            <div class="fw-bold mb-1">المشرفون:</div>
            @forelse ($supervisors as $s)
                <div data-rep-supervisor="{{ $s['supervisor']?->id }}">{{ $s['supervisor']?->name }} — {{ $s['label'] }}</div>
            @empty
                <div class="text-warning-emphasis">بلا مشرف (يراه الأدمن فقط عند تفعيل الحصر).</div>
            @endforelse
            @if ($scopeLabel)
                <div class="mt-2 text-primary-emphasis" data-rep-scope-line><i class="fas fa-filter"></i> نطاقك: {{ $scopeLabel }}</div>
            @else
                @include('partials.rep-scope-line')
            @endif
        </div>
    </div>

    {{-- المرحلة 8B: تبويب «الخطة والتغطية» --}}
    <ul class="nav nav-tabs mb-3">
        @foreach (\App\Http\Controllers\RepProfileController::TABS as $key => $label)
            <li class="nav-item"><a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('rep-supervisor.reps.show', [$rep, 'tab' => $key]) }}">{{ $label }}</a></li>
        @endforeach
    </ul>

    @if ($tab === 'sheets')
        <div class="card overflow-hidden" id="rp-sheets">
            @if ($partial)<div class="card-header bg-white small text-muted">الأعداد والمجاميع لفواتير المندوب في صيدليات نطاقك فقط، لا إجمالي الكشف.</div>@endif
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="table-light"><tr><th class="ps-3">أمر التسليم</th><th>التاريخ</th><th>الحالة</th><th>الفواتير</th><th>الصيدليات</th><th class="pe-3">المجموع</th></tr></thead>
                    <tbody>
                        @forelse ($sheets as $row)
                            <tr data-sheet="{{ $row['sheet']->id }}">
                                <td class="ps-3"><a href="{{ route('supervisor.sheet.show', $row['sheet']->id) }}">#{{ $row['sheet']->orderLabel() }}</a></td>
                                <td dir="ltr">{{ $row['sheet']->distribution_date?->toDateString() }}</td>
                                <td><x-status-badge :status="$row['sheet']->status" /></td>
                                <td>{{ $row['invoices'] }}</td>
                                <td>{{ $row['pharmacies'] }}</td>
                                <td class="pe-3 font-monospace">{{ $money($row['total']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted ps-3">لا كشوف.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'receivables')
        <div class="row g-2 mb-3" id="rp-totals">
            <div class="col-6 col-lg"><div class="card card-body py-2"><div class="small text-muted">الدَين الناشئ</div><div class="fw-bold font-monospace">{{ $money($totals['arising']) }}</div></div></div>
            <div class="col-6 col-lg"><div class="card card-body py-2"><div class="small text-muted">المحصّل المُصدَّر</div><div class="fw-bold font-monospace">{{ $money($totals['exported']) }}</div></div></div>
            <div class="col-6 col-lg"><div class="card card-body py-2"><div class="small text-muted">المحصّل المعلّق</div><div class="fw-bold font-monospace">{{ $money($totals['pending']) }}</div></div></div>
            <div class="col-6 col-lg"><div class="card card-body py-2"><div class="small text-muted">المتبقي</div><div class="fw-bold font-monospace text-danger" id="rp-remaining">{{ $money($totals['remaining']) }}</div></div></div>
        </div>
        <div class="card overflow-hidden">
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="table-light"><tr><th class="ps-3">الصيدلية</th><th>المنطقة</th><th>الناشئ</th><th>المُصدَّر</th><th>المعلّق</th><th class="pe-3">المتبقي</th></tr></thead>
                    <tbody>
                        @forelse ($state as $s)
                            <tr data-customer="{{ $s['customer_id'] }}">
                                <td class="ps-3">@if ($statementUrl = \App\Services\Reps\PharmacyStatementService::linkFor(auth()->user(), $s['customer_id']))<a href="{{ $statementUrl }}" data-statement-link>{{ $s['name'] }}</a>@else{{ $s['name'] }}@endif</td>
                                <td>{{ $s['area'] }}</td>
                                <td class="font-monospace">{{ $money($s['arising_cents'] / 100) }}</td>
                                <td class="font-monospace">{{ $money($s['exported_cents'] / 100) }}</td>
                                <td class="font-monospace">{{ $money($s['pending_cents'] / 100) }}</td>
                                <td class="pe-3 font-monospace">{{ $money($s['remaining_cents'] / 100) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted ps-3">لا ذمم.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @elseif ($tab === 'plan')
        {{-- المرحلة 8B: «الخطة والتغطية» لهذا الأسبوع (بنود المشاهِد وزياراته فقط) --}}
        <div class="d-flex flex-wrap gap-2 mb-3" id="rp-plan">
            <a href="{{ route('rep-supervisor.plan.show', $rep) }}" class="btn btn-primary fw-bold"><i class="fas fa-route"></i> الخطة</a>
            <a href="{{ route('rep-supervisor.coverage', ['rep_id' => $rep->id, 'period' => 'month']) }}" class="btn btn-outline-primary fw-bold"><i class="fas fa-chart-column"></i> التغطية لهذا الشهر</a>
        </div>
        <p class="small text-muted mb-2">تغطية هذا الأسبوع ({{ $coverage['from'] }} — {{ $coverage['to'] }}):</p>
        @include('rep-supervisor.partials.coverage-cards', ['cards' => $coverage['cards'], 'reps' => $coverage['reps']])
    @else
        @if ($partial)
            <div class="alert alert-info small py-2" data-rep-scope-note>تُوزَّع تسديدات المندوب على فواتيره بالأقدم؛ تظهر هنا حصة فواتير مناطقك فقط.</div>
        @endif
        <div class="card overflow-hidden" id="rp-payments">
            <div class="table-responsive">
                <table class="table align-middle mb-0 small">
                    <thead class="table-light"><tr><th class="ps-3">التاريخ</th><th>الصيدلية</th><th>المبلغ</th><th>رقم الدفتر</th><th class="pe-3">الحالة</th></tr></thead>
                    <tbody>
                        @forelse ($payments as $p)
                            <tr data-payment="{{ $p->id }}">
                                <td class="ps-3" dir="ltr">{{ $p->collected_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</td>
                                <td>@if ($statementUrl = \App\Services\Reps\PharmacyStatementService::linkFor(auth()->user(), $p->customer_id))<a href="{{ $statementUrl }}" data-statement-link>{{ $p->customer?->name }}</a>@else{{ $p->customer?->name }}@endif</td>
                                <td class="font-monospace">{{ $money($p->amount) }} {{ $p->currency === 'USD' ? '$' : 'ل.س' }}</td>
                                <td>{{ $p->book_receipt_no }}</td>
                                <td class="pe-3">{{ $p->voided_at ? 'ملغاة' : ($p->status === 'pending' ? 'غير مُصدَّرة' : 'مُصدَّرة') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted ps-3">لا تسديدات.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-2">{{ $payments->links() }}</div>
    @endif
@endsection
