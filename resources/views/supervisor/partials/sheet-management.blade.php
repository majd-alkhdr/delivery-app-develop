{{-- إدارة الكشف للمشرف والمدير: الحالة، إعادة الإسناد، إسناد المناطق --}}
@php
    $unassigned = $assignment['unassigned'] ?? [];
    $locked = $sheet->isDraft();
    $rowIndex = 0;
@endphp

@if (! $locked && ! empty($unassigned))
    <div class="alert alert-danger d-flex gap-2 fw-bold">
        <i class="fas fa-triangle-exclamation mt-1 fs-5"></i>
        <div>
            @if ($unassigned === ['*'])
                هذا الكشف غير مُسند لأي موزع.
            @else
                {{ count($unassigned) }} منطقة بدون موزع: لن يرى أحد زبائنها حتى تُسند.
            @endif
        </div>
    </div>
@endif

@if (($pendingChanges ?? collect())->isNotEmpty() && in_array($sheet->status, ['pending', 'in_progress']))
    <div class="alert alert-warning small" id="pending-changes">
        <div class="fw-bold mb-1"><i class="fas fa-code-compare me-1"></i> لفواتير هذا الكشف {{ $pendingChanges->count() }} تغيير ينتظر القرار عند مسؤول الكشوف:</div>
        <ul class="mb-1">
            @foreach ($pendingChanges as $p)
                <li><span class="font-monospace">{{ $p['number'] }}</span> — {{ $p['customer'] }} ({{ $p['label'] }})</li>
            @endforeach
        </ul>
        إن أغلقت الكشف الآن تُحفظ هذه التغييرات «بانتظار التسوية بعد الإغلاق» ولا تُطبَّق.
    </div>
@endif

