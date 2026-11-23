@extends('layouts.guest')

@section('title', 'استعادة كلمة المرور')
@section('subtitle', 'أدخل بريدك الإلكتروني وسنرسل لك رابطاً لتعيين كلمة مرور جديدة')

@section('content')
    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-4">
            <label for="email" class="form-label fw-bold small">البريد الإلكتروني</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus dir="ltr"
                class="form-control @error('email') is-invalid @enderror">
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary fw-bold">
                <i class="fas fa-paper-plane"></i> إرسال رابط الاستعادة
            </button>
            <a href="{{ route('login') }}" class="btn btn-light fw-bold">رجوع لتسجيل الدخول</a>
        </div>
    </form>
@endsection
