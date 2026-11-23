@extends('layouts.panel')

@section('title', 'كل الكشوف')

@section('content')
    <x-page-header title="كل الكشوف" subtitle="كل الكشوف بكل حالاتها، بما فيها قيد التجهيز" icon="fa-layer-group" />

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('sheet-manager.sheets.index') }}" class="row g-2 align-items-end">
                <div class="col-sm-4">
                    <label for="f_status" class="form-label small fw-bold mb-1">الحالة:</label>
                    <select name="status" id="f_status" class="form-select">
                        <option value="">كل الحالات</option>
                        @foreach (['preparing' => 'قيد التجهيز', 'pending' => 'بانتظار البدء', 'in_progress' => 'قيد التحصيل', 'completed' => 'مكتمل ومغلق', 'draft' => 'مسودة'] as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-4">
                    <label for="f_date" class="form-label small fw-bold mb-1">تاريخ التوزيع:</label>
                    <input type="date" name="date" id="f_date" class="form-control" value="{{ request('date') }}">
                </div>
                <div class="col-sm-4 d-grid">
                    <button class="btn btn-primary fw-bold"><i class="fas fa-filter"></i> تطبيق</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">الكشف</th>
                        <th>تاريخ التوزيع</th>
                        <th>الفواتير / الزبائن</th>
                        <th>الإجمالي</th>
                        <th>الموزعون</th>
                        <th>الحالة</th>
                        <th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sheets as $sheet)
                        <tr>
                            <td class="ps-3 fw-bold">
                                {{ $sheet->displayTitle() }}
                                @include('partials.sheet-new-badge', ['sheet' => $sheet])
                                @include('partials.ack-pending-badge', ['count' => $unackedCounts[$sheet->id] ?? 0])
                                @unless ($sheet->isV2())
                                    <span class="badge bg-light text-secondary border">صيغة قديمة</span>
                                @endunless
                            </td>
                            <td class="font-monospace">{{ $sheet->distribution_date?->format('Y-m-d') ?? $sheet->created_at->format('Y-m-d') }}</td>
                            <td>{{ $sheet->invoices_count }} / {{ $sheet->customers_count }}</td>
                            <td class="font-monospace fw-bold">{{ number_format($sheet->invoices_total, 2) }}</td>
                            <td>{{ $sheet->distributorsLabel() }}</td>
                            <td><x-status-badge :status="$sheet->status" :reason="$sheet->status_reason" /></td>
                            <td class="pe-3 text-end">
                                <div class="d-flex gap-1 justify-content-end">
                                    <a href="{{ route('sheet-manager.sheets.show', $sheet) }}" class="btn btn-sm btn-light border text-primary fw-bold"><i class="fas fa-eye"></i> فتح</a>
                                    @include('partials.export-button', ['sheet' => $sheet])
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state title="لا توجد كشوف" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($sheets->hasPages())
            <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $sheets->links() }}</div>
        @endif
    </div>
@endsection
