{{-- أسطر فاتورة (قراءة فقط) للإدارة: جدول فواتير الكشف (صف قابل للتوسيع) و/invoices/deferred --}}
{{-- المرحلة 6B: الإفرادي والإجمالي قبل الحسم، وعمود الحسم % وتذييل «حسم الأقلام/الصافي» فقط عند وجود حسم --}}
@php
    $view = \App\Support\InvoiceLinesView::summarize($invoice);
    $lines = $invoice->lines;
    $hasDiscount = $view['has_discount'];
    $cols = $hasDiscount ? 5 : 4;
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.');
@endphp
<div class="invoice-lines">
    <div class="d-flex flex-wrap gap-2 align-items-center small text-muted mb-1">
        <x-series-badge :series="$invoice->series" />
        <span class="font-monospace" dir="ltr">{{ $invoice->invoice_number }}</span>
        <span>التاريخ: <span dir="ltr">{{ $invoice->invoice_date?->format('Y-m-d') ?? '—' }}</span></span>
        <span>مركز الكلفة: {{ $invoice->cost_center_name ?? '—' }}</span>
        <span>المدخِل: {{ $invoice->entered_by_name ?? '—' }}</span>
        @if ($invoice->invoice_note)<span>البيان: {{ $invoice->invoice_note }}</span>@endif
        @if ($invoice->is_zero_value)<span class="badge bg-info-subtle text-info-emphasis">هدية / صفر</span>@endif
        @if ($invoice->net_uncertain)<span class="badge bg-warning-subtle text-warning-emphasis">قد يختلف الصافي عن برنامج المحاسبة: حسم هدايا/إضافات</span>@endif
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle text-center small mb-1 invoice-lines-table">
            <thead class="table-light">
                <tr>
                    <th class="text-start">الصنف</th><th>الكمية</th><th>الهدايا</th><th>الإفرادي</th>
                    @if ($hasDiscount)<th class="discount-col" style="width: 1%">الحسم %</th>@endif
                    <th>الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($view['lines'] as $line)
                    <tr>
                        <td class="text-start">{{ $line['item_name'] }}@if ($line['item_group'])<div class="text-muted fs-8">{{ $line['item_group'] }}</div>@endif</td>
                        <td dir="ltr">{{ $qty($line['quantity']) }}</td>
                        <td dir="ltr">@if ($line['gift_quantity'] > 0)<span class="badge bg-info-subtle text-info-emphasis">هدية {{ $qty($line['gift_quantity']) }}</span>@else — @endif</td>
                        <td dir="ltr">{{ number_format($line['unit_price'], 2) }}</td>
                        @if ($hasDiscount)
                            <td dir="ltr" class="discount-col text-nowrap">{{ ($line['discount_percent'] ?? 0) > 0 ? \App\Support\InvoiceLinesView::percent($line['discount_percent']) : '—' }}</td>
                        @endif
                        <td dir="ltr" class="fw-bold">{{ number_format($line['line_total'], 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ $cols + 1 }}" class="text-muted">لا توجد أسطر لهذه الفاتورة.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                @if ($hasDiscount)
                    <tr class="fw-bold"><td colspan="{{ $cols }}" class="text-start">المجموع قبل الحسم</td><td dir="ltr">{{ number_format($view['gross_total'], 2) }}</td></tr>
                    <tr class="fw-bold"><td colspan="{{ $cols }}" class="text-start">حسم الأقلام</td><td dir="ltr" class="lines-discount-total">{{ number_format($view['discount_total'], 2) }}</td></tr>
                    <tr class="fw-bold"><td colspan="{{ $cols }}" class="text-start">الصافي</td><td dir="ltr" class="lines-net-total">{{ number_format($view['net_total'], 2) }}</td></tr>
                @else
                    <tr class="fw-bold"><td colspan="{{ $cols }}" class="text-start">مجموع الأسطر</td><td dir="ltr">{{ number_format($view['net_total'], 2) }}</td></tr>
                @endif
                <tr class="fw-bold"><td colspan="{{ $cols }}" class="text-start">إجمالي الفاتورة</td><td dir="ltr">{{ number_format((float) $invoice->invoice_amount, 2) }}</td></tr>
            </tfoot>
        </table>
    </div>
    @if ($view['mismatch'] && $lines->isNotEmpty())
        <div class="alert alert-warning py-1 small mb-0">تنبيه: مجموع الأسطر لا يطابق إجمالي الفاتورة.</div>
    @endif
</div>
