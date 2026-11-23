@extends('layouts.guest')

@section('title', 'تسجيل الدخول')
@section('subtitle', 'سجّل الدخول إلى حسابك للمتابعة')

@section('content')
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label fw-bold small">البريد الإلكتروني</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus
                autocomplete="username" dir="ltr" placeholder="name@company.com"
                class="form-control @error('email') is-invalid @enderror">
        </div>

        <div class="mb-3">
            <label for="password" class="form-label fw-bold small">كلمة المرور</label>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                dir="ltr" class="form-control @error('password') is-invalid @enderror">
        </div>

        {{-- يبقى المستخدم مسجلاً للدخول تلقائياً (سلوك قائم للموزعين في الميدان) --}}
        <input type="hidden" name="remember" value="1">

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4 small">
            <span class="text-muted"><i class="fas fa-circle-info me-1"></i> سيبقى حسابك مسجلاً على هذا الجهاز</span>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="fw-bold text-decoration-none">نسيت كلمة المرور؟</a>
            @endif
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg fw-bold">
                <i class="fas fa-right-to-bracket"></i> تسجيل الدخول
            </button>
        </div>
    </form>
@endsection
