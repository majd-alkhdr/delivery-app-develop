@extends('layouts.panel')

@section('title', 'سلاسل الفواتير')

@section('content')
    <x-page-header title="سلاسل الفواتير" subtitle="تعديل الاسم الظاهر لكل سلسلة فقط (المفتاح يأتي من رقم الفاتورة)" icon="fa-tags" />

    <div class="card overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr><th class="ps-3">المفتاح</th><th>الشارة</th><th class="pe-3">الاسم</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($series as $item)
                            <tr>
                                <td class="ps-3 font-monospace fw-bold">{{ $item->key }}</td>
                                <td><x-series-badge :series="$item->key" /></td>
                                <td class="pe-3">
                                    <form action="{{ route('admin.series.update', $item) }}" method="POST" class="d-flex gap-2 m-0">
                                        @csrf
                                        @method('PUT')
                                        <input type="text" name="label" value="{{ $item->label }}" class="form-control form-control-sm" required maxlength="255" aria-label="الاسم">
                                        <button type="submit" class="btn btn-sm btn-primary fw-bold">حفظ</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty-state icon="fa-tags" title="لا توجد سلاسل" /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
