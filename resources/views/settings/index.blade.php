@extends('layouts.panel')

@section('title', 'الإعدادات')

@section('content')
    <x-page-header title="الإعدادات" subtitle="إعدادات الموقع والطباعة والمالية، ومجموعة التقسيم لكل سلسلة فواتير (يستخدمها محرك تقسيم الدفعات)" icon="fa-sliders" />

    <div class="card mb-4" id="settings-location">
        <div class="card-header bg-white fw-bold py-3"><i class="fas fa-location-crosshairs text-primary me-1"></i> الموقع</div>
        <div class="card-body">
            <form action="{{ route('settings.location') }}" method="POST" class="row g-2 align-items-end">
                @csrf
                @method('PUT')
                <div class="col-md-5">
                    <label for="pin_accuracy" class="form-label small fw-bold">أسوأ دقة GPS مقبولة لتثبيت الموزع موقع الصيدلية (متر)</label>
                    <input type="number" name="location_pin_max_accuracy_m" id="pin_accuracy" class="form-control" min="5" max="500" required
                        value="{{ old('location_pin_max_accuracy_m', $location['pin_max_accuracy_m']) }}" dir="ltr">
                    <div class="form-text">من 5 إلى 500. إن كانت دقة جهاز الموزع أسوأ منها لا يُقبل التثبيت.</div>
                    <div class="form-text text-warning-emphasis"><i class="fas fa-circle-info"></i> على الحاسوب تكون الدقة عادةً أقل من 500 م؛ جرّب التثبيت على الجوال في مكان مفتوح.</div>
                </div>
                <div class="col-md-4">
                    <span class="form-label small fw-bold d-block">نصف قطر «بعيد عن الموقع»</span>
                    <div class="form-control bg-light" dir="ltr">{{ $location['radius_m'] }} م</div>
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-primary fw-bold">حفظ</button>
                </div>
            </form>
        </div>
    </div>

    @if (auth()->user()->isAdmin())
        {{-- المرحلة 7H: الإشعارات التي تُلزم الموزع بـ«اطلعت» قبل التسجيل على الصيدلية --}}
        <div class="card mb-4" id="settings-ack-types">
            <div class="card-header bg-white fw-bold py-3"><i class="fas fa-user-check text-primary me-1"></i> اطلاع الموزع الإلزامي</div>
            <div class="card-body">
                <p class="small text-muted">تعديل من هذه الأنواع على صيدلية في كشف مُرسل يمنع الموزع من تسجيل زيارة أو دفعة عليها حتى يضغط «اطلعت». التسجيل دون اتصال يُقبل دائماً ويُوسَم.</p>
                <form action="{{ route('settings.ack-types') }}" method="POST">
                    @csrf
                    @method('PUT')
                    @php $ackTypes = \App\Services\NoticeAckService::requiredTypes(); @endphp
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        @foreach (\App\Models\SheetChangeNotice::TYPES as $type)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="types[]" value="{{ $type }}" id="ack_{{ $type }}" @checked(in_array($type, $ackTypes, true))>
                                <label class="form-check-label small" for="ack_{{ $type }}">{{ \App\Services\NoticeAckService::typeLabel($type) }}</label>
                            </div>
                        @endforeach
                    </div>
                    <button class="btn btn-primary fw-bold">حفظ</button>
                </form>
            </div>
        </div>

        {{-- المرحلة 8D: مفتاح كشف الصيدلية الكامل --}}
        @php $repFull = \App\Services\Reps\PharmacyStatementService::fullEnabled(); @endphp
        <div class="card mb-4" id="settings-rep-full-statement">
            <div class="card-header bg-white fw-bold py-3"><i class="fas fa-file-invoice-dollar text-primary me-1"></i> كشف الصيدلية الكامل للمندوب</div>
            <div class="card-body">
                <p class="small text-muted">مفعّل: يرى المندوب ومشرف المندوبين كل فواتير الصيدلية ودفعاتها بأسماء المندوبين (دون متبقي فواتير الآخرين ولا حصصهم). موقوف: فواتير المندوب ودفعاته فقط. التغيير يُسجَّل في سجل النشاط.</p>
                <form action="{{ route('settings.rep-full-statement') }}" method="POST" class="d-flex flex-wrap align-items-center gap-3">
                    @csrf
                    @method('PUT')
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="rep_full_statement" value="1" @checked($repFull)>
                        <label class="form-check-label small fw-bold" for="rep_full_statement">الكشف الكامل مفعّل</label>
                    </div>
                    <button class="btn btn-primary fw-bold">حفظ</button>
                </form>
            </div>
        </div>

        {{-- المرحلة 8B: خطة المندوب --}}
        <div class="card mb-4" id="settings-rep-plan">
            <div class="card-header bg-white fw-bold py-3"><i class="fas fa-route text-primary me-1"></i> خطة المندوب</div>
            <div class="card-body">
                <form action="{{ route('settings.rep-plan') }}" method="POST" class="row g-2 align-items-end">
                    @csrf
                    @method('PUT')
                    <div class="col-md-3">
                        <label for="rp_distance" class="form-label small fw-bold">حدّ مطابقة الموقع (متر)</label>
                        <input type="number" name="plan_location_max_distance_m" id="rp_distance" class="form-control" min="10" max="5000" required dir="ltr" value="{{ old('plan_location_max_distance_m', $repPlan['plan_location_max_distance_m']) }}">
                        <div class="form-text">أبعد منه ⇒ «بعيدة عن الموقع» بعد تأكيد المندوب.</div>
                    </div>
                    <div class="col-md-3">
                        <label for="rp_accuracy" class="form-label small fw-bold">أسوأ دقة GPS مقبولة (متر)</label>
                        <input type="number" name="plan_min_gps_accuracy_m" id="rp_accuracy" class="form-control" min="5" max="2000" required dir="ltr" value="{{ old('plan_min_gps_accuracy_m', $repPlan['plan_min_gps_accuracy_m']) }}">
                        <div class="form-text">أسوأ منها ⇒ «دقة ضعيفة».</div>
                    </div>
                    <div class="col-md-2">
                        <label for="rp_backfill" class="form-label small fw-bold">أيام التسجيل المتأخر</label>
                        <input type="number" name="rep_plan_backfill_days" id="rp_backfill" class="form-control" min="0" max="7" required dir="ltr" value="{{ old('rep_plan_backfill_days', $repPlan['rep_plan_backfill_days']) }}">
                        <div class="form-text">0 = اليوم فقط.</div>
                    </div>
                    <div class="col-md-2">
                        <label for="rp_hour" class="form-label small fw-bold">ساعة تنبيه «لا نشاط»</label>
                        <input type="number" name="plan_no_activity_hour" id="rp_hour" class="form-control" min="0" max="23" required dir="ltr" value="{{ old('plan_no_activity_hour', $repPlan['plan_no_activity_hour']) }}">
                    </div>
                    <div class="col-md-2 d-grid"><button class="btn btn-primary fw-bold">حفظ</button></div>
                </form>
            </div>
        </div>
    @endif

    <div class="card mb-4" id="settings-printing">
        <div class="card-header bg-white fw-bold py-3"><i class="fas fa-print text-primary me-1"></i> الطباعة</div>
        <div class="card-body">
            <p class="small text-muted">قيم عامة لكل الأجهزة. يمكن لكل جهاز تجاوزها محلياً من <a href="{{ route('print.test') }}">صفحة اختبار الطابعة</a>.</p>
            <form action="{{ route('settings.printing') }}" method="POST" class="row g-3">
                @csrf
                @method('PUT')
                @foreach (\App\Support\PrintSettings::FIELDS as $key => [$type, $default, $min, $max, $label])
                    @if ($type === 'bool')
                        <div class="col-md-4">
                            <div class="form-check form-switch mt-md-4">
                                <input class="form-check-input" type="checkbox" role="switch" name="{{ $key }}" id="ps_{{ $key }}" value="1" @checked(old($key, $printing[$key]))>
                                <label class="form-check-label small fw-bold" for="ps_{{ $key }}">{{ $label }}</label>
                            </div>
                        </div>
                    @elseif ($type === 'int')
                        <div class="col-md-4">
                            <label for="ps_{{ $key }}" class="form-label small fw-bold">{{ $label }}</label>
                            <input type="number" name="{{ $key }}" id="ps_{{ $key }}" class="form-control" dir="ltr" min="{{ $min }}" max="{{ $max }}" required
                                value="{{ old($key, $printing[$key]) }}">
                            <div class="form-text">من {{ $min }} إلى {{ $max }}</div>
                        </div>
                    @else
                        <div class="col-md-6">
                            <label for="ps_{{ $key }}" class="form-label small fw-bold">{{ $label }}</label>
                            <input type="text" name="{{ $key }}" id="ps_{{ $key }}" class="form-control" maxlength="{{ $max }}" @if ($min > 0) required @endif
                                value="{{ old($key, $printing[$key]) }}">
                        </div>
                    @endif
                @endforeach
                <div class="col-12">
                    <button class="btn btn-primary fw-bold"><i class="fas fa-save"></i> حفظ إعدادات الطباعة</button>
                </div>
            </form>
        </div>
    </div>

    @if (auth()->user()->isAdmin())
        {{-- المرحلة 7B: أرصدة الصيدليات --}}
        <div class="card mb-4" id="settings-balances">
            <div class="card-header bg-white fw-bold py-3"><i class="fas fa-scale-balanced text-primary me-1"></i> الأرصدة</div>
            <div class="card-body">
                <form action="{{ route('settings.balances') }}" method="POST" class="row g-2 align-items-end">
                    @csrf
                    @method('PUT')
                    @foreach ([
                        'balance_unexplained_tolerance' => ['حدّ «غير المفسَّر» (ل.س)', 'فرق أكبر منه يجعل التغيّر «يحتاج تحققاً».'],
                        'balance_notice_min_change' => ['أقل تغيّر يُنبَّه عنه (ل.س)', 'التغيّر الأقل يُحصى في الدفعة ولا يُنشئ إشعاراً.'],
                        'balances_zeroing_confirm_percent' => ['نسبة التصفير التي تطلب تأكيداً (%)', 'إن تجاوز من سيُصفَّرون هذه النسبة يتوقف التطبيق ويسأل «هل الملف كامل؟».'],
                        'balances_stale_days' => ['الأرصدة قديمة بعد (أيام)', 'شريط «آخر تحديث للأرصدة منذ N يوم» للموزع والمشرف والمندوب.'],
                        'exported_unreflected_days' => ['دفعة مصدَّرة لم تنعكس متأخرة بعد (أيام)', 'وسم أحمر في قسم «دفعات مصدَّرة لم تنعكس».'],
                    ] as $key => [$label, $hint])
                        <div class="col-md-4">
                            <label for="{{ $key }}" class="form-label small fw-bold">{{ $label }}</label>
                            <input type="number" step="0.01" min="0" name="{{ $key }}" id="{{ $key }}" class="form-control" required value="{{ old($key, $balanceSettings[$key]) }}">
                            <div class="form-text">{{ $hint }}</div>
                        </div>
                    @endforeach
                    <div class="col-md-4 d-grid"><button class="btn btn-primary fw-bold">حفظ</button></div>
                </form>
            </div>
        </div>

        <div class="card mb-4" id="settings-finance">
            <div class="card-header bg-white fw-bold py-3"><i class="fas fa-coins text-primary me-1"></i> المالية</div>
            <div class="card-body">
                <form action="{{ route('settings.finance') }}" method="POST" class="row g-2 align-items-end">
                    @csrf
                    @method('PUT')
                    <div class="col-md-9">
                        <label for="finance_statement_template" class="form-label small fw-bold">قالب «بيان المالية» في ملفات التصدير</label>
                        <input type="text" name="finance_statement_template" id="finance_statement_template" class="form-control" maxlength="500" required
                            value="{{ old('finance_statement_template', $finance['statement_template']) }}">
                        <div class="form-text">
                            المتغيران: <code>{user}</code> اسم من أدخل الدفعة (وإن تعدد المُدخِلون فمفصولين بـ « و »)، و<code>{order}</code> رقم أمر التسليم (أو <code>#رقم الكشف</code> إن كان فارغاً).
                            يُحفظ النص حرفياً بما فيه المسافات (لا تُحذف المسافة الأخيرة).
                            المعاينة: <span class="bg-light border px-1" style="white-space: pre">«{{ $finance['statement_preview'] }}»</span>
                        </div>
                    </div>
                    <div class="col-md-3 d-grid"><button class="btn btn-primary fw-bold">حفظ</button></div>
                </form>
                <hr>
                <form action="{{ route('settings.finance-rep') }}" method="POST" class="row g-2 align-items-end">
                    @csrf
                    @method('PUT')
                    <div class="col-md-9">
                        <label for="finance_rep_statement_template" class="form-label small fw-bold">قالب «بيان المالية» لتصدير دفعات المندوبين</label>
                        <input type="text" name="finance_rep_statement_template" id="finance_rep_statement_template" class="form-control" maxlength="500" required
                            value="{{ old('finance_rep_statement_template', $finance['rep_statement_template']) }}">
                        <div class="form-text">
                            <code>{user}</code> مركز كلفة المندوب صاحب الصف (مثل 655231-كرم الخالدي)، و<code>{order}</code> رقم دفعة تصدير المندوبين بصيغة <code>م-12</code>.
                            يُحفظ النص حرفياً بما فيه المسافات.
                            المعاينة: <span class="bg-light border px-1" style="white-space: pre">«{{ $finance['rep_statement_preview'] }}»</span>
                        </div>
                    </div>
                    <div class="col-md-3 d-grid"><button class="btn btn-primary fw-bold">حفظ</button></div>
                </form>
            </div>
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="card-header bg-white fw-bold py-3"><i class="fas fa-layer-group text-primary me-1"></i> مجموعة التقسيم لكل سلسلة (الحليب ثم ارجيفيت ثم البقية)</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr><th class="ps-3">السلسلة</th><th>الاسم</th><th class="pe-3">مجموعة التقسيم</th></tr>
                </thead>
                <tbody>
                    @forelse ($series as $item)
                        <tr>
                            <td class="ps-3"><x-series-badge :series="$item->key" /></td>
                            <td>{{ $item->label }}</td>
                            <td class="pe-3">
                                <form action="{{ route('settings.allocation', $item) }}" method="POST" class="d-flex gap-2 m-0">
                                    @csrf
                                    @method('PUT')
                                    <select name="allocation_group" class="form-select form-select-sm" aria-label="مجموعة التقسيم">
                                        @foreach (\App\Models\InvoiceSeries::ALLOCATION_GROUPS as $value => $label)
                                            <option value="{{ $value }}" @selected($item->allocation_group === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary fw-bold">حفظ</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><x-empty-state icon="fa-tags" title="لا توجد سلاسل" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
