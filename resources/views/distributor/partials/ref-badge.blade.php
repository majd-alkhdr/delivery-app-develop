{{-- حالة الموقع المرجعي للصيدلية (تذكير هادئ للموزع، بلا إحداثيات) --}}
@if ($customerId)
    <span data-ref-badge="{{ $customerId }}">
        @if ($state === 'confirmed')
            <span class="badge bg-success-subtle text-success-emphasis fw-normal" title="موقع مؤكد"><i class="fas fa-location-dot"></i> موقع مؤكد</span>
        @elseif ($state === 'unconfirmed')
            <span class="badge bg-warning-subtle text-warning-emphasis fw-normal">موقع غير مؤكد</span>
        @else
            <span class="badge bg-light text-secondary border fw-normal">بلا موقع مرجعي</span>
        @endif
    </span>
@endif
