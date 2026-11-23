{{-- المرحلة 7E: تبويب «الكشوف» — التعارضات، جدول الكشوف بعمود «جديدة/معدّلة»، و«بلا كشف» إن وُجدت، وما يخص الدفعات القديمة --}}
@php
    $statusLabels = ['preparing' => 'قيد التجهيز', 'pending' => 'بانتظار البدء', 'in_progress' => 'قيد التحصيل', 'completed' => 'مكتمل', 'draft' => 'مسودة'];
    $stat = fn ($key, $default = 0) => $batch->stat($key, $default);
@endphp

{{-- تعارض: زبون في أكثر من كشف --}}
@if ($conflicts)
    <div class="card border-danger mb-3">
        <div class="card-header text-bg-danger fw-bold py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="fas fa-triangle-exclamation me-1"></i> زبائن لهم فواتير في أكثر من كشف في اليوم نفسه ({{ count($conflicts) }}) — غالباً خطأ إدخال</span>
            @if ($batch->conflictsAcknowledged())
                <span class="badge text-bg-light">أُقرّ بها {{ $batch->conflicts_acknowledged_at->format('Y-m-d g:i A') }}{{ $batch->acknowledgedBy ? ' — ' . $batch->acknowledgedBy->name : '' }}</span>
            @else
                <form action="{{ route('sheet-manager.batches.acknowledge', $batch) }}" method="POST" class="m-0">
                    @csrf
                    <button class="btn btn-sm btn-light fw-bold" onclick="return confirm('الإقرار بهذه التعارضات يسمح بإرسال الكشوف كما هي. متابعة؟')">
                        <i class="fas fa-check-double"></i> أقرّ بهذه التعارضات
                    </button>
                </form>
            @endif
        </div>
        <div class="card-body small">
            @foreach ($conflicts as $conflict)
                <div class="border rounded-3 p-2 mb-2">
                    <div class="fw-bold mb-1">{{ $conflict['customer_name'] }}</div>
                    @foreach ($conflict['sheets'] as $part)
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <a href="{{ route('sheet-manager.sheets.show', $part['sheet_id']) }}" class="badge text-bg-secondary text-decoration-none">أمر #{{ $part['order'] }}</a>
                            @foreach ($part['invoices'] as $inv)
                                <span class="font-monospace">{{ $inv['number'] }} ({{ number_format($inv['amount'], 0) }})</span>
                            @endforeach
                            <form action="{{ route('sheet-manager.invoices.move') }}" method="POST" class="d-flex gap-1 m-0">
                                @csrf
                                @foreach ($part['invoices'] as $inv)
                                    <input type="hidden" name="invoice_ids[]" value="{{ $inv['id'] }}">
                                @endforeach
                                <select name="sheet_id" class="form-select form-select-sm" required aria-label="نقل إلى">
                                    <option value="">نقلها إلى…</option>
                                    @foreach ($conflict['sheets'] as $other)
                                        @if ($other['sheet_id'] !== $part['sheet_id'])
                                            <option value="{{ $other['sheet_id'] }}">أمر #{{ $other['order'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button class="btn btn-sm btn-outline-primary">نقل</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- الكشوف --}}
