{{-- المرحلة 7C: «تغيّرت ذمتك بسبب تعديل لاحق» يبقى حتى «اطلعت» --}}
@foreach ($adjustmentAlerts ?? [] as $a)
    <div class="alert alert-warning py-2 small d-flex flex-wrap align-items-center justify-content-between gap-2 adjustment-alert" data-adjustment-id="{{ $a->id }}">
        <span><i class="fas fa-file-pen me-1"></i> <strong>تغيّرت ذمتك بسبب تعديل لاحق</strong>:
            {{ \App\Services\Finance\PaymentAllocationEngine::invoiceLabel((string) $a->invoice?->invoice_number) }} — {{ $a->customer?->name }} —
            {{ $a->tagLabel() }}@if ($a->rep_debt_before !== null)، ذمتك على الصيدلية {{ number_format((float) $a->rep_debt_before, 2) }} ← {{ number_format((float) $a->rep_debt_after, 2) }}@endif
            @if ((float) $a->surplus > 0)، زيادة تحصيل {{ number_format((float) $a->surplus, 2) }} تحتاج قراراً@endif
            @unless ($a->isApplied()) <span class="badge text-bg-secondary">تُراجع عن التعديل: {{ $a->revert_reason }}</span>@endunless
            <span class="text-muted">— {{ $a->reasonLabel() }}</span>
        </span>
        <form action="{{ route('rep.adjustment-seen', $a) }}" method="POST" class="m-0">
            @csrf
            <button class="btn btn-sm btn-outline-dark fw-bold py-0">اطلعت</button>
        </form>
    </div>
@endforeach
