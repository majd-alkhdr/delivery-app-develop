@extends('layouts.panel')

@section('title', 'خطة ' . $rep->name)

@php
    $optionLabel = fn ($o) => \App\Models\RepPlanTemplate::TYPES[$o['type']] . ' ' . $o['label'] . ' (' . $o['count'] . ' صيدلية'
        . ($o['no_location'] ? '، ' . $o['no_location'] . ' بلا موقع' : '') . ')' . ($isAdmin ? ' — ' . $o['owner_name'] : '');
    $optionValue = fn ($o) => $o['owner_id'] . '|' . $o['type'] . '|' . $o['value'];
@endphp

@section('content')
    <x-page-header :title="'خطة ' . $rep->name" subtitle="الخطة الأسبوعية (السبت إلى الجمعة) وتعديلات بتاريخ محدد" icon="fa-route">
        <a href="{{ route('rep-supervisor.plans') }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> المندوبون</a>
        <a href="{{ route('rep-supervisor.coverage', ['rep_id' => $rep->id]) }}" class="btn btn-outline-primary fw-bold"><i class="fas fa-chart-column"></i> التغطية</a>
    </x-page-header>

    @if ($options === [])
        <div class="alert alert-warning">لا نطاق لك لهذا المندوب في «إسناد المندوبين»، فلا يمكنك إضافة بنود.</div>
    @endif

    {{-- الخطة الأسبوعية الثابتة --}}
    <h2 class="h6 fw-bold mb-2"><i class="fas fa-calendar-week text-primary"></i> الخطة الأسبوعية (تعديلها يسري من اليوم ولا يغيّر الزيارات السابقة)</h2>
    <div class="row g-2 mb-4" id="plan-grid">
        @foreach ($grid as $wd => $day)
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card h-100" data-weekday="{{ $wd }}">
                    <div class="card-header bg-white fw-bold py-2">{{ $day['name'] }}</div>
                    <div class="card-body p-2 small">
                        @forelse ($day['items'] as $item)
                            @php $t = $item['template']; @endphp
                            @if ($item['mine'])
                                <div class="border rounded p-2 mb-2 plan-item" data-template="{{ $t->id }}">
                                    <div class="d-flex justify-content-between gap-2">
                                        <span class="fw-bold">{{ $t->scopeText() }}</span>
                                        <form method="POST" action="{{ route('rep-supervisor.plan.templates.destroy', $t) }}" class="m-0"
                                            onsubmit="return confirm({{ \Illuminate\Support\Js::from('إزالة ' . $t->scopeText() . ' من خطة يوم ' . $day['name'] . '؟ يسري من اليوم، والزيارات المسجَّلة تبقى محتسبة.') }})">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-link btn-sm p-0 text-danger" aria-label="إزالة"><i class="fas fa-xmark"></i></button>
                                        </form>
                                    </div>
                                    <div class="text-muted">{{ $item['count'] }} صيدلية @if ($isAdmin) — {{ $t->supervisor?->name }} @endif</div>
                                    @if ($item['no_location'])<div class="text-warning-emphasis"><i class="fas fa-location-dot"></i> {{ $item['no_location'] }} صيدلية بلا موقع محفوظ</div>@endif
                                    @if ($item['days'] > 1)<div class="text-warning-emphasis"><i class="fas fa-circle-info"></i> المنطقة نفسها في {{ $item['days'] }} أيام</div>@endif
                                </div>
                            @else
                                <div class="border rounded p-2 mb-2 bg-body-tertiary text-muted plan-item-other">
                                    <i class="fas fa-user-lock"></i> مشغول — مشرف آخر
                                </div>
                            @endif
                        @empty
                            <div class="text-muted mb-2">لا بنود.</div>
                        @endforelse
                        @if ($options)
                            <form method="POST" action="{{ route('rep-supervisor.plan.templates.store', $rep) }}" class="d-flex gap-1"
                                onsubmit="return confirm('يسري التعديل من اليوم ولا يغيّر الزيارات السابقة. متابعة؟')">
                                @csrf
                                <input type="hidden" name="weekday" value="{{ $wd }}">
                                <select name="scope" class="form-select form-select-sm" required aria-label="إضافة منطقة/كتلة">
                                    <option value="">+ إضافة منطقة/كتلة</option>
                                    @foreach ($options as $o)<option value="{{ $optionValue($o) }}">{{ $optionLabel($o) }}</option>@endforeach
                                </select>
                                <button class="btn btn-sm btn-primary fw-bold">إضافة</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- تعديل بتاريخ محدد --}}
    <h2 class="h6 fw-bold mb-2"><i class="fas fa-calendar-day text-primary"></i> تعديل بتاريخ محدد (ذلك اليوم فقط)</h2>
    <div class="card mb-4" id="plan-date">
        <div class="card-body">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end mb-3">
                <div><label class="form-label small fw-bold" for="plan-date-input">التاريخ</label>
                    <input type="date" name="date" id="plan-date-input" class="form-control" value="{{ $date }}" @unless ($isAdmin) min="{{ $today }}" @endunless></div>
                <button class="btn btn-outline-primary fw-bold">عرض</button>
            </form>
            <p class="fw-bold mb-2">خطة {{ \App\Services\Reps\RepPlanService::dayName($date) }} {{ $date }}:</p>
            @forelse ($dayPlan as $item)
                @php $mine = $isAdmin || $item['supervisor_id'] === auth()->id(); $visits = $visitCounts[$item['key']] ?? 0; @endphp
                <div class="d-flex flex-wrap gap-2 align-items-center border rounded p-2 mb-2 date-item" data-key="{{ $item['key'] }}">
                    @if ($mine)
                        <span class="fw-bold">{{ $item['text'] }}</span>
                        <span class="badge text-bg-light border">{{ count($item['customer_ids']) }} صيدلية</span>
                        <span class="badge text-bg-{{ $item['source'] === 'override' ? 'info' : 'secondary' }}">{{ $item['source'] === 'override' ? 'تعديل لهذا اليوم' : 'من الخطة الثابتة' }}</span>
                        @if ($visits)<span class="badge text-bg-success">{{ $visits }} زيارة مسجَّلة</span>@endif
                        @if ($canEditDate)
                            <form method="POST" action="{{ route('rep-supervisor.plan.overrides.remove', $rep) }}" class="ms-auto d-flex gap-1 m-0"
                                onsubmit="return confirm({{ \Illuminate\Support\Js::from('إزالة ' . $item['text'] . ' من خطة ' . $date . ' فقط؟' . ($visits ? ' سُجّلت ' . $visits . ' زيارة؛ تبقى مسجَّلة ومحتسبة، وتُحذف فقط الصيدليات غير المزارة من قائمة المندوب.' : '')) }})">
                                @csrf
                                <input type="hidden" name="plan_date" value="{{ $date }}">
                                <input type="hidden" name="scope_type" value="{{ $item['type'] }}">
                                <input type="hidden" name="scope_value" value="{{ $item['value'] }}">
                                <input type="text" name="reason" class="form-control form-control-sm" placeholder="السبب (اختياري)" maxlength="500">
                                <button class="btn btn-sm btn-outline-danger fw-bold text-nowrap">إزالة لهذا اليوم</button>
                            </form>
                        @endif
                    @else
                        <span class="text-muted"><i class="fas fa-user-lock"></i> مشغول — مشرف آخر</span>
                    @endif
                </div>
            @empty
                <p class="text-muted">لا خطة في هذا اليوم.</p>
            @endforelse

            @if ($canEditDate && $options)
                <form method="POST" action="{{ route('rep-supervisor.plan.overrides.store', $rep) }}" class="row g-2 align-items-end mt-2">
                    @csrf
                    <input type="hidden" name="plan_date" value="{{ $date }}">
                    <div class="col-md-6"><label class="form-label small fw-bold" for="ov-scope">إضافة لهذا اليوم فقط</label>
                        <select name="scope" id="ov-scope" class="form-select" required>
                            <option value="">— اختر —</option>
                            @foreach ($options as $o)<option value="{{ $optionValue($o) }}">{{ $optionLabel($o) }}</option>@endforeach
                        </select></div>
                    <div class="col-md-4"><label class="form-label small fw-bold" for="ov-reason">السبب (اختياري)</label>
                        <input type="text" name="reason" id="ov-reason" class="form-control" maxlength="500"></div>
                    <div class="col-md-2 d-grid"><button class="btn btn-primary fw-bold">إضافة</button></div>
                </form>
            @elseif (! $canEditDate)
                <p class="small text-muted mb-0"><i class="fas fa-lock"></i> يوم ماضٍ: التعديل للأدمن فقط.</p>
            @endif
        </div>
    </div>

    @if ($disabledTemplates->isNotEmpty())
        <h2 class="h6 fw-bold mb-2 text-warning-emphasis"><i class="fas fa-ban"></i> بنود معطَّلة (نطاقها بلا مشرف)</h2>
        <ul class="small" id="plan-disabled">
            @foreach ($disabledTemplates as $t)
                <li>{{ \App\Models\RepPlanTemplate::WEEKDAYS[$t->weekday] }}: {{ $t->scopeText() }} (كان عند {{ $t->supervisor?->name ?? '—' }})
                    @if ($isAdmin)
                        <form method="POST" action="{{ route('rep-supervisor.plan.templates.destroy', $t) }}" class="d-inline m-0" onsubmit="return confirm('حذف البند المعطَّل نهائياً؟')">
                            @csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-danger">حذف</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
@endsection
