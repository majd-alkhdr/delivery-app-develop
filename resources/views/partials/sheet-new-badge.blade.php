{{-- شارة «تعديل جديد» على بطاقة الكشف (المرحلة 6B-D3): إشعارات غير مقروءة للمستخدم (الموزع: مناطقه فقط) --}}
@php
    $newNotices = (int) ($sheet->revision ?? 0) > 0 && auth()->check()
        ? app(\App\Services\SheetChangeNotifier::class)->unseen($sheet, auth()->user())->count()
        : 0;
@endphp
@if ($newNotices > 0)
    <span class="badge text-bg-warning sheet-new-badge"><i class="fas fa-bell me-1"></i>تعديل جديد ({{ $newNotices }})</span>
@endif
