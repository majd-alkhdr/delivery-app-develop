@extends('layouts.panel')

@section('title', 'دفعات المندوبين')

@php
    $money = fn ($v) => number_format((float) $v, 2);
    $flagLabels = ['ok' => ['ضمن الموقع', 'text-bg-success'], 'far' => ['بعيد', 'text-bg-danger'], 'no_reference' => ['بلا مرجع', 'text-bg-warning'], 'no_gps' => ['بلا GPS', 'text-bg-light border']];
    $reviewLabels = ['none' => ['لم تُراجع', 'text-bg-light border'], 'verified' => ['تم التحقق', 'text-bg-success'], 'needs_review' => ['بحاجة مراجعة', 'text-bg-warning']];
    $justExported = session('finance_export_id');
    $repNames = $allReps->keyBy('id');
    $selectedIds = $selected ?? $reps->pluck('id')->all();
@endphp

@section('content')
    <x-page-header title="دفعات المندوبين" subtitle="مراجعة تحصيلات المندوبين بصور إيصالاتها وتصديرها للمالية (منفصلاً عن الكشوف)" icon="fa-receipt">
        <a href="{{ route('finance.rep-receivables') }}" class="btn btn-light border fw-bold btn-sm"><i class="fas fa-hand-holding-dollar"></i> ذمم المندوبين</a>
        @if ($canExportReports)
            <a href="{{ route('finance.rep-payments.list-export', request()->only(['rep_id', 'date', 'status', 'q'])) }}" class="btn btn-success fw-bold btn-sm"><i class="fas fa-file-excel"></i> تصدير القائمة Excel</a>
        @endif
    </x-page-header>
    @include('partials.rep-scope-line', ['scopeNote' => 'تُوزَّع تسديدات المندوب على فواتيره بالأقدم؛ تظهر هنا حصة فواتير مناطقك فقط.'])3

    @unless ($canManage)
        <div class="alert alert-info small"><i class="fas fa-circle-info me-1"></i> عرض فقط: المراجعة والتصدير للمحاسب والأدمن.</div>
    @endunless

    <form method="GET" action="{{ route('finance.rep-payments') }}" class="card card-body mb-3">
        <div class="row g-2 align-items-end small">
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold mb-1" for="rp_rep">المندوب</label>
                <select name="rep_id" id="rp_rep" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($allReps as $r)<option value="{{ $r->id }}" @selected((string) $filters['rep_id'] === (string) $r->id)>{{ $r->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-md-2"><label class="form-label fw-bold mb-1" for="rp_date">التاريخ</label><input type="date" name="date" id="rp_date" class="form-control form-control-sm" value="{{ $filters['date'] }}"></div>
            <div class="col-6 col-md-3">
                <label class="form-label fw-bold mb-1" for="rp_status">الحالة</label>
                <select name="status" id="rp_status" class="form-select form-select-sm">
                    <option value="pending" @selected($filters['status'] === 'pending')>غير مُصدَّرة</option>
                    <option value="needs_review" @selected($filters['status'] === 'needs_review')>بحاجة مراجعة</option>
                    <option value="verified" @selected($filters['status'] === 'verified')>تم التحقق</option>
                    <option value="exported" @selected($filters['status'] === 'exported')>مُصدَّرة</option>
                </select>
            </div>
            <div class="col-6 col-md-2"><label class="form-label fw-bold mb-1" for="rp_q">الصيدلية</label><input type="search" name="q" id="rp_q" class="form-control form-control-sm" value="{{ $filters['q'] }}"></div>
            <div class="col-12 col-md-2 d-grid"><button class="btn btn-sm btn-primary fw-bold"><i class="fas fa-filter"></i> تطبيق</button></div>
        </div>
    </form>

    {{-- 1) القائمة مجمّعة بالمندوب --}}
    @forelse ($payments as $repId => $rows)
        <div class="card mb-3 overflow-hidden rep-group">
            <div class="card-header bg-white py-2 fw-bold">
                @if (isset($repNames[$repId]) && auth()->user()->hasRole('admin', 'rep_supervisor'))<a href="{{ route('rep-supervisor.reps.show', $repId) }}" class="text-reset" data-rep-profile-link>{{ $repNames[$repId]->name }}</a>@else{{ $repNames[$repId]->name ?? '—' }}@endif <span class="text-muted small fw-normal">{{ $repNames[$repId]->cost_center_label ?? '' }}</span>
                <span class="badge text-bg-light border">{{ $rows->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th class="ps-3">الإيصال</th><th>الصيدلية</th><th>المبلغ</th><th>السعر</th><th>بالليرة</th><th>الدفتر</th><th>الموقع</th><th>تنبيهات</th><th>وقت التحصيل</th><th>المراجعة</th><th class="pe-3"></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $p)
                            @php [$ft, $fc] = $flagLabels[$p->location_flag] ?? [$p->location_flag, 'text-bg-light']; [$rt, $rc] = $reviewLabels[$p->review_status] ?? [$p->review_status, 'text-bg-light']; @endphp
                            <tr class="rep-payment-row {{ $p->isVoided() ? 'text-muted text-decoration-line-through' : '' }}">
                                <td class="ps-3">
                                    <a href="{{ route('payments.image', $p) }}" target="_blank" rel="noopener" title="تكبير">
                                        <img src="{{ route('payments.image', $p) }}" alt="صورة الإيصال" loading="lazy" class="img-thumbnail" style="width: 56px; height: 56px; object-fit: cover">
                                    </a>
                                </td>
                                <td class="fw-bold">@if ($statementUrl = \App\Services\Reps\PharmacyStatementService::linkFor(auth()->user(), $p->customer_id))<a href="{{ $statementUrl }}" class="text-reset" data-statement-link>{{ $p->customer?->name }}</a>@else{{ $p->customer?->name }}@endif<div class="text-muted fw-normal">{{ $p->customer?->area ?: '—' }}</div></td>
                                <td class="font-monospace text-nowrap">{{ $money($p->amount) }} {{ $p->currency === 'USD' ? '$' : 'ل.س' }}</td>
                                <td class="font-monospace">{{ $p->exchange_rate !== null ? rtrim(rtrim(number_format((float) $p->exchange_rate, 4, '.', ''), '0'), '.') : '—' }}</td>
                                <td class="font-monospace text-nowrap">{{ $p->amount_syp !== null ? $money($p->amount_syp) : 'بانتظار السعر' }}</td>
                                <td class="font-monospace" dir="ltr">{{ $p->book_receipt_no }}</td>
                                <td><span class="badge {{ $fc }}">{{ $ft }}</span></td>
                                <td>
                                    @if ($p->exceeds_known_debt)<span class="badge text-bg-warning">أكبر من الدَين المعروف</span>@endif
                                    @if ($p->duplicate_book_no)<span class="badge text-bg-danger">رقم دفتر مكرر</span>@endif
                                    @if ($p->isVoided())<span class="badge text-bg-dark" title="{{ $p->void_reason }}">ملغاة</span>@endif
                                    @if ($p->status !== 'pending')<span class="badge text-bg-primary">مُصدَّرة</span>@endif
                                </td>
                                <td class="font-monospace text-nowrap">{{ $p->collected_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</td>
                                <td><span class="badge {{ $rc }}">{{ $rt }}</span>@if ($p->review_note)<div class="fs-8 text-muted">{{ $p->review_note }}</div>@endif</td>
                                <td class="pe-3">
                                    @if ($canManage && ! $p->isVoided() && $p->status === 'pending')
                                        <div class="d-flex flex-wrap gap-1">
                                            <form action="{{ route('finance.rep-payments.review', $p) }}" method="POST" class="m-0">
                                                @csrf
                                                <input type="hidden" name="review_status" value="verified">
                                                <button class="btn btn-sm btn-outline-success"><i class="fas fa-check"></i> تم التحقق</button>
                                            </form>
                                            <form action="{{ route('finance.rep-payments.review', $p) }}" method="POST" class="d-flex gap-1 m-0">
                                                @csrf
                                                <input type="hidden" name="review_status" value="needs_review">
                                                <input type="text" name="review_note" class="form-control form-control-sm" placeholder="الملاحظة" required style="width: 120px">
                                                <button class="btn btn-sm btn-outline-warning">بحاجة مراجعة</button>
                                            </form>
                                            @if ($p->currency === 'USD' && $p->amount_syp === null)
                                                <form action="{{ route('finance.payments.rate', $p) }}" method="POST" class="d-flex gap-1 m-0">
                                                    @csrf
                                                    <input type="text" name="exchange_rate" class="form-control form-control-sm" dir="ltr" style="width: 80px" value="{{ $rateRange[2] }}" required>
                                                    <button class="btn btn-sm btn-warning">ضبط السعر</button>
                                                </form>
                                            @endif
                                            @if ($voidable[$p->id] ?? false)
                                                <form action="{{ route('payments.void', $p) }}" method="POST" class="d-flex gap-1 m-0" onsubmit="return confirm('إلغاء هذه الدفعة؟')">
                                                    @csrf
                                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="سبب الإلغاء" required minlength="3" style="width: 110px">
                                                    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-ban"></i></button>
                                                </form>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card mb-3"><x-empty-state icon="fa-receipt" title="لا دفعات مندوبين" message="لا دفعات تطابق الفلترة." /></div>
    @endforelse

    {{-- 2) التصدير للمالية (معاينة ثم تأكيد) --}}
    @if ($canManage)
        <div class="card mb-4 overflow-hidden" id="rep-export">
            <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-file-export text-primary me-1"></i> تصدير دفعات المندوبين للمالية</div>
            <div class="card-body">
                <form method="GET" action="{{ route('finance.rep-payments') }}" class="mb-3" id="repPreviewForm">
                    <div class="small fw-bold mb-1">المندوبون:</div>
                    <div class="d-flex flex-wrap gap-3 mb-2">
                        @foreach ($reps as $r)
                            <label class="form-check-label"><input type="checkbox" class="form-check-input" name="reps[]" value="{{ $r->id }}" @checked(in_array($r->id, $selectedIds, true))> {{ $r->name }}</label>
                        @endforeach
                    </div>
                    @if ($preview['needs_review']->isNotEmpty())
                        <div class="small fw-bold mb-1">دفعات «بحاجة مراجعة» (غير مشمولة افتراضياً؛ اكتب سبباً لشمولها):</div>
                        @foreach ($preview['needs_review'] as $p)
                            <div class="d-flex flex-wrap gap-2 align-items-center small mb-1">
                                <span>{{ $p->customer?->name }} — <span class="font-monospace">{{ $money($p->amount_syp) }}</span> — دفتر {{ $p->book_receipt_no }}</span>
                                <input type="text" name="include[{{ $p->id }}]" class="form-control form-control-sm" style="max-width: 260px" placeholder="سبب الشمول (إلزامي للشمول)" value="{{ $preview['overrides'][$p->id] ?? '' }}">
                            </div>
                        @endforeach
                    @endif
                    <button class="btn btn-sm btn-outline-primary fw-bold"><i class="fas fa-eye"></i> تحديث المعاينة</button>
                </form>

                @foreach ($preview['blockers'] as $b)
                    <div class="alert alert-danger py-2 small fw-bold mb-2 export-blocker"><i class="fas fa-ban me-1"></i> {{ $b }}</div>
                @endforeach

                @if ($preview['groups']->isNotEmpty())
                    <div class="table-responsive mb-2">
                        <table class="table table-sm small mb-0">
                            <thead class="table-light"><tr><th>المندوب</th><th>الزبون</th><th>مركز الكلفة</th><th>الدفعات</th><th>المبلغ</th></tr></thead>
                            <tbody>
                                @foreach ($preview['groups'] as $g)
                                    <tr class="rep-preview-row"><td>{{ $g['rep']->name }}</td><td>{{ $g['customer']?->name }}</td><td>{{ $g['rep']->cost_center_label }}</td><td>{{ $g['payments']->count() }}</td><td class="font-monospace fw-bold">{{ $money($g['cents'] / 100) }}</td></tr>
                                @endforeach
                            </tbody>
                            <tfoot><tr class="fw-bold"><td colspan="4">المجموع</td><td class="font-monospace" id="rep-preview-total">{{ $money($preview['total_syp']) }}</td></tr></tfoot>
                        </table>
                    </div>
                @endif

                <form action="{{ route('finance.rep-payments.export') }}" method="POST" class="d-flex flex-wrap gap-2 align-items-center m-0" onsubmit="this.querySelector('button').disabled = true">
                    @csrf
                    <input type="hidden" name="signature" value="{{ $preview['signature'] }}">
                    @foreach ($preview['reps'] as $r)<input type="hidden" name="reps[]" value="{{ $r->id }}">@endforeach
                    @foreach ($preview['overrides'] as $id => $reason)<input type="hidden" name="include[{{ $id }}]" value="{{ $reason }}">@endforeach
                    <label class="form-check-label small fw-bold">
                        <input type="checkbox" name="confirm" value="1" class="form-check-input" required @disabled($preview['blockers'] !== [])>
                        أؤكد أن هذه الدفعات ستُسدَّد الآن في البرنامج المحاسبي (لا تعديل ولا إلغاء بعد التصدير).
                    </label>
                    <button class="btn btn-success fw-bold" @disabled($preview['blockers'] !== [])><i class="fas fa-file-export"></i> تصدير للمالية</button>
                </form>
            </div>
        </div>
    @endif

    @if ($restricted ?? false)
        <div class="alert alert-secondary small" id="rep-exports-restricted">أرشيف دفعات التصدير يخص كل المندوبين فلا يظهر في العرض المقيَّد بنطاقك.</div>
    @else
    {{-- 3) الأرشيف --}}
    <div class="card mb-4 overflow-hidden" id="rep-exports">
        <div class="card-header bg-white py-3 fw-bold"><i class="fas fa-box-archive text-primary me-1"></i> دفعات تصدير المندوبين</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 small">
                <thead class="table-light"><tr><th class="ps-3">الرقم</th><th>الوقت</th><th>بواسطة</th><th>المندوبون</th><th>الدفعات</th><th>بالليرة</th><th class="pe-3">الملفات</th></tr></thead>
                <tbody>
                    @forelse ($exports as $e)
                        <tr class="{{ $justExported == $e->id ? 'table-success' : '' }}">
                            <td class="ps-3 fw-bold">{{ $e->repOrder() }}</td>
                            <td class="font-monospace text-nowrap">{{ $e->exported_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</td>
                            <td>{{ $e->exporter?->name }}</td>
                            <td>{{ implode('، ', $e->meta['rep_names'] ?? []) }}</td>
                            <td>{{ $e->payments_count }}</td>
                            <td class="font-monospace">{{ $money($e->total_syp) }}</td>
                            <td class="pe-3">
                                @if ($canManage)
                                    <div class="d-flex flex-wrap gap-1 {{ $justExported == $e->id ? 'auto-download' : '' }}">
                                        <a href="{{ route('finance.exports.download', [$e, 'import']) }}" class="btn btn-sm btn-success fw-bold"><i class="fas fa-file-excel"></i> ملف الاستيراد</a>
                                        <a href="{{ route('finance.exports.download', [$e, 'receipts']) }}" class="btn btn-sm btn-outline-success fw-bold">كشف المقبوضات</a>
                                        @if ($usdExports[$e->id] ?? false)
                                            <a href="{{ route('finance.exports.download', [$e, 'usd']) }}" class="btn btn-sm btn-outline-secondary fw-bold">الدولار</a>
                                        @endif
                                    </div>
                                @else — @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted ps-3 py-3">لا دفعات تصدير للمندوبين بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endif
@endsection

@push('scripts')
    <script>
        // بعد التأكيد: تنزيل الملفات واحداً تلو الآخر
        document.querySelectorAll('.auto-download a').forEach((a, i) => setTimeout(() => {
            const frame = document.createElement('iframe');
            frame.style.display = 'none';
            frame.src = a.href;
            document.body.appendChild(frame);
        }, 600 * i));
    </script>
@endpush
