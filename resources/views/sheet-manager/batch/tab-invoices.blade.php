{{-- المرحلة 7E: تبويب «الفواتير» (مراجعة الفروق) — الافتراضي ما يحتاج انتباهاً، والجديدة/بلا تغيير مجمَّعة حسب الموقع حين تكثر --}}
@use('App\Models\ImportBatchItem', 'Item')
@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2);
    $show = fn (array $q = []) => route('sheet-manager.batches.show', ['batch' => $batch, 'tab' => 'invoices'] + $q);
@endphp

@if (! $hasItems)
    <div class="alert alert-light border small">هذه دفعة سابقة لـ«مراجعة الفروق»: تغييراتها وتحديثاتها في تبويب «الكشوف».</div>
@else
    @php
        $pendingSummary = collect($counts['pendingByKind'])->map(fn ($n, $k) => (Item::KINDS[$k][0] ?? $k) . ': ' . $n)->implode('، ');
        $f = $filters;
        $isDefault = $f['kind'] === '' && $f['status'] === '' && $f['q'] === '';
    @endphp
    <div class="card mb-3 border-primary" id="review">
        <div class="card-header bg-primary-subtle py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <span class="fw-bold"><i class="fas fa-code-compare me-1"></i> مراجعة الفروق</span>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <a href="{{ $show(['status' => 'pending']) }}" data-ajax
                    class="badge text-bg-{{ $counts['pending'] ? 'warning' : 'secondary' }} text-decoration-none fs-6" id="review-pending-count">
                    بانتظار قرارك: {{ $counts['pending'] }}
                </a>
                @if ($canDecide && $counts['pending'])
                    <form action="{{ route('sheet-manager.batches.apply-many', $batch) }}" method="POST" class="m-0">
                        @csrf
                        <input type="hidden" name="confirm_printed" value="1">
                        <button class="btn btn-sm btn-success fw-bold" id="apply-all"
                            onclick="return confirm(@js('تطبيق كل العناصر المعلّقة (' . $counts['pending'] . '): ' . $pendingSummary . '.' . ($printedPending ? ' بينها ' . $printedPending . ' فاتورة طُبعت سابقاً للصيدلية.' : '') . ' تُتخطّى العناصر المحجوزة (نشاط على الصيدلية لغير المدير، أو كشف مكتمل). متابعة؟'))">
                            <i class="fas fa-check-double"></i> تطبيق الكل
                        </button>
                    </form>
                @endif
            </div>
        </div>
        <div class="card-body small">
            @if (session('apply_results'))
                <div class="alert alert-light border" id="apply-results">
                    <div class="fw-bold mb-1">نتيجة «تطبيق الكل»:</div>
                    <ul class="mb-0">
                        @foreach (session('apply_results') as $res)
                            <li class="{{ $res['ok'] ? 'text-success' : 'text-danger' }}">{{ $res['ok'] ? 'طُبّق' : 'لم يُطبَّق' }} — {{ $res['message'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- الملخص: عدّاد كل نوع موجود فقط، والتعريف عند المرور؛ والشرح الكامل في «دليل الوسوم» --}}
            <div class="d-flex flex-wrap align-items-center gap-1 mb-2" id="review-legend">
                @foreach (Item::KINDS as $kind => [$label, $hint, $variant])
                    @continue($kind === Item::NOTE || empty($counts['kinds'][$kind]))
                    <a href="{{ $show(['kind' => $kind]) }}" data-ajax class="badge text-bg-{{ $variant }} text-decoration-none" data-bs-toggle="tooltip" title="{{ $hint }}">
                        {{ $label }} ({{ $counts['kinds'][$kind] }})
                    </a>
                @endforeach
                @if ($counts['blocked'])
                    <a href="{{ $show(['status' => 'blocked']) }}" data-ajax class="badge text-bg-danger text-decoration-none" data-bs-toggle="tooltip"
                        title="لا يمكن تطبيقها الآن: نشاط على الصيدلية في الكشف (يطبّقها المدير فقط)، أو أن الكشف مكتمل.">محجوزة ({{ $counts['blocked'] }})</a>
                @endif
                <button type="button" class="btn btn-sm btn-link p-0 ms-1" data-bs-toggle="modal" data-bs-target="#tagGuide"><i class="fas fa-book-open me-1"></i>دليل الوسوم</button>
            </div>

            {{-- مرشّحات سريعة --}}
            <div class="d-flex flex-wrap gap-1 mb-2" id="review-quick">
                <a href="{{ $show() }}" data-ajax class="btn btn-sm {{ $isDefault ? 'btn-primary' : 'btn-outline-primary' }} fw-bold">يحتاج انتباهاً ({{ $counts['attention'] }})</a>
                <a href="{{ $show(['kind' => Item::CREATED]) }}" data-ajax class="btn btn-sm {{ $f['kind'] === Item::CREATED ? 'btn-success' : 'btn-outline-success' }} fw-bold">الجديدة ({{ $counts['kinds'][Item::CREATED] ?? 0 }})</a>
                <a href="{{ $show(['kind' => Item::UNCHANGED]) }}" data-ajax class="btn btn-sm {{ $f['kind'] === Item::UNCHANGED ? 'btn-secondary' : 'btn-outline-secondary' }} fw-bold" id="review-unchanged">بلا تغيير ({{ $counts['quiet'] }})</a>
                <a href="{{ $show(['kind' => 'all']) }}" data-ajax class="btn btn-sm {{ $f['kind'] === 'all' ? 'btn-dark' : 'btn-outline-dark' }} fw-bold">الكل ({{ $counts['total'] }})</a>
                @if ($counts['descriptive'])
                    <a href="{{ $show(['kind' => \App\Http\Controllers\BatchPageController::DESCRIPTIVE]) }}" data-ajax class="btn btn-sm {{ $f['kind'] === \App\Http\Controllers\BatchPageController::DESCRIPTIVE ? 'btn-info' : 'btn-outline-info' }} fw-bold" id="review-descriptive">تغيّر وصفي فقط ({{ $counts['descriptive'] }})</a>
                @endif
            </div>

            {{-- شريط الأدوات: النوع والحالة والبحث (البحث يشمل كل الأنواع) --}}
            <form method="GET" action="{{ route('sheet-manager.batches.show', $batch) }}" class="row g-2 align-items-end mb-3" id="review-filter">
                <input type="hidden" name="tab" value="invoices">
                <div class="col-6 col-md-3">
                    <label class="form-label small text-muted mb-1" for="f-kind">النوع</label>
                    <select name="kind" id="f-kind" class="form-select form-select-sm">
                        <option value="">كل ما يحتاج انتباهاً</option>
                        <option value="all" @selected($f['kind'] === 'all')>الكل</option>
                        @if ($counts['descriptive'])
                            <option value="{{ \App\Http\Controllers\BatchPageController::DESCRIPTIVE }}" @selected($f['kind'] === \App\Http\Controllers\BatchPageController::DESCRIPTIVE)>تغيّر وصفي فقط ({{ $counts['descriptive'] }})</option>
                        @endif
                        @foreach (Item::KINDS as $kind => [$label])
                            @continue($kind === Item::NOTE)
                            <option value="{{ $kind }}" @selected($f['kind'] === $kind)>{{ $label }} ({{ $counts['kinds'][$kind] ?? 0 }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small text-muted mb-1" for="f-status">الحالة</label>
                    <select name="status" id="f-status" class="form-select form-select-sm">
                        <option value="">كل الحالات</option>
                        @foreach (Item::STATUSES as $status => [$label])
                            <option value="{{ $status }}" @selected($f['status'] === $status)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-8 col-md-4">
                    <label class="form-label small text-muted mb-1" for="f-q">بحث بالرقم أو الزبون (كل الأنواع)</label>
                    <input type="search" name="q" id="f-q" value="{{ $f['q'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-4 col-md-2 d-grid">
                    <button class="btn btn-sm btn-primary fw-bold">تصفية</button>
                </div>
            </form>

            {{-- المرحلة 7E2: مجموعة «تغيّر وصفي فقط» — بطاقة مستقلة بعددها ومثال وجدول كسول و«تطبيق هذه المجموعة» --}}
            @if ($descriptive && ($isDefault || $f['kind'] === \App\Http\Controllers\BatchPageController::DESCRIPTIVE))
                @include('sheet-manager.batch.descriptive-group')
            @endif

            @if ($groups !== null)
                {{-- عرض مجمَّع حسب الموقع: كل مجموعة تُحمَّل عند التوسيع بترقيم --}}
                <div class="text-muted mb-2">{{ Item::KINDS[$f['kind']][0] ?? $f['kind'] }}: {{ number_format($groups->sum('count')) }} فاتورة في {{ $groups->count() }} موقعاً. وسّع الموقع لعرض فواتيره.</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle small" id="review-groups">
                        <thead class="table-light"><tr><th>الموقع</th><th>العدد</th><th>الحالة</th><th class="text-end"></th></tr></thead>
                        <tbody>
                            @foreach ($groups as $i => $group)
                                <tr class="review-group" data-count="{{ $group['count'] }}">
                                    <td class="fw-bold">
                                        @if ($group['sheet_id'])<a href="{{ route('sheet-manager.sheets.show', $group['sheet_id']) }}">{{ $group['label'] }}</a>@else{{ $group['label'] }}@endif
                                    </td>
                                    <td class="font-monospace">{{ number_format($group['count']) }} فاتورة</td>
                                    <td>
                                        @foreach ($group['statuses'] as $st => $n)
                                            <span class="badge text-bg-{{ Item::STATUSES[$st][1] ?? 'secondary' }}">{{ Item::STATUSES[$st][0] ?? $st }}: {{ $n }}</span>
                                        @endforeach
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary fw-bold" aria-expanded="false" data-target="group-{{ $i }}"
                                            data-load-url="{{ route('sheet-manager.batches.show', ['batch' => $batch, 'partial' => 'group', 'kind' => $f['kind'], 'loc' => $group['key']]) }}">
                                            <i class="fas fa-chevron-down"></i> عرض الفواتير
                                        </button>
                                    </td>
                                </tr>
                                <tr class="d-none"><td colspan="4" class="bg-light"><div id="group-{{ $i }}" data-ajax-root data-partial=""></div></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif ($items->isEmpty() && $isDefault)
                <div class="text-center py-4" id="review-empty">
                    @if ($descriptive)
                        <div class="fw-bold mb-2"><i class="fas fa-circle-check text-success me-1"></i> لا توجد فواتير أخرى تحتاج قراراً غير مجموعة «تغيّر وصفي فقط» أعلاه</div>
                    @else
                        <div class="fw-bold text-success mb-2"><i class="fas fa-circle-check me-1"></i> لا توجد فواتير تحتاج قراراً</div>
                    @endif
                    <a href="{{ $show(['kind' => Item::CREATED]) }}" data-ajax class="btn btn-sm btn-outline-success fw-bold">عرض الجديدة ({{ $counts['kinds'][Item::CREATED] ?? 0 }})</a>
                    <a href="{{ $show(['kind' => Item::UNCHANGED]) }}" data-ajax class="btn btn-sm btn-outline-secondary fw-bold">عرض بلا تغيير ({{ $counts['quiet'] }})</a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle small table-cards" id="review-items">
                        <thead class="table-light">
                            <tr><th>الفاتورة</th><th>الزبون</th><th>الموقع</th><th>الشرح</th><th>ما تغيّر</th><th class="text-end">الحالة والقرار</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($items as $item)
                                @include('sheet-manager.batch.item-row', ['item' => $item, 'blocker' => $blockers[$item->id] ?? null, 'printed' => $printed[$item->id] ?? false])
                            @empty
                                <tr><td colspan="6" class="text-muted">لا توجد عناصر تطابق هذا المرشّح.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($items->hasPages())
                    <div class="d-flex justify-content-center">{{ $items->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    {{-- دليل الوسوم: الشرح الكامل لكل الأنواع --}}
    <div class="modal fade" id="tagGuide" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title h6 fw-bold"><i class="fas fa-book-open me-1"></i> دليل الوسوم</h2>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body small">
                    @foreach (Item::KINDS as $kind => [$label, $hint, $variant])
                        @continue($kind === Item::NOTE)
                        <div class="d-flex gap-2 align-items-start mb-2">
                            <span class="badge text-bg-{{ $variant }} text-nowrap">{{ $label }} ({{ $counts['kinds'][$kind] ?? 0 }})</span>
                            <span class="text-muted">{{ $hint }}</span>
                        </div>
                    @endforeach
                    <div class="d-flex gap-2 align-items-start">
                        <span class="badge text-bg-danger text-nowrap">محجوزة</span>
                        <span class="text-muted">لا يمكن تطبيقها الآن: نشاط على الصيدلية في الكشف (يطبّقها المدير فقط)، أو أن الكشف مكتمل.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endif
