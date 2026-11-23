@extends('layouts.panel')

@section('title', 'كشوفاتي')
@section('body_class', 'touch-ui')

@section('content')
    @include('partials.balances-stale')
    <x-page-header :title="'مرحباً، ' . Auth::user()->name" subtitle="تابع وحصّل فواتير الكشوفات المسندة إليك" icon="fa-user-check">
        <a href="{{ route('distributor.customers') }}" class="btn btn-outline-primary fw-bold"><i class="fas fa-address-book"></i> العملاء</a>
    </x-page-header>

    @php
        // حالة الكشف بلغة الموزع
        $distributorStatus = ['pending' => ['جديد', 'text-bg-secondary'], 'in_progress' => ['قيد العمل', 'text-bg-primary'], 'completed' => ['مكتمل', 'text-bg-success']];
    @endphp

    {{-- مؤشرات اليوم (كشوف تاريخ توزيعها اليوم، من السجلات غير الملغاة) --}}
    <div class="row g-2 mb-3" id="today-stats">
        <div class="col-4 col-md"><x-stat-card label="مزارة اليوم" :value="$todayStats['visited']" variant="success" value-id="today-visited" /></div>
        <div class="col-4 col-md"><x-stat-card label="غير مزارة" :value="$todayStats['not_visited']" variant="secondary" value-id="today-not-visited" /></div>
        <div class="col-4 col-md"><x-stat-card label="المحصّل (ل.س)" :value="number_format($todayStats['syp'], 0)" variant="success" value-id="today-syp" /></div>
        <div class="col-6 col-md"><x-stat-card label="المحصّل (دولار)" :value="number_format($todayStats['usd'], 2)" variant="primary" value-id="today-usd" /></div>
        <div class="col-6 col-md"><x-stat-card label="نسبة الإنجاز" :value="$todayStats['progress'] . '%'" variant="dark" value-id="today-progress" /></div>
    </div>

    <div class="card mb-3">
        <div class="card-body p-3">
            <div class="input-group mb-2">
                <span class="input-group-text bg-white"><i class="fas fa-magnifying-glass"></i></span>
                <input type="text" id="sheetSearchInput" class="form-control" placeholder="ابحث باسم الكشف أو تاريخ الرفع...">
            </div>

            <div class="row g-2">
                <div class="col-6">
                    <input type="date" id="dateFilterInput" class="form-control" aria-label="التاريخ"
                        value="{{ request('date') }}">
                </div>
                <div class="col-6">
                    <select id="statusSelectFilter" class="form-select" aria-label="الحالة">
                        <option value="all">كل الحالات</option>
                        <option value="pending">جديد</option>
                        <option value="in_progress">قيد العمل</option>
                        <option value="completed">مكتمل</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="chip-scroller mb-3" id="statusChips">
        <button type="button" class="btn btn-sm btn-dark px-3" data-status="all" onclick="filterStatus('all', this)">الكل</button>
        <button type="button" class="btn btn-sm btn-light border px-3" data-status="pending" onclick="filterStatus('pending', this)">جديد</button>
        <button type="button" class="btn btn-sm btn-light border px-3" data-status="in_progress" onclick="filterStatus('in_progress', this)">قيد العمل</button>
        <button type="button" class="btn btn-sm btn-light border px-3" data-status="completed" onclick="filterStatus('completed', this)">مكتمل</button>
    </div>

    <div id="sheetsContainer">
        @if ($sheets->isEmpty())
            <div class="card">
                <x-empty-state icon="fa-clipboard-list" title="لا توجد كشوفات مسندة إليك حالياً"
                    message="راجع مشرف التوزيع إن كنت تنتظر كشفاً جديداً." />
            </div>
        @else
            @foreach ($sheets as $sheet)
                <a href="{{ route('distributor.sheet', $sheet->id) }}"
                    class="card sheet-card status-{{ $sheet->status }} d-block text-decoration-none text-body mb-3 overflow-hidden">

                    <div class="card-body p-3" data-status="{{ $sheet->status }}">
                        <div class="d-flex align-items-center justify-content-between gap-2">
                            <div class="d-flex align-items-center gap-3 min-w-0">
                                <div class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width: 48px; height: 48px;">
                                    <i class="far fa-file-excel fs-4"></i>
                                </div>

                                <div class="min-w-0">
                                    <h2 class="h6 fw-bold mb-1 text-break-word">
                                        كشف: {{ $sheet->displayTitle() }}
                                        @include('partials.sheet-new-badge', ['sheet' => $sheet])
                                    </h2>
                                    <div class="d-flex align-items-center gap-1 text-muted fs-7">
                                        <i class="far fa-clock"></i>
                                        <span class="font-monospace">{{ $sheet->created_at->format('Y-m-d g:i A') }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                @php [$dsLabel, $dsClass] = $distributorStatus[$sheet->status] ?? [$sheet->status, 'text-bg-light']; @endphp
                                <span class="badge {{ $dsClass }} p-2">{{ $dsLabel }}</span>
                                <i class="fas fa-chevron-left text-muted small"></i>
                            </div>
                        </div>

                        <div class="progress mt-3" style="height: 6px;" role="progressbar" aria-label="نسبة الإنجاز" aria-valuenow="{{ $sheet->stats['progress'] }}" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar bg-success" style="width: {{ $sheet->stats['progress'] }}%"></div>
                        </div>
                        <div class="small text-muted mt-1">مزارة {{ $sheet->stats['visited'] }} من {{ $sheet->stats['total'] }} — محصّل {{ number_format($sheet->stats['syp'], 0) }} ل.س @if ($sheet->stats['usd'] > 0) و{{ number_format($sheet->stats['usd'], 2) }} $ @endif</div>
                        <div class="row g-2 mt-3 pt-3 border-top text-center">
                            <div class="col-4">
                                <small class="d-block text-muted fs-8"><i class="fas fa-store me-1"></i>الصيدليات</small>
                                <span class="fw-bold font-monospace">{{ $sheet->total_pharmacies_count }}</span>
                            </div>
                            <div class="col-4 border-start border-end">
                                <small class="d-block text-muted fs-8"><i class="fas fa-file-invoice me-1"></i>الطلبيات</small>
                                <span class="fw-bold text-primary font-monospace">{{ number_format($sheet->total_invoices_amount, 0) }}</span>
                            </div>
                            <div class="col-4">
                                <small class="d-block text-muted fs-8"><i class="fas fa-wallet me-1"></i>الرصيد المستحق</small>
                                <span class="fw-bold text-danger font-monospace">{{ number_format($sheet->total_balances_amount, 0) }}</span>
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        let currentStatusFilter = 'all';

        // فلترة لحظية بدون إعادة تحميل الصفحة
        document.getElementById('sheetSearchInput').addEventListener('input', runFilters);
        document.getElementById('dateFilterInput').addEventListener('change', runFilters);
        document.getElementById('statusSelectFilter').addEventListener('change', function() {
            currentStatusFilter = this.value;
            syncFilterButtons(this.value);
            runFilters();
        });

        // الضغط على أزرار الحالة العلوية
        function filterStatus(status, element) {
            currentStatusFilter = status;
            document.getElementById('statusSelectFilter').value = status;
            syncFilterButtons(status);
            runFilters();
        }

        function syncFilterButtons(status) {
            document.querySelectorAll('#statusChips button').forEach(btn => {
                const isActive = btn.getAttribute('data-status') === status;
                btn.classList.toggle('btn-dark', isActive);
                btn.classList.toggle('btn-light', !isActive);
                btn.classList.toggle('border', !isActive);
            });
        }

        // تطبيق الفلاتر الثلاثة معاً: النص + الحالة + التاريخ
        function runFilters() {
            const searchText = document.getElementById('sheetSearchInput').value.toLowerCase().trim();
            const selectedDate = document.getElementById('dateFilterInput').value;
            const cards = document.querySelectorAll('.sheet-card');

            cards.forEach(card => {
                const body = card.querySelector('.card-body');
                const sheetStatus = body.getAttribute('data-status');
                const sheetText = card.textContent.toLowerCase();

                const matchSearch = sheetText.includes(searchText);
                const matchStatus = (currentStatusFilter === 'all' || sheetStatus === currentStatusFilter);
                const matchDate = !selectedDate || sheetText.includes(selectedDate);

                card.style.setProperty('display', (matchSearch && matchStatus && matchDate) ? '' : 'none', 'important');
            });
        }
    </script>
@endpush
