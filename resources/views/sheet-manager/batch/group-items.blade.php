{{-- المرحلة 7E: فواتير موقع واحد (يُحمَّل عند توسيع مجموعة «الجديدة/بلا تغيير»)، بترقيم --}}
@php $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2); @endphp
<div class="table-responsive">
    <table class="table table-sm align-middle small mb-1 table-cards group-items">
        <thead><tr><th>الفاتورة</th><th>الزبون</th><th>المبلغ</th><th>الشرح</th></tr></thead>
        <tbody>
            @forelse ($items as $item)
                <tr class="group-item" data-item-id="{{ $item->id }}">
                    <td class="font-monospace text-nowrap" data-label="الفاتورة">{{ $item->invoice_number }}</td>
                    <td data-label="الزبون">{{ $item->customer_name }}</td>
                    <td class="font-monospace" data-label="المبلغ">{{ $money($item->new['amount'] ?? $item->old['amount'] ?? null) }}</td>
                    <td class="text-muted" data-label="الشرح">{{ $item->reason }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">لا شيء.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($items->hasPages())
    <div class="d-flex justify-content-center">{{ $items->links() }}</div>
@endif
