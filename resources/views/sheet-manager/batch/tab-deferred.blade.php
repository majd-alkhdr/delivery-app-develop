{{-- المرحلة 7E: تبويب «المؤجلة» — مصدر واحد للعدد (البطاقة والتبويب والملاحظة)، تفصيل أسباب التأجيل، جدول بترقيم وبحث --}}
@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $fromItems = $hasItems;
@endphp

{{-- مؤجلة سابقة غير موجودة في هذا الملف (تحتاج قراراً) --}}
@if ($missingDeferred->isNotEmpty())
    <div class="card mb-3 border-warning">
        <div class="card-header bg-white py-2 fw-bold">
            <i class="fas fa-ghost text-warning me-1"></i> مؤجلة سابقة غير موجودة في هذا الملف ({{ $missingDeferred->count() }})
            <span class="text-muted fw-normal small">— لا تُلغى تلقائياً</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 small table-cards">
                <thead class="table-light"><tr><th class="ps-3">الزبون</th><th>الرقم</th><th>تاريخ التسليم</th><th>القيمة</th><th class="pe-3"></th></tr></thead>
                <tbody>
                    @foreach ($missingDeferred as $invoice)
                        <tr>
                            <td class="ps-3" data-label="الزبون">{{ $invoice->customer_name }}</td>
                            <td class="font-monospace" data-label="الرقم">{{ $invoice->invoice_number }}</td>
                            <td class="font-monospace" data-label="تاريخ التسليم">{{ $invoice->delivery_date?->format('Y-m-d') }}</td>
                            <td class="font-monospace fw-bold" data-label="القيمة">{{ number_format($invoice->invoice_amount, 2) }}</td>
                            <td class="pe-3" style="min-width: 280px;">
                                <div class="d-flex gap-1 justify-content-end">
                                    <form action="{{ route('sheet-manager.invoices.cancel', $invoice) }}" method="POST" class="d-flex gap-1 m-0">
                                        @csrf
                                        <input type="text" name="reason" class="form-control form-control-sm" required minlength="3" value="لم تعد في ملف المحاسبة" aria-label="السبب">
                                        <button class="btn btn-sm btn-outline-danger fw-bold text-nowrap">اعتبرها ملغاة</button>
                                    </form>
                                    <form action="{{ route('sheet-manager.invoices.keep', $invoice) }}" method="POST" class="m-0">
                                        @csrf
                                        <input type="hidden" name="batch_id" value="{{ $batch->id }}">
                                        <button class="btn btn-sm btn-light border fw-bold">إبقاء</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

<div class="card mb-3" id="batch-deferred">
    <div class="card-header bg-white py-2">
        <div class="fw-bold"><i class="fas fa-clock text-warning me-1"></i> الطلبيات المؤجلة في هذه الدفعة ({{ $deferred['count'] }}) — {{ number_format($deferred['total'], 2) }}</div>
        @if ($fromItems && $deferred['count'])
            <div class="small text-muted mt-1" id="deferred-breakdown">
                {{ $deferred['count'] }} مؤجلة: {{ $deferred['with_order'] }} تاريخ تسليمها مستقبلي ولها أمر تسليم، و{{ $deferred['without_order'] }} تاريخ تسليمها مستقبلي بلا أمر تسليم (أو بأمر غير صالح)@if ($deferred['manual'])، و{{ $deferred['manual'] }} وضعها المستخدم يدوياً في «مؤجلة»@endif.
            </div>
        @endif
    </div>
    <div class="card-body py-2">
        <form method="GET" action="{{ route('sheet-manager.batches.show', $batch) }}" class="d-flex gap-2">
            <input type="hidden" name="tab" value="deferred">
            <input type="search" name="dq" value="{{ $deferredTerm }}" class="form-control form-control-sm" placeholder="بحث بالرقم أو الزبون" aria-label="بحث في المؤجلة">
            <button class="btn btn-sm btn-light border fw-bold">بحث</button>
        </form>
    </div>
    {{-- المرحلة 7F: إلغاء جماعي للمؤجلة (بالقاعدة نفسها لـ«اعتبارها ملغاة» الفردي) --}}
    @php $bulkDeferredFilters = array_filter(['batch' => $batch->id, 'q' => $deferredTerm]); @endphp
    @include('invoices.partials.bulk-bar', ['scope' => 'batch_deferred', 'filters' => $bulkDeferredFilters,
        'total' => app(\App\Services\BulkInvoiceActionService::class)->query('batch_deferred', $bulkDeferredFilters)->count()])
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 small table-cards" id="deferred-table">
            <thead class="table-light"><tr><th class="ps-2"><span class="visually-hidden">تحديد</span></th><th class="ps-3">الفاتورة</th><th>الزبون</th><th>تاريخ التسليم</th><th>أمر التسليم</th><th>المبلغ</th><th class="pe-3">سبب التأجيل</th></tr></thead>
            <tbody>
                @forelse ($deferredRows as $row)
                    @php
                        if ($fromItems) {
                            $order = $row->new['order'] ?? null;
                            [$number, $customer, $delivery, $amount] = [$row->invoice_number, $row->customer_name, $row->new['delivery_date'] ?? null, $row->new['amount'] ?? null];
                            $why = str_contains((string) $row->reason, 'يدوياً') ? 'وضعها المستخدم يدوياً في «مؤجلة»'
                                : ($order !== null ? 'تاريخ تسليم مستقبلي وأمر تسليم ' . $order : 'تاريخ تسليم مستقبلي بلا أمر تسليم (أو بأمر غير صالح)');
                        } else {
                            $order = $row->delivery_order_no;
                            [$number, $customer, $delivery, $amount] = [$row->invoice_number, $row->customer_name, $row->delivery_date?->format('Y-m-d'), $row->invoice_amount];
                            $why = 'تاريخ تسليم مستقبلي';
                        }
                    @endphp
                    <tr class="deferred-row">
                        <td class="ps-2">@if ($row->invoice_id ?? $row->id)<input type="checkbox" class="form-check-input" name="ids[]" value="{{ $fromItems ? $row->invoice_id : $row->id }}" form="bulk-batch_deferred" aria-label="تحديد {{ $number }}">@endif</td>
                        <td class="ps-3 font-monospace" data-label="الفاتورة">{{ $number }}</td>
                        <td data-label="الزبون">{{ $customer }}</td>
                        <td class="font-monospace" data-label="تاريخ التسليم">{{ $delivery ?? '—' }}</td>
                        <td data-label="أمر التسليم">{{ $order ?? 'بلا' }}</td>
                        <td class="font-monospace fw-bold" data-label="المبلغ">{{ $money($amount) }}</td>
                        <td class="pe-3 text-muted" data-label="سبب التأجيل">{{ $why }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted ps-3">لا توجد طلبيات مؤجلة.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($deferredRows->hasPages())
        <div class="p-2 d-flex justify-content-center border-top">{{ $deferredRows->links() }}</div>
    @endif
</div>
