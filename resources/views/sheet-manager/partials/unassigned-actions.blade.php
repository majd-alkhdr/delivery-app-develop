{{-- إجراءات فاتورة "بلا كشف": ضعها في كشف (مع تغيير منطقتها اختيارياً)، أو استبعاد بسبب --}}
@php $sheetsForDate = isset($forDate) ? $targetSheets->filter(fn ($s) => $s->distribution_date?->toDateString() === $forDate) : $targetSheets; @endphp
<div class="d-flex flex-wrap gap-1 justify-content-end">
    <button class="btn btn-sm btn-primary fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#place-{{ $invoice->id }}">
        <i class="fas fa-right-to-bracket"></i> ضعها في كشف
    </button>
    <button class="btn btn-sm btn-outline-danger fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#exclude-{{ $invoice->id }}">
        <i class="fas fa-ban"></i> استبعاد
    </button>
</div>
<div class="collapse mt-2" id="place-{{ $invoice->id }}">
    <form action="{{ route('sheet-manager.invoices.place', $invoice) }}" method="POST" class="row g-1 align-items-end text-start">
        @csrf
        <div class="col-sm-5">
            <label class="form-label fs-8 mb-0">الكشف:</label>
            <select name="sheet_id" class="form-select form-select-sm" required>
                <option value="">— اختر —</option>
                @foreach ($sheetsForDate as $target)
                    <option value="{{ $target->id }}">
                        {{ $target->title() }}@if ($target->distribution_date && ! isset($forDate)) — {{ $target->distribution_date->format('Y-m-d') }}@endif ({{ ['preparing' => 'قيد التجهيز', 'pending' => 'بانتظار البدء', 'in_progress' => 'قيد التحصيل'][$target->status] ?? $target->status }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-5">
            <label class="form-label fs-8 mb-0">منطقة هذه الفاتورة (اختياري):</label>
            <select name="area_key" class="form-select form-select-sm">
                <option value="">— منطقة الزبون ({{ $invoice->area ?? 'بلا منطقة' }}) —</option>
                @foreach ($areaOptions as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-sm-2 d-grid">
            <button class="btn btn-sm btn-success fw-bold"><i class="fas fa-check"></i> حفظ</button>
        </div>
    </form>
</div>
<div class="collapse mt-2" id="exclude-{{ $invoice->id }}">
    <form action="{{ route('sheet-manager.invoices.exclude', $invoice) }}" method="POST" class="d-flex gap-1">
        @csrf
        <input type="text" name="reason" class="form-control form-control-sm" required minlength="3" placeholder="سبب الاستبعاد (إلزامي)">
        <button class="btn btn-sm btn-danger fw-bold text-nowrap">تأكيد</button>
    </form>
</div>
