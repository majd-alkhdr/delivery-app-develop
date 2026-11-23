@extends('layouts.guest')

@section('title', 'تأكيد البريد الإلكتروني')
@section('subtitle', 'يرجى تأكيد بريدك الإلكتروني عبر الرابط المرسل إليك')

@section('content')
    <div class="d-grid gap-2">
        <form method="POST" action="{{ route('verification.send') }}" class="d-grid">
            @csrf
            <button type="submit" class="btn btn-primary fw-bold">إعادة إرسال رابط التأكيد</button>
        </form>

        <form method="POST" action="{{ route('logout') }}" class="d-grid">
            @csrf
            <button type="submit" class="btn btn-light fw-bold">تسجيل الخروج</button>
        </form>
    </div>
@endsection
