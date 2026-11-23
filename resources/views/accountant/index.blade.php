@extends('layouts.panel')

@section('title', 'مراجعة الكشوفات')

@section('content')
    <x-page-header title="مراجعة الكشوفات" subtitle="عرض وتحميل، والمالية لكل كشف (المقبوضات والتوزيع والتصدير)" icon="fa-calculator" />

    @include('partials.stale-sheets')
    @include('partials.balances-stale')
    @include('partials.unreflected-payments')

    {{-- المرحلة 7C: تعديلات لاحقة بانتظار القرار --}}
    @if (($pendingAdjustments ?? 0) > 0)
        <div class="alert alert-danger d-flex flex-wrap align-items-center justify-content-between gap-2 py-2" id="pending-adjustments-alert">
            <span class="fw-bold"><i class="fas fa-file-pen me-1"></i> تعديلات بانتظار القرار: {{ $pendingAdjustments }} — فواتير في كشوف مكتملة وصلت بقيمة مختلفة في ملف.</span>
            <a href="{{ route('finance.adjustments') }}" class="btn btn-sm btn-outline-danger fw-bold">التعديلات اللاحقة</a>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body p-3 p-md-4">
            <form action="{{ route('accountant.index') }}" method="GET" class="row g-2 g-md-3">
                <div class="col-sm-5">
                    <label for="filter_date" class="form-label small fw-bold text-secondary mb-1">التاريخ:</label>
                    <input type="date" name="date" id="filter_date" class="form-control" value="{{ request('date') }}">
                </div>
                <div class="col-sm-4">
                    <label for="filter_status" class="form-label small fw-bold text-secondary mb-1">حالة الكشف:</label>
                    <select name="status" id="filter_status" class="form-select">
                        <option value="">كل الحالات</option>
                        <option value="pending" @selected(request('status') == 'pending')>بانتظار البدء</option>
                        <option value="in_progress" @selected(request('status') == 'in_progress')>قيد التحصيل</option>
                        <option value="completed" @selected(request('status') == 'completed')>مكتمل ومغلق</option>
                        <option value="draft" @selected(request('status') == 'draft')>مسودة</option>
                    </select>
                </div>
                <div class="col-sm-3 d-grid align-self-end">
                    <button type="submit" class="btn btn-primary fw-bold">
                        <i class="fas fa-filter me-1 small"></i> تطبيق
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
            <i class="fas fa-table-list text-primary"></i>
            <h2 class="h6 fw-bold mb-0">الكشوفات وحالاتها</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3 py-3">الكشف</th>
                            <th>الموزعون</th>
                            <th>تاريخ التوزيع</th>
                            <th>الإجمالي</th>
                            <th>الحالة</th>
                            <th class="text-center pe-3">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="small">
                        @forelse($sheets as $s)
                            <tr>
                                <td class="fw-bold ps-3 py-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="bg-success-subtle text-success rounded-2 d-inline-flex align-items-center justify-content-center flex-shrink-0"
                                            style="width: 32px; height: 32px;">
                                            <i class="far fa-file-excel"></i>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="d-block text-truncate" style="max-width: 220px;" title="{{ $s->original_name ?? $s->file_name }}">{{ $s->displayTitle() }}</span>
                                            @unless ($s->isV2())
                                                <span class="badge bg-light text-secondary border">صيغة قديمة</span>
                                            @endunless
                                        </span>
                                    </div>
                                </td>
                                <td class="fw-semibold text-nowrap">
                                    <i class="fas fa-user-circle text-muted me-1"></i> {{ $s->distributorsLabel() }}
                                </td>
                                <td class="text-muted text-nowrap font-monospace">
                                    {{ $s->distribution_date?->format('Y-m-d') ?? '—' }}
                                    <span class="d-block fs-8">رُفع {{ $s->created_at->format('Y-m-d g:i A') }}</span>
                                </td>
                                <td class="font-monospace fw-bold text-nowrap">{{ number_format($s->listTotal(), 2) }}</td>
                                <td>
                                    <x-status-badge :status="$s->status" :reason="$s->status_reason" />
                                    @if ($s->isDraft() && $s->status_reason)
                                        <div class="text-muted fs-8 mt-1 text-break-word" style="max-width: 220px;">{{ $s->status_reason }}</div>
                                    @endif
                                </td>
                                <td class="text-center pe-3">
                                    <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                        <a href="{{ route('accountant.sheet.show', $s->id) }}" class="btn btn-sm btn-light border text-primary fw-bold">
                                            <i class="fas fa-eye"></i> التفاصيل
                                        </a>
                                        @if ($s->isV2())
                                            <a href="{{ route('finance.sheets.show', $s) }}" class="btn btn-sm btn-success fw-bold">
                                                <i class="fas fa-coins"></i> المالية
                                            </a>
                                            @include('partials.export-button', ['sheet' => $s])
                                        @else
                                            <a href="{{ route('accountant.download', $s->id) }}" class="btn btn-sm btn-light border text-success fw-bold">
                                                <i class="fas fa-download"></i> تحميل
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <x-empty-state title="لا توجد كشوفات" message="لا توجد كشوفات تطابق الفلترة الحالية." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($sheets->hasPages())
                <div class="p-3 bg-light d-flex justify-content-center border-top">
                    {{ $sheets->appends(request()->query())->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection
