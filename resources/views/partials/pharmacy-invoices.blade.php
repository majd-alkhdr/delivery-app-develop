{{-- فواتير صيدلية في كشوف المندوب (6B-C): الرقم والقيمة والموزع المسؤول، ومركز الكلفة والبيان (G)، والمنسوب والمتبقي --}}
<ul class="list-group list-group-flush small">
    @foreach ($rows as $row)
        @php $inv = $row['invoice']; @endphp
        {{-- قابل للضغط: يفتح تفاصيل الفاتورة (public/js/rep/rep-sheet.js) --}}
        <li class="list-group-item list-group-item-action px-1 rep-invoice" role="button" tabindex="0" data-rep-invoice-id="{{ $inv->id }}" title="تفاصيل الفاتورة">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <x-series-badge :series="$inv->series" />
                <span class="font-monospace" dir="ltr">{{ \App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $inv->invoice_number) }}</span>
                <strong class="font-monospace" dir="ltr">{{ number_format((float) ($row['amount'] ?? $inv->invoice_amount), 2) }}</strong>
                <span class="text-muted">الموزع: {{ $row['distributor'] }}</span>
                <span class="ms-auto text-muted">حُصِّل <span class="font-monospace" dir="ltr">{{ number_format($row['paid'], 2) }}</span> — متبقٍّ <span class="font-monospace" dir="ltr">{{ number_format($row['remaining'], 2) }}</span></span>
            </div>
            @if (! empty($row['adjustment']))
                {{-- المرحلة 7C --}}
                <div class="mt-1"><span class="badge text-bg-warning post-close-tag">{{ $row['adjustment']->tagLabel() }}</span>
                    <span class="text-muted" dir="ltr">{{ $row['adjustment']->applied_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d') }}</span>
                    <span class="text-muted">— {{ $row['adjustment']->reasonLabel() }}</span></div>
            @endif
            @include('partials.invoice-meta', ['costCenter' => $row['cost_center'], 'note' => $row['note']])
        </li>
    @endforeach
</ul>
