{{-- المرحلة 7E: تبويب «الأرصدة» — بطاقة موجزة، عدّادات اللقطات بالتصنيف الجديد، الدفعات المصدَّرة غير المنعكسة، وقرارات الملف المحجوز --}}
@php
    $fmt = fn ($v) => number_format((float) $v, 2);
    $user = auth()->user();
    $changes = fn (array $q = []) => route('finance.balance-changes', ['import' => $balanceImport?->id] + $q);
@endphp

@if ($balanceImport)
    @php
        $bi = $balanceImport;
        $stats = $bi->stats ?? [];
    @endphp
    <div class="card mb-3 {{ $bi->isHeld() ? 'border-danger' : '' }}" id="balances-section">
        <div class="card-header py-2 fw-bold {{ $bi->isHeld() ? 'text-bg-danger' : 'bg-white' }}">
            <i class="fas fa-scale-balanced me-1"></i> ملف الأرصدة: «{{ $bi->file_name }}» — بتاريخ {{ $bi->as_of?->format('Y-m-d') ?? 'غير محدد' }}
            @if ($bi->as_of_manual)<span class="badge text-bg-warning ms-1">تاريخ أُدخل يدوياً</span>@endif
            <span class="badge {{ $bi->isApplied() ? 'text-bg-success' : 'text-bg-light' }} ms-1" id="balances-status">{{ $bi->statusLabel() }}</span>
        </div>
        <div class="card-body small">
            @if ($bi->isHeld())
                <div class="alert alert-danger fw-bold" id="balances-hold">{{ $bi->hold_message }}</div>
                @if ($user->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::SheetManager))
                    <div class="d-flex flex-wrap gap-3 align-items-start">
                        @if ($bi->hold_reason === 'older')
                            @if ($user->isAdmin())
                                <form action="{{ route('sheet-manager.balances.apply', $bi) }}" method="POST" class="d-flex gap-1 m-0">
                                    @csrf
                                    <input type="text" name="reason" class="form-control form-control-sm" required minlength="3" placeholder="سبب التطبيق (إلزامي)" aria-label="سبب التطبيق">
                                    <button class="btn btn-sm btn-danger fw-bold text-nowrap" onclick="return confirm('تطبيق أرصدة أقدم من المطبَّقة؟ سيُسجَّل السبب.')">تطبيقها رغم ذلك</button>
                                </form>
                            @else
                                <span class="text-muted">التطبيق رغم ذلك للمدير فقط.</span>
                            @endif
                        @elseif ($bi->hold_reason === 'zeroing')
                            <form action="{{ route('sheet-manager.balances.apply', $bi) }}" method="POST" class="m-0">
                                @csrf
                                <input type="hidden" name="confirm_zeroing" value="1">
                                <button class="btn btn-sm btn-danger fw-bold">نعم، الملف كامل</button>
                            </form>
                        @elseif ($bi->hold_reason === 'no_date')
                            <form action="{{ route('sheet-manager.balances.apply', $bi) }}" method="POST" class="d-flex gap-1 m-0">
                                @csrf
                                <input type="date" name="as_of" class="form-control form-control-sm" required aria-label="تاريخ الأرصدة">
                                @if ($user->isAdmin())
                                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="سبب (إن كان التاريخ أقدم من المطبَّق)" aria-label="سبب">
                                @endif
                                <button class="btn btn-sm btn-primary fw-bold text-nowrap">إدخال التاريخ وتطبيق</button>
                            </form>
                        @endif
                        <form action="{{ route('sheet-manager.balances.ignore', $bi) }}" method="POST" class="d-flex gap-1 m-0">
                            @csrf
                            <input type="text" name="note" class="form-control form-control-sm" placeholder="ملاحظة (اختيارية)" aria-label="ملاحظة">
                            <button class="btn btn-sm btn-outline-secondary fw-bold text-nowrap">{{ $bi->hold_reason === 'zeroing' ? 'لا، تجاهل الأرصدة' : 'تجاهل أرصدة هذا الملف' }}</button>
                        </form>
                    </div>
                    <div class="text-muted mt-2">التجاهل لا يحذف شيئاً: لا تتغيّر أرصدة الزبائن، ويبقى الملف محفوظاً، وتُعالَج فواتير الملف كالمعتاد.</div>
                @endif
            @elseif ($bi->status === 'ignored')
                <div class="alert alert-secondary mb-0">لم تُطبَّق أرصدة هذا الملف (تُجوهلت بقرار){{ $bi->decision_note ? ': ' . $bi->decision_note : '' }}. السبب الأصلي: {{ $bi->hold_message }}</div>
            @else
                {{-- البطاقة الموجزة --}}
                <div id="balances-result" class="mb-2">
                    <div class="fw-bold mb-1">الأرصدة المطبَّقة (بتاريخ {{ $bi->as_of?->format('Y-m-d') }})</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-1 text-center balances-summary">
                            <thead class="table-light">
                                <tr><th>صفوف الملف</th><th>طابَق</th><th>أُنشئ</th><th>صُفِّر</th><th>بلا تغيير</th><th>تغيّر</th><th>مجموع المدين</th><th>تاريخ الأرصدة</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{{ number_format($stats['rows'] ?? 0) }}</td>
                                    <td>{{ number_format($stats['matched'] ?? 0) }}</td>
                                    <td>{{ number_format($stats['created'] ?? 0) }}</td>
                                    <td>{{ number_format($stats['zeroed'] ?? 0) }}</td>
                                    <td>{{ number_format($stats['unchanged'] ?? 0) }}</td>
                                    <td>{{ number_format($snap['changed']) }}</td>
                                    <td class="font-monospace" dir="ltr">{{ $fmt($stats['total_debit'] ?? 0) }}</td>
                                    <td class="font-monospace">{{ $bi->as_of?->format('Y-m-d') }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <span class="text-muted">صُفّر رصيد {{ $stats['zeroed'] ?? 0 }} زبوناً غير موجودين في الملف.</span>
                    @if ($bi->decision_note)<span class="d-block">سبب التطبيق: {{ $bi->decision_note }}</span>@endif
                </div>

                {{-- عدّادات اللقطات --}}
                @php
                    $tiles = [
                        ['app', 'تغيّر مرتبط بحركة التطبيق', $snap['app'], 'primary', ['scope' => 'app']],
                        ['external', 'تغيّر خارجي', $snap['external'], 'secondary', ['scope' => 'external']],
                        ['created', 'زبائن جدد', $snap['created'], 'success', ['kind' => 'created', 'scope' => 'all']],
                        ['zeroed', 'مصفَّرون', $snap['zeroed'], 'dark', ['kind' => 'zeroed', 'scope' => 'all']],
                        ['flagged', 'تحتاج تحققاً', $snap['flagged'], 'danger', ['status' => 'flagged']],
                    ];
                @endphp
                <div class="row g-2 mb-2" id="snapshot-counters">
                    @foreach ($tiles as [$key, $label, $value, $variant, $q])
                        <div class="col-6 col-md">
                            @can('viewBalanceChanges')
                                <a href="{{ $changes($q) }}" class="card card-body py-2 text-decoration-none border-{{ $variant }} snap-{{ $key }}">
                            @else
                                <div class="card card-body py-2 border-{{ $variant }} snap-{{ $key }}">
                            @endcan
                                <span class="text-muted">{{ $label }}</span>
                                <span class="fw-bold fs-5 text-{{ $variant }}">{{ number_format($value) }}</span>
                            @can('viewBalanceChanges')</a>@else</div>@endcan
                        </div>
                    @endforeach
                </div>
                <div class="text-muted mb-2">
                    لقطات تغيّر الرصيد: {{ $snap['total'] }} — تحتاج تحققاً: {{ $snap['flagged'] }}.
                    اللقطات الخارجية لا تحتاج تحققاً لأنها تغيّر من مبيعات أو تحصيل خارج التطبيق.
                    <span class="d-block">{{ \App\Models\CustomerBalanceSnapshot::METHOD_NOTE }}</span>
                    @can('viewBalanceChanges')
                        <a href="{{ $changes() }}">عرض التغيّرات</a>
                    @endcan
                </div>

                {{-- إشعارات التغيّر (كانت بين الملاحظات) --}}
                @foreach ($balanceNotes as $note)
                    <div class="small mb-1 balance-note" data-code="{{ $note->new['code'] ?? '' }}"><i class="fas fa-bell-slash text-muted me-1"></i>{{ $note->reason }}</div>
                @endforeach

                @if ($mismatch->isNotEmpty())
                    <div class="alert alert-danger mt-2" id="exported-unreflected-batch">
                        <div class="fw-bold mb-1"><i class="fas fa-triangle-exclamation me-1"></i> دفعات مصدَّرة لم تنعكس في هذا الملف ({{ $mismatch->count() }})</div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-1 table-cards bg-transparent">
                                <thead><tr><th>الزبون</th><th>المصدَّر</th><th>رقم التصدير</th><th>الفرق</th></tr></thead>
                                <tbody>
                                    @foreach ($mismatch as $s)
                                        <tr>
                                            <td data-label="الزبون">{{ $s->customer?->name }}</td>
                                            <td class="font-monospace" data-label="المصدَّر">{{ $fmt($s->exported_payments_sum) }}</td>
                                            <td data-label="رقم التصدير">{{ collect($s->details ?? [])->where('type', 'exported_payment')->pluck('finance_export_id')->filter()->unique()->implode('، ') ?: '—' }}</td>
                                            <td class="font-monospace" data-label="الفرق">{{ $fmt($s->unexplained) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div>الفرق = تغيّر الرصيد الفعلي + المصدَّر − أثر الفواتير: موجب يعني أن الرصيد لم ينخفض بقدر المصدَّر (لم تُرحَّل القيود بعد)، وسالب يعني أنه انخفض أكثر منه.</div>
                        <div class="mt-1">تبقى هذه الدفعات تُطرح من الرصيد المعروض حتى تنعكس في ملف لاحق أو تُراجَع في «تغيّرات الأرصدة».</div>
                    </div>
                @endif

                @if ($zeroed->isNotEmpty())
                    <div class="mt-2" id="zeroed-list">
                        <div class="fw-bold mb-1">زبائن غابوا عن ملف الأرصدة فصُفِّر رصيدهم ({{ $snap['zeroed'] }})</div>
                        @if ($snap['zeroed'] <= 5)
                            <ul class="mb-0">
                                @foreach ($zeroed as $s)
                                    <li>{{ $s->customer?->name }}: من <span class="font-monospace">{{ $fmt($s->prev_balance) }}</span> إلى 0</li>
                                @endforeach
                            </ul>
                        @else
                            <div class="table-responsive">
                                <table class="table table-sm mb-1 table-cards">
                                    <thead class="table-light"><tr><th>الزبون</th><th>من</th><th>إلى</th></tr></thead>
                                    <tbody>
                                        @foreach ($zeroed as $s)
                                            <tr><td data-label="الزبون">{{ $s->customer?->name }}</td><td class="font-monospace" data-label="من">{{ $fmt($s->prev_balance) }}</td><td data-label="إلى">0</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @can('viewBalanceChanges')
                                <a href="{{ $changes(['kind' => 'zeroed', 'scope' => 'all']) }}">عرض كل المصفَّرين ({{ $snap['zeroed'] }}) في «تغيّرات الأرصدة»</a>
                            @endcan
                        @endif
                    </div>
                @endif
            @endif
        </div>
    </div>
@elseif ($balances = $batch->stat('balances'))
    {{-- الدفعات الأقدم تعرض نتيجتها المخزّنة (7A F12) --}}
    <div class="alert alert-light border small" id="balances-result">
        <i class="fas fa-scale-balanced me-1"></i>
        <strong>الأرصدة المطبَّقة</strong> (بتاريخ {{ $balances['as_of'] ?? 'غير محدد' }}):
        صفوف الملف {{ $balances['rows'] ?? 0 }} — طوبق {{ $balances['matched'] ?? 0 }} زبوناً —
        أُنشئ {{ $balances['created'] ?? 0 }} زبوناً جديداً — صُفّر رصيد {{ $balances['zeroed'] ?? 0 }} زبوناً غير موجودين في الملف —
        مجموع المدين <span class="font-monospace" dir="ltr">{{ $fmt((float) ($balances['total_debit'] ?? 0)) }}</span>
    </div>
@else
    <div class="alert alert-light border small">لا توجد صفحة أرصدة في هذه الدفعة.</div>
@endif
