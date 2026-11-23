@extends('layouts.panel')

@section('title', 'لا توجد واجهة')
@section('body_class', 'touch-ui')

@section('content')
    <div class="card">
        <div class="card-body">
            <x-empty-state icon="fa-person-digging" title="لا توجد واجهة متاحة لهذا الحساب بعد"
                message="سيتم تفعيل شاشات هذا الحساب في تحديث قادم. للاستفسار تواصل مع مدير النظام." />
        </div>
    </div>
@endsection
