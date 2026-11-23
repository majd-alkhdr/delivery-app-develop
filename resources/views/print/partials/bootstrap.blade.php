{{--
    تحميل محرك الطباعة (وحدات ES) لصفحات الطباعة فقط: صفحات الموزع وصفحة اختبار الطابعة.
    الإعدادات تأتي مضمّنة في الصفحة ثم تُحدَّث من GET /print/config (مع ETag) وتُخزَّن محلياً.
    لا يحوي أي رصيد ولا بيانات زبائن.
--}}
@php
    $printSettings = \App\Support\PrintSettings::values();
    $printPage = [
        'configUrl' => route('print.config'),
        'eventsUrl' => auth()->user()->hasRole('distributor', 'rep') ? route('print-events.store') : null,
        'settings' => $printSettings,
        'ranges' => \App\Support\PrintSettings::ranges(),
        'printingEnabled' => (bool) $printSettings['printing_enabled'],
        'userName' => auth()->user()->name,
        'deliveryOrderNo' => $deliveryOrderNo ?? null,
    ];
@endphp
<script>
    window.PrintPage = @json($printPage);
</script>
<script type="module">
    import * as PrintService from '{{ asset('js/print/print-service.js') }}?v={{ @filemtime(public_path('js/print/print-service.js')) }}';
    window.PrintService = PrintService;
    PrintService.loadConfig().then(() => {
        @if (($bind ?? 'distributor') === 'distributor')
            PrintService.bindDistributorPage();
        @else
            document.dispatchEvent(new CustomEvent('print:ready'));
        @endif
    });
</script>
