@extends('layouts.guest')

@section('title', 'تأكيد كلمة المرور')
@section('subtitle', 'هذه منطقة محمية، أكّد كلمة المرور للمتابعة')

@section('content')
    <form method="POST" action="{{ route('password.confirm') }}">
        @csrf

        <div class="mb-4">
            <label for="password" class="form-label fw-bold small">كلمة المرور</label>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                dir="ltr" class="form-control @error('password') is-invalid @enderror">
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary fw-bold">تأكيد</button>
        </div>
    </form>
@endsection
