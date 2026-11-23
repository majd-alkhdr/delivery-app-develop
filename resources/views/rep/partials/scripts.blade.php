{{-- أدوات صفحات المندوب: الطابور والموقع والتأكيد من distributor-app.js، ونموذج التحصيل. لا وحدة طباعة هنا. --}}
<script>
    window.DistributorConfig = @json($config);
</script>
<script src="{{ asset('js/distributor-app.js') }}?v={{ @filemtime(public_path('js/distributor-app.js')) }}"></script>
<script type="module" src="{{ asset('js/rep/rep-app.js') }}?v={{ @filemtime(public_path('js/rep/rep-app.js')) }}"></script>
