{{-- المرحلة 7E2: مجموعة «تغيّر وصفي فقط» (مثل مركز الكلفة) — المبلغ والأصناف والموقع كما هي، وتُطبَّق دفعة واحدة --}}
@php
    $labels = collect($descriptive['fields'])->map(fn ($n, $field) => (\App\Models\ImportBatchItem::DESCRIPTIVE_LABELS[$field] ?? $field) . ' (' . number_format($n) . ')')->implode('، ');
    $example = $descriptive['example'];
@endphp
<div class="card border-info mb-3" id="descriptive-group">
    <div class="card-body py-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge text-bg-info"><i class="fas fa-tags me-1"></i>تغيّر وصفي فقط</span>
            <span class="fw-bold flex-grow-1" id="descriptive-title">{{ $descriptive['title'] }}</span>
            <button type="button" class="btn btn-sm btn-outline-primary fw-bold" aria-expanded="false" data-target="descriptive-items"
                data-load-url="{{ route('sheet-manager.batches.show', ['batch' => $batch, 'partial' => 'group', 'kind' => \App\Http\Controllers\BatchPageController::DESCRIPTIVE]) }}">
                <i class="fas fa-chevron-down"></i> عرض الفواتير
            </button>
            @if ($canDecide)
                <button type="button" class="btn btn-sm btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#descriptiveConfirm" id="apply-descriptive">
                    <i class="fas fa-check-double"></i> تطبيق هذه المجموعة
                </button>
            @endif
        </div>
        <div class="small text-muted mt-1">الحقول المتغيّرة: {{ $labels }}. لن يتغيّر المبلغ ولا الأصناف ولا موقع أي فاتورة.</div>
        @if ($example)
            <div class="small mt-1" id="descriptive-example">مثال: <span class="font-monospace">{{ $example->invoice_number }}</span> — {{ $example->customer_name }}: {{ $example->reason }}</div>
        @endif
        <div id="descriptive-items" class="d-none mt-2" data-ajax-root data-partial=""></div>
    </div>
</div>

@if ($canDecide)
    <div class="modal fade" id="descriptiveConfirm" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <form action="{{ route('sheet-manager.batches.apply-descriptive', $batch) }}" method="POST" class="modal-content">
                @csrf
                <input type="hidden" name="confirm_printed" value="1">
                <div class="modal-header py-2">
                    <h2 class="modal-title h6 fw-bold"><i class="fas fa-check-double me-1"></i> تطبيق مجموعة «تغيّر وصفي فقط»</h2>
                </div>
                <div class="modal-body small">
                    <p class="fw-bold mb-2">سيُطبَّق التغيير على {{ number_format($descriptive['count']) }} فاتورة.</p>
                    <ul class="mb-2">
                        <li>الحقول التي تتغيّر: {{ $labels }}.</li>
                        <li>لا يتغيّر المبلغ ولا الأصناف ولا موقع أي فاتورة.</li>
                        @if (isset($descriptive['fields']['cost_center']))
                            <li>يُنسب دَين كل فاتورة إلى مندوب مركز الكلفة الجديد.</li>
                        @endif
                        <li>تُحفظ نسخة كل فاتورة قبل التعديل، ويُسجَّل كل تطبيق في سجل النشاط بالقيم قبل وبعد.</li>
                        <li>يشمل ذلك أي فاتورة طُبعت سابقاً للصيدلية. والعناصر المحجوزة (نشاط على الصيدلية لغير المدير، أو كشف مكتمل) تُتخطّى وتبقى معلّقة.</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">إلغاء</button>
                    <button class="btn btn-success fw-bold" id="apply-descriptive-confirm">تطبيق هذه المجموعة</button>
                </div>
            </form>
        </div>
    </div>
@endif
