<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') - @endif{{ config('app.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
    <link rel="stylesheet" href="{{ asset('vendor/bootstrap/css/bootstrap.rtl.min.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/fontawesome/css/all.min.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app-panel.css') }}?v={{ @filemtime(public_path('css/app-panel.css')) }}">
    @stack('styles')
</head>

@php
    $authUser = auth()->user();
    // الأدوار الإدارية: قائمة جانبية من مصدر واحد (AdminMenu). الموزع والمندوب: شريط الجوال كما هو.
    $useSidebar = \App\Support\Navigation\AdminMenu::usesSidebar($authUser);
    $menuGroups = $useSidebar ? \App\Support\Navigation\AdminMenu::for($authUser) : [];

    $navLinks = [];
    if ($authUser->hasRole('distributor')) {
        $navLinks[] = ['route' => 'distributor.index', 'active' => ['distributor.index', 'distributor.sheet'], 'label' => 'كشوفاتي', 'icon' => 'fa-clipboard-list'];
        $navLinks[] = ['route' => 'distributor.customers', 'active' => ['distributor.customers'], 'label' => 'العملاء', 'icon' => 'fa-address-book'];
    }
    if ($authUser->hasRole('rep')) {
        $navLinks[] = ['route' => 'rep.index', 'active' => ['rep.index'], 'label' => 'ذممي', 'icon' => 'fa-hand-holding-dollar'];
        // المرحلة 8D: «صيدلياتي» وكشف الصيدلية الكامل
        $navLinks[] = ['route' => 'rep.pharmacies.index', 'active' => ['rep.pharmacies.*'], 'label' => 'صيدلياتي', 'icon' => 'fa-store'];
        $navLinks[] = ['route' => 'rep.customers', 'active' => ['rep.customers'], 'label' => 'تحصيل لأي زبون', 'icon' => 'fa-address-book'];
        $navLinks[] = ['route' => 'rep.payments', 'active' => ['rep.payments'], 'label' => 'دفعاتي', 'icon' => 'fa-receipt'];
        $navLinks[] = ['route' => 'rep.sheets.index', 'active' => ['rep.sheets.*'], 'label' => 'كشوفي', 'icon' => 'fa-clipboard-list'];
        $navLinks[] = ['route' => 'rep.plan', 'active' => ['rep.plan'], 'label' => 'خطتي', 'icon' => 'fa-route'];
    }
    // المرحلة 8C: «نشاطي» للموزع والمندوب (سجلهما وحدهما)
    if ($authUser->hasRole('distributor', 'rep')) {
        $navLinks[] = ['route' => 'activity.index', 'active' => ['activity.*'], 'label' => 'نشاطي', 'icon' => 'fa-clock-rotate-left'];
    }
    $navLinks[] = ['route' => 'profile.edit', 'active' => ['profile.*'], 'label' => 'حسابي', 'icon' => 'fa-user-gear'];
@endphp

<body class="@yield('body_class') {{ $useSidebar ? 'has-sidebar' : '' }}">

@if ($useSidebar)
    {{-- حالة طيّ القائمة الجانبية قبل رسم المحتوى (لتفادي الوميض) --}}
    <script>
        try { if (localStorage.getItem('sidebarCollapsed') === '1') document.documentElement.classList.add('sidebar-collapsed'); } catch (e) {}
    </script>

    {{-- شريط علوي رفيع للجوال والتابلت: زر القائمة واسم الصفحة والشعار --}}
    <header class="app-topbar d-lg-none d-flex align-items-center gap-2 px-3 sticky-top">
        <button class="btn btn-link text-white p-1 fs-4" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar"
            aria-controls="appSidebar" aria-label="فتح القائمة">
            <i class="fas fa-bars" aria-hidden="true"></i>
        </button>
        <span class="fw-bold text-truncate flex-grow-1">@hasSection('title')@yield('title')@else{{ config('app.name') }}@endif</span>
        <a href="{{ route($authUser->role->homeRoute()) }}" class="text-decoration-none">@include('layouts.partials.logo')</a>
    </header>

    <x-sidebar :groups="$menuGroups" :user="$authUser" />

    <main class="app-main container-fluid px-3 px-lg-4 py-3 py-md-4 pb-5">
        <x-flash />

        @yield('content')
    </main>
