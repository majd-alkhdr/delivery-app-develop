{{-- صف صيدلية في «خطتي»: الاسم، المنطقة، دَين المندوب المعروف، حالة اليوم، وزرّا «تمت الزيارة» و«لم تتم» --}}
@php
    [$state, $variant, $text] = $stateOf($visit);
    $debt = $debts[$customer->id] ?? 0;
    $readonly = ($readonly ?? false) || ! $canRecord;
@endphp
<div class="card mb-2 plan-row" data-customer-id="{{ $customer->id }}" data-name="{{ $customer->name }}" data-state="{{ $state }}" @if ($orphan ?? false) data-orphan @endif>
    <div class="card-body p-3">
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <div class="min-w-0 flex-grow-1">
                <div class="fw-bold">{{ $customer->name }}</div>
                <div class="small text-muted">{{ $customer->area ?? 'بلا منطقة' }}{{ $customer->block ? ' — ' . $customer->block : '' }}</div>
                @if ($customer->latitude === null || $customer->longitude === null)
                    <div class="small text-warning-emphasis"><i class="fas fa-location-dot"></i> لا يوجد موقع محفوظ لهذه الصيدلية</div>
                @endif
            </div>
            <div class="text-center small">
                @if ($debt > 0)
                    <span class="d-block text-muted">دَينك المعروف</span>
                    <span class="fw-bold text-danger font-monospace" dir="ltr">{{ number_format($debt, 2) }}</span>
                @else
                    <span class="text-muted">لا دَين معروف لك</span>
                @endif
            </div>
        </div>
        <div class="mt-2"><span class="badge text-bg-{{ $variant }} text-wrap text-start" data-row-status>{{ $text }}</span></div>
        @unless ($readonly)
            <div class="d-grid gap-2 mt-2" style="grid-template-columns: 1fr 1fr;">
                <button type="button" class="btn btn-success fw-bold" data-plan-visit><i class="fas fa-check"></i> تمت الزيارة</button>
                <button type="button" class="btn btn-outline-warning fw-bold" data-plan-skip><i class="fas fa-xmark"></i> لم تتم</button>
            </div>
        @endunless
        <div class="small text-danger mt-2 d-none" data-row-error></div>
    </div>
</div>
