@extends('layouts.panel')

@section('title', 'إسناد المندوبين')

@section('content')
    <x-page-header title="إسناد المندوبين" subtitle="من يشرف على أي مندوب وفي أي منطقة أو كتلة، وما الذي يراه كل مشرف مندوبين" icon="fa-people-arrows">
        <button type="button" class="btn btn-primary btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#assignModal"><i class="fas fa-plus"></i> إسناد مندوب</button>
    </x-page-header>

    {{-- التنبيهات (لا شيء يُتجاهل بصمت) --}}
    <div class="d-flex flex-column gap-2 mb-3" id="ra-alerts">
        @if ($alerts['reps_without_supervisor'] > 0)
            <div class="alert alert-warning py-2 mb-0" data-alert="reps-without-supervisor">
                <i class="fas fa-user-slash"></i> <strong>{{ $alerts['reps_without_supervisor'] }} مندوباً بلا مشرف</strong>
                — يراهم الأدمن فقط عند تفعيل الحصر.
                <a href="{{ route('admin.rep-assignments.index', ['view' => 'reps']) }}" class="alert-link">عرض بحسب المندوب</a>
            </div>
        @endif
        @if ($alerts['no_scope_customers'] > 0)
            <div class="alert alert-danger py-2 mb-0" data-alert="no-scope-customers">
                <i class="fas fa-map-location"></i> <strong>{{ $alerts['no_scope_customers'] }} صيدلية بلا منطقة ولا كتلة (لا يمكن إسنادها)</strong>
                — لا يراها إلا الأدمن ومشرف بنطاق «كل صيدليات المندوب».
                <a href="{{ route('admin.rep-assignments.index', ['view' => 'no-scope']) }}" class="alert-link">قائمتها</a>
            </div>
        @endif
        @if ($alerts['unassigned_customers'] > 0)
            <div class="alert alert-warning py-2 mb-0" data-alert="unassigned-customers">
                <i class="fas fa-circle-exclamation"></i> <strong>{{ $alerts['unassigned_customers'] }} صيدلية في مناطق غير مسنَدة لأي مشرف</strong>
                (من صيدليات مندوبين لهم مشرف) — يراها الأدمن فقط.
                <a href="{{ route('admin.rep-assignments.index', ['view' => 'reps', 'sort' => 'count']) }}" class="alert-link">التفصيل بحسب المندوب</a>
            </div>
        @endif
        @if ($alerts['reps_without_cost_center'] > 0)
            <div class="alert alert-secondary py-2 mb-0" data-alert="reps-without-cost-center">
                <i class="fas fa-circle-info"></i> {{ $alerts['reps_without_cost_center'] }} مندوباً بلا مركز كلفة: لا فواتير لهم فلا صيدليات تُحصر.
            </div>
        @endif
    </div>

    {{-- تفعيل الحصر --}}
    <div class="card mb-3" id="ra-scoping">
        <div class="card-body d-flex flex-wrap align-items-center gap-2 justify-content-between">
            <div>
                <span class="fw-bold">حصر رؤية مشرفي المندوبين:</span>
                @if ($scopingEnabled)
                    <span class="badge text-bg-success" data-scoping="on">مفعّل</span>
                    <span class="small text-muted">كل مشرف يرى مندوبيه ونطاقاتهم فقط.</span>
                @else
                    <span class="badge text-bg-secondary" data-scoping="off">غير مفعّل</span>
                    <span class="small text-muted">مشرفو المندوبين يرون كل المندوبين (السلوك السابق).</span>
                @endif
            </div>
            @if ($scopingEnabled)
                <form method="POST" action="{{ route('admin.rep-assignments.scoping') }}" class="m-0">
                    @csrf @method('PUT')
                    <input type="hidden" name="enabled" value="0">
                    <button class="btn btn-outline-secondary btn-sm fw-bold">إيقاف الحصر</button>
                </form>
            @else
                <button type="button" class="btn btn-success btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#scopingModal">تفعيل الحصر…</button>
            @endif
        </div>
    </div>

    {{-- تبويبات العرض والبحث --}}
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <ul class="nav nav-pills">
            <li class="nav-item"><a class="nav-link {{ $view === 'supervisors' ? 'active' : '' }}" href="{{ route('admin.rep-assignments.index', ['view' => 'supervisors'] + array_filter($filters)) }}">بحسب المشرف</a></li>
            <li class="nav-item"><a class="nav-link {{ $view === 'reps' ? 'active' : '' }}" href="{{ route('admin.rep-assignments.index', ['view' => 'reps'] + array_filter($filters)) }}">بحسب المندوب</a></li>
            <li class="nav-item"><a class="nav-link {{ $view === 'no-scope' ? 'active' : '' }}" href="{{ route('admin.rep-assignments.index', ['view' => 'no-scope']) }}">بلا منطقة ولا كتلة ({{ $alerts['no_scope_customers'] }})</a></li>
        </ul>
        @if ($view !== 'no-scope')
            <form method="GET" class="d-flex gap-2 ms-auto">
                <input type="hidden" name="view" value="{{ $view }}">
                <input type="search" name="q" value="{{ $filters['q'] }}" class="form-control form-control-sm" placeholder="بحث باسم مندوب أو مشرف" aria-label="بحث">
                <select name="sort" class="form-select form-select-sm" aria-label="الترتيب">
                    <option value="name" @selected($filters['sort'] === 'name')>بالاسم</option>
                    <option value="count" @selected($filters['sort'] === 'count')>{{ $view === 'reps' ? 'بغير المسنَد' : 'بعدد المندوبين' }}</option>
                </select>
                <button class="btn btn-sm btn-outline-primary">تطبيق</button>
            </form>
        @endif
    </div>

    @if ($view === 'supervisors')
        @forelse ($bySupervisor as $s)
            <div class="card mb-3" data-supervisor="{{ $s['supervisor']->id }}">
                <div class="card-header bg-white d-flex align-items-center gap-2">
                    <i class="fas fa-binoculars text-primary"></i>
                    <span class="fw-bold">{{ $s['supervisor']->name }}</span>
                    <span class="badge text-bg-light">{{ $s['reps']->count() }} مندوب</span>
                    <button type="button" class="btn btn-outline-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#assignModal" data-supervisor-id="{{ $s['supervisor']->id }}">
                        <i class="fas fa-plus"></i> إسناد مندوب
                    </button>
                </div>
                @if ($s['reps']->isEmpty())
                    <div class="card-body small text-muted">لا مندوبين مُسنَدين لهذا المشرف.</div>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($s['reps'] as $r)
                            <li class="list-group-item">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <a href="{{ route('rep-supervisor.reps.show', $r['rep']) }}" class="fw-bold">{{ $r['rep']?->name }}</a>
                                    <span class="small text-muted">({{ $r['pharmacies'] }} صيدلية)</span>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-1">
                                    @foreach ($r['assignments'] as $a)
                                        @include('admin.partials.rep-assignment-chip', ['a' => $a])
                                    @endforeach
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <x-empty-state icon="fa-binoculars" title="لا مشرفي مندوبين" message="أنشئ حساباً بدور «مشرف المندوبين» من شاشة المستخدمين." />
        @endforelse
    @elseif ($view === 'reps')
        <div class="card overflow-hidden">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light"><tr><th class="ps-3">المندوب</th><th>صيدلياته</th><th>مشرفوه ونطاقاتهم</th><th class="pe-3">غير المسنَد له</th></tr></thead>
                    <tbody>
                        @foreach ($byRep as $r)
                            <tr data-rep="{{ $r['rep']->id }}">
                                <td class="ps-3">
                                    <a href="{{ route('rep-supervisor.reps.show', $r['rep']) }}" class="fw-bold">{{ $r['rep']->name }}</a>
                                    <div class="small text-muted">{{ $r['rep']->cost_center_label ?: 'بلا مركز كلفة' }}</div>
                                </td>
                                <td>{{ $r['pharmacies'] }}</td>
                                <td>
                                    @if ($r['assignments']->isEmpty())
                                        <span class="badge text-bg-warning">بلا مشرف</span>
                                    @else
                                        <div class="d-flex flex-wrap gap-2">
                                            @foreach ($r['assignments'] as $a)
                                                @include('admin.partials.rep-assignment-chip', ['a' => $a, 'withSupervisor' => true])
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="pe-3 small">
                                    @if ($r['unassigned_count'] === 0)
                                        <span class="text-success">كل صيدلياته مسنَدة</span>
                                    @else
                                        <span class="badge text-bg-danger" data-unassigned-count>{{ $r['unassigned_count'] }}</span>
                                        @foreach ($r['unassigned'] as $u)
                                            <div>{{ $u['label'] }}: {{ $u['count'] }} صيدلية</div>
                                        @endforeach
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card overflow-hidden" id="ra-no-scope">
            <div class="card-header bg-white small">صيدليات بلا منطقة ولا كتلة في جدول الزبائن (تُصحَّح من ملف الأرصدة)</div>
            <ul class="list-group list-group-flush">
                @forelse ($noScopeCustomers as $c)
                    <li class="list-group-item small">{{ $c->name }}</li>
                @empty
                    <li class="list-group-item small text-muted">لا شيء.</li>
                @endforelse
            </ul>
        </div>
    @endif

    {{-- آخر 50 تغييراً --}}
    <div class="card mt-4" id="ra-log">
        <div class="card-header bg-white fw-bold small">آخر تغييرات الإسناد ({{ $logs->count() }})</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 small">
                <thead class="table-light"><tr><th class="ps-3">الوقت</th><th>المستخدم</th><th>الحدث</th><th class="pe-3">التفصيل</th></tr></thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="ps-3" dir="ltr">{{ $log->created_at?->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</td>
                            <td>{{ $log->user?->name }}</td>
                            <td>{{ $log->actionLabel() }}</td>
                            <td class="pe-3">
                                @php $m = $log->meta ?? []; @endphp
                                {{ collect([
                                    isset($m['rep']) ? 'المندوب: '.$m['rep'] : null,
                                    isset($m['from_supervisor']) ? 'من '.$m['from_supervisor'].' إلى '.$m['to_supervisor'] : (isset($m['supervisor']) ? 'المشرف: '.$m['supervisor'] : null),
                                    $m['scope'] ?? null,
                                    isset($m['pharmacies']) ? $m['pharmacies'].' صيدلية' : null,
                                    isset($m['reps_without_supervisor']) ? 'بلا مشرف: '.$m['reps_without_supervisor'] : null,
                                ])->filter()->implode(' — ') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted ps-3">لا تغييرات بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection

@push('modals')
    {{-- نافذة الإسناد --}}
    <div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="POST" action="{{ route('admin.rep-assignments.store') }}" class="modal-content">
                @csrf
                <div class="modal-header"><h2 class="modal-title h6 fw-bold" id="assignModalLabel">إسناد مندوب</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold" for="ra-supervisor">المشرف</label>
                            <select name="supervisor_id" id="ra-supervisor" class="form-select" required>
                                <option value="">— اختر —</option>
                                @foreach ($supervisors as $s)<option value="{{ $s->id }}" @selected(old('supervisor_id') == $s->id)>{{ $s->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold" for="ra-rep">المندوب</label>
                            <select name="rep_id" id="ra-rep" class="form-select" required>
                                <option value="">— اختر —</option>
                                @foreach ($reps as $r)<option value="{{ $r->id }}" @selected(old('rep_id') == $r->id)>{{ $r->name }}{{ $r->cost_center_label ? ' — '.$r->cost_center_label : ' (بلا مركز كلفة)' }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-2 small fw-bold">النطاق</div>
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        @foreach (\App\Models\RepSupervisorAssignment::TYPES as $type => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="scope_type" id="ra-type-{{ $type }}" value="{{ $type }}" data-scope-type @checked(old('scope_type', 'area') === $type)>
                                <label class="form-check-label" for="ra-type-{{ $type }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>
                    <input type="search" class="form-control form-control-sm mb-2" placeholder="تصفية القيم…" data-scope-filter aria-label="تصفية">
                    @foreach (['area' => $areas, 'block' => $blocks] as $type => $options)
                        <div class="border rounded p-2" style="max-height: 300px; overflow:auto" data-scope-list="{{ $type }}">
                            @forelse ($options as $o)
                                <div class="form-check" data-scope-option="{{ $o['label'] }}">
                                    <input class="form-check-input" type="checkbox" name="scope_values[]" value="{{ $o['value'] }}" id="ra-{{ $type }}-{{ $loop->index }}" @disabled($type !== old('scope_type', 'area'))>
                                    <label class="form-check-label small" for="ra-{{ $type }}-{{ $loop->index }}">{{ $o['label'] }} <span class="text-muted">({{ $o['count'] }} صيدلية)</span></label>
                                </div>
                            @empty
                                <div class="small text-muted">لا قيم في جدول الزبائن.</div>
                            @endforelse
                        </div>
                    @endforeach
                    <div class="form-text">يمكن اختيار عدة مناطق أو كتل دفعة واحدة. يرفض الخادم أي تعارض (نطاق لمشرف آخر، «كل صيدلياته»، تقاطع منطقة/كتلة) ويسمّي الطرف المتعارض.</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-primary fw-bold">إسناد</button></div>
            </form>
        </div>
    </div>

    {{-- نافذة نقل نطاق --}}
    <div class="modal fade" id="moveModal" tabindex="-1" aria-labelledby="moveModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form method="POST" action="" class="modal-content" data-move-form>
                @csrf
                <div class="modal-header"><h2 class="modal-title h6 fw-bold" id="moveModalLabel">نقل نطاق إلى مشرف آخر</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                <div class="modal-body">
                    <p class="small mb-2" data-move-summary></p>
                    <label class="form-label small fw-bold" for="ra-move-to">المشرف الجديد</label>
                    <select name="supervisor_id" id="ra-move-to" class="form-select mb-2" required>
                        <option value="">— اختر —</option>
                        @foreach ($supervisors as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                    <div class="alert alert-info small py-2 mb-2">يُنهى الإسناد القديم ويُنشأ الجديد في معاملة واحدة، ويُسجَّل قبل/بعد في سجل النشاط.</div>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="ra-move-confirm" required><label class="form-check-label small" for="ra-move-confirm">أؤكد النقل</label></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-warning fw-bold">نقل</button></div>
            </form>
        </div>
    </div>

    {{-- نافذة تفعيل الحصر --}}
    @unless ($scopingEnabled)
        <div class="modal fade" id="scopingModal" tabindex="-1" aria-labelledby="scopingModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('admin.rep-assignments.scoping') }}" class="modal-content">
                    @csrf @method('PUT')
                    <input type="hidden" name="enabled" value="1">
                    <div class="modal-header"><h2 class="modal-title h6 fw-bold" id="scopingModalLabel">تفعيل حصر الرؤية</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="إغلاق"></button></div>
                    <div class="modal-body small">
                        <p class="mb-2">بعد التفعيل يرى كل مشرف مندوبين مندوبيه ونطاقاتهم فقط في كل الشاشات والتقارير والتصدير.</p>
                        @if ($alerts['reps_without_supervisor'] > 0)
                            <div class="alert alert-warning py-2" data-scoping-warning>
                                <strong>{{ $alerts['reps_without_supervisor'] }} مندوباً بلا مشرف</strong> سيراهم الأدمن فقط:
                                {{ $repsWithoutSupervisor->take(15)->map(fn ($r) => $r['rep']->name)->implode('، ') }}{{ $repsWithoutSupervisor->count() > 15 ? '…' : '' }}
                            </div>
                        @endif
                        <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="ra-scoping-confirm" required><label class="form-check-label" for="ra-scoping-confirm">أؤكد التفعيل</label></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">إلغاء</button><button class="btn btn-success fw-bold">تفعيل</button></div>
                </form>
            </div>
        </div>
    @endunless

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const modal = document.getElementById('assignModal');
            const sync = () => {
                const type = modal.querySelector('[data-scope-type]:checked')?.value;
                modal.querySelectorAll('[data-scope-list]').forEach(list => {
                    const on = list.dataset.scopeList === type;
                    list.classList.toggle('d-none', !on);
                    list.querySelectorAll('input').forEach(i => { i.disabled = !on; });
                });
                modal.querySelector('[data-scope-filter]').classList.toggle('d-none', type === 'all');
            };
            modal.querySelectorAll('[data-scope-type]').forEach(r => r.addEventListener('change', sync));
            modal.querySelector('[data-scope-filter]').addEventListener('input', e => {
                const term = e.target.value.trim();
                modal.querySelectorAll('[data-scope-option]').forEach(o => o.classList.toggle('d-none', term !== '' && !o.dataset.scopeOption.includes(term)));
            });
            modal.addEventListener('show.bs.modal', e => {
                const id = e.relatedTarget?.dataset.supervisorId;
                if (id) modal.querySelector('#ra-supervisor').value = id;
            });
            sync();

            const move = document.getElementById('moveModal');
            move.addEventListener('show.bs.modal', e => {
                const b = e.relatedTarget;
                move.querySelector('[data-move-form]').action = b.dataset.moveUrl;
                move.querySelector('[data-move-summary]').textContent = b.dataset.moveSummary;
            });
            @if ($errors->any() && old('rep_id'))
                bootstrap.Modal.getOrCreateInstance(modal).show();
            @endif
        });
    </script>
@endpush
