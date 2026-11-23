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
</head>

<body class="touch-ui">
    <div class="auth-wrapper">
        <div class="auth-card">
            <div class="text-center mb-4">
                @include('layouts.partials.logo', ['class' => 'mb-3'])
                <h1 class="h5 fw-bold mb-1">{{ config('app.name') }}</h1>
                @hasSection('subtitle')
                    <p class="text-muted small mb-0">@yield('subtitle')</p>
                @endif
            </div>

            <div class="card">
                <div class="card-body p-4">
                    <x-flash />

                    @yield('content')
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
</body>

</html>
