{{-- شعار المستودع، وعند تعذر تحميل الصورة تظهر أيقونة محايدة بدلاً منها --}}
<span class="app-logo {{ $class ?? '' }}">
    <img src="{{ asset('images/logo.png') }}" alt="الشعار"
        onerror="this.hidden = true; this.nextElementSibling.hidden = false;">
    <i class="fas fa-truck-fast" hidden aria-hidden="true"></i>
</span>