<div class="card mb-3">
    <div class="card-header bg-white py-2 fw-bold"><i class="fas fa-layer-group text-primary me-1"></i> الكشوف ({{ $sheets->count() }})</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0 small table-cards" id="batchSheetsTable">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">أمر التسليم</th>
                    <th>الفواتير</th>
                    <th>فواتير جديدة/معدّلة</th>
                    <th>الزبائن</th>
                    <th>المناطق</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th class="pe-3 text-end">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sheets as $sheet)
                    @php $ch = $sheetChanges[$sheet->id] ?? []; @endphp
                    <tr>
                        <td class="ps-3 fw-bold" data-label="أمر التسليم">
                            <a href="{{ route('sheet-manager.sheets.show', $sheet) }}">#{{ $sheet->delivery_order_no !== null ? $sheet->orderLabel() : '—' }}</a>
                            @if ($sheet->batch_id !== $batch->id)
                                <span class="badge bg-light text-secondary border ms-1" title="كشف من دفعة سابقة أضيفت إليه فواتير">دفعة سابقة</span>
                            @endif
                        </td>
                        <td data-label="الفواتير">{{ $sheet->invoices_count }}</td>
                        <td data-label="جديدة/معدّلة" class="sheet-changes">
                            @if ($ch)
                                <span class="text-success fw-bold">{{ $ch['new'] ?? 0 }} جديدة</span> / <span class="text-warning-emphasis fw-bold">{{ $ch['changed'] ?? 0 }} معدّلة</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td data-label="الزبائن">{{ $sheet->customers_count }}</td>
                        <td data-label="المناطق">{{ $sheet->areas_count }}</td>
                        <td class="font-monospace fw-bold" data-label="الإجمالي">{{ number_format($sheet->invoices_total, 2) }}</td>
                        <td data-label="الحالة"><x-status-badge :status="$sheet->status" :reason="$sheet->status_reason" /></td>
                        <td class="pe-3 text-end">
                            <div class="d-flex flex-wrap gap-1 justify-content-end">
                                <a href="{{ route('sheet-manager.sheets.show', $sheet) }}" class="btn btn-sm btn-light border fw-bold"><i class="fas fa-eye"></i> الكشف</a>
                                @include('partials.export-button', ['sheet' => $sheet])
                                @if ($sheet->isPreparing())
                                    <form action="{{ route('sheet-manager.sheets.send', $sheet) }}" method="POST" class="m-0"
                                        @if ($costCenterNote) onsubmit="return confirm(document.getElementById('cost-center-text').textContent + ' إرسال الكشف رغم ذلك؟')" @endif>
                                        @csrf
                                        @if ($costCenterNote)<input type="hidden" name="confirm_no_cost_center" value="1">@endif
                                        <button class="btn btn-sm btn-success fw-bold"><i class="fas fa-paper-plane"></i> إرسال</button>
                                    </form>
                                    <button class="btn btn-sm btn-outline-secondary fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#draft-{{ $sheet->id }}">
                                        <i class="fas fa-file-pen"></i> إغلاق كمسودة
                                    </button>
                                @endif
                            </div>
                            @if ($sheet->isPreparing())
                                <div class="collapse mt-2" id="draft-{{ $sheet->id }}">
                                    <form action="{{ route('sheet-manager.sheets.draft', $sheet) }}" method="POST" class="d-flex gap-1">
                                        @csrf
                                        <input type="text" name="reason" class="form-control form-control-sm" required minlength="3" placeholder="سبب الإغلاق (إلزامي)">
                                        <button class="btn btn-sm btn-secondary fw-bold text-nowrap">تأكيد</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8"><x-empty-state title="لم يُنشأ أي كشف من هذه الدفعة" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- بلا كشف: يظهر فقط إن وُجدت --}}
