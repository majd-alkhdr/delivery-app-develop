{{--
    القائمة الجانبية للأدوار الإدارية. تعرض ما يعيده App\Support\Navigation\AdminMenu فقط (لا شروط أدوار هنا).
    - الشاشات الكبيرة (≥ lg): ثابتة على اليمين، وقابلة للطي إلى شريط أيقونات (الحالة في localStorage).
    - الجوال والتابلت (< lg): offcanvas ينزلق من اليمين (offcanvas-start في RTL).
--}}
@props(['groups', 'user'])

<aside class="offcanvas-lg offcanvas-start app-sidebar" tabindex="-1" id="appSidebar" aria-labelledby="appSidebarLabel" data-app-sidebar>
    <div class="app-sidebar-brand d-flex align-items-center gap-2">
        <a href="{{ route($user->role->homeRoute()) }}" class="d-flex align-items-center gap-2 text-decoration-none min-w-0" id="appSidebarLabel">
            @include('layouts.partials.logo')
            <span class="app-sidebar-text fw-bold text-truncate">{{ config('app.name') }}</span>
        </a>
        <button type="button" class="btn-close btn-close-white ms-auto d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="إغلاق القائمة"></button>
        <button type="button" class="app-sidebar-toggle ms-auto d-none d-lg-inline-flex" data-sidebar-toggle
            aria-label="طيّ القائمة" aria-expanded="true" title="طيّ القائمة / فردها">
            <i class="fas fa-angles-right" aria-hidden="true"></i>
        </button>
    </div>

    <nav class="app-sidebar-nav" aria-label="القائمة الرئيسية">
        @foreach ($groups as $group)
            <div class="app-sidebar-group" data-menu-group="{{ $group['key'] }}">
                <div class="app-sidebar-heading">{{ $group['label'] }}</div>
                <ul class="list-unstyled m-0">
                    @foreach ($group['items'] as $item)
                        @php $isActive = request()->routeIs(...$item['active']); @endphp
                        <li>
                            <a href="{{ route($item['route']) }}" class="app-sidebar-link {{ $isActive ? 'active' : '' }}"
                                @if ($isActive) aria-current="page" @endif
                                data-menu-item="{{ $item['route'] }}" title="{{ $item['label'] }}" data-sidebar-tip>
                                <i class="fas {{ $item['icon'] }} fa-fw" aria-hidden="true"></i>
                                <span class="app-sidebar-text">{{ $item['label'] }}</span>
                                @if ($item['badge'])
                                    <span class="badge rounded-pill app-sidebar-badge" dir="ltr" data-menu-badge="{{ $item['route'] }}">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="app-sidebar-user">
        <div class="d-flex align-items-center gap-2 min-w-0 mb-2">
            <i class="fas fa-user-circle fs-4 flex-shrink-0" aria-hidden="true"></i>
            <div class="app-sidebar-text min-w-0">
                <div class="fw-bold text-truncate">{{ $user->name }}</div>
                <span class="badge rounded-pill {{ $user->role->badgeClass() }}">{{ $user->role->label() }}</span>
            </div>
        </div>
        <a href="{{ route('profile.edit') }}" class="app-sidebar-link {{ request()->routeIs('profile.*') ? 'active' : '' }}"
            @if (request()->routeIs('profile.*')) aria-current="page" @endif title="الملف الشخصي" data-sidebar-tip>
            <i class="fas fa-user-gear fa-fw" aria-hidden="true"></i>
            <span class="app-sidebar-text">الملف الشخصي</span>
        </a>
        <form method="POST" action="{{ route('logout') }}" class="m-0" data-logout-form
            onsubmit="try { window.indexedDB && indexedDB.deleteDatabase('distributor_app'); localStorage.removeItem('printConfig:v1'); } catch (e) {}">
            @csrf
            <button type="submit" class="app-sidebar-link w-100 border-0 bg-transparent" title="تسجيل الخروج" data-sidebar-tip>
                <i class="fas fa-sign-out-alt fa-fw" aria-hidden="true"></i>
                <span class="app-sidebar-text">تسجيل الخروج</span>
            </button>
        </form>
    </div>
</aside>
