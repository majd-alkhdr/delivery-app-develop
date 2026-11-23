@extends('layouts.panel')

@section('title', 'فواتير بلا كشف')

@section('content')
    <x-page-header :title="$showExcluded ? 'الفواتير المستبعدة' : 'فواتير بلا كشف'"
        subtitle="فواتير اليوم بلا أمر تسليم، أو أُخرجت من كشف، أو من كشف أُغلق كمسودة — عبر كل الدفعات" icon="fa-inbox">
        @if ($showExcluded)
            <a href="{{ route('invoices.unassigned') }}" class="btn btn-light border fw-bold">بلا كشف</a>
        @else
            <a href="{{ route('invoices.unassigned', ['excluded' => 1]) }}" class="btn btn-light border fw-bold">المستبعدة</a>
        @endif
    </x-page-header>

    <div class="card overflow-hidden">
        {{-- المرحلة 7F: استبعاد جماعي --}}
        @unless ($showExcluded)
            @include('invoices.partials.bulk-bar', ['scope' => 'unassigned', 'filters' => [], 'total' => $invoices->total()])
        @endunless
        <div class="table-responsive">
            <table class="table align-middle mb-0 small">
                <thead class="table-light">
                    <tr>@unless ($showExcluded)<th class="ps-2"><span class="visually-hidden">تحديد</span></th>@endunless<th class="ps-3">الزبون</th><th>المنطقة</th><th>الرقم</th><th>التاريخ</th><th>القيمة</th><th class="pe-3"></th></tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        <tr>
                            @unless ($showExcluded)<td class="ps-2"><input type="checkbox" class="form-check-input" name="ids[]" value="{{ $invoice->id }}" form="bulk-unassigned" aria-label="تحديد {{ $invoice->invoice_number }}"></td>@endunless
                            <td class="ps-3 fw-bold">{{ $invoice->customer_name }}</td>
                            <td>{{ $invoice->area ?? 'بلا منطقة' }}</td>
                            <td class="font-monospace text-nowrap">{{ $invoice->invoice_number }}</td>
                            <td class="font-monospace text-nowrap">{{ $invoice->invoice_date?->format('Y-m-d') }}</td>
                            <td class="font-monospace fw-bold">{{ number_format($invoice->invoice_amount, 2) }}</td>
                            <td class="pe-3" style="min-width: 260px;">
                                @if ($showExcluded)
                                    <div class="d-flex gap-2 align-items-center justify-content-end">
                                        <span class="text-muted">السبب: {{ $invoice->exclusion_reason }}</span>
                                        <form action="{{ route('sheet-manager.invoices.restore', $invoice) }}" method="POST" class="m-0">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-primary fw-bold">إعادة إلى بلا كشف</button>
                                        </form>
                                    </div>
                                @else
                                    @include('sheet-manager.partials.unassigned-actions', ['invoice' => $invoice])
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><x-empty-state icon="fa-inbox" :title="$showExcluded ? 'لا توجد فواتير مستبعدة' : 'لا توجد فواتير بلا كشف'" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($invoices->hasPages())
            <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $invoices->links() }}</div>
        @endif
    </div>
@endsection