@else
    <nav class="navbar navbar-expand-lg navbar-dark app-navbar shadow-sm sticky-top py-2">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2 m-0 fw-bold fs-6"
                href="{{ route($authUser->role->homeRoute()) }}">
                @include('layouts.partials.logo')
                <span class="text-truncate">{{ config('app.name') }}</span>
            </a>

            <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
                data-bs-target="#appNavbar" aria-controls="appNavbar" aria-expanded="false" aria-label="القائمة">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="appNavbar">
                <ul class="navbar-nav ms-lg-3 me-lg-auto mt-2 mt-lg-0 gap-lg-1">
                    @foreach ($navLinks as $link)
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs(...$link['active']) ? 'active' : '' }}"
                                href="{{ route($link['route']) }}">
                                <i class="fas {{ $link['icon'] }} fa-fw"></i> {{ $link['label'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>

                <div class="d-flex align-items-center justify-content-between gap-2 py-2 py-lg-0 app-navbar-user">
                    <span class="app-user-chip d-flex align-items-center gap-2 min-w-0">
                        <i class="fas fa-user-circle fs-5"></i>
                        <span class="text-truncate">{{ $authUser->name }}</span>
                        <span class="badge rounded-pill {{ $authUser->role->badgeClass() }}">{{ $authUser->role->label() }}</span>
                    </span>
                    {{-- تسجيل الخروج يمسح تفاصيل الفواتير المخزنة دون اتصال (IndexedDB)؛ طابور الدفعات المعلّقة لا يُمس --}}
                    <form method="POST" action="{{ route('logout') }}" class="m-0"
                        onsubmit="try { window.indexedDB && indexedDB.deleteDatabase('distributor_app'); localStorage.removeItem('printConfig:v1'); } catch (e) {}">
                        @csrf
                        <button type="submit" class="btn btn-outline-light btn-sm fw-bold px-3" style="min-height: 40px;">
                            <i class="fas fa-sign-out-alt"></i> خروج
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </nav>

    <main class="container py-3 py-md-4 pb-5">
        <x-flash />

        @yield('content')
    </main>
@endif

    @stack('modals')

    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    <script>
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));

    </script>
    @if ($useSidebar)
    <script>
        // القائمة الجانبية: الطيّ على الشاشات الكبيرة (يُحفظ في localStorage) وتلميحات الأيقونات عند الطيّ
        (function () {
            const root = document.documentElement;
            // الجوال: إغلاق القائمة عند اختيار عنصر (دون منع الانتقال)
            const sidebar = document.getElementById('appSidebar');
            sidebar?.querySelectorAll('a.app-sidebar-link').forEach(link => link.addEventListener('click', () => {
                if (window.innerWidth < 992) bootstrap.Offcanvas.getInstance(sidebar)?.hide();
            }));

            const toggle = document.querySelector('[data-sidebar-toggle]');
            if (!toggle) return;
            let tips = [];
            const sync = () => {
                const collapsed = root.classList.contains('sidebar-collapsed');
                toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                tips.forEach(t => t.dispose());
                tips = collapsed
                    ? [...document.querySelectorAll('[data-sidebar-tip]')].map(el => new bootstrap.Tooltip(el, { placement: 'left', trigger: 'hover focus' }))
                    : [];
            };
            toggle.addEventListener('click', () => {
                root.classList.toggle('sidebar-collapsed');
                try { localStorage.setItem('sidebarCollapsed', root.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (e) {}
                sync();
            });
            sync();
        })();
    </script>
    @endif
    @stack('scripts')
</body>

</html>
