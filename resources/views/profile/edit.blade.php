@extends('layouts.panel')

@section('title', 'حسابي')
@section('body_class', 'touch-ui')

@section('content')
    <x-page-header title="حسابي" subtitle="تعديل الاسم والبريد الإلكتروني وكلمة المرور" icon="fa-user-gear" />

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-id-card text-primary me-1"></i> بيانات الحساب</h2>

                    <form method="POST" action="{{ route('profile.update') }}">
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="name" class="form-label fw-bold small">الاسم</label>
                            <input id="name" name="name" type="text" required autocomplete="name"
                                value="{{ old('name', $user->name) }}"
                                class="form-control @error('name') is-invalid @enderror">
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label fw-bold small">البريد الإلكتروني</label>
                            <input id="email" name="email" type="email" required autocomplete="username" dir="ltr"
                                value="{{ old('email', $user->email) }}"
                                class="form-control @error('email') is-invalid @enderror">
                        </div>

                        <div class="mb-3">
                            <span class="form-label fw-bold small d-block">الدور</span>
                            <span class="badge rounded-pill {{ $user->role->badgeClass() }}">{{ $user->role->label() }}</span>
                        </div>

                        <button type="submit" class="btn btn-primary fw-bold px-4">
                            <i class="fas fa-save"></i> حفظ
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-body p-4">
                    <h2 class="h6 fw-bold mb-3"><i class="fas fa-key text-primary me-1"></i> تغيير كلمة المرور</h2>

                    @if ($errors->updatePassword->any())
                        <div class="alert alert-danger small">
                            <ul class="mb-0 ps-3">
                                @foreach ($errors->updatePassword->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('password.update') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="current_password" class="form-label fw-bold small">كلمة المرور الحالية</label>
                            <input id="current_password" name="current_password" type="password" dir="ltr"
                                autocomplete="current-password" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label for="new_password" class="form-label fw-bold small">كلمة المرور الجديدة (8 خانات على الأقل)</label>
                            <input id="new_password" name="password" type="password" dir="ltr"
                                autocomplete="new-password" class="form-control">
                        </div>

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label fw-bold small">تأكيد كلمة المرور الجديدة</label>
                            <input id="password_confirmation" name="password_confirmation" type="password" dir="ltr"
                                autocomplete="new-password" class="form-control">
                        </div>

                        <button type="submit" class="btn btn-primary fw-bold px-4">
                            <i class="fas fa-check"></i> تغيير كلمة المرور
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
