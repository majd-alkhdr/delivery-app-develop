@extends('layouts.panel')

@section('title', 'معاينة الدفعة')

@php
    $stat = fn ($key, $default = 0) => $batch->stat($key, $default);
    $legacy = (bool) $batch->stat('legacy');
    $tabUrl = fn (string $t, array $q = []) => route('sheet-manager.batches.show', ['batch' => $batch, 'tab' => $t] + $q);
    $levelStyles = [
        'critical' => ['danger', 'fa-circle-exclamation', 'حرج'],
        'decision' => ['warning', 'fa-hand-pointer', 'قرار'],
        'info' => ['info', 'fa-circle-info', 'للعلم'],
    ];
@endphp

@push('styles')
    <style>
        /* المرحلة 7E: ترويسة ثابتة على الشاشات الكبيرة، تبويبات تتمرر أفقياً على الجوال، وجداول تصبح بطاقات */
        @media (min-width: 992px) {
            .batch-header { position: sticky; top: 0; z-index: 1020; }
        }
        .batch-header { background: var(--bs-body-bg); }
        .batch-tabs { flex-wrap: nowrap; overflow-x: auto; overflow-y: hidden; scrollbar-width: thin; }
        .batch-tabs .nav-link { white-space: nowrap; }
        .stat-link { display: block; text-decoration: none; }
        .stat-link:hover .stat-card { filter: brightness(0.95); }
        .attention-row + .attention-row { border-top: 1px solid var(--bs-border-color-translucent); }
        @media (max-width: 575.98px) {
            table.table-cards thead { display: none; }
            table.table-cards, table.table-cards tbody, table.table-cards tr, table.table-cards td { display: block; width: 100%; }
            table.table-cards tr { border: 1px solid var(--bs-border-color); border-radius: .5rem; margin-bottom: .5rem; padding: .25rem .5rem; }
            table.table-cards td { border: 0; padding: .2rem 0; min-width: 0 !important; white-space: normal !important; }
            table.table-cards td[data-label]::before { content: attr(data-label) ': '; font-weight: 700; color: var(--bs-secondary-color); }
        }
    </style>
@endpush

