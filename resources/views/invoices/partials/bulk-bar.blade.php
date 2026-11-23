{{--
    المرحلة 7F: شريط التحديد الجماعي لقائمة فواتير ($scope وما يقابله من إجراء، $filters مرشّحات القائمة، $total عدد نتائج التصفية).
    خانات الصفوف خارج النموذج وترتبط به بـ form="{{ $formId }}" (لا نماذج متداخلة مع نماذج الصف).
    «كل نتائج التصفية» يرسل all=1 فيُعاد الاستعلام على الخادم بالمرشّحات نفسها (لا قائمة معرّفات ضخمة).
--}}
@php
    $formId = 'bulk-'.$scope;
    $verb = ['exclude' => 'استبعاد', 'cancel' => 'إلغاء', 'restore' => 'إعادة إدراج'][\App\Services\BulkInvoiceActionService::SCOPES[$scope]];
    $allUrl = route('bulk-invoices.preview', ['scope' => $scope, 'all' => 1] + $filters);
@endphp
<form method="GET" action="{{ route('bulk-invoices.preview') }}" id="{{ $formId }}" class="d-flex flex-wrap align-items-center gap-2 p-2 border-bottom bg-body-tertiary small" data-bulk-bar>
    <input type="hidden" name="scope" value="{{ $scope }}">
    @foreach ($filters as $key => $value)
        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
    @endforeach
    <div class="form-check m-0">
        <input class="form-check-input" type="checkbox" id="{{ $formId }}-page" data-bulk-page="{{ $formId }}">
        <label class="form-check-label" for="{{ $formId }}-page">تحديد كل المعروض</label>
    </div>
    <button class="btn btn-sm btn-outline-danger fw-bold" data-bulk-submit disabled>
        <i class="fas fa-layer-group"></i> {{ $verb }} المحدد (<span data-bulk-count>0</span>)…
    </button>
    <a href="{{ $allUrl }}" class="btn btn-sm btn-danger fw-bold" data-bulk-all>
        <i class="fas fa-check-double"></i> تحديد كل نتائج التصفية ({{ $total }} فاتورة)…
    </a>
    @if (session('bulk_operation_id') && ($lastOperation = \App\Models\InvoiceBulkOperation::find(session('bulk_operation_id')))?->isUndoable())
        <a href="{{ route('bulk-invoices.undo.preview', $lastOperation) }}" class="btn btn-sm btn-outline-secondary fw-bold ms-auto" data-bulk-undo>
            <i class="fas fa-rotate-left"></i> إعادة إدراج العملية الأخيرة ({{ $lastOperation->count }})…
        </a>
    @endif
</form>
@include('invoices.partials.bulk-script')
