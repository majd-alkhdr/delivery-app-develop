@extends('layouts.panel')

@section('title', 'مسؤول الكشوف')

@section('content')
    <x-page-header title="رفع الملفات وتشكيل الكشوف" subtitle="كل أمر تسليم يصبح كشفاً قيد التجهيز، يُراجع ثم يُرسل إلى مشرف التوزيع" icon="fa-layer-group" />

    @if ($pendingItemsTotal)
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 fw-bold" id="pending-items-alert">
            <i class="fas fa-list-check"></i>
            {{ $pendingItemsTotal }} عنصراً من دفعات الرفع ينتظر قرارك (تطبيق أو تجاهل):
            @foreach ($pendingItemBatches as $batchId)
                <a href="{{ route('sheet-manager.batches.show', ['batch' => $batchId, 'status' => 'pending']) }}#review" class="badge text-bg-dark text-decoration-none">دفعة {{ \Illuminate\Support\Str::limit($batchId, 8, '') }}</a>
            @endforeach
        </div>
    @endif

    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <a href="{{ route('invoices.unassigned') }}" class="text-decoration-none">
                <x-stat-card label="فواتير بلا كشف" icon="fa-inbox" :value="$counters['unassigned']" :variant="$counters['unassigned'] ? 'danger' : 'secondary'" value-id="counter-unassigned" />
            </a>
        </div>
        <div class="col-6 col-md-3">
            <x-stat-card label="تغييرات تنتظر قرارك" icon="fa-code-compare" :value="$counters['proposals']" :variant="$counters['proposals'] ? 'warning' : 'secondary'" value-id="counter-proposals" />
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('sheet-manager.sheets.index', ['status' => 'preparing']) }}" class="text-decoration-none">
                <x-stat-card label="كشوف قيد التجهيز" icon="fa-screwdriver-wrench" :value="$counters['preparing']" variant="info" value-id="counter-preparing" />
            </a>
        </div>
        <div class="col-6 col-md-3">
            <a href="{{ route('customers.review') }}" class="text-decoration-none">
                <x-stat-card label="زبائن بحاجة مطابقة" icon="fa-user-check" :value="$counters['review']" :variant="$counters['review'] ? 'warning' : 'secondary'" />
            </a>
        </div>
    </div>

    <div class="row g-3 g-lg-4">
        <div class="col-lg-4">
            <div class="card overflow-hidden">
                <div class="card-header text-bg-success border-0 py-3">
                    <h2 class="h6 fw-bold mb-0"><i class="fas fa-cloud-upload-alt me-1"></i> رفع ملفات</h2>
                </div>
                <div class="card-body p-4">
                    <form action="{{ route('sheet-manager.upload') }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="excel_files" class="form-label small fw-bold text-secondary">ملف أو عدة ملفات إكسل:</label>
                            <input type="file" name="excel_files[]" id="excel_files" multiple
                                class="form-control @error('excel_files') is-invalid @enderror @error('excel_files.*') is-invalid @enderror"
                                accept=".xlsx, .xls" required>
                            <div class="form-text">ملف "الحركة اليومية" و"الأرصدة" مسحوباً بتاريخ تسليم أكبر أو يساوي تاريخ التوزيع.</div>
                        </div>
                        <div class="mb-3">
                            <label for="distribution_date" class="form-label small fw-bold text-secondary">تاريخ التوزيع:</label>
                            <input type="date" name="distribution_date" id="distribution_date"
                                class="form-control @error('distribution_date') is-invalid @enderror"
                                value="{{ old('distribution_date', $defaultDistributionDate) }}" required>
                            <div class="form-text">يُطبَّق على كل ملفات الدفعة. التسليم بعده = طلبية مؤجلة.</div>
                        </div>
                        <div class="mb-3">
                            <label for="balances_as_of" class="form-label small fw-bold text-secondary">تاريخ الأرصدة (اختياري):</label>
                            <input type="date" name="balances_as_of" id="balances_as_of" class="form-control @error('balances_as_of') is-invalid @enderror" value="{{ old('balances_as_of') }}">
                            <div class="form-text">فقط إن لم يحوِ ملف الأرصدة نص «ولغاية …». يُسجَّل أنه أُدخل يدوياً.</div>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="check_missing" value="1" id="check_missing" @checked(old('check_missing'))>
                            <label class="form-check-label small fw-bold" for="check_missing">هذا الملف يغطي كل حركة الفترة (افحص الفواتير الغائبة)</label>
                            <div class="form-text">اتركه مُطفأً للملفات الجزئية (فاتورة واحدة معدّلة مثلاً). عند تفعيله: كل فاتورة في كشف مفتوح تاريخها ضمن مدى الملف ولم تظهر فيه تُعرض «غائبة» لتقرر إخراجها.</div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg fw-bold fs-6">
                                <i class="fas fa-file-import me-1"></i> رفع وتشكيل الكشوف
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card overflow-hidden">
                <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
                    <i class="fas fa-boxes-stacked text-primary"></i>
                    <h2 class="h6 fw-bold mb-0">آخر دفعات الرفع</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">تاريخ التوزيع</th>
                                <th>الملفات</th>
                                <th>الكشوف</th>
                                <th>بلا كشف</th>
                                <th>عناصر معلّقة</th>
                                <th class="pe-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($batches as $batch)
                                @php
                                    $batchSheets = $sheetsByBatch->get($batch->id, collect());
                                    $pendingUnassigned = $unassignedByBatch[$batch->id] ?? 0;
                                @endphp
                                <tr>
                                    <td class="ps-3 font-monospace text-nowrap">
                                        {{ $batch->distribution_date?->format('Y-m-d') ?? '—' }}
                                        <span class="d-block text-muted fs-8">رُفع {{ $batch->created_at->format('Y-m-d g:i A') }} — {{ $batch->user?->name }}</span>
                                    </td>
                                    <td class="text-break-word" style="max-width: 220px;">
                                        {{ implode('، ', $batch->file_names ?? []) }}
                                        @if (($bi = $balanceStatusByBatch[$batch->id] ?? null) && ! $bi->isApplied())
                                            <span class="d-block badge text-bg-danger text-wrap mt-1 batch-balances-not-applied" title="{{ $bi->hold_message }}">{{ $bi->statusLabel() }}: {{ \Illuminate\Support\Str::limit((string) $bi->hold_message, 90) }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="fw-bold">{{ $batchSheets->count() }}</span>
                                        @foreach ($batchSheets->countBy('status') as $status => $n)
                                            <x-status-badge :status="$status" class="ms-1" /> <span class="text-muted">×{{ $n }}</span>
                                        @endforeach
                                    </td>
                                    <td>
                                        @if ($pendingUnassigned)
                                            <span class="badge text-bg-danger">{{ $pendingUnassigned }}</span>
                                        @else
                                            <span class="text-muted">0</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($n = $pendingItemsByBatch[$batch->id] ?? 0)
                                            <a href="{{ route('sheet-manager.batches.show', ['batch' => $batch, 'status' => 'pending']) }}#review" class="badge text-bg-warning text-decoration-none batch-pending-items">{{ $n }}</a>
                                        @else
                                            <span class="text-muted">0</span>
                                        @endif
                                    </td>
                                    <td class="pe-3 text-end">
                                        <a href="{{ route('sheet-manager.batches.show', $batch) }}" class="btn btn-sm btn-light border text-primary fw-bold">
                                            <i class="fas fa-eye"></i> المعاينة
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6"><x-empty-state title="لا توجد دفعات بعد" message="ارفع ملف الحركة اليومية والأرصدة للبدء." /></td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($batches->hasPages())
                    <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $batches->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
