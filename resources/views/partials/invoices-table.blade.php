{{-- جدول فواتير صيدلية واحدة (مشترك بين شاشة الموزع وشاشة الإشراف) --}}
@php $isV2 = isset($sheet) && $sheet->isV2(); @endphp
<div class="table-responsive rounded-3 border mb-3">
    <table class="table table-sm table-striped mb-0 text-center align-middle fs-7">
        <thead class="table-light">
            <tr>
                <th class="py-2">رقم الفاتورة</th>
                <th class="py-2 text-start">{{ $isV2 ? 'مركز الكلفة والبيان' : 'البيان' }}</th>
                <th class="py-2">{{ $isV2 ? 'السلسلة' : 'النمط' }}</th>
                <th class="py-2">القيمة</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoices as $invoice)
                <tr>
                    <td class="fw-bold text-secondary font-monospace text-nowrap">{{ $invoice->invoice_number }}@if ($invoice->added_after_send_at) <span class="badge text-bg-warning added-after-send">أُضيفت بعد الإرسال</span>@endif</td>
                    <td class="text-start text-wrap text-break-word" style="max-width: 220px;">
                        @if ($isV2 && ! $invoice->isExternal())
                            {{-- المرحلة 6B-G: مركز الكلفة والبيان كما في الحركة اليومية (حلّا محل «N صنف — البيان» السابق) --}}
                            <span class="text-muted">{{ $invoice->lines_count ?? $invoice->lines()->count() }} صنف</span>
                            @include('partials.invoice-meta', ['costCenter' => \App\Support\InvoiceLinesView::costCenter($invoice), 'note' => $invoice->invoice_note])
                            @if ($invoice->is_zero_value)
                                <span class="badge bg-info-subtle text-info-emphasis ms-1"><i class="fas fa-gift me-1"></i>هدية / صفر</span>
                            @endif
                        @else
                            {{ $invoice->statement ?? '-' }}
                        @endif
                    </td>
                    <td>
                        @if ($isV2 && ! $invoice->isExternal())
                            <x-series-badge :series="$invoice->series" />
                        @else
                            @php $pattern = strtolower((string) $invoice->invoice_pattern); @endphp
                            @if (Str::contains($pattern, 'm'))
                                <span class="badge bg-pattern-m">M</span>
                            @elseif (Str::contains($pattern, 'a'))
                                <span class="badge text-bg-primary">A</span>
                            @elseif (Str::contains($pattern, 'b'))
                                <span class="badge text-bg-info">B</span>
                            @else
                                <span class="badge text-bg-secondary">{{ $invoice->invoice_pattern ?? '-' }}</span>
                            @endif
                        @endif
                    </td>
                    <td class="fw-bold font-monospace">
                        {{ number_format($invoice->invoice_amount, 0) }}
                        @if ($isV2 && ! $invoice->isExternal() && auth()->user()?->hasRole('admin', 'sheet_manager', 'supervisor', 'accountant', 'rep_supervisor'))
                            <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="أسطر الفاتورة"
                                data-lines-url="{{ route('invoices.lines', $invoice) }}" data-lines-target="lines-{{ $invoice->id }}">
                                <i class="fas fa-list"></i>
                            </button>
                        @endif
                    </td>
                </tr>
                @if ($isV2 && ! $invoice->isExternal())
                    <tr class="d-none" id="lines-{{ $invoice->id }}"><td colspan="4" class="bg-white text-start" data-lines-body></td></tr>
                @endif
            @endforeach
        </tbody>
    </table>
</div>
