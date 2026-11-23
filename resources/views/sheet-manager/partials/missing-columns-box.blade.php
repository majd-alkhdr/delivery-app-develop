{{-- المرحلة 7G: مربع واحد لكل الأعمدة الحرجة الغائبة (لا مربع لكل عمود)، مع أعمدة الملف الموجودة فعلاً وعدّاد الأثر.
     $columns: [['column', 'text', 'count'?, 'accepted'?]]، $present: [اسم الملف => [أعمدة]]، $impact: [نص]، $impactTitle --}}
<div class="alert alert-danger mt-2" id="missing-columns">
    <div class="fw-bold mb-2"><i class="fas fa-table-columns me-1"></i>
        {{ count($columns) > 1 ? 'أعمدة حرجة غير موجودة في الملف (' . count($columns) . ')' : 'عمود حرج غير موجود في الملف' }}
    </div>
    <ul class="mb-2 ps-3 pe-3">
        @foreach ($columns as $col)
            <li class="missing-column mb-1" data-column="{{ $col['column'] }}">
                <span class="fw-bold">«{{ $col['column'] }}»:</span>
                <span @if (($col['code'] ?? null) === \App\Services\Import\WorkbookImporter::COST_CENTER_COLUMN_MISSING) id="cost-center-text" @endif>{{ $col['text'] }}</span>
                @if (! empty($col['accepted']))
                    <span class="d-block small fw-normal text-body-secondary">الأسماء المقبولة لهذا العمود: {{ implode('، ', array_map(fn ($a) => '«' . $a . '»', $col['accepted'])) }}</span>
                @endif
            </li>
        @endforeach
    </ul>
    @if ($impact)
        <div class="fw-bold small mb-2" id="missing-columns-impact"><i class="fas fa-calculator me-1"></i>{{ $impactTitle }}: {{ implode('، ', $impact) }}.</div>
    @endif
    @foreach ($present as $file => $labels)
        <div class="small fw-normal" id="present-columns">
            <span class="fw-bold">الأعمدة الموجودة فعلاً في الملف{{ count($present) > 1 ? ' «' . $file . '»' : '' }} ({{ count($labels) }}):</span>
            {{ implode('، ', $labels) }}
        </div>
    @endforeach
</div>
