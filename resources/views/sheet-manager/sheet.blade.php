@extends('layouts.panel')

@section('title', 'الكشف ' . $sheet->displayTitle())

@php
    $statusLabels = ['preparing' => 'قيد التجهيز', 'pending' => 'بانتظار البدء', 'in_progress' => 'قيد التحصيل'];
    $total = $invoices->sum('invoice_amount');
    $allCount = (int) $totals->invoices;
    // المرحلة 8C: الأعداد للنتائج المعروضة من أصل كل فواتير الكشف
    $subtitle = 'الموزعون: ' . $sheet->distributorsLabel() . ' — '
        . ($filtering
            ? $invoices->count() . ' من أصل ' . $allCount . ' فاتورة، ' . $invoices->unique('customer_name')->count() . ' من أصل ' . $totals->customers . ' زبوناً — إجمالي المعروض ' . number_format($total, 2) . ' من أصل ' . number_format((float) $totals->amount, 2)
            : $allCount . ' فاتورة، ' . $totals->customers . ' زبوناً — الإجمالي ' . number_format((float) $totals->amount, 2));
    $none = \App\Services\SheetInvoiceFilter::NONE;
@endphp

@push('styles')
    <style>
        /* المرحلة 8C: الجدول يتحول إلى بطاقات على الجوال (بلا تمرير أفقي) */
        @media (max-width: 767.98px) {
            .stack-sm thead { display: none; }
            .stack-sm tr { display: block; border-bottom: 1px solid var(--bs-border-color); padding: .5rem 0; }
            .stack-sm td { display: flex; gap: .5rem; justify-content: space-between; align-items: flex-start; border: 0; padding: .15rem .75rem !important; overflow-wrap: anywhere; }
            .stack-sm td[data-label]::before { content: attr(data-label); color: var(--bs-secondary-color); font-weight: 600; flex-shrink: 0; }
            .stack-sm td.row-actions { display: block; min-width: 0 !important; text-align: start !important; }
            .stack-sm .table-responsive, .stack-sm { overflow-x: visible; }
        }
        @media (min-width: 768px) { .stack-sm td.row-actions { min-width: 220px; } }
    </style>
@endpush

