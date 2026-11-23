{{-- المرحلة 7E: تبويب «ملاحظات وتحذيرات» — كل نوع صفّ واحد بعدده و5 أمثلة و«عرض الكل» (جدول بترقيم وبحث يُحمَّل عند الطلب) --}}
@php
    $levels = ['critical' => ['danger', 'حرج'], 'warning' => ['warning', 'تحذير'], 'info' => ['info', 'معلومة']];
    $show = fn (array $q) => route('sheet-manager.batches.show', ['batch' => $batch] + $q);
@endphp

<div class="card mb-3" id="file-notes">
    <div class="card-header bg-white py-2 fw-bold"><i class="fas fa-file-circle-question text-warning me-1"></i> ما لم يُقرأ أو عُدِّل بقاعدة تلقائية ({{ $noteRows->count() }})</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle small mb-0 table-cards">
            <thead class="table-light"><tr><th>الدرجة</th><th>الملاحظة</th><th>العدد</th><th>أمثلة</th><th></th></tr></thead>
            <tbody>
                @forelse ($noteRows as $note)
                    @php [$variant, $label] = $levels[$note['level']]; @endphp
                    <tr class="file-note {{ $note['level'] === 'critical' ? 'table-danger' : '' }}" data-code="{{ $note['code'] }}">
                        <td data-label="الدرجة"><span class="badge text-bg-{{ $variant }}">{{ $label }}</span></td>
                        <td class="{{ $note['level'] === 'critical' ? 'fw-bold' : '' }}" data-label="الملاحظة">
                            {{ $note['text'] }}
                            @if ($note['files'])<span class="text-muted"> — {{ implode('، ', $note['files']) }}</span>@endif
                        </td>
                        <td class="fw-bold" data-label="العدد">{{ $note['count'] ? number_format($note['count']) : '—' }}</td>
                        <td data-label="أمثلة">
                            @foreach ($note['examples'] as $example)
                                <span class="d-block text-muted">{{ $example }}</span>
                            @endforeach
                        </td>
                        <td class="text-end">
                            @if ($note['count'] > count($note['examples']))
                                <button type="button" class="btn btn-sm btn-outline-primary fw-bold text-nowrap" data-modal="1" data-title="{{ $note['text'] }}"
                                    data-load-url="{{ $show(['partial' => 'note', 'code' => $note['code']]) }}">عرض الكل ({{ number_format($note['count']) }})</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-muted">قُرئ كل شيء في الملف ولم تُطبَّق أي قاعدة تلقائية تستحق الذكر.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-3 {{ $warningGroups ? 'border-warning' : '' }}" id="batch-warnings">
    <div class="card-header bg-warning-subtle py-2 fw-bold"><i class="fas fa-triangle-exclamation me-1"></i> تحذيرات ({{ count($warningGroups) + ($newCustomersCount ? 1 : 0) }})</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle small mb-0 table-cards">
            <tbody>
                @if ($newCustomersCount)
                    <tr class="warning-group" data-key="new_customers">
                        <td data-label="التحذير">
                            زبائن جدد بحاجة مراجعة ({{ $newCustomersCount }}): {{ $newCustomers->pluck('name')->implode('، ') }}@if ($newCustomersCount > 5)، …@endif
                        </td>
                        <td class="text-end"><a href="{{ route('customers.review') }}" class="btn btn-sm btn-outline-primary fw-bold text-nowrap">شاشة مطابقة الزبائن</a></td>
                    </tr>
                @endif
                @forelse ($warningGroups as $group)
                    <tr class="warning-group" data-key="{{ $group['key'] }}">
                        <td data-label="التحذير">
                            {{ $group['title'] }}
                            @if ($group['rows'] || $group['tab'])<span class="fw-bold">({{ number_format($group['count']) }})</span>@elseif ($group['count'] > 1)<span class="text-muted">(تكرر {{ $group['count'] }} مرات)</span>@endif
                            @if ($group['rows'] && ! $group['tab'])
                                @foreach (array_slice($group['rows'], 0, 5) as [$ref, $detail])
                                    <span class="d-block text-muted">{{ $ref }}{{ $detail ? ' — ' . $detail : '' }}</span>
                                @endforeach
                            @endif
                        </td>
                        <td class="text-end">
                            @if ($group['tab'])
                                <a href="{{ $show(['tab' => $group['tab']]) }}" data-tab-link="{{ $group['tab'] }}" class="btn btn-sm btn-outline-primary fw-bold text-nowrap">عرض في تبويب «المؤجلة»</a>
                            @elseif (count($group['rows']) > 5)
                                <button type="button" class="btn btn-sm btn-outline-primary fw-bold text-nowrap" data-modal="1" data-title="{{ $group['title'] }}"
                                    data-load-url="{{ $show(['partial' => 'warnings', 'group' => $group['key']]) }}">عرض الكل ({{ number_format($group['count']) }})</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    @unless ($newCustomersCount)
                        <tr><td class="text-muted">لا توجد تحذيرات.</td></tr>
                    @endunless
                @endforelse
            </tbody>
        </table>
    </div>
</div>