@if ($unassigned->isNotEmpty())
    <div class="card mb-3 border-danger" id="unassigned">
        <div class="card-header bg-white py-2 fw-bold">
            <i class="fas fa-inbox text-danger me-1"></i> فواتير بلا كشف ({{ $unassigned->total() }})
            <span class="text-muted fw-normal small">— فواتير اليوم بلا أمر تسليم: غالباً خطأ إدخال أو حالة نادرة</span>
        </div>
        {{-- المرحلة 7F: استبعاد جماعي (المحدد أو كل نتائج التصفية) --}}
        @include('invoices.partials.bulk-bar', ['scope' => 'batch_unassigned', 'filters' => ['batch' => $batch->id], 'total' => $unassigned->total()])
        <div class="table-responsive">
            <table class="table align-middle mb-0 small table-cards">
                <thead class="table-light">
                    <tr><th class="ps-2"><span class="visually-hidden">تحديد</span></th><th class="ps-3">الزبون</th><th>المنطقة</th><th>الرقم</th><th>التاريخ</th><th>القيمة</th><th class="pe-3"></th></tr>
                </thead>
                <tbody>
                    @foreach ($unassigned as $invoice)
                        <tr>
                            <td class="ps-2"><input type="checkbox" class="form-check-input" name="ids[]" value="{{ $invoice->id }}" form="bulk-batch_unassigned" aria-label="تحديد {{ $invoice->invoice_number }}"></td>
                            <td class="ps-3 fw-bold" data-label="الزبون">{{ $invoice->customer_name }}</td>
                            <td data-label="المنطقة">{{ $invoice->area ?? 'بلا منطقة' }}</td>
                            <td class="font-monospace text-nowrap" data-label="الرقم">{{ $invoice->invoice_number }}</td>
                            <td class="font-monospace text-nowrap" data-label="التاريخ">{{ $invoice->invoice_date?->format('Y-m-d') }}</td>
                            <td class="font-monospace fw-bold" data-label="القيمة">{{ number_format($invoice->invoice_amount, 2) }}</td>
                            <td class="pe-3 text-end">
                                {{-- المرحلة 7E: نافذة واحدة مشتركة للوضع في كشف والاستبعاد (لا قائمة مناطق في كل صف) --}}
                                <div class="d-flex flex-wrap gap-1 justify-content-end">
                                    <button class="btn btn-sm btn-primary fw-bold" type="button" data-bs-toggle="modal" data-bs-target="#unassignedModal" data-mode="place"
                                        data-place-action="{{ route('sheet-manager.invoices.place', $invoice) }}" data-exclude-action="{{ route('sheet-manager.invoices.exclude', $invoice) }}"
                                        data-label="{{ $invoice->invoice_number }} — {{ $invoice->customer_name }}" data-area="{{ $invoice->area ?? 'بلا منطقة' }}">
                                        <i class="fas fa-right-to-bracket"></i> ضعها في كشف
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger fw-bold" type="button" data-bs-toggle="modal" data-bs-target="#unassignedModal" data-mode="exclude"
                                        data-place-action="{{ route('sheet-manager.invoices.place', $invoice) }}" data-exclude-action="{{ route('sheet-manager.invoices.exclude', $invoice) }}"
                                        data-label="{{ $invoice->invoice_number }} — {{ $invoice->customer_name }}" data-area="{{ $invoice->area ?? 'بلا منطقة' }}">
                                        <i class="fas fa-ban"></i> استبعاد
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if ($unassigned->hasPages())
            <div class="p-2 d-flex justify-content-center border-top">{{ $unassigned->links() }}</div>
        @endif
    </div>

    <div class="modal fade" id="unassignedModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title h6 fw-bold" data-label>فاتورة بلا كشف</h2>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body small">
                    <form method="POST" class="row g-2" data-mode-form="place">
                        @csrf
                        <div class="col-12">
                            <label class="form-label fs-8 mb-0" for="um-sheet">الكشف:</label>
                            <select name="sheet_id" id="um-sheet" class="form-select form-select-sm" required>
                                <option value="">— اختر —</option>
                                @foreach ($targetSheets as $target)
                                    <option value="{{ $target->id }}">{{ $target->title() }} ({{ ['preparing' => 'قيد التجهيز', 'pending' => 'بانتظار البدء', 'in_progress' => 'قيد التحصيل'][$target->status] ?? $target->status }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fs-8 mb-0" for="um-area">منطقة هذه الفاتورة (اختياري):</label>
                            <select name="area_key" id="um-area" class="form-select form-select-sm">
                                <option value="" data-area-default>— منطقة الزبون —</option>
                                @foreach ($areaOptions as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12 d-grid"><button class="btn btn-sm btn-success fw-bold"><i class="fas fa-check"></i> حفظ</button></div>
                    </form>
                    <form method="POST" class="d-flex gap-1 d-none" data-mode-form="exclude">
                        @csrf
                        <input type="text" name="reason" class="form-control form-control-sm" required minlength="3" placeholder="سبب الاستبعاد (إلزامي)" aria-label="سبب الاستبعاد">
                        <button class="btn btn-sm btn-danger fw-bold text-nowrap">تأكيد</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- الدفعات السابقة للمرحلة 7A: الاقتراحات والتحديثات كما كانت (الدفعات الجديدة في تبويب «الفواتير») --}}
@unless ($hasItems)
    @if ($proposals->isNotEmpty())
        <div class="card mb-3 border-warning">
            <div class="card-header bg-warning-subtle py-2 fw-bold"><i class="fas fa-code-compare me-1"></i> تغييرات تحتاج قرارك ({{ $proposals->count() }})</div>
            <div class="card-body">
                @foreach ($proposals as $proposal)
                    @php
                        $invoice = $proposal->invoice;
                        $new = $proposal->payload;
                    @endphp
                    <div class="border rounded-3 p-3 mb-3 small">
                        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                            <div>
                                <strong class="font-monospace">{{ $invoice->invoice_number }}</strong> — {{ $invoice->customer_name }}
                                <span class="text-muted">(في كشف أمر #{{ $invoice->distributionSheet?->orderLabel() }} — {{ $statusLabels[$invoice->distributionSheet?->status] ?? '' }})</span>
                            </div>
                            <div class="d-flex gap-1">
                                @php $wasPrinted = \App\Models\PrintEvent::invoicePrinted($invoice); @endphp
                                <form action="{{ route('sheet-manager.proposals.apply', $proposal) }}" method="POST" class="m-0"
                                    @if ($wasPrinted) onsubmit="return confirm('طُبعت هذه الفاتورة سابقاً للصيدلية. هل تريد تطبيق التحديث رغم ذلك؟')" @endif>
                                    @csrf
                                    @if ($wasPrinted)
                                        <input type="hidden" name="confirm_printed" value="1">
                                        <span class="badge text-bg-warning proposal-printed">طُبعت سابقاً للصيدلية</span>
                                    @endif
                                    <button class="btn btn-sm btn-success fw-bold"><i class="fas fa-check"></i> تطبيق التحديث</button>
                                </form>
                                <form action="{{ route('sheet-manager.proposals.reject', $proposal) }}" method="POST" class="m-0">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-danger fw-bold"><i class="fas fa-xmark"></i> رفض</button>
                                </form>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <div class="bg-light rounded-3 p-2 h-100">
                                    <div class="fw-bold text-muted mb-1">الحالي</div>
                                    القيمة: <span class="font-monospace">{{ number_format($invoice->invoice_amount, 2) }}</span> —
                                    أمر التسليم: {{ $invoice->delivery_order_no ?? 'بلا' }} —
                                    التسليم: {{ $invoice->delivery_date?->format('Y-m-d') ?? '—' }}
                                    <ul class="mb-0 mt-1">
                                        @foreach ($invoice->lines as $line)
                                            <li>{{ $line->item_name }} × {{ (float) $line->quantity }} = {{ number_format($line->line_total, 2) }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="bg-warning-subtle rounded-3 p-2 h-100">
                                    <div class="fw-bold text-warning-emphasis mb-1">الجديد من الملف</div>
                                    القيمة: <span class="font-monospace">{{ number_format($new['invoice_amount'], 2) }}</span> —
                                    أمر التسليم: {{ $new['delivery_order_no'] ?? 'بلا' }} —
                                    التسليم: {{ $new['delivery_date'] ?? '—' }}
                                    <ul class="mb-0 mt-1">
                                        @foreach ($new['lines'] as $line)
                                            <li>{{ $line['item_name'] }} × {{ (float) $line['quantity'] }} = {{ number_format($line['line_total'], 2) }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($stat('updates', []))
        <div class="card mb-3">
            <div class="card-header bg-white py-2 fw-bold"><i class="fas fa-rotate text-info me-1"></i> تحديثات طُبّقت من الملف ({{ count($stat('updates', [])) }})</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light"><tr><th class="ps-3">الفاتورة</th><th>الزبون</th><th>القيمة</th><th>أمر التسليم</th><th class="pe-3">تاريخ التسليم</th></tr></thead>
                    <tbody>
                        @foreach ($stat('updates', []) as $update)
                            <tr>
                                <td class="ps-3 font-monospace">{{ $update['invoice_number'] }}</td>
                                <td>{{ $update['customer'] }}</td>
                                <td class="font-monospace">{{ number_format($update['old_amount'], 2) }} ← {{ number_format($update['new_amount'], 2) }}</td>
                                <td>{{ $update['old_order'] ?? 'بلا' }} ← {{ $update['new_order'] ?? 'بلا' }}</td>
                                <td class="pe-3 font-monospace">{{ $update['old_delivery'] ?? '—' }} ← {{ $update['new_delivery'] ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($stat('added_to_published', []))
        <div class="alert alert-info small">
            <i class="fas fa-circle-info me-1"></i>
            أضيفت فواتير جديدة إلى كشوف منشورة سابقاً:
            @foreach ($stat('added_to_published', []) as $added)
                <span class="font-monospace">{{ $added['number'] }}</span> (كشف #{{ $added['sheet_id'] }})@if (! $loop->last)، @endif
            @endforeach
        </div>
    @endif
@endunless