<div class="card mb-4">
    <div class="card-header bg-white py-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h2 class="h6 fw-bold mb-0"><i class="fas fa-sliders text-primary me-1"></i> إدارة الكشف</h2>
        <div class="d-flex flex-wrap gap-2">
            @if (in_array($sheet->status, ['pending', 'in_progress']))
                @php
                    $pendingChanges = $pendingChanges ?? collect();
                    // المرحلة 7H: إشعارات لم يطّلع عليها الموزع ⇒ تحذير بتأكيد
                    $unackedNotices = app(\App\Services\NoticeAckService::class)->unackedCount($sheet);
                @endphp
                <form action="{{ route('supervisor.complete', $sheet->id) }}" method="POST" class="m-0">
                    @csrf
                    @if ($pendingChanges->isNotEmpty())
                        <input type="hidden" name="confirm_pending" value="1">
                    @endif
                    @if ($unackedNotices > 0)
                        <input type="hidden" name="confirm_unacked" value="1">
                    @endif
                    <button type="submit" class="btn btn-sm btn-warning fw-bold"
                        onclick="return confirm(@js(($unackedNotices > 0 ? 'تنبيه: ' . $unackedNotices . ' إشعاراً بتعديل لم يطّلع عليه الموزع بعد. ' : '') . ($pendingChanges->isNotEmpty()
                            ? 'لفواتير هذا الكشف ' . $pendingChanges->count() . ' تغيير ينتظر القرار (' . $pendingChanges->map(fn ($p) => $p['number'])->implode('، ') . '). عند الإغلاق تُحفظ «بانتظار التسوية بعد الإغلاق» ولا تُطبَّق. إغلاق الكشف كمكتمل؟'
                            : 'إغلاق هذا الكشف كمكتمل؟ لن يتمكن الموزع من التعديل عليه بعد الإغلاق.')))">
                        <i class="fas fa-lock"></i> إغلاق (مكتمل)
                    </button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-secondary fw-bold" data-bs-toggle="collapse" data-bs-target="#draftForm">
                    <i class="fas fa-file-pen"></i> إغلاق كمسودة بدون توزيع
                </button>
            @endif
            @can('reopen', $sheet)
                <form action="{{ route('supervisor.sheet.reopen', $sheet) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success fw-bold"
                        onclick="return confirm('إعادة تفعيل هذا الكشف إلى بانتظار البدء؟')">
                        <i class="fas fa-rotate-left"></i> إعادة التفعيل
                    </button>
                </form>
            @endcan
        </div>
    </div>

    @if (in_array($sheet->status, ['pending', 'in_progress']))
        <div class="collapse {{ $errors->has('reason') ? 'show' : '' }}" id="draftForm">
            <div class="card-body border-bottom bg-light">
                <form action="{{ route('supervisor.sheet.draft', $sheet) }}" method="POST">
                    @csrf
                    <label for="draft_reason" class="form-label fw-bold small">سبب الإغلاق كمسودة (إلزامي):</label>
                    <textarea name="reason" id="draft_reason" rows="2" required minlength="3"
                        class="form-control mb-2 @error('reason') is-invalid @enderror"
                        placeholder="مثال: الملف رُفع بالخطأ، سيُرفع الكشف الصحيح لاحقاً">{{ old('reason') }}</textarea>
                    <div class="small text-muted mb-2">المسودة تُخفى عن الموزعين ولا تقبل أي تعديل، وتُنقل فواتيرها إلى "بلا كشف" ليعيد مسؤول الكشوف توزيعها. الإسنادات تبقى محفوظة.</div>
                    <button type="submit" class="btn btn-secondary fw-bold btn-sm"><i class="fas fa-check"></i> تأكيد الإغلاق كمسودة</button>
                </form>
            </div>
        </div>
    @endif

    <div class="card-body">
        <h3 class="h6 fw-bold mb-3"><i class="fas fa-map-location-dot text-primary me-1"></i> إسناد المناطق / تغيير الموزع</h3>

        @if ($locked)
            <p class="text-muted small mb-0">الكشف مسودة ولا يقبل تعديل الإسناد.</p>
        @else
            <form action="{{ route('supervisor.sheet.assign', $sheet) }}" method="POST">
                @csrf
                @method('PUT')

                <div class="row g-2 align-items-end mb-3">
                    <div class="col-md-6">
                        <label for="all_distributor_id" class="form-label small fw-bold">موزع واحد لكل المناطق:</label>
                        <select name="all_distributor_id" id="all_distributor_id" class="form-select">
                            <option value="">— إسناد حسب المنطقة (أدناه) —</option>
                            @foreach ($distributors as $d)
                                <option value="{{ $d->id }}" @selected(($assignment['all_distributor_id'] ?? null) == $d->id)>{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6 small text-muted">
                        الزيارات والدفعات المسجلة سابقاً تبقى كما هي عند تغيير الموزع.
                    </div>
                </div>

                @if (! empty($assignment['blocks']))
                    <div id="areaAssignments">
                        @foreach ($assignment['blocks'] as $blockName => $areas)
                            @php $blockId = 'b' . $loop->index; @endphp
                            <div class="border rounded-3 p-2 p-md-3 mb-2">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                    <strong class="small"><i class="fas fa-layer-group text-secondary me-1"></i> {{ $blockName }}</strong>
                                    <div class="d-flex gap-1">
                                        <select class="form-select form-select-sm" data-block-select="{{ $blockId }}" aria-label="موزع الكتلة">
                                            <option value="">— بدون —</option>
                                            @foreach ($distributors as $d)
                                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                                            @endforeach
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" data-block-apply="{{ $blockId }}">
                                            حدّد كل مناطق الكتلة
                                        </button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0 small">
                                        <tbody>
                                            @foreach ($areas as $area)
                                                @php $isUnassigned = in_array($area['key'], $unassigned, true); @endphp
                                                <tr class="{{ $isUnassigned ? 'table-danger' : '' }}">
                                                    <td class="w-50">
                                                        {{ $area['label'] }}
                                                        <span class="text-muted">({{ $area['customers'] }} زبون)</span>
                                                        @if ($isUnassigned)
                                                            <span class="badge text-bg-danger ms-1">بلا موزع</span>
                                                        @endif
                                                    </td>
                                                    <td>
                                                        <input type="hidden" name="rows[{{ $rowIndex }}][area_key]" value="{{ $area['key'] }}">
                                                        <select name="rows[{{ $rowIndex }}][distributor_id]" class="form-select form-select-sm"
                                                            data-area-block="{{ $blockId }}" aria-label="موزع المنطقة">
                                                            <option value="">— بدون موزع —</option>
                                                            @foreach ($distributors as $d)
                                                                <option value="{{ $d->id }}" @selected(($assignment['current'][$area['key']] ?? null) == $d->id)>{{ $d->name }}</option>
                                                            @endforeach
                                                        </select>
                                                    </td>
                                                </tr>
                                                @php $rowIndex++; @endphp
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <button type="submit" class="btn btn-primary fw-bold mt-2">
                    <i class="fas fa-save"></i> حفظ الإسناد
                </button>
            </form>
        @endif
    </div>
</div>
