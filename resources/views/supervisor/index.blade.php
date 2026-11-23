@extends('layouts.panel')

@section('title', 'الكشوفات')

@section('content')
    <x-page-header title="كشوفات التوزيع" subtitle="الكشوف المرسلة من مسؤول الكشوف: إسناد المناطق والمتابعة والإغلاق" icon="fa-table-list" />
    @include('partials.rep-scope-line')

    @include('partials.stale-sheets')
    @include('partials.balances-stale')
    @if (auth()->user()->isAdmin())
        @include('partials.unreflected-payments')
    @endif

    <div class="row g-3 g-lg-4">

        <div class="col-12">

            <div class="card mb-3">
                <div class="card-body p-3 p-md-4">
                    <label for="supervisorTableSearch" class="form-label fw-bold text-secondary small">
                        <i class="fas fa-magnifying-glass text-primary me-1"></i> بحث سريع في الجدول:
                    </label>
                    <div class="input-group mb-3">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="supervisorTableSearch" class="form-control"
                            placeholder="اسم الكشف أو اسم الموزع...">
                    </div>

                    <form action="{{ route('supervisor.index') }}" method="GET" class="row g-2 g-md-3">
                        <div class="col-sm-5">
                            <label for="filter_date" class="form-label small fw-bold text-secondary mb-1">تاريخ الرفع:</label>
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
                    <h2 class="h6 fw-bold mb-0">الكشوفات الحالية</h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle" id="supervisorSheetsTable">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3 py-3">الكشف</th>
                                    <th>الموزع</th>
                                    <th>التاريخ</th>
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
                                                <div class="min-w-0">
                                                    <span class="d-block text-truncate" style="max-width: 220px;" title="{{ $s->original_name ?? $s->file_name }}">
                                                        {{ $s->displayTitle() }}
                                                    </span>
                                                    @include('partials.sheet-new-badge', ['sheet' => $s])
                                                    @include('partials.ack-pending-badge', ['count' => $unackedCounts[$s->id] ?? 0])
                                                    <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                                                        <span class="badge bg-dark-subtle text-dark-emphasis">
                                                            <i class="fas fa-store me-1"></i>{{ $s->total_pharmacies_count ?? 0 }}
                                                        </span>
                                                        @unless ($s->isV2())
                                                            <span class="badge bg-light text-secondary border">صيغة قديمة</span>
                                                        @endunless
                                                        @if (($s->not_visited_count ?? 0) > 0)
                                                            <span class="badge bg-warning-subtle text-warning-emphasis">
                                                                <i class="fas fa-clock me-1"></i>{{ $s->not_visited_count }} غير مزارة
                                                            </span>
                                                        @elseif (($s->total_pharmacies_count ?? 0) > 0)
                                                            <span class="badge bg-success-subtle text-success-emphasis">
                                                                <i class="fas fa-check-circle me-1"></i>كل الزيارات مكتملة
                                                            </span>
                                                        @endif
                                                        @if ($s->awaitsAssignment())
                                                            <span class="badge text-bg-warning">
                                                                <i class="fas fa-user-clock me-1"></i>بانتظار الإسناد
                                                            </span>
                                                        @endif
                                                        @if (! empty($s->unassigned_areas))
                                                            <span class="badge text-bg-danger">
                                                                <i class="fas fa-triangle-exclamation me-1"></i>
                                                                {{ $s->unassigned_areas === ['*'] ? 'غير مُسند' : count($s->unassigned_areas) . ' منطقة بلا موزع' }}
                                                            </span>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="fw-semibold">
                                            <i class="fas fa-user-circle text-muted me-1"></i>
                                            {{ $s->distributorsLabel() }}
                                        </td>
                                        <td class="text-muted text-nowrap font-monospace">
                                            @if ($s->distribution_date)
                                                <span class="d-block" title="تاريخ التوزيع">{{ $s->distribution_date->format('Y-m-d') }}</span>
                                            @endif
                                            <span class="fs-8">رُفع {{ $s->created_at->format('Y-m-d g:i A') }}</span>
                                        </td>
                                        <td>
                                            <x-status-badge :status="$s->status" :reason="$s->status_reason" />
                                        </td>
                                        <td class="text-center pe-3">
                                            <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                                <a href="{{ route('supervisor.sheet.show', $s->id) }}"
                                                    class="btn btn-sm btn-light border text-primary fw-bold" title="عرض التفاصيل والإدارة">
                                                    <i class="fas fa-eye"></i> التفاصيل
                                                </a>
                                                @if ($s->isV2())
                                                    @include('partials.export-button', ['sheet' => $s])
                                                @else
                                                    <a href="{{ route('supervisor.download', $s->id) }}"
                                                        class="btn btn-sm btn-light border text-success fw-bold" title="تحميل ملف الإكسل المحدث">
                                                        <i class="fas fa-download"></i> تحميل
                                                    </a>
                                                @endif

                                                @if (in_array($s->status, ['pending', 'in_progress']) && auth()->user()->can('close', $s))
                                                    <form action="{{ route('supervisor.complete', $s->id) }}" method="POST" class="m-0">
                                                        @csrf
                                                        @if (($unackedCounts[$s->id] ?? 0) > 0)
                                                            <input type="hidden" name="confirm_unacked" value="1">
                                                        @endif
                                                        <button type="submit" class="btn btn-sm btn-warning fw-bold"
                                                            onclick="return confirm(@js((($unackedCounts[$s->id] ?? 0) > 0 ? 'تنبيه: ' . $unackedCounts[$s->id] . ' إشعاراً بتعديل لم يطّلع عليه الموزع بعد. ' : '') . 'إغلاق هذا الكشف كمكتمل؟ لن يتمكن الموزع من التعديل عليه بعد الإغلاق.'))">
                                                            <i class="fas fa-lock"></i> إغلاق
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5">
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

        </div>
    </div>
@endsection

@push('scripts')
    <script>
        document.getElementById('supervisorTableSearch').addEventListener('input', function() {
            const searchText = this.value.toLowerCase().trim();
            const tableRows = document.querySelectorAll('#supervisorSheetsTable tbody tr');

            tableRows.forEach(row => {
                if (row.cells.length > 1) {
                    const rowText = row.textContent.toLowerCase();
                    row.style.setProperty('display', rowText.includes(searchText) ? '' : 'none', 'important');
                }
            });
        });
    </script>
@endpush
