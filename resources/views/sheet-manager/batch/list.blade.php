{{-- المرحلة 7E: «عرض الكل» لملاحظة أو تحذير مجمَّع — جدول بترقيم وبحث (يُحمَّل في نافذة) --}}
<div class="fw-bold mb-2 list-title">{{ $title }}</div>
<form method="GET" action="{{ route('sheet-manager.batches.show', $batch) }}" class="d-flex gap-2 mb-2">
    @foreach ($query as $k => $v)
        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
    @endforeach
    <input type="search" name="lq" value="{{ $term }}" class="form-control form-control-sm" placeholder="بحث بالرقم أو الزبون" aria-label="بحث في القائمة">
    <button class="btn btn-sm btn-light border fw-bold">بحث</button>
</form>
<div class="text-muted mb-1">العدد: {{ number_format($rows->total()) }}</div>
<div class="table-responsive">
    <table class="table table-sm align-middle small mb-1 table-cards batch-list">
        <thead class="table-light"><tr><th>#</th><th>الفاتورة/الزبون</th><th>السبب</th><th>النص الأصلي</th></tr></thead>
        <tbody>
            @forelse ($rows as $i => [$ref, $detail, $original])
                <tr class="list-row">
                    <td class="text-muted" data-label="#">{{ ($rows->currentPage() - 1) * $rows->perPage() + $loop->iteration }}</td>
                    <td class="font-monospace" data-label="الفاتورة/الزبون">{{ $ref }}</td>
                    <td data-label="السبب">{{ $detail ?: '—' }}</td>
                    <td class="text-muted" data-label="النص الأصلي">{{ $original }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-muted">لا شيء.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($rows->hasPages())
    <div class="d-flex justify-content-center">{{ $rows->links() }}</div>
@endif
