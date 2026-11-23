@extends('layouts.panel')

@section('title', 'تأكيد الرفع')

@php
    $e = $pending['estimate'];
    $impact = array_values(array_filter([
        $e['unassigned'] ? number_format($e['unassigned']) . ' فاتورة ستصبح بلا كشف' : null,
        $e['deferred'] ? number_format($e['deferred']) . ' ستصبح مؤجلة' : null,
        number_format($e['sheets']) . ' ستدخل ' . number_format($e['orders']) . ' كشف',
    ]));
@endphp

@section('content')
    <x-page-header title="تأكيد الرفع: أعمدة ناقصة"
        :subtitle="implode('، ', array_column($pending['files'], 'original_name')) . ' — تاريخ التوزيع ' . $pending['distribution_date']"
        icon="fa-triangle-exclamation">
        <a href="{{ route('sheet-manager.index') }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> رجوع دون رفع</a>
    </x-page-header>

    <p class="small text-muted mb-1"><i class="fas fa-circle-info me-1"></i> لم يُطبَّق أي شيء بعد. الملف محفوظ وينتظر قرارك.</p>

    @include('sheet-manager.partials.missing-columns-box', [
        'columns' => $pending['columns'],
        'present' => $pending['present'],
        'impact' => $impact,
        'impactTitle' => 'الأثر المتوقع على ' . number_format($e['invoices']) . ' فاتورة في الملف',
    ])

    @if ($duplicate)
        @include('sheet-manager.partials.duplicate-upload', ['duplicate' => $duplicate])
    @endif

    <form method="POST" action="{{ route('sheet-manager.uploads.confirm.store', $token) }}" class="card card-body border-danger mt-3" id="columns-confirm-form">
        @csrf
        <div class="form-check mb-3">
            <input class="form-check-input @error('confirm_missing_columns') is-invalid @enderror" type="checkbox" name="confirm_missing_columns" value="1" id="confirm-missing-columns">
            <label class="form-check-label fw-bold" for="confirm-missing-columns">{{ $pending['text'] }}</label>
            @error('confirm_missing_columns')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-danger fw-bold" id="columns-confirm-submit"><i class="fas fa-file-import"></i> تطبيق الرفع رغم ذلك</button>
            <a href="{{ route('sheet-manager.index') }}" class="btn btn-light border fw-bold">إلغاء وإعادة تصدير الملف</a>
        </div>
        <p class="small text-muted mb-0 mt-2">التأكيد لا يغيّر أي تصنيف: الفواتير تُصنَّف بالقواعد نفسها، ويُسجَّل تأكيدك باسمك في سجل النشاط.</p>
    </form>
@endsection
