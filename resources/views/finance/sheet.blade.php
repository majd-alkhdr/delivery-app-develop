@extends('layouts.panel')

@section('title', 'المالية — أمر التسليم #' . $sheet->orderLabel())

@php
    $flagLabels = ['ok' => ['ضمن الموقع', 'text-bg-success'], 'far' => ['بعيد', 'text-bg-danger'], 'no_reference' => ['بلا مرجع', 'text-bg-warning'], 'no_gps' => ['بلا GPS', 'text-bg-light']];
    $statusLabels = ['pending' => ['غير مُصدَّرة', 'text-bg-secondary'], 'exported' => ['مُصدَّرة', 'text-bg-success'], 'paid' => ['مسددة', 'text-bg-primary']];
    $invoiceStatus = ['paid' => ['مسددة', 'text-bg-success'], 'partial' => ['جزئياً', 'text-bg-warning'], 'unpaid' => ['غير مسددة', 'text-bg-danger']];
    $money = fn ($v) => number_format((float) $v, 2);
    $justExported = session('finance_export_id');
    $canExport = $canManage && $preview['blockers'] === [];
@endphp

@section('content')
    <x-page-header :title="'المالية — أمر التسليم #' . $sheet->orderLabel()"
        :subtitle="'تاريخ التوزيع ' . ($sheet->distribution_date?->format('Y-m-d') ?? '—') . ' — ' . $sheet->distributorsLabel()" icon="fa-coins">
        <a href="{{ route(auth()->user()->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::Accountant) ? 'accountant.sheet.show' : 'supervisor.sheet.show', $sheet->id) }}" class="btn btn-light border fw-bold btn-sm">
            <i class="fas fa-eye"></i> تفاصيل الكشف
        </a>
        @if ($canManage && ! $preview['sheet_block'])
            <a href="#preview" class="btn btn-success fw-bold btn-sm"><i class="fas fa-file-export"></i> تصدير للمالية</a>
        @endif
    </x-page-header>
    @include('partials.rep-scope-line')3

    @if ($preview['sheet_block'])
        <div class="alert alert-secondary fw-bold"><i class="fas fa-lock me-1"></i> {{ $preview['sheet_block'] }}</div>
    @endif
    @unless ($canManage)
        <div class="alert alert-info small mb-3"><i class="fas fa-circle-info me-1"></i> عرض فقط: التعديل والتصدير للمحاسب والأدمن.</div>
    @endunless

    {{-- 1) المقبوضات --}}
    <div class="card mb-4 overflow-hidden" id="payments">
        <div class="card-header bg-white py-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <h2 class="h6 fw-bold mb-0"><i class="fas fa-receipt text-primary me-1"></i> المقبوضات ({{ $payments->count() }})</h2>
            <div class="d-flex flex-wrap gap-2 small">
                <span class="badge text-bg-light border">ليرة: <span class="font-monospace" id="total-syp">{{ $money($paymentTotals['syp']) }}</span></span>
                <span class="badge text-bg-light border">دولار: <span class="font-monospace" id="total-usd">{{ $money($paymentTotals['usd']) }}</span></span>
                <span class="badge text-bg-primary">المكافئ بالليرة: <span class="font-monospace" id="total-equivalent">{{ $money($paymentTotals['equivalent']) }}</span></span>
            </div>
        </div>
        <div class="card-body border-bottom py-2">
            <form method="GET" class="row g-2 align-items-end small">
                <div class="col-sm-4">
                    <label class="form-label fw-bold mb-1" for="f_dist">الموزع</label>
                    <select name="distributor_id" id="f_dist" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        @foreach ($paymentFilters['distributors'] as $d)
                            <option value="{{ $d->id }}" @selected(request('distributor_id') == $d->id)>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label fw-bold mb-1" for="f_area">المنطقة</label>
                    <select name="area" id="f_area" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        @foreach ($paymentFilters['areas'] as $a)
                            <option value="{{ $a }}" @selected(request('area') === $a)>{{ $a }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-3">
                    <label class="form-label fw-bold mb-1" for="f_flag">علامة الموقع</label>
                    <select name="flag" id="f_flag" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        @foreach ($flagLabels as $k => [$t])
                            <option value="{{ $k }}" @selected(request('flag') === $k)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-2 d-grid"><button class="btn btn-sm btn-primary fw-bold"><i class="fas fa-filter"></i> تطبيق</button></div>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">الصيدلية</th><th>المنطقة</th><th>المُدخِل</th><th>المبلغ</th><th>السعر</th><th>بالليرة</th>
                        <th>الإيصال</th><th>الموقع</th><th>الحالة</th><th>وقت التحصيل</th><th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($payments as $p)
                        @php [$ft, $fc] = $flagLabels[$p->location_flag] ?? [$p->location_flag, 'text-bg-light']; [$st, $sc] = $statusLabels[$p->status] ?? [$p->status, 'text-bg-light']; @endphp
                        <tr class="{{ $p->isVoided() ? 'text-decoration-line-through text-muted' : '' }}">
                            <td class="ps-3 fw-bold">{{ $p->customer?->name }} @if ($p->source !== 'sheet')<span class="badge text-bg-info">خارجية</span>@endif @if ($p->outside_sheet)<span class="badge text-bg-danger outside-sheet">صيدلية خارج الكشف</span>@endif @include('partials.before-ack-badge', ['record' => $p])</td>
                            <td>{{ $p->customer?->area ?: '—' }}</td>
                            <td>{{ $p->enteredBy?->name }}</td>
                            <td class="font-monospace text-nowrap">{{ $money($p->amount) }} {{ $p->currency === 'USD' ? '$' : 'ل.س' }}</td>
                            <td class="font-monospace">{{ $p->exchange_rate !== null ? rtrim(rtrim(number_format((float) $p->exchange_rate, 4, '.', ''), '0'), '.') : '—' }}</td>
                            <td class="font-monospace text-nowrap">{{ $p->amount_syp !== null ? $money($p->amount_syp) : 'بانتظار السعر' }}</td>
                            <td class="font-monospace" dir="ltr">{{ $p->receipt_number }}</td>
                            <td><span class="badge {{ $fc }}">{{ $ft }}</span></td>
                            <td>
                                @if ($p->isVoided())
                                    <span class="badge text-bg-dark" title="{{ $p->void_reason }}">ملغاة</span>
                                @else
                                    <span class="badge {{ $sc }}">{{ $st }}</span>
                                @endif
                            </td>
                            <td class="font-monospace text-nowrap">{{ $p->collected_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</td>
                            <td class="pe-3 text-end">
                                @if ($voidable[$p->id] ?? false)
                                    <form action="{{ route('payments.void', $p) }}" method="POST" class="d-flex gap-1 m-0" onsubmit="return confirm('إلغاء هذه الدفعة؟')">
                                        @csrf
                                        <input type="text" name="reason" class="form-control form-control-sm" placeholder="سبب الإلغاء" required minlength="3" style="width: 130px">
                                        <button class="btn btn-sm btn-outline-danger"><i class="fas fa-ban"></i></button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="text-muted ps-3 py-3">لا توجد دفعات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($restricted ?? false)
        <div class="alert alert-secondary small" id="finance-restricted-note">مطابقة الصندوق ومعاينة التوزيع والدفعات التصديرية تخص الكشف كاملاً، فلا تظهر في العرض المقيَّد بنطاقك. الدفعات أعلاه لصيدليات نطاقك فقط.</div>
    @else
    {{-- 2) مطابقة الصندوق --}}
    <div class="card mb-4 overflow-hidden" id="cash">
        <div class="card-header bg-white py-3"><h2 class="h6 fw-bold mb-0"><i class="fas fa-cash-register text-primary me-1"></i> مطابقة الصندوق <span class="text-muted small fw-normal">(لا تمنع التصدير)</span></h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th class="ps-3">الموزع</th><th>العملة</th><th>المحصّل المسجَّل</th><th>المستلم فعلياً</th><th>الفرق</th><th>ملاحظة</th><th class="pe-3"></th></tr></thead>
                <tbody>
                    @forelse ($cash as $c)
                        <tr>
                            <td class="ps-3 fw-bold">{{ $c['distributor'] }}</td>
                            <td>{{ $c['currency'] === 'USD' ? 'دولار' : 'ليرة' }}</td>
                            <td class="font-monospace">{{ $money($c['recorded']) }}</td>
                            @if ($canManage)
                                <td colspan="3">
                                    <form action="{{ route('finance.cash-counts.store', $sheet) }}" method="POST" class="d-flex flex-wrap gap-1 m-0 align-items-center">
                                        @csrf
                                        <input type="hidden" name="distributor_id" value="{{ $c['distributor_id'] }}">
                                        <input type="hidden" name="currency" value="{{ $c['currency'] }}">
                                        <input type="text" name="amount" inputmode="decimal" dir="ltr" class="form-control form-control-sm" style="width: 130px" value="{{ $c['counted'] !== null ? $c['counted'] : '' }}" placeholder="المستلم" required>
                                        @if ($c['difference'] !== null)
                                            <span class="badge {{ abs($c['difference']) < 0.005 ? 'text-bg-success' : 'text-bg-danger' }} font-monospace cash-diff">{{ $c['difference'] > 0 ? '+' : '' }}{{ $money($c['difference']) }}</span>
                                        @endif
                                        <input type="text" name="note" class="form-control form-control-sm" style="width: 180px" value="{{ $c['note'] }}" placeholder="ملاحظة (اختياري)" maxlength="500">
                                        <button class="btn btn-sm btn-outline-primary fw-bold"><i class="fas fa-floppy-disk"></i> حفظ</button>
                                    </form>
                                </td>
                            @else
                                <td class="font-monospace">{{ $c['counted'] !== null ? $money($c['counted']) : '—' }}</td>
                                <td>
                                    @if ($c['difference'] !== null)
                                        <span class="badge {{ abs($c['difference']) < 0.005 ? 'text-bg-success' : 'text-bg-danger' }} font-monospace cash-diff">{{ $money($c['difference']) }}</span>
                                    @else — @endif
                                </td>
                                <td>{{ $c['note'] ?: '—' }}</td>
                            @endif
                            <td class="pe-3 text-muted fs-8">{{ $c['entered_by'] ? $c['entered_by'] . ' — ' . $c['updated_at']?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted ps-3 py-3">لا مقبوضات لمطابقتها.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- 3) دفعات بانتظار السعر --}}
    @if ($preview['awaiting_rate']->isNotEmpty())
        <div class="card mb-4 border-warning overflow-hidden" id="awaiting-rate">
            <div class="card-header bg-warning-subtle py-3"><h2 class="h6 fw-bold mb-0"><i class="fas fa-dollar-sign me-1"></i> بانتظار السعر ({{ $preview['awaiting_rate']->count() }}) — التصدير ممنوع حتى يُضبط السعر</h2></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light"><tr><th class="ps-3">الصيدلية</th><th>المُدخِل</th><th>المبلغ بالدولار</th><th>الإيصال</th><th class="pe-3">السعر (المجال {{ $rateRange[0] }} – {{ $rateRange[1] }})</th></tr></thead>
                    <tbody>
                        @foreach ($preview['awaiting_rate'] as $p)
                            <tr>
                                <td class="ps-3 fw-bold">{{ $p->customer?->name }}</td>
                                <td>{{ $p->enteredBy?->name }}</td>
                                <td class="font-monospace">{{ $money($p->amount) }} $</td>
                                <td class="font-monospace" dir="ltr">{{ $p->receipt_number }}</td>
                                <td class="pe-3">
                                    @if ($canManage)
                                        <form action="{{ route('finance.payments.rate', $p) }}" method="POST" class="d-flex flex-wrap gap-1 m-0 align-items-center">
                                            @csrf
                                            <input type="text" name="exchange_rate" inputmode="decimal" dir="ltr" class="form-control form-control-sm" style="width: 110px" required
                                                value="{{ session('rate_confirm_payment') == $p->id ? old('exchange_rate') : $rateRange[2] }}">
                                            @if (session('rate_confirm_payment') == $p->id)
                                                <label class="form-check-label small text-danger fw-bold"><input type="checkbox" name="rate_confirmed" value="1" class="form-check-input" required> أؤكد السعر خارج المجال</label>
                                            @endif
                                            <button class="btn btn-sm btn-warning fw-bold"><i class="fas fa-check"></i> ضبط السعر</button>
                                        </form>
                                    @else
                                        <span class="text-muted">بانتظار المحاسب</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- 4) معاينة التوزيع --}}
    <div class="card mb-4 overflow-hidden" id="preview">
        <div class="card-header bg-white py-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
            <h2 class="h6 fw-bold mb-0"><i class="fas fa-scale-balanced text-primary me-1"></i> معاينة التوزيع (حيّة — للدفعات غير المُصدَّرة)</h2>
            <div class="d-flex flex-wrap gap-2 small">
                <span class="badge text-bg-light border">المدفوع: <span class="font-monospace" id="preview-paid">{{ $money($preview['totals']['paid_syp']) }}</span></span>
                <span class="badge text-bg-light border">الموزَّع: <span class="font-monospace">{{ $money($preview['totals']['allocated_syp']) }}</span></span>
                <span class="badge text-bg-info">الفائض: <span class="font-monospace">{{ $money($preview['totals']['surplus_syp']) }}</span></span>
            </div>
        </div>
        <div class="card-body">
            @foreach ($preview['blockers'] as $b)
                <div class="alert alert-danger py-2 small fw-bold mb-2 export-blocker"><i class="fas fa-ban me-1"></i> {{ $b }}</div>
            @endforeach
            {{-- المرحلة 7C (allocation_clipped): لا قصّ صامت --}}
            @foreach ($preview['clipped'] ?? [] as $c)
                <div class="alert alert-warning py-2 small mb-2 allocation-clipped" data-allocation-clipped="{{ $c['invoice_id'] }}">
                    <i class="fas fa-scissors me-1"></i> <strong>allocation_clipped</strong>: وُزِّع سابقاً على الفاتورة {{ $c['label'] }} ({{ $c['customer'] }}) {{ $money($c['previous_cents'] / 100) }} وقيمتها الحالية {{ $money($c['total_cents'] / 100) }} بعد تعديل لاحق:
                    قُصّ {{ $money($c['clipped']) }} فلا يُخصم من الدفعات الجديدة، ويظهر «زيادة تحصيل» في ذمم المندوب ويُسجَّل عند التصدير.
                </div>
            @endforeach

            @php $paying = $preview['customers']->filter(fn ($r) => $r['result']->hasPayments()); $unpaidOnly = $preview['customers']->reject(fn ($r) => $r['result']->hasPayments()); @endphp
            @forelse ($paying as $row)
                @php $r = $row['result']; @endphp
                <div class="border rounded-3 p-3 mb-3 preview-customer" data-customer-id="{{ $row['customer']?->id }}">
                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                        <div class="fw-bold">{{ $row['customer']?->name }} <span class="text-muted small fw-normal">— {{ implode(' و ', $row['users']) }}</span></div>
                        <div class="small">المدفوع: <span class="font-monospace fw-bold">{{ $money($r->totalPaidSyp()) }}</span> ل.س</div>
                    </div>
                    @if ($r->isIncomplete())
                        <div class="alert alert-warning py-1 small mb-2"><i class="fas fa-hourglass-half"></i> ناقصة: {{ $r->awaitingRateCount }} دفعة دولار بانتظار السعر — لا توزيع.</div>
                    @endif
                    @if ($r->isExternalOnly())
                        <div class="alert alert-info py-1 small mb-2"><i class="fas fa-circle-info"></i> دفعة خارجية: لا فواتير لهذا الزبون في الكشف.</div>
                    @endif
                    @if ($r->invoiceRows || $r->excludedRows)
                        <div class="table-responsive">
                            <table class="table table-sm mb-2 small align-middle">
                                <thead class="table-light"><tr><th>المجموعة</th><th>الفاتورة</th><th>مركز الكلفة</th><th>الإجمالي</th><th>سابقاً</th><th>الموزَّع</th><th>غير المسدد</th><th>الحالة</th></tr></thead>
                                <tbody>
                                    @foreach ($r->invoiceRows as $i)
                                        @php [$it, $ic] = $invoiceStatus[$i['status']]; @endphp
                                        <tr>
                                            <td>{{ $i['group_label'] }}</td>
                                            <td class="font-monospace" dir="ltr">{{ $i['label'] }}</td>
                                            <td>{{ $i['cost_center'] ?? 'بلا مركز' }}</td>
                                            <td class="font-monospace">{{ $money($i['total']) }}</td>
                                            <td class="font-monospace text-muted">{{ $i['previous'] > 0 ? $money($i['previous']) : '—' }}</td>
                                            <td class="font-monospace fw-bold">{{ $money($i['allocated']) }}</td>
                                            <td class="font-monospace">{{ $money($i['unpaid']) }}</td>
                                            <td><span class="badge {{ $ic }}">{{ $it }}</span></td>
                                        </tr>
                                    @endforeach
                                    @foreach ($r->excludedRows as $i)
                                        <tr class="text-muted">
                                            <td>{{ $i['group_label'] }}</td>
                                            <td class="font-monospace" dir="ltr">{{ $i['label'] }}</td>
                                            <td>{{ $i['cost_center'] ?? '—' }}</td>
                                            <td colspan="5">خارج التوزيع (هدية/صفرية أو سالبة)</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <div class="d-flex flex-wrap gap-2 align-items-center small">
                        @foreach ($r->costCenterRows as $cc)
                            <span class="badge text-bg-light border">{{ $cc['cost_center'] ?? 'بلا مركز' }}: <span class="font-monospace">{{ $money($cc['amount']) }}</span></span>
                        @endforeach
                        @if ($r->surplusCents > 0)
                            <span class="badge {{ $r->surplusCostCenter ? 'text-bg-info' : 'text-bg-danger' }} surplus-badge">
                                فائض {{ $money($r->surplus()) }} —
                                {{ $r->surplusCostCenter ?? 'بلا مركز (يمنع التصدير)' }}
                                @if ($r->surplusCostCenterSource === 'suggested') <span class="fw-normal">(مقترح)</span> @endif
                            </span>
                            @if ($canManage && $preview['cost_centers'])
                                <form action="{{ route('finance.sheets.surplus', $sheet) }}" method="POST" class="d-flex gap-1 m-0">
                                    @csrf
                                    <input type="hidden" name="customer_id" value="{{ $row['customer']->id }}">
                                    <select name="cost_center" class="form-select form-select-sm" style="width: 220px" required>
                                        @foreach ($preview['cost_centers'] as $center)
                                            <option value="{{ $center }}" @selected($center === $r->surplusCostCenter)>{{ $center }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-outline-secondary fw-bold">تعديل مركز كلفة الفائض</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-muted small mb-2">لا دفعات غير مُصدَّرة في هذا الكشف.</p>
            @endforelse

            @if ($unpaidOnly->isNotEmpty())
                <details class="mt-2">
                    <summary class="small fw-bold">زبائن بلا دفعات جديدة ({{ $unpaidOnly->count() }}) — غير المسدد: {{ $money($unpaidOnly->sum(fn ($r) => $r['result']->totalUnpaidSyp())) }}</summary>
                    <ul class="small mt-2 mb-0">
                        @foreach ($unpaidOnly as $row)
                            <li>{{ $row['customer']?->name }} — <span class="font-monospace">{{ $money($row['result']->totalUnpaidSyp()) }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </div>
        @if ($canManage && ! $preview['sheet_block'])
            <div class="card-footer bg-light">
                <form action="{{ route('finance.sheets.export', $sheet) }}" method="POST" class="d-flex flex-wrap gap-2 align-items-center m-0" id="financeExportForm" onsubmit="this.querySelector('button').disabled = true">
                    @csrf
                    <input type="hidden" name="signature" value="{{ $preview['signature'] }}">
                    <label class="form-check-label small fw-bold">
                        <input type="checkbox" name="confirm" value="1" class="form-check-input" required @disabled(! $canExport)>
                        أؤكد أن هذا الكشف سيُسدَّد الآن في البرنامج المحاسبي (لا تعديل ولا إلغاء للدفعات بعد التصدير).
                    </label>
                    <button class="btn btn-success fw-bold" @disabled(! $canExport)>
                        <i class="fas fa-file-export"></i> تصدير للمالية
                    </button>
                    @if ($preview['pending']->isEmpty())
                        <span class="text-muted small">لا دفعات جديدة.</span>
                    @endif
                </form>
            </div>
        @endif
    </div>

    {{-- 5) سجل الدفعات التصديرية --}}
    <div class="card mb-4 overflow-hidden" id="exports">
        <div class="card-header bg-white py-3"><h2 class="h6 fw-bold mb-0"><i class="fas fa-box-archive text-primary me-1"></i> الدفعات التصديرية ({{ $exports->count() }})</h2></div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th class="ps-3">الرقم</th><th>الوقت</th><th>بواسطة</th><th>الدفعات</th><th>بالليرة (مكافئ)</th><th>بالدولار</th><th>الفائض</th><th class="pe-3">الملفات</th></tr></thead>
                <tbody>
                    @forelse ($exports as $e)
                        <tr class="{{ $justExported == $e->id ? 'table-success' : '' }}">
                            <td class="ps-3 fw-bold">{{ $e->sequence }}</td>
                            <td class="font-monospace text-nowrap">{{ $e->exported_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</td>
                            <td>{{ $e->exporter?->name }}</td>
                            <td>{{ $e->payments_count }}</td>
                            <td class="font-monospace">{{ $money($e->total_syp) }}</td>
                            <td class="font-monospace">{{ $money($e->total_usd) }}</td>
                            <td class="font-monospace">{{ $money($e->surplus_syp) }}</td>
                            <td class="pe-3">
                                @if ($canManage)
                                    <div class="d-flex flex-wrap gap-1 {{ $justExported == $e->id ? 'auto-download' : '' }}">
                                        <a href="{{ route('finance.exports.download', [$e, 'import']) }}" class="btn btn-sm btn-success fw-bold"><i class="fas fa-file-excel"></i> ملف الاستيراد</a>
                                        <a href="{{ route('finance.exports.download', [$e, 'receipts']) }}" class="btn btn-sm btn-outline-success fw-bold">كشف المقبوضات</a>
                                        @if ($usdExports[$e->id] ?? false)
                                            <a href="{{ route('finance.exports.download', [$e, 'usd']) }}" class="btn btn-sm btn-outline-secondary fw-bold">الدولار</a>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-muted ps-3 py-3">لم يُصدَّر هذا الكشف بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif
@endsection

@push('scripts')
    <script>
        // بعد التأكيد: تنزيل الملفات الثلاثة تلقائياً (واحداً تلو الآخر)
        document.querySelectorAll('.auto-download a').forEach((a, i) => setTimeout(() => {
            const frame = document.createElement('iframe');
            frame.style.display = 'none';
            frame.src = a.href;
            document.body.appendChild(frame);
        }, 600 * i));
    </script>
@endpush
