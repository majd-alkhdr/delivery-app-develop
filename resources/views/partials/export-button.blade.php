{{--
    زر «تصدير Excel» لكشوف v2 مع خيارات التصدير قبل التنزيل (قائمة منسدلة):
    النطاق (كل الكشف / موزع مسنَد / منطقة) وتضمين تفاصيل الأصناف.
    $sheet: الكشف (يُفضَّل تحميل assignments.distributor مسبقاً)
    $areas (اختياري): [مفتاح المنطقة => الاسم]؛ إن لم يُمرَّر لا يظهر خيار المنطقة (في القوائم)
    كشوف v1 لا تستخدم هذا الزر وتبقى بزر «تحميل» القديم.
--}}
@php
    $exportDistributors = $sheet->assignments
        ->map(fn ($a) => $a->distributor)
        ->filter()
        ->unique('id')
        ->values();
    $exportAreas = $areas ?? null;
    $exportId = 'export-' . $sheet->id . '-' . \Illuminate\Support\Str::random(4);
@endphp
@if ($sheet->isV2())
    <div class="dropdown d-inline-block">
        <button class="btn btn-sm btn-light border text-success fw-bold dropdown-toggle" type="button"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
            <i class="fas fa-file-excel"></i> تصدير Excel
        </button>
        <div class="dropdown-menu dropdown-menu-end p-3 text-start" style="min-width: 260px;">
            <form method="GET" action="{{ route('sheets.export', $sheet) }}" class="small">
                <label class="form-label fw-bold mb-1" for="{{ $exportId }}-scope">النطاق:</label>
                <select name="scope" id="{{ $exportId }}-scope" class="form-select form-select-sm mb-2"
                    onchange="this.form.querySelectorAll('[data-scope-input]').forEach(el => { el.disabled = el.dataset.scopeInput !== this.value; el.closest('[data-scope-box]').classList.toggle('d-none', el.disabled); })">
                    <option value="all">كل الكشف</option>
                    @if ($exportDistributors->isNotEmpty())
                        <option value="distributor">موزع محدد</option>
                    @endif
                    @if ($exportAreas)
                        <option value="area">منطقة محددة</option>
                    @endif
                </select>
                @if ($exportDistributors->isNotEmpty())
                    <div class="mb-2 d-none" data-scope-box>
                        <select name="distributor_id" class="form-select form-select-sm" data-scope-input="distributor" disabled aria-label="الموزع">
                            @foreach ($exportDistributors as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if ($exportAreas)
                    <div class="mb-2 d-none" data-scope-box>
                        <select name="area_key" class="form-select form-select-sm" data-scope-input="area" disabled aria-label="المنطقة">
                            @foreach ($exportAreas as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="with_lines" value="1" id="{{ $exportId }}-lines">
                    <label class="form-check-label" for="{{ $exportId }}-lines">تضمين تفاصيل الأصناف</label>
                </div>
                <button class="btn btn-sm btn-success fw-bold w-100"><i class="fas fa-download"></i> تنزيل للطباعة (A4)</button>
            </form>
        </div>
    </div>
@endif
