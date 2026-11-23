{{-- المرحلة 7B (D5): «آخر تحديث للأرصدة منذ N يوم» إن كان أحدث ملف أرصدة مطبَّق أقدم من الحدّ المضبوط --}}
@php $staleness = app(\App\Services\CustomerBalanceService::class)->staleness(); @endphp
@if ($staleness['stale'])
    <div class="alert alert-warning py-2 small fw-bold" id="balances-stale">
        <i class="fas fa-hourglass-half me-1"></i> آخر تحديث للأرصدة منذ {{ $staleness['days'] }} يوم (الرصيد حتى {{ $staleness['as_of'] }}): الأرقام قد لا تعكس آخر حركة في المحاسبة.
    </div>
@endif
