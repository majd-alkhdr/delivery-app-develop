@extends('layouts.guest')

@section('title', 'تعيين كلمة مرور جديدة')
@section('subtitle', 'اختر كلمة مرور جديدة لا تقل عن 8 خانات')

@section('content')
    <form method="POST" action="{{ route('password.store') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3">
            <label for="email" class="form-label fw-bold small">البريد الإلكتروني</label>
            <input id="email" type="email" name="email" value="{{ old('email', $request->email) }}" required
                autofocus autocomplete="username" dir="ltr"
                class="form-control @error('email') is-invalid @enderror">
        </div>

        <div class="mb-3">
            <label for="password" class="form-label fw-bold small">كلمة المرور الجديدة</label>
            <input id="password" type="password" name="password" required autocomplete="new-password" dir="ltr"
                class="form-control @error('password') is-invalid @enderror">
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label fw-bold small">تأكيد كلمة المرور</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required
                autocomplete="new-password" dir="ltr" class="form-control">
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary fw-bold">
                <i class="fas fa-key"></i> حفظ كلمة المرور
            </button>
        </div>
    </form>
@endsection
