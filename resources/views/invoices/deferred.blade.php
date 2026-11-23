@extends('layouts.panel')

@section('title', 'الطلبيات المؤجلة')

@section('content')
    <x-page-header title="الطلبيات المؤجلة" subtitle="فواتير تاريخ تسليمها لاحق: خارج كل الكشوف، وتُطرح من الرصيد المستحق للزبون" icon="fa-clock" />

    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('invoices.deferred') }}" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label for="f_customer" class="form-label small fw-bold mb-1">الزبون:</label>
                    <input type="text" name="customer" id="f_customer" class="form-control" value="{{ request('customer') }}" placeholder="اسم الزبون">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f_from" class="form-label small fw-bold mb-1">تسليم من:</label>
                    <input type="date" name="from" id="f_from" class="form-control" value="{{ request('from') }}">
                </div>
                <div class="col-6 col-md-2">
                    <label for="f_to" class="form-label small fw-bold mb-1">إلى:</label>
                    <input type="date" name="to" id="f_to" class="form-control" value="{{ request('to') }}">
                </div>
                <div class="col-md-3 d-grid">
                    <button class="btn btn-primary fw-bold"><i class="fas fa-filter"></i> تطبيق</button>
                </div>
            </form>
        </div>
    </div>

    <p class="small text-muted">العدد: {{ $invoices->total() }} — الإجمالي: <strong class="font-monospace">{{ number_format($total, 2) }}</strong></p>

    <div class="card overflow-hidden">
        {{-- المرحلة 7F: إلغاء جماعي للطلبيات المؤجلة (لمسؤول الكشوف والمدير) --}}
        @if ($canEdit)
            @include('invoices.partials.bulk-bar', ['scope' => 'deferred', 'filters' => array_filter(request()->only(['customer', 'from', 'to'])), 'total' => $invoices->total()])
        @endif
        <div class="table-responsive">
            <table class="table align-middle mb-0 small">
                <thead class="table-light">
                    <tr>@if ($canEdit)<th class="ps-2"><span class="visually-hidden">تحديد</span></th>@endif<th class="ps-3">الزبون</th><th>الرقم</th><th>تاريخ التسليم</th><th>القيمة</th><th class="pe-3">الأصناف</th></tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            @if ($canEdit)<td class="ps-2"><input type="checkbox" class="form-check-input" name="ids[]" value="{{ $invoice->id }}" form="bulk-deferred" aria-label="تحديد {{ $invoice->invoice_number }}"></td>@endif
                            <td class="ps-3 fw-bold">{{ $invoice->customer_name }}</td>
                            <td class="font-monospace text-nowrap">{{ $invoice->invoice_number }}</td>
                            <td class="font-monospace text-nowrap">{{ $invoice->delivery_date?->format('Y-m-d') }}</td>
                            <td class="font-monospace fw-bold">{{ number_format($invoice->invoice_amount, 2) }}</td>
                            <td class="pe-3">
                                <details>
                                    <summary>{{ $invoice->lines->count() }} صنف</summary>
                                    <div class="mt-1">@include('partials.invoice-lines', ['invoice' => $invoice])</div>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><x-empty-state icon="fa-clock" title="لا توجد طلبيات مؤجلة" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($invoices->hasPages())
            <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $invoices->links() }}</div>
        @endif
    </div>
@endsection
