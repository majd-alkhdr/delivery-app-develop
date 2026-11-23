@extends('layouts.panel')

@section('title', 'تفاصيل الكشف - ' . $sheet->displayTitle())

@php
    $isAccountantView = request()->routeIs('accountant.*');
    $backRoute = $isAccountantView ? 'accountant.index' : 'supervisor.index';
    $downloadRoute = $isAccountantView ? 'accountant.download' : 'supervisor.download';
@endphp

@section('content')
    @include('partials.balances-stale')
    <x-page-header :title="'كشف: ' . $sheet->displayTitle()"
        :subtitle="'الموزعون: ' . $sheet->distributorsLabel()
            . ($sheet->distribution_date ? '' : ' — ' . $sheet->created_at->format('Y-m-d'))"
        icon="fa-file-invoice">
        <x-status-badge :status="$sheet->status" :reason="$sheet->status_reason" class="align-self-center" />
        @if ($sheet->isV2())
            {{-- القسم المالي (6B): للأدمن والمحاسب ومشرف المندوبين؛ لا لمشرف التوزيع --}}
            @if (! $sheet->isPreparing() && auth()->user()->can('viewFinance'))
                <a href="{{ route('finance.sheets.show', $sheet) }}" class="btn btn-sm btn-success fw-bold"><i class="fas fa-coins"></i> المالية</a>
            @endif
            @include('partials.export-button', ['sheet' => $sheet, 'areas' => $exportAreas])
        @else
            <a href="{{ route($downloadRoute, $sheet->id) }}" class="btn btn-sm btn-success fw-bold">
                <i class="fas fa-download"></i> تحميل
            </a>
        @endif
        <a href="{{ route($backRoute) }}" class="btn btn-sm btn-light border fw-bold">
            <i class="fas fa-arrow-right"></i> رجوع
        </a>
    </x-page-header>
    @include('partials.rep-scope-line')

    @include('partials.supplement-line', ['sheet' => $sheet, 'route' => 'supervisor.sheet.show'])
    @include('partials.sheet-notices', ['sheet' => $sheet, 'mode' => 'recent'])
    @include('partials.ack-panel', ['sheet' => $sheet])

    @unless ($isAccountantView)
        @include('partials.status-explanation', ['sheet' => $sheet])
    @endunless

    @if ($sheet->isDraft())
        <div class="alert alert-warning d-flex gap-2">
            <i class="fas fa-file-pen mt-1"></i>
            <div>
                <strong>هذا الكشف مسودة (أُغلق بدون توزيع)</strong> ولا يقبل أي تعديل، وهو مخفي عن الموزعين.
                <div class="small mt-1">السبب: {{ $sheet->status_reason }}
                    @if ($sheet->statusChanger) — بواسطة {{ $sheet->statusChanger->name }} @endif
                    @if ($sheet->status_changed_at) في {{ $sheet->status_changed_at->format('Y-m-d g:i A') }} @endif
                </div>
            </div>
        </div>
    @endif

    @if ($sheet->isV2())
        <div class="row g-2 mb-2 align-items-stretch">
            <div class="col-md-6"><x-stat-card label="إجمالي الكشف" icon="fa-file-lines" :value="number_format($sheet->total_all, 2)" variant="dark" /></div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column justify-content-center gap-1 small">
                        <span><i class="fas fa-hashtag text-secondary me-1"></i> أمر التسليم: <strong>{{ $sheet->delivery_order_no !== null ? $sheet->orderLabel() : '—' }}</strong></span>
                        <a href="{{ route('invoices.deferred') }}" class="fw-bold text-decoration-none">
                            <i class="fas fa-clock text-warning me-1"></i> الطلبيات المؤجلة (خارج الكشوف)
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-2 mb-2">
        <div class="col-4"><x-stat-card :label="$sheet->isV2() ? 'الفواتير المستحقة' : 'إجمالي الفواتير'" :value="number_format($totalInvoicesAmount, 0)" variant="primary" /></div>
        <div class="col-4"><x-stat-card :label="$sheet->isV2() ? 'الرصيد المستحق' : 'إجمالي الأرصدة'" :value="number_format($totalBalancesAmount, 0)" variant="danger" /></div>
        <div class="col-4"><x-stat-card label="إجمالي المقبوض" :value="number_format($totalCollectedAmount, 0) . ($totalCollectedUsd > 0 ? ' + ' . number_format($totalCollectedUsd, 2) . ' $' : '')" variant="success" /></div>
    </div>

    <div class="row g-2 mb-4">
        <div class="col-4"><x-stat-card label="الصيدليات" icon="fa-store" :value="$totalPharmaciesCount" variant="dark" /></div>
        <div class="col-4"><x-stat-card label="المزارة" icon="fa-check-circle" :value="$visitedPharmaciesCount" variant="success" /></div>
        <div class="col-4"><x-stat-card label="غير المزارة" icon="fa-clock" :value="$notVisitedPharmaciesCount" variant="secondary" /></div>
    </div>

    @if ($canManage)
        @include('supervisor.partials.sheet-management')
    @endif

    <div class="row g-2 mb-4">
        <div class="col-md-6 col-12">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                <input type="text" id="customerSearchInput" class="form-control"
                    placeholder="ابحث باسم الصيدلية أو رقم الفاتورة...">
            </div>
        </div>
        <div class="col-md-3 col-6">
            <select id="areaFilterInput" class="form-select" aria-label="المنطقة">
                <option value="">كل المناطق</option>
                @foreach ($areas as $area)
                    @if (!empty(trim($area)))
                        <option value="{{ trim($area) }}">{{ $area }}</option>
                    @endif
                @endforeach
            </select>
        </div>
        <div class="col-md-3 col-6">
            <select id="statusFilterInput" class="form-select" aria-label="حالة الزيارة">
                <option value="">كل الحالات</option>
                <option value="visited">تمت الزيارة</option>
                <option value="not_visited">لم تتم الزيارة بعد</option>
            </select>
        </div>
    </div>

    <div id="customersContainer">
        @forelse ($groupedInvoices as $customerName => $invoices)
            @php
                $firstInvoice = $invoices->first();
                $isAllPaid = $invoices->where('is_paid_full', false)->count() === 0;
                $customerArea = !empty($firstInvoice->area) ? trim($firstInvoice->area) : '';
                // المرحلة 3: الزيارة والدفعات من sheet_visits وpayments (كشوف v1 من أعمدتها القديمة)
                $customerVisit = $sheet->isV2() ? $visits->get($firstInvoice->customer_id) : null;
                $customerPayments = $sheet->isV2() ? $paymentsByCustomer->get($firstInvoice->customer_id, collect()) : collect();
                $hasServerData = $sheet->isV2()
                    ? (($customerVisit?->status === 'visited') || $customerPayments->whereNull('voided_at')->isNotEmpty())
                    : !is_null($firstInvoice->visited_at);
                $customerLock = $sheet->isV2() ? $locks->get($firstInvoice->customer_id) : null;
                $breakdown = $balanceBreakdown[$firstInvoice->customer_id] ?? null;
            @endphp
            <div class="card mb-3 customer-card {{ $isAllPaid ? 'all-paid' : '' }}"
                data-area="{{ $customerArea }}" data-visited="{{ $hasServerData ? 'true' : 'false' }}">
                <div class="card-body p-3">
                    <h3 class="h6 fw-bold mb-3 d-flex align-items-center gap-2 text-break-word">
                        <i class="fas fa-store {{ $isAllPaid ? 'text-success' : 'text-primary' }}"></i>
                        <span>{{ $customerName }}
                            @if (!empty($customerArea))
                                <small class="text-muted fw-normal fs-7">({{ $customerArea }})</small>
                            @elseif ($sheet->isV2())
                                <small class="badge bg-warning-subtle text-warning-emphasis fw-normal">بلا منطقة</small>
                            @endif
                        </span>
                    </h3>

                    @include('partials.invoices-table', ['invoices' => $invoices])

                    <div class="row g-2 p-2 bg-light rounded-3 border text-center small mb-3 mx-0">
                        <div class="col-6 border-end">
                            <span class="text-muted d-block small mb-1">{{ $sheet->isV2() ? 'إجمالي الفواتير المستحقة' : 'إجمالي فواتير الزبون' }}</span>
                            <span class="fw-bold fs-6 font-monospace">{{ number_format($firstInvoice->customer_total_invoices, 0) }}</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted d-block small mb-1">
                                {{ $sheet->isV2() ? 'الرصيد المستحق' : 'الرصيد الإجمالي النهائي' }}
                                @if ($breakdown)
                                    <span class="badge rounded-pill text-bg-light border" data-bs-toggle="tooltip" tabindex="0" role="button"
                                        title="{{ $breakdown->breakdown() }}" aria-label="{{ $breakdown->breakdown() }}">؟</span>
                                @endif
                            </span>
                            <span class="fw-bold text-danger fs-6 font-monospace">{{ number_format($breakdown ? $breakdown->net : $firstInvoice->final_balance, 0) }}</span>
                            @if ($breakdown)<span class="d-block text-muted fs-8">{{ $breakdown->asOfLabel() }}</span>@endif
                        </div>
                    </div>

                    {{-- حالة الزيارة والدفعات --}}
                    <div class="p-3 rounded-3 border border-2 {{ $hasServerData ? 'border-success-subtle' : 'border-secondary-subtle' }}">
                        @if ($sheet->isV2())
                            @include('supervisor.partials.customer-activity', [
                                'visit' => $customerVisit,
                                'payments' => $customerPayments,
                                'lock' => $customerLock,
                            ])
                        @else
                            <span class="badge {{ $hasServerData ? 'text-bg-success' : 'text-bg-secondary' }} p-2 fs-7 text-wrap text-start">
                                @if ($hasServerData)
                                    <i class="fas fa-check me-1"></i> تمت الزيارة بتاريخ {{ $firstInvoice->visited_at->format('Y-m-d g:i A') }}
                                @else
                                    <i class="fas fa-hourglass-half me-1"></i> لم تتم الزيارة بعد
                                @endif
                            </span>
                            @if ($hasServerData)
                                <div class="mt-2 small text-muted text-break-word">
                                    <strong>المقبوض:</strong> {{ number_format($firstInvoice->collected_amount, 0) }} ل.س |
                                    <strong>ملاحظات:</strong> {{ $firstInvoice->notes ?? '-' }}
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="card">
                <x-empty-state icon="fa-file-circle-xmark" title="لا توجد فواتير مستحقة في هذا الكشف" />
            </div>
        @endforelse
    </div>

    @if ($sheet->isV2() && $externalPayments->isNotEmpty())
        <div class="card mb-4">
            <div class="card-header bg-white fw-bold py-3"><i class="fas fa-plus-circle text-warning me-1"></i> دفعات خارجية مربوطة بهذا الكشف ({{ $externalPayments->count() }})</div>
            <div class="card-body">
                @foreach ($externalPayments->groupBy('customer_id') as $customerId => $rows)
                    <div class="fw-bold small">{{ $rows->first()->customer?->name }}</div>
                    @include('supervisor.partials.customer-activity', ['visit' => null, 'payments' => $rows, 'lock' => null])
                @endforeach
            </div>
        </div>
    @endif
