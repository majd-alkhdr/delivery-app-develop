{{-- نافذة تفاصيل الفاتورة (ملء الشاشة على الجوال، modal على الشاشات الكبيرة): مشتركة بين الموزع وكشوف المندوب.
     المحتوى يرسمه public/js/invoice-view.js. زر «طباعة هذه الفاتورة» للموزع فقط ($printable). --}}
@php
    $printable = $printable ?? false;
    $printingEnabled = $printingEnabled ?? false;
@endphp
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true" aria-labelledby="invoiceModalTitle">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title fw-bold fs-6" id="invoiceModalTitle"><i class="fas fa-file-invoice me-1"></i> تفاصيل الفاتورة</h3>
                <button type="button" class="btn-close p-3" data-bs-dismiss="modal" aria-label="إغلاق"></button>
            </div>
            <div class="modal-body" id="invoiceModalBody"></div>
            <div class="modal-footer">
                @if ($printable)
                    <button type="button" class="btn btn-outline-secondary fw-bold" id="invoicePrintBtn" data-print-invoice=""
                        @disabled(! $printingEnabled) @unless ($printingEnabled) title="قريباً" @endunless>
                        <i class="fas fa-print"></i> طباعة هذه الفاتورة
                    </button>
                @endif
                <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>
