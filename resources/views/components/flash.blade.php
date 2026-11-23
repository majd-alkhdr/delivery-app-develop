{{-- رسائل النظام الموحدة: نجاح، خطأ، حالة، وأخطاء التحقق من المدخلات --}}
@php
    $statusMessages = [
        'profile-updated' => 'تم حفظ بيانات الحساب.',
        'password-updated' => 'تم تغيير كلمة المرور.',
        'verification-link-sent' => 'تم إرسال رابط التحقق إلى بريدك الإلكتروني.',
    ];
    $status = session('status');
@endphp

@if (session('success'))
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-start gap-2 fw-bold" role="alert">
        <i class="fas fa-check-circle mt-1"></i>
        <div>{{ session('success') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
@endif

@if (session('warning'))
    <div class="alert alert-warning alert-dismissible fade show d-flex align-items-start gap-2 fw-bold" role="alert">
        <i class="fas fa-triangle-exclamation mt-1"></i>
        <div>{{ session('warning') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
@endif

@if (session('error'))
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-start gap-2 fw-bold" role="alert">
        <i class="fas fa-exclamation-circle mt-1"></i>
        <div>{{ session('error') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
@endif

@if ($status)
    <div class="alert alert-info alert-dismissible fade show d-flex align-items-start gap-2" role="alert">
        <i class="fas fa-info-circle mt-1"></i>
        <div>{{ $statusMessages[$status] ?? $status }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="إغلاق"></button>
    </div>
@endif

@if (isset($errors) && $errors->any())
    <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
        <i class="fas fa-triangle-exclamation mt-1"></i>
        <div>
            <strong class="d-block mb-1">يرجى تصحيح ما يلي:</strong>
            <ul class="mb-0 ps-3">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