@endsection

@push('scripts')
    <script>
        const searchInput = document.getElementById('customerSearchInput');
        const areaFilter = document.getElementById('areaFilterInput');
        const statusFilter = document.getElementById('statusFilterInput');

        function filterCustomers() {
            const searchText = searchInput.value.toLowerCase().trim();
            const selectedArea = areaFilter ? areaFilter.value.trim() : '';
            const selectedStatus = statusFilter ? statusFilter.value.trim() : '';
            const cards = document.querySelectorAll('.customer-card');

            cards.forEach(card => {
                const cardText = card.textContent.toLowerCase();
                const cardArea = (card.getAttribute('data-area') || '').trim();
                const isVisited = card.getAttribute('data-visited') === 'true';

                const matchesSearch = cardText.includes(searchText);
                const matchesArea = (selectedArea === '') || (cardArea === selectedArea);

                let matchesStatus = true;
                if (selectedStatus === 'visited') matchesStatus = isVisited;
                else if (selectedStatus === 'not_visited') matchesStatus = !isVisited;

                card.style.setProperty('display', (matchesSearch && matchesArea && matchesStatus) ? '' : 'none', 'important');
            });
        }

        // أسطر الفاتورة (قراءة فقط): تُجلب عند الطلب من /invoices/{id}/lines
        document.querySelectorAll('[data-lines-url]').forEach(btn => btn.addEventListener('click', async () => {
            const row = document.getElementById(btn.dataset.linesTarget);
            if (!row) return;
            const cell = row.querySelector('[data-lines-body]');
            const opening = row.classList.contains('d-none');
            row.classList.toggle('d-none');
            if (opening && !cell.dataset.loaded) {
                cell.innerHTML = '<span class="text-muted small">جارٍ التحميل…</span>';
                try {
                    const res = await fetch(btn.dataset.linesUrl, { headers: { 'Accept': 'text/html' }, credentials: 'same-origin' });
                    cell.innerHTML = res.ok ? await res.text() : '<span class="text-danger small">تعذّر تحميل الأسطر.</span>';
                    cell.dataset.loaded = res.ok ? '1' : '';
                } catch (e) {
                    cell.innerHTML = '<span class="text-danger small">لا اتصال.</span>';
                }
            }
        }));

        if (searchInput) searchInput.addEventListener('input', filterCustomers);
        if (areaFilter) areaFilter.addEventListener('change', filterCustomers);
        if (statusFilter) statusFilter.addEventListener('change', filterCustomers);

        // إسناد المناطق: "حدّد كل مناطق كتلة" تضع الموزع المختار لكل مناطق الكتلة دفعة واحدة
        document.querySelectorAll('[data-block-apply]').forEach(btn => {
            btn.addEventListener('click', function() {
                const block = this.getAttribute('data-block-apply');
                const chosen = document.querySelector(`[data-block-select="${block}"]`).value;
                document.querySelectorAll(`[data-area-block="${block}"]`).forEach(sel => sel.value = chosen);
            });
        });

        // اختيار "كل المناطق لموزع واحد" يعطّل الإسناد حسب المنطقة لتجنب الجمع بينهما
        const allSelect = document.getElementById('all_distributor_id');
        function toggleAreaSelects() {
            const disabled = allSelect && allSelect.value !== '';
            document.querySelectorAll('[data-area-block], [data-block-select], [data-block-apply]').forEach(el => el.disabled = disabled);
            const box = document.getElementById('areaAssignments');
            if (box) box.classList.toggle('opacity-50', disabled);
        }
        if (allSelect) {
            allSelect.addEventListener('change', toggleAreaSelects);
            toggleAreaSelects();
        }
    </script>
@endpush
