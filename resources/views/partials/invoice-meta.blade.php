{{-- المرحلة 6B-G: مركز الكلفة (كود-اسم) وبيان الفاتورة كما في الحركة اليومية، بجانب كل فاتورة في بطاقة الصيدلية.
     البيان الفارغ أو «0» لا يُعرض، والطويل يُقصّ بسطرين مع «المزيد». --}}
@php
    $metaCostCenter = $costCenter ?? null;
    $metaNote = \App\Support\InvoiceLinesView::note($note ?? null);
@endphp
@if ($metaCostCenter)
    <div class="small text-muted invoice-cost-center text-break-word"><i class="fas fa-sitemap me-1"></i>{{ $metaCostCenter }}</div>
@endif
@if ($metaNote)
    <div class="small invoice-note text-break-word">
        <span class="text-clamp-2 d-block">{{ $metaNote }}</span>
        @if (mb_strlen($metaNote) > 70)
            <button type="button" class="btn btn-link btn-sm p-0 small" onclick="this.previousElementSibling.classList.toggle('text-clamp-2'); this.textContent = this.textContent === 'المزيد' ? 'أقل' : 'المزيد';">المزيد</button>
        @endif
    </div>
@endif