@section('content')
    <x-page-header :title="'كشف ' . $sheet->displayTitle()" :subtitle="$subtitle" icon="fa-file-invoice">
        <x-status-badge :status="$sheet->status" :reason="$sheet->status_reason" class="align-self-center" />
        @include('partials.export-button', ['sheet' => $sheet, 'areas' => collect($filterOptions['areas'])->except($none)->map(fn ($a) => $a['label'])->sort()->all()])
        @if ($sheet->isPreparing())
            <form action="{{ route('sheet-manager.sheets.send', $sheet) }}" method="POST" class="m-0">
                @csrf
                <button class="btn btn-sm btn-success fw-bold" @disabled($blockers)><i class="fas fa-paper-plane"></i> إرسال إلى مشرف التوزيع</button>
            </form>
        @endif
        @if ($canRecall)
            <form action="{{ route('sheet-manager.sheets.recall', $sheet) }}" method="POST" class="m-0">
                @csrf
                <button class="btn btn-sm btn-outline-warning fw-bold" onclick="return confirm('سحب الكشف من مشرف التوزيع وإعادته إلى قيد التجهيز؟')">
                    <i class="fas fa-rotate-left"></i> سحب من المشرف
                </button>
            </form>
        @endif
        @if ($draftable)
            <button class="btn btn-sm btn-outline-secondary fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#draftForm">
                <i class="fas fa-file-pen"></i> إغلاق كمسودة
            </button>
        @endif
        @if ($sheet->batch_id)
            <a href="{{ route('sheet-manager.batches.show', $sheet->batch_id) }}" class="btn btn-sm btn-light border fw-bold"><i class="fas fa-arrow-right"></i> الدفعة</a>
        @endif
    </x-page-header>

    @include('partials.supplement-line', ['sheet' => $sheet, 'route' => 'sheet-manager.sheets.show'])
    @include('partials.sheet-notices', ['sheet' => $sheet, 'mode' => 'recent'])
    @include('partials.ack-panel', ['sheet' => $sheet])
    @include('partials.status-explanation', ['sheet' => $sheet])

    @if ($draftable)
        <div class="collapse {{ $errors->has('reason') ? 'show' : '' }} mb-3" id="draftForm">
            <form action="{{ route('sheet-manager.sheets.draft', $sheet) }}" method="POST" class="card card-body bg-light">
                @csrf
                <label for="draft_reason" class="form-label fw-bold small">سبب الإغلاق كمسودة (إلزامي):</label>
                <textarea name="reason" id="draft_reason" rows="2" required minlength="3" class="form-control mb-2"></textarea>
                <div class="small text-muted mb-2">تُنقل كل فواتير الكشف إلى "بلا كشف" لإعادة توزيعها. لا حذف لأي كشف أو فاتورة.</div>
                <div><button class="btn btn-secondary btn-sm fw-bold"><i class="fas fa-check"></i> تأكيد</button></div>
            </form>
        </div>
    @endif

    @if ($sheet->sent_at)
        <p class="small text-muted">أُرسل إلى مشرف التوزيع في {{ $sheet->sent_at->format('Y-m-d g:i A') }}{{ $sheet->sender ? ' بواسطة ' . $sheet->sender->name : '' }}.</p>
    @endif

    @if ($blockers)
        <div class="alert alert-warning small">
            <div class="fw-bold mb-1"><i class="fas fa-hand me-1"></i> لا يمكن إرسال الكشف بعد:</div>
            <ul class="mb-0">@foreach ($blockers as $b)<li>{{ $b }}</li>@endforeach</ul>
            @foreach ($pendingItemBatches ?? [] as $batchId)
                <a href="{{ route('sheet-manager.batches.show', ['batch' => $batchId, 'status' => 'pending']) }}#review" class="btn btn-sm btn-warning fw-bold mt-2">
                    <i class="fas fa-list-check"></i> عرض العناصر المعلّقة لهذا الكشف
                </a>
            @endforeach
        </div>
    @endif

    @if ($conflicts)
        <div class="alert alert-danger small">
            <i class="fas fa-triangle-exclamation me-1"></i>
            <strong>زبائن لهم فواتير في كشف آخر في اليوم نفسه:</strong>
            @foreach ($conflicts as $conflict)
                {{ $conflict['customer_name'] }} (أوامر: {{ collect($conflict['sheets'])->pluck('order')->implode('، ') }})@if (! $loop->last)، @endif
            @endforeach
        </div>
    @endif

    @unless ($canEdit)
        <div class="alert alert-light border small"><i class="fas fa-lock me-1"></i> فواتير هذا الكشف غير قابلة للتعديل في حالته الحالية.</div>
    @endunless

    @if ($canEdit)
        {{-- إضافة فاتورة --}}
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('sheet-manager.sheets.show', $sheet) }}" class="d-flex gap-2">
                    <input type="text" name="q" class="form-control" value="{{ request('q') }}" placeholder="إضافة فاتورة: ابحث برقم الفاتورة أو اسم الزبون (في بلا كشف وكشوف اليوم نفسه)">
                    <button class="btn btn-primary fw-bold text-nowrap"><i class="fas fa-magnifying-glass"></i> بحث</button>
                </form>
                @if (request()->filled('q'))
                    <div class="table-responsive mt-2">
                        <table class="table table-sm align-middle mb-0 small">
                            @forelse ($searchResults as $result)
                                <tr>
                                    <td class="font-monospace">{{ $result->invoice_number }}</td>
                                    <td>{{ $result->customer_name }}</td>
                                    <td>{{ $result->placement === 'unassigned' ? 'بلا كشف' : 'كشف ' . $result->distributionSheet?->title() }}</td>
                                    <td class="font-monospace">{{ number_format($result->invoice_amount, 2) }}</td>
                                    <td class="text-end">
                                        <form action="{{ route('sheet-manager.invoices.place', $result) }}" method="POST" class="m-0">
                                            @csrf
                                            <input type="hidden" name="sheet_id" value="{{ $sheet->id }}">
                                            <button class="btn btn-sm btn-success fw-bold"><i class="fas fa-plus"></i> إضافة</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-muted">لا نتائج.</td></tr>
                            @endforelse
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- عمليات جماعية --}}
        <form id="bulkForm" method="POST" action="{{ route('sheet-manager.invoices.move') }}" class="card card-body mb-3 py-2">
            @csrf
            <div class="row g-2 align-items-end small">
                <div class="col-md-4">
                    <label class="form-label fs-8 mb-0">نقل المحدد إلى:</label>
                    <select name="sheet_id" class="form-select form-select-sm">
                        <option value="">— اختر كشفاً —</option>
                        @foreach ($targetSheets as $target)
                            <option value="{{ $target->id }}">{{ $target->title() }} ({{ $statusLabels[$target->status] ?? $target->status }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-sm btn-primary fw-bold" formaction="{{ route('sheet-manager.invoices.move') }}"><i class="fas fa-right-left"></i> نقل المحدد</button>
                </div>
                <div class="col-md-4">
                    <label class="form-label fs-8 mb-0">أو إخراجه إلى "بلا كشف" بسبب:</label>
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="السبب (إلزامي للإخراج)">
                </div>
                <div class="col-md-2 d-grid">
                    <button class="btn btn-sm btn-outline-danger fw-bold" formaction="{{ route('sheet-manager.invoices.remove') }}"><i class="fas fa-right-from-bracket"></i> إخراج المحدد</button>
                </div>
            </div>
        </form>
    @endif

    {{-- المرحلة 8C: فلترة فواتير الكشف (على الخادم، والفلاتر في الرابط) --}}
    <form method="GET" action="{{ route('sheet-manager.sheets.show', $sheet) }}" class="card card-body mb-3 py-2" id="sheetFilterForm">
        <div class="row g-2 align-items-end small">
            <div class="col-12 col-md-4">
                <label for="ff_search" class="form-label fs-8 mb-0 fw-bold">بحث في فواتير الكشف</label>
                <input type="search" name="search" id="ff_search" class="form-control form-control-sm" value="{{ $filters['search'] ?? '' }}" placeholder="اسم الصيدلية أو رقم الفاتورة">
            </div>
            <div class="col-6 col-md-2">
                <label for="ff_area" class="form-label fs-8 mb-0 fw-bold">المنطقة</label>
                <select name="area" id="ff_area" class="form-select form-select-sm">
                    <option value="">كل المناطق</option>
                    @foreach ($filterOptions['areas'] as $key => $area)
                        <option value="{{ $key }}" @selected(($filters['area'] ?? '') === (string) $key)>{{ $area['label'] }} ({{ $area['count'] }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="ff_block" class="form-label fs-8 mb-0 fw-bold">الكتلة</label>
                <select name="block" id="ff_block" class="form-select form-select-sm">
                    <option value="">كل الكتل</option>
                    @foreach ($filterOptions['blocks'] as $key => $block)
                        <option value="{{ $key }}" @selected(($filters['block'] ?? '') === (string) $key)>{{ $block['label'] }} ({{ $block['count'] }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label for="ff_origin" class="form-label fs-8 mb-0 fw-bold">الأصل/السبب</label>
                <select name="origin" id="ff_origin" class="form-select form-select-sm">
                    <option value="">الكل</option>
                    @foreach ($filterOptions['origins'] as $key => $count)
                        <option value="{{ $key }}" @selected(($filters['origin'] ?? '') === $key)>{{ \App\Services\SheetInvoiceFilter::ORIGINS[$key] }}{{ $key === 'latest_batch' && $filterOptions['latest_batch'] ? ' (' . $filterOptions['latest_batch']->created_at?->format('Y-m-d g:i A') . ')' : '' }} ({{ $count }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label for="ff_min" class="form-label fs-8 mb-0 fw-bold">القيمة من</label>
                <input type="number" step="0.01" min="0" name="min" id="ff_min" class="form-control form-control-sm" value="{{ $filters['min'] ?? '' }}" inputmode="decimal">
            </div>
            <div class="col-6 col-md-2">
                <label for="ff_max" class="form-label fs-8 mb-0 fw-bold">إلى</label>
                <input type="number" step="0.01" min="0" name="max" id="ff_max" class="form-control form-control-sm" value="{{ $filters['max'] ?? '' }}" inputmode="decimal">
            </div>
            <div class="col-12 col-md-3">
                <label for="ff_sort" class="form-label fs-8 mb-0 fw-bold">الفرز</label>
                <select name="sort" id="ff_sort" class="form-select form-select-sm">
                    @foreach (\App\Services\SheetInvoiceFilter::SORTS as $key => $label)
                        <option value="{{ $key }}" @selected(($filters['sort'] ?? 'customer') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($filterOptions['review'] > 0)
                <div class="col-12 col-md-2">
                    <label class="form-check-label fw-bold">
                        <input type="checkbox" name="review" value="1" class="form-check-input" @checked(! empty($filters['review']))>
                        يحتاج مراجعة ({{ $filterOptions['review'] }})
                    </label>
                </div>
            @endif
            <div class="col-12 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary fw-bold flex-grow-1"><i class="fas fa-filter"></i> تطبيق</button>
                <a href="{{ route('sheet-manager.sheets.show', $sheet) }}" class="btn btn-sm btn-light border fw-bold" data-clear-filters><i class="fas fa-xmark"></i> مسح الفلاتر</a>
            </div>
        </div>
        <div class="mt-2 small fw-bold" id="filterCounter">
            <i class="fas fa-list-ol text-primary"></i> {{ $invoices->count() }} من أصل {{ $allCount }} فاتورة
            @if ($filtering)<span class="text-muted fw-normal">— إجمالي المعروض {{ number_format($total, 2) }}</span>@endif
        </div>
    </form>

    <div class="card overflow-hidden stack-sm">
        <div class="table-responsive">
            <table class="table align-middle mb-0 small" id="sheetInvoicesTable">
                <thead class="table-light">
                    <tr>
                        @if ($canEdit)<th class="ps-3" style="width: 32px;"><input type="checkbox" class="form-check-input" id="checkAll" aria-label="تحديد الكل"></th>@endif
                        <th class="{{ $canEdit ? '' : 'ps-3' }}">الزبون</th>
                        <th>المنطقة</th>
                        <th>الفاتورة</th>
                        <th>التسليم</th>
                        <th>الأصناف</th>
                        <th>القيمة</th>
                        @if ($canEdit)<th class="pe-3 text-end">إجراءات</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            @if ($canEdit)
                                <td class="ps-3"><input type="checkbox" class="form-check-input row-check" name="invoice_ids[]" value="{{ $invoice->id }}" form="bulkForm" aria-label="تحديد"></td>
                            @endif
                            <td class="{{ $canEdit ? '' : 'ps-3' }} fw-bold" data-label="الزبون">
                                {{ $invoice->customer_name }}
                                @if (in_array($invoice->customer_id, $activityCustomers))
                                    <span class="badge bg-success-subtle text-success-emphasis" title="سُجّلت زيارة أو دفعة">نشاط</span>
                                @endif
                            </td>
                            <td data-label="المنطقة">
                                {{ $invoice->area ?? 'بلا منطقة' }}
                                @if ($invoice->area_overridden)
                                    <span class="badge bg-info-subtle text-info-emphasis" title="منطقة معدّلة لهذه الفاتورة وحدها">معدّلة</span>
                                @endif
                            </td>
                            <td class="font-monospace text-nowrap" data-label="الفاتورة">
                                {{ $invoice->invoice_number }}
                                @if ($invoice->manual_placement)
                                    <span class="badge bg-light text-secondary border" title="وُضعت يدوياً">يدوي</span>
                                @endif
                                @if ($invoice->added_after_send_at)
                                    <span class="badge text-bg-warning added-after-send" title="{{ $invoice->added_after_send_at->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}">أُضيفت بعد الإرسال</span>
                                @endif
                                @if ($invoice->net_uncertain)
                                    <div class="text-warning-emphasis small net-uncertain">قد يختلف الصافي عن برنامج المحاسبة: حسم هدايا/إضافات</div>
                                @endif
                            </td>
                            <td class="font-monospace text-nowrap" data-label="التسليم">{{ $invoice->delivery_date?->format('Y-m-d') ?? '—' }}</td>
                            <td data-label="الأصناف">{{ $invoice->isExternal() ? 'دفعة خارجية' : $invoice->lines_count }}</td>
                            <td class="font-monospace fw-bold" data-label="القيمة">{{ number_format($invoice->invoice_amount, 2) }}</td>
                            @if ($canEdit)
                                <td class="pe-3 text-end row-actions">
                                    @unless ($invoice->isExternal())
                                        <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#row-{{ $invoice->id }}">
                                            <i class="fas fa-ellipsis"></i> إجراءات
                                        </button>
                                        <div class="collapse mt-2 text-start" id="row-{{ $invoice->id }}">
                                            <form action="{{ route('sheet-manager.invoices.move') }}" method="POST" class="d-flex gap-1 mb-1">
                                                @csrf
                                                <input type="hidden" name="invoice_ids[]" value="{{ $invoice->id }}">
                                                <select name="sheet_id" class="form-select form-select-sm" required aria-label="الكشف الهدف">
                                                    <option value="">انقل إلى كشف آخر…</option>
                                                    @foreach ($targetSheets as $target)
                                                        <option value="{{ $target->id }}">{{ $target->title() }}</option>
                                                    @endforeach
                                                </select>
                                                <button class="btn btn-sm btn-primary text-nowrap">نقل</button>
                                            </form>
                                            <form action="{{ route('sheet-manager.invoices.remove') }}" method="POST" class="d-flex gap-1 mb-1">
                                                @csrf
                                                <input type="hidden" name="invoice_ids[]" value="{{ $invoice->id }}">
                                                <input type="text" name="reason" class="form-control form-control-sm" required minlength="3" placeholder="سبب الإخراج">
                                                <button class="btn btn-sm btn-outline-danger text-nowrap">أخرجها من الكشف</button>
                                            </form>
                                            <form action="{{ route('sheet-manager.invoices.area', $invoice) }}" method="POST" class="d-flex gap-1">
                                                @csrf
                                                <select name="area_key" class="form-select form-select-sm" required aria-label="المنطقة">
                                                    <option value="">تغيير منطقة هذه الفاتورة…</option>
                                                    @foreach ($areaOptions as $key => $label)
                                                        <option value="{{ $key }}" @selected($invoice->area_key === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                                <button class="btn btn-sm btn-outline-primary text-nowrap">حفظ</button>
                                            </form>
                                        </div>
                                    @endunless
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="8"><x-empty-state icon="fa-file-circle-xmark" :title="$filtering ? 'لا فواتير تطابق الفلاتر' : 'لا توجد فواتير في هذا الكشف'" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const checkAll = document.getElementById('checkAll');
        if (checkAll) {
            checkAll.addEventListener('change', () => document.querySelectorAll('.row-check').forEach(c => c.checked = checkAll.checked));
        }
    </script>
@endpush
