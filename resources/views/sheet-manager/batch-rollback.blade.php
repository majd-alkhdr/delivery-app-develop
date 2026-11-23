@extends('layouts.panel')

@section('title', 'التراجع عن دفعة')

@php
    $c = $analysis['counts'];
    $rows = [
        ['invoices_deleted', 'فواتير ستُحذف (أنشأتها الدفعة)', 'danger'],
        ['invoices_deleted_unassigned', '— منها «بلا كشف»', 'secondary'],
        ['invoices_deleted_in_sheets', '— منها في كشوف قيد التجهيز', 'secondary'],
        ['deferred_removed', 'طلبيات مؤجلة ستُزال', 'danger'],
        ['invoices_restored', 'فواتير ستعود إلى حالتها السابقة (عدّلتها الدفعة أو نقلتها)', 'warning'],
        ['last_seen_reset', 'فواتير موجودة سابقاً يعود «آخر ملف رآها» إلى ما قبل الدفعة', 'secondary'],
        ['sheets_deleted', 'كشوف ستُحذف (قيد التجهيز/مسودة أنشأتها الدفعة)', 'danger'],
        ['customers_deleted', 'زبائن ستُحذف (أنشأتهم الدفعة بلا أي ارتباط آخر)', 'danger'],
        ['customers_balance_restored', 'زبائن يعود رصيدهم وتاريخه إلى ما قبل الدفعة', 'warning'],
        ['snapshots_deleted', 'لقطات أرصدة ستُحذف', 'danger'],
        ['notices_deleted', 'إشعارات «تغيّر رصيد» ستُحذف', 'danger'],
        ['proposals_deleted', 'اقتراحات تغيير من الدفعة ستُحذف', 'secondary'],
        ['items_reopened', 'عناصر دفعات سابقة «حلّ محلها» عنصر من هذه الدفعة تعود معلّقة', 'secondary'],
    ];
@endphp

@section('content')
    <x-page-header title="التراجع عن هذه الدفعة" :subtitle="implode('، ', (array) $batch->file_names) . ' — تاريخ التوزيع ' . $batch->distribution_date?->format('Y-m-d') . ' — رُفعت ' . $batch->created_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i')" icon="fa-rotate-left">
        <a href="{{ route('sheet-manager.batches.show', $batch) }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> رجوع للدفعة</a>
    </x-page-header>

    @if (! $analysis['eligible'])
        <div class="card border-danger mb-3" id="rollback-blockers">
            <div class="card-header bg-danger-subtle fw-bold">لا يمكن التراجع عن هذه الدفعة:</div>
            <ul class="list-group list-group-flush small">
                @foreach ($analysis['blockers'] as $b)
                    <li class="list-group-item" data-blocker="{{ $b['code'] }}">{{ $b['text'] }} @if ($b['count'])<strong>({{ $b['count'] }})</strong>@endif
                        @if ($b['examples'])<div class="text-muted">مثل: {{ implode('، ', $b['examples']) }}</div>@endif</li>
                @endforeach
            </ul>
            @unless (collect($analysis['blockers'])->contains('code', 'rolled_back'))
                <div class="card-body small border-top" id="rollback-alternative">
                    <strong>البديل:</strong> يمكنك الاستبعاد الجماعي للفواتير غير المتأثرة:
                    <a href="{{ route('sheet-manager.batches.show', ['batch' => $batch, 'tab' => 'sheets']) }}#unassigned">فواتير «بلا كشف» في هذه الدفعة</a>،
                    و<a href="{{ route('sheet-manager.batches.show', ['batch' => $batch, 'tab' => 'deferred']) }}">مؤجلاتها</a> (إلغاء جماعي)،
                    مع سبب مشترك واستثناء ما عليه نشاط بأسبابه.
                </div>
            @endunless
        </div>
    @else
        <div class="card mb-3" id="rollback-preview">
            <div class="card-header bg-white fw-bold">ماذا سيحدث (أرقام محسوبة فعلاً)</div>
            <ul class="list-group list-group-flush small">
                @foreach ($rows as [$key, $label, $tone])
                    @if (($c[$key] ?? 0) > 0 || in_array($key, ['invoices_deleted', 'deferred_removed', 'customers_deleted'], true))
                        <li class="list-group-item d-flex justify-content-between" data-count="{{ $key }}"><span>{{ $label }}</span><span class="badge text-bg-{{ $tone }}">{{ $c[$key] ?? 0 }}</span></li>
                    @endif
                @endforeach
            </ul>
        </div>

        @if ($analysis['keeps'])
            <div class="card border-info mb-3" id="rollback-keeps">
                <div class="card-header bg-info-subtle fw-bold small">ما سيبقى وسببه</div>
                <ul class="list-group list-group-flush small">
                    @foreach ($analysis['keeps'] as $k)
                        <li class="list-group-item">{{ $k['text'] }} <strong>{{ $k['count'] }}</strong>@if ($k['examples']) <span class="text-muted">— مثل: {{ implode('، ', $k['examples']) }}</span>@endif</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @foreach ($analysis['notes'] as $note)
            <div class="alert alert-warning small py-2">{{ $note }}</div>
        @endforeach

        <div class="alert alert-secondary small py-2">ملف الرفع المحفوظ يبقى، وتبقى صفحة الدفعة وعناصرها للاطلاع بشارة «تم التراجع». يمكنك رفع الملف الصحيح بعد التراجع (لا تكرار، وحارسا تاريخ الأرصدة لا يحتسبان ملف هذه الدفعة).</div>

        <form method="POST" action="{{ route('sheet-manager.batches.rollback.store', $batch) }}" class="card card-body border-danger" id="rollback-form">
            @csrf
            <label class="form-label fw-bold small" for="rb-reason">سبب التراجع (إلزامي)</label>
            <textarea name="reason" id="rb-reason" class="form-control mb-2" rows="2" required minlength="3" maxlength="1000">{{ old('reason') }}</textarea>
            <label class="form-label fw-bold small" for="rb-code">اكتب رقم الدفعة للتأكيد: <span class="font-monospace">{{ $code }}</span></label>
            <input type="text" name="confirm_code" id="rb-code" class="form-control mb-3 font-monospace" dir="ltr" required autocomplete="off">
            <div><button class="btn btn-danger fw-bold"><i class="fas fa-rotate-left"></i> تراجع عن الدفعة الآن</button></div>
        </form>
    @endif
@endsection