@section('content')
    {{-- الترويسة: العنوان والإرسال والرجوع، وسبب منع الإرسال بجانب الزر --}}
    <div class="batch-header pt-2 pb-1 mb-2 border-bottom" id="batch-header">
        <x-page-header title="معاينة الدفعة" class="mb-1"
            :subtitle="'تاريخ التوزيع: ' . ($batch->distribution_date?->format('Y-m-d') ?? '—') . ' — رُفعت ' . $batch->created_at->format('Y-m-d g:i A') . ($batch->user ? ' بواسطة ' . $batch->user->name : '')"
            icon="fa-magnifying-glass-chart">
            @if ($preparing->isNotEmpty())
                <form action="{{ route('sheet-manager.batches.send', $batch) }}" method="POST" class="m-0" id="batch-send-form">
                    @csrf
                    @if ($costCenterNote)
                        <input type="hidden" name="confirm_no_cost_center" value="1">
                        <button type="button" class="btn btn-success fw-bold" @disabled($batchBlockers) data-bs-toggle="modal" data-bs-target="#costCenterConfirm" id="batch-send">
                            <i class="fas fa-paper-plane"></i> إرسال الدفعة إلى مشرف التوزيع
                        </button>
                    @else
                        <button class="btn btn-success fw-bold" @disabled($batchBlockers) id="batch-send"
                            onclick="return confirm('إرسال كل كشوف الدفعة قيد التجهيز ({{ $preparing->count() }}) إلى مشرف التوزيع؟')">
                            <i class="fas fa-paper-plane"></i> إرسال الدفعة إلى مشرف التوزيع
                        </button>
                    @endif
                </form>
            @endif
            {{-- المرحلة 7F: التراجع عن الدفعة (الأدمن، دفعة مطبَّقة لم يُتراجع عنها) --}}
            @if (auth()->user()->isAdmin() && ! $batch->isRolledBack())
                <a href="{{ route('sheet-manager.batches.rollback', $batch) }}" class="btn btn-outline-danger fw-bold" id="batch-rollback">
                    <i class="fas fa-rotate-left"></i> التراجع عن هذه الدفعة…
                </a>
            @endif
            <a href="{{ route('sheet-manager.index') }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> رجوع</a>
        </x-page-header>
        @if ($batch->isRolledBack())
            <div class="alert alert-danger py-2 px-3 mb-1 small" id="batch-rolled-back">
                <span class="badge text-bg-danger me-1">تم التراجع عن هذه الدفعة</span>
                {{ $batch->rolled_back_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}
                @if ($batch->rolledBackBy) بواسطة {{ $batch->rolledBackBy->name }} @endif
                — السبب: {{ $batch->rollback_reason }}. الصفحة وعناصرها للاطلاع فقط؛ فواتيرها المحذوفة لم تعد في النظام.
            </div>
        @endif
        @if (($previouslyExcluded ?? 0) > 0)
            <div class="alert alert-info py-2 px-3 mb-1 small" id="batch-previously-excluded">
                <i class="fas fa-circle-info me-1"></i> <strong>{{ $previouslyExcluded }} فاتورة في هذا الملف مستبعَدة سابقاً</strong> وبقيت مستبعَدة (معلومات، لا تكرار).
                الخيار: <span class="fw-bold">إبقاء الاستبعاد</span> (لا شيء تفعله)، أو
                <a href="{{ route('bulk-invoices.preview', ['scope' => 'batch_excluded', 'batch' => $batch->id, 'all' => 1]) }}" class="alert-link">إعادة إدراجها كلها إلى «بلا كشف»…</a>
            </div>
        @endif
        @if ($batchBlockers)
            <div class="alert alert-warning py-2 px-3 mb-1 small" id="batch-blockers">
                <span class="fw-bold"><i class="fas fa-hand me-1"></i> لا يمكن الإرسال بعد:</span>
                @foreach ($batchBlockers as $blocker)
                    <span class="d-block">{{ $blocker }}</span>
                @endforeach
                @if ($counts['pending'])
                    <a href="{{ $tabUrl('invoices', ['status' => 'pending']) }}" class="btn btn-sm btn-warning fw-bold mt-1" data-tab-link="invoices">
                        <i class="fas fa-list-check"></i> عرض العناصر المعلّقة ({{ $counts['pending'] }})
                    </a>
                @endif
            </div>
        @endif
    </div>

    {{-- سطر الملفات --}}
    @foreach ((array) $stat('files', []) as $file)
        <div class="small mb-1 {{ $file['success'] ? 'text-body' : 'alert alert-danger py-2' }}">
            <i class="fas {{ $file['success'] ? 'fa-file-circle-check text-success' : 'fa-file-circle-xmark' }} me-1"></i>
            <strong>{{ $file['name'] }}</strong>
            @if ($file['success'])
                — {{ $file['counts']['invoices'] ?? 0 }} فاتورة، {{ $file['counts']['lines'] ?? 0 }} سطراً، {{ $file['counts']['customers'] ?? 0 }} زبوناً
                ({{ $file['counts']['matched'] ?? 0 }} مطابق، {{ $file['counts']['new'] ?? 0 }} جديد)،
                موجودة مسبقاً: {{ $file['counts']['unchanged'] ?? '—' }}
            @else
                — رُفض الملف ولم يُحفظ منه أي شيء: {{ $file['error'] }}
            @endif
            @foreach ($file['notes'] ?? [] as $note)
                <span class="d-block text-muted"><i class="fas fa-circle-info me-1"></i>{{ $note }}</span>
            @endforeach
        </div>
    @endforeach

    {{-- تحذير حرج (المرحلة 7G): كل الأعمدة الغائبة في مربع واحد، مع أعمدة الملف الموجودة والأثر الفعلي --}}
    @if ($missingColumns['columns'])
        @php
            $columnCodes = array_column($missingColumns['columns'], 'code');
            $columnImpact = [];
            if (array_intersect($columnCodes, ['delivery_order_column_missing', 'delivery_date_column_missing'])) {
                $columnImpact[] = number_format((int) $stat('unassigned.count')) . ' فاتورة صارت بلا كشف';
                $columnImpact[] = number_format($deferred['count']) . ' مؤجلة';
                $columnImpact[] = number_format((int) $stat('sheets.count')) . ' كشف أُنشئ';
            }
            if ($costCenterNote) {
                $columnImpact[] = 'فواتير الملف بلا مركز كلفة: ' . number_format((int) ($costCenterNote->new['count'] ?? 0));
            }
        @endphp
        @include('sheet-manager.partials.missing-columns-box', [
            'columns' => $missingColumns['columns'],
            'present' => $missingColumns['present'],
            'impact' => $columnImpact,
            'impactTitle' => 'الأثر الفعلي في هذه الدفعة',
        ])
    @endif
    @if ($duplicate)
        @include('sheet-manager.partials.duplicate-upload', ['duplicate' => $duplicate])
    @endif

    {{-- أرقام الدفعة: إجمالي الملف = كشوف جديدة + مؤجلة + بلا كشف + موجودة مسبقاً + أخرى (كل بطاقة تنقل إلى تبويبها) --}}
    @unless ($legacy)
        @php
            $hasUnchanged = $batch->stat('unchanged_count') !== null;   // دفعات ما قبل 2C لا تحوي الرقم
            $unchangedSheets = (array) $batch->stat('unchanged_sheets', []);
            $nothingNew = $hasUnchanged
                && $stat('unchanged_count') > 0
                && empty($stat('created_sheet_ids', []))
                && empty($stat('updates', []))
                && empty($stat('proposal_ids', []))
                && $stat('sheets.count') == 0
                && $deferred['count'] == 0
                && $stat('unassigned.count') == 0;
        @endphp

        @if ($nothingNew)
            <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 fw-bold mt-2" id="nothing-new-banner">
                <i class="fas fa-circle-info"></i>
                لم يُنشأ كشف جديد: كل فواتير الملف موجودة مسبقاً بلا تغيير.
                @foreach ($unchangedSheets as $us)
                    <a href="{{ route('sheet-manager.sheets.show', $us['id']) }}" class="badge text-bg-primary text-decoration-none">أمر التسليم #{{ $us['order'] ?? '—' }}</a>
                @endforeach
            </div>
        @endif

        {{-- المرحلة 7H: ملاحق لأوامر مكتملة، وإشعارات بانتظار اطلاع الموزع --}}
        @if (($supplementSheets ?? collect())->isNotEmpty())
            <div class="alert alert-info d-flex flex-wrap align-items-center gap-2 mt-2 small" id="batch-supplements">
                <i class="fas fa-paperclip"></i>
                <span class="fw-bold">أُنشئ {{ $supplementSheets->count() }} ملحقاً لأوامر كشوفها مكتملة (قيد التجهيز، تُعاين وتُرسل كأي كشف):</span>
                @foreach ($supplementSheets as $sup)
                    <a href="{{ route('sheet-manager.sheets.show', $sup) }}" class="badge text-bg-primary text-decoration-none">{{ $sup->shortLabel() }}</a>
                    @if ($sup->parent)<span class="text-muted">(الأصل: <a href="{{ route('sheet-manager.sheets.show', $sup->parent) }}">{{ $sup->parent->shortLabel() }}</a>)</span>@endif
                @endforeach
            </div>
        @endif
        @if (($ackPending ?? collect())->isNotEmpty())
            <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 mt-2 small" id="batch-ack-pending">
                <i class="fas fa-bell"></i>
                <span class="fw-bold">{{ $ackPending->sum('count') }} إشعاراً بانتظار اطلاع الموزع:</span>
                @foreach ($ackPending as $row)
                    <a href="{{ route('sheet-manager.sheets.show', $row['sheet']) }}#ack-panel" class="badge text-bg-warning text-decoration-none">{{ $row['sheet']->shortLabel() }} ({{ $row['count'] }})</a>
                @endforeach
            </div>
        @endif

        <div class="row g-2 my-2" id="batch-cards">
            <div class="col-6 col-md"><a class="stat-link" href="{{ $tabUrl('invoices', ['kind' => 'all']) }}" data-tab-link="invoices"><x-stat-card label="إجمالي الملف" icon="fa-file-lines" :value="number_format($stat('file_total'), 2)" variant="dark" value-id="batch-file-total" /></a></div>
            <div class="col-6 col-md"><a class="stat-link" href="{{ $tabUrl('sheets') }}" data-tab-link="sheets"><x-stat-card :label="'في كشوف جديدة (' . $stat('sheets.count') . ')'" icon="fa-layer-group" :value="number_format($stat('sheets.total'), 2)" variant="success" value-id="batch-sheets-total" /></a></div>
            <div class="col-6 col-md"><a class="stat-link" href="{{ $tabUrl('invoices', ['kind' => 'unchanged']) }}" data-tab-link="invoices"><x-stat-card :label="'موجودة مسبقاً بلا تغيير (' . ($hasUnchanged ? $stat('unchanged_count') : '—') . ')'" icon="fa-equals"
                    :value="$hasUnchanged ? number_format($stat('unchanged_total'), 2) : '—'" variant="secondary" value-id="batch-unchanged-total" /></a></div>
            <div class="col-6 col-md"><a class="stat-link" href="{{ $tabUrl('deferred') }}" data-tab-link="deferred"><x-stat-card :label="'المؤجل (' . $deferred['count'] . ')'" icon="fa-clock" :value="number_format($deferred['total'], 2)" variant="warning" value-id="batch-deferred-total" /></a></div>
            <div class="col-6 col-md"><a class="stat-link" href="{{ $tabUrl('sheets') }}#unassigned" data-tab-link="sheets"><x-stat-card :label="'بلا كشف (' . $stat('unassigned.count') . ')'" icon="fa-inbox" :value="number_format($stat('unassigned.total'), 2)" variant="danger" value-id="batch-unassigned-total" /></a></div>
        </div>
        @if ((float) $stat('totals.discount') > 0)
            <div class="small text-muted mb-1" id="batch-discount-totals">
                <i class="fas fa-percent text-primary me-1"></i>
                المجموع قبل الحسم: <span class="font-monospace fw-bold" dir="ltr">{{ number_format($stat('totals.gross'), 2) }}</span>
                — حسم الأقلام: <span class="font-monospace fw-bold" dir="ltr">{{ number_format($stat('totals.discount'), 2) }}</span>
                — الصافي: <span class="font-monospace fw-bold" dir="ltr">{{ number_format($stat('totals.net'), 2) }}</span>
                (قيم الفواتير في الكشوف صافية بعد الحسم)
            </div>
        @endif
        @if ((int) $stat('totals.net_uncertain_invoices') > 0)
            <div class="small text-warning-emphasis mb-1" id="batch-net-uncertain">
                <i class="fas fa-triangle-exclamation me-1"></i>
                {{ $stat('totals.net_uncertain_invoices') }} فاتورة: قد يختلف الصافي عن برنامج المحاسبة (حسم هدايا/إضافات).
            </div>
        @endif
        @if ($stat('other.count'))
            <p class="small text-muted mb-1">أخرى: {{ $stat('other.count') }} فاتورة بقيمة {{ number_format($stat('other.total'), 2) }} (سبق توزيعها، أو مستبعدة سابقاً، أو بتغيير ينتظر قرارك).</p>
        @endif
        @if ($hasUnchanged && $unchangedSheets && ! $nothingNew)
            <p class="small text-muted mb-1">
                الفواتير الموجودة مسبقاً في:
                @foreach ($unchangedSheets as $us)
                    <a href="{{ route('sheet-manager.sheets.show', $us['id']) }}">أمر التسليم #{{ $us['order'] ?? '—' }}</a> ({{ $us['count'] }})@if (! $loop->last)، @endif
                @endforeach
            </p>
        @endif
    @endunless

    {{-- يحتاج انتباهك --}}
    <div class="card my-3" id="attention-bar">
        @forelse ($attention as $row)
            @php [$variant, $icon, $levelLabel] = $levelStyles[$row['level']]; @endphp
            <div class="attention-row d-flex flex-wrap align-items-center gap-2 px-3 py-2 small attention-{{ $row['level'] }}">
                <span class="badge text-bg-{{ $variant }}"><i class="fas {{ $icon }} me-1"></i>{{ $levelLabel }}</span>
                <i class="fas {{ $row['icon'] }} text-{{ $variant }}"></i>
                <span class="flex-grow-1 {{ $row['level'] === 'critical' ? 'fw-bold' : '' }}">{{ $row['text'] }}</span>
                @if ($row['count'] !== null)<span class="badge rounded-pill text-bg-light border">{{ number_format($row['count']) }}</span>@endif
                <a href="{{ $row['url'] }}" class="btn btn-sm btn-outline-{{ $variant }} fw-bold py-0">عرض</a>
            </div>
        @empty
            <div class="px-3 py-2 small text-success fw-bold" id="attention-none"><i class="fas fa-circle-check me-1"></i> لا شيء يحتاج قرارك في هذه الدفعة</div>
        @endforelse
    </div>

    {{-- التبويبات: التبديل دون إعادة تحميل، والرابط ?tab= قابل للمشاركة --}}
    <ul class="nav nav-tabs batch-tabs mb-3" id="batch-tabs" role="tablist">
        @foreach (\App\Http\Controllers\BatchPageController::TABS as $key => $label)
            <li class="nav-item" role="presentation">
                <a class="nav-link fw-bold {{ $tab === $key ? 'active' : '' }}" href="{{ $tabUrl($key) }}" data-tab="{{ $key }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}">
                    {{ $label }}
                    @if ($badges[$key] > 0)<span class="badge rounded-pill text-bg-{{ $key === 'balances' || $key === 'invoices' ? 'warning' : 'danger' }} ms-1 tab-badge">{{ $badges[$key] }}</span>@endif
                </a>
            </li>
        @endforeach
    </ul>

    <div id="batch-panes">
        @foreach (\App\Http\Controllers\BatchPageController::TABS as $key => $label)
            <div class="batch-pane {{ $tab === $key ? '' : 'd-none' }}" data-pane="{{ $key }}" data-ajax-root data-partial="1" @if ($tab === $key) data-loaded="1" @endif>
                @if ($tab === $key)
                    @include('sheet-manager.batch.tab-' . $key)
                @endif
            </div>
        @endforeach
    </div>

    {{-- نافذة عامة لقوائم «عرض الكل» (تحميل كسول) --}}
    <div class="modal fade" id="batchListModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title h6 fw-bold">عرض الكل</h2>
                    <button type="button" class="btn-close ms-0 me-auto" data-bs-dismiss="modal" aria-label="إغلاق"></button>
                </div>
                <div class="modal-body small" data-ajax-root data-partial=""></div>
            </div>
        </div>
    </div>

    @if ($costCenterNote && $preparing->isNotEmpty())
        <div class="modal fade" id="costCenterConfirm" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content border-danger">
                    <div class="modal-header text-bg-danger py-2">
                        <h2 class="modal-title h6 fw-bold"><i class="fas fa-user-slash me-1"></i> تأكيد الإرسال بلا عمود «مركز الكلفة»</h2>
                    </div>
                    <div class="modal-body fw-bold">{{ \App\Services\Import\WorkbookImporter::COST_CENTER_COLUMN_MISSING_TEXT }}</div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light border fw-bold" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" form="batch-send-form" class="btn btn-danger fw-bold" id="cost-center-confirm-send">أفهم ذلك، أرسل الدفعة رغم ذلك</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
    @include('invoices.partials.bulk-script')
@endsection

@push('scripts')
    <script>
        // المرحلة 7E: تبويبات وأجزاء تُحمَّل عند الحاجة، مع مؤشر تحميل وحالة خطأ عربية و«إعادة المحاولة»
        (() => {
            const loading = '<div class="text-center text-muted py-4 batch-loading"><span class="spinner-border spinner-border-sm me-2"></span>جارٍ التحميل…</div>';
            const failed = '<div class="alert alert-danger d-flex align-items-center gap-2 batch-error"><i class="fas fa-triangle-exclamation"></i> تعذّر التحميل. تحقق من الاتصال ثم <button type="button" class="btn btn-sm btn-danger fw-bold" data-retry>إعادة المحاولة</button></div>';
            const initWidgets = (root) => root.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => bootstrap.Tooltip.getOrCreateInstance(el));

            async function load(root, url) {
                const target = new URL(url, location.href);
                if (root.dataset.partial) target.searchParams.set('partial', root.dataset.partial);
                root.innerHTML = loading;
                try {
                    const res = await fetch(target, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    if (!res.ok) throw new Error(res.status);
                    root.innerHTML = await res.text();
                    root.dataset.loaded = '1';
                    initWidgets(root);
                    return true;
                } catch (e) {
                    root.innerHTML = failed;
                    root.querySelector('[data-retry]').addEventListener('click', () => load(root, url));
                    return false;
                }
            }

            function pageUrl(url) {
                const u = new URL(url, location.href);
                u.searchParams.delete('partial');
                return u.toString();
            }

            function showTab(key, url, push = true) {
                document.querySelectorAll('#batch-tabs [data-tab]').forEach(a => {
                    a.classList.toggle('active', a.dataset.tab === key);
                    a.setAttribute('aria-selected', a.dataset.tab === key ? 'true' : 'false');
                });
                document.querySelectorAll('.batch-pane').forEach(p => p.classList.toggle('d-none', p.dataset.pane !== key));
                const pane = document.querySelector(`.batch-pane[data-pane="${key}"]`);
                const target = url || document.querySelector(`#batch-tabs [data-tab="${key}"]`).href;
                if (url || !pane.dataset.loaded) load(pane, target);
                if (push) history.pushState({ tab: key }, '', pageUrl(target));
            }

            document.addEventListener('click', (e) => {
                const tabLink = e.target.closest('#batch-tabs [data-tab], [data-tab-link]');
                if (tabLink && !e.ctrlKey && !e.metaKey) {
                    e.preventDefault();
                    const key = tabLink.dataset.tab || tabLink.dataset.tabLink;
                    showTab(key, tabLink.dataset.tab ? null : tabLink.href);
                    if (tabLink.dataset.tabLink) document.getElementById('batch-tabs').scrollIntoView({ behavior: 'smooth' });

                    return;
                }
                // روابط داخل جزء محمَّل (الترقيم، المرشّحات السريعة)
                const link = e.target.closest('[data-ajax-root] a[href]');
                if (link && !link.target && !e.ctrlKey && !e.metaKey && (link.closest('.pagination') || link.hasAttribute('data-ajax'))) {
                    e.preventDefault();
                    const root = link.closest('[data-ajax-root]');
                    load(root, link.href);
                    if (root.classList.contains('batch-pane')) history.replaceState({ tab: root.dataset.pane }, '', pageUrl(link.href));

                    return;
                }
                // توسيع مجموعة أو فتح «عرض الكل»
                const opener = e.target.closest('[data-load-url]');
                if (opener) {
                    e.preventDefault();
                    if (opener.dataset.modal) {
                        const modal = document.getElementById('batchListModal');
                        modal.querySelector('.modal-title').textContent = opener.dataset.title || 'عرض الكل';
                        bootstrap.Modal.getOrCreateInstance(modal).show();
                        load(modal.querySelector('.modal-body'), opener.dataset.loadUrl);
                    } else {
                        const box = document.getElementById(opener.dataset.target);
                        const row = box.closest('tr') || box;
                        const open = row.classList.toggle('d-none') === false;
                        opener.setAttribute('aria-expanded', open ? 'true' : 'false');
                        if (open && !box.dataset.loaded) load(box, opener.dataset.loadUrl);
                    }
                }
            });

            // نماذج البحث والتصفية (GET) داخل جزء محمَّل
            document.addEventListener('submit', (e) => {
                const form = e.target;
                const root = form.closest('[data-ajax-root]');
                if (!root || (form.method || 'get').toLowerCase() !== 'get') return;
                e.preventDefault();
                const url = new URL(form.action, location.href);
                new FormData(form).forEach((v, k) => { if (v !== '') url.searchParams.set(k, v); else url.searchParams.delete(k); });
                load(root, url);
                if (root.classList.contains('batch-pane')) history.replaceState({ tab: root.dataset.pane }, '', pageUrl(url));
            });

            // نافذة «بلا كشف» المشتركة: تُملأ بفاتورة الزر الذي فتحها
            document.addEventListener('show.bs.modal', (e) => {
                if (e.target.id !== 'unassignedModal' || !e.relatedTarget) return;
                const b = e.relatedTarget.dataset;
                e.target.querySelector('[data-label]').textContent = b.label;
                e.target.querySelector('[data-mode-form="place"]').action = b.placeAction;
                e.target.querySelector('[data-mode-form="exclude"]').action = b.excludeAction;
                e.target.querySelector('[data-area-default]').textContent = `— منطقة الزبون (${b.area}) —`;
                e.target.querySelectorAll('[data-mode-form]').forEach(f => f.classList.toggle('d-none', f.dataset.modeForm !== b.mode));
            });

            window.addEventListener('popstate', () => {
                const key = new URL(location.href).searchParams.get('tab') || 'sheets';
                showTab(key, location.href, false);
            });

            initWidgets(document);
        })();
    </script>
@endpush
