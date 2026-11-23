{{-- المرحلة 7E: صف عنصر «مراجعة الفروق» المختصر — «ما تغيّر» يعرض الحقول المتغيّرة فقط، والتفاصيل عند التوسيع --}}
@use('App\Models\ImportBatchItem', 'Item')
@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $old = $item->old ?? [];
    $new = $item->new ?? [];
    $disabled = $blocker !== null;
    $fields = [
        'amount' => ['المبلغ', fn ($s) => $money($s['amount'] ?? null), true],
        'order' => ['الأمر', fn ($s) => $s ? ($s['order'] ?? 'بلا') : '—', false],
        'delivery_date' => ['التسليم', fn ($s) => $s['delivery_date'] ?? '—', true],
        'cost_center' => ['مركز الكلفة', fn ($s) => $s['cost_center'] ?? '—', false],
        'lines_count' => ['الأصناف', fn ($s) => $s['lines_count'] ?? '—', false],
    ];
    $changed = [];
    if ($old && $new) {
        foreach ($fields as $key => [$label, $fmt]) {
            if ((string) ($old[$key] ?? '') !== (string) ($new[$key] ?? '')) {
                $changed[$key] = [$label, $fmt($old), $fmt($new)];
            }
        }
    }
    $linesDiffer = $old && $new && ($old['lines'] ?? []) != ($new['lines'] ?? []);
@endphp
<tr class="review-item" data-item-id="{{ $item->id }}" data-kind="{{ $item->kind }}" data-status="{{ $item->status }}">
    <td class="font-monospace text-nowrap" data-label="الفاتورة">{{ $item->invoice_number }}</td>
    <td data-label="الزبون">{{ $item->customer_name }}</td>
    <td class="text-nowrap" data-label="الموقع">
        @if ($item->sheet_id)
            <a href="{{ route('sheet-manager.sheets.show', $item->sheet_id) }}">{{ $item->sheet_label }}</a>
            @if ($item->sheet)<span class="d-block text-muted fs-8">{{ $item->sheet->statusLabel() }}</span>@endif
        @else
            {{ $item->sheet_label }}
        @endif
        @if ($item->targetSheet && $item->target_sheet_id !== $item->sheet_id)
            <span class="d-block text-muted fs-8">الهدف: <a href="{{ route('sheet-manager.sheets.show', $item->target_sheet_id) }}">{{ $item->targetSheet->shortLabel() }}</a> ({{ $item->targetSheet->statusLabel() }})</span>
        @endif
    </td>
    <td style="min-width: 240px;" data-label="الشرح">
        <span class="badge text-bg-{{ $item->kindVariant() }} mb-1">{{ $item->kindLabel() }}</span>
        <div>{{ $item->reason }}</div>
        @if ($item->decision_note && $item->status !== Item::PENDING)
            <div class="text-muted mt-1"><i class="fas fa-gavel me-1"></i>{{ $item->decision_note }}@if ($item->decider) — {{ $item->decider->name }}@endif</div>
        @endif
        @if ($item->status === Item::SUPERSEDED && $item->supersededBy)
            <div class="mt-1"><a href="{{ route('sheet-manager.batches.show', ['batch' => $item->supersededBy->batch_id, 'tab' => 'invoices', 'q' => $item->invoice_number]) }}">حلّ محله عنصر في دفعة أحدث ←</a></div>
        @endif
    </td>
    <td class="text-nowrap" data-label="ما تغيّر">
        @if ($changed)
            @foreach ($changed as [$label, $from, $to])
                <div>{{ $label }}: <span class="font-monospace">{{ $from }}</span> ← <span class="font-monospace fw-bold">{{ $to }}</span></div>
            @endforeach
        @elseif ($new)
            {{-- جديدة أو بلا تغيير: المبلغ فقط (الموقع في عمود الموقع) --}}
            <div>المبلغ: <span class="font-monospace fw-bold">{{ $money($new['amount'] ?? $old['amount'] ?? null) }}</span></div>
        @elseif ($old)
            <div>المبلغ: <span class="font-monospace fw-bold">{{ $money($old['amount'] ?? null) }}</span></div>
        @endif
        @if ($linesDiffer || ($changed && (! empty($old['lines']) || ! empty($new['lines']))))
            <details class="mt-1">
                <summary class="text-primary">فرق الأسطر/التفاصيل</summary>
                <div class="mt-1">
                    @foreach ($fields as $key => [$label, $fmt])
                        <div class="text-muted">{{ $label }}: {{ $fmt($old) }} ← {{ $fmt($new) }}</div>
                    @endforeach
                </div>
                <div class="d-flex gap-3 mt-1">
                    <ul class="mb-0 ps-3"><li class="fw-bold list-unstyled">القديم</li>
                        @foreach ($old['lines'] ?? [] as [$name, $qty, $total])<li>{{ $name }} × {{ (float) $qty }} = {{ $money($total) }}</li>@endforeach
                    </ul>
                    <ul class="mb-0 ps-3"><li class="fw-bold list-unstyled">الجديد</li>
                        @foreach ($new['lines'] ?? [] as [$name, $qty, $total])<li>{{ $name }} × {{ (float) $qty }} = {{ $money($total) }}</li>@endforeach
                    </ul>
                </div>
            </details>
        @endif
    </td>
    <td class="text-end" style="min-width: 200px;" data-label="الحالة والقرار">
        <span class="badge text-bg-{{ $item->statusVariant() }}">{{ $item->statusLabel() }}</span>
        @if ($printed)
            <span class="badge text-bg-warning proposal-printed">طُبعت سابقاً للصيدلية</span>
        @endif
        @if ($item->status === Item::BLOCKED && $item->blocked_reason)
            <div class="text-danger mt-1 text-start item-blocked">{{ $item->blocked_reason }}</div>
        @elseif ($blocker && $item->status === Item::PENDING)
            <div class="text-danger mt-1 text-start item-blocked">{{ $blocker['message'] }}</div>
        @endif
        {{-- المرحلة 7C: تغيير على فاتورة في كشف مكتمل يُحال إلى «التعديلات اللاحقة» --}}
        @if ($item->proposal_id && ($item->kind === Item::CLOSED_CHANGED || in_array($item->blocked_reason, [\App\Services\Import\ImportReviewService::CLOSED_BLOCK, \App\Services\Import\ImportReviewService::LEGACY_CLOSED_BLOCK], true)))
            <div class="text-start small mt-1 post-close-referral">
                <span class="d-block">أُحيل إلى التعديلات اللاحقة لأن الكشف مكتمل: <span class="font-monospace">{{ $money($old['amount'] ?? null) }}</span> ← <span class="font-monospace fw-bold">{{ $money($new['amount'] ?? null) }}</span></span>
                @can('viewAdjustments')
                    <a href="{{ route('finance.adjustments', ['proposal' => $item->proposal_id]) }}" class="btn btn-sm btn-outline-danger fw-bold mt-1"><i class="fas fa-arrow-left"></i> انتقل إلى التعديلات اللاحقة</a>
                @endcan
            </div>
        @endif
        @if ($canDecide && $item->isOpen())
            <div class="mt-1">
                <form action="{{ route('sheet-manager.items.apply', $item) }}" method="POST" class="d-inline m-0"
                    @if ($printed) onsubmit="return confirm('طُبعت هذه الفاتورة سابقاً للصيدلية. هل تريد تطبيق التحديث رغم ذلك؟')" @endif>
                    @csrf
                    @if ($printed)<input type="hidden" name="confirm_printed" value="1">@endif
                    <button class="btn btn-sm btn-success fw-bold" @disabled($disabled) title="{{ $blocker['message'] ?? 'تطبيق' }}">
                        <i class="fas fa-check"></i> تطبيق
                    </button>
                </form>
                <details class="d-inline-block text-start">
                    <summary class="btn btn-sm btn-outline-secondary fw-bold">تجاهل</summary>
                    <form action="{{ route('sheet-manager.items.reject', $item) }}" method="POST" class="mt-1 d-flex gap-1">
                        @csrf
                        <input type="text" name="note" class="form-control form-control-sm" placeholder="السبب (اختياري)" aria-label="سبب التجاهل">
                        <button class="btn btn-sm btn-outline-danger fw-bold text-nowrap">تأكيد</button>
                    </form>
                </details>
            </div>
        @endif
    </td>
</tr>
