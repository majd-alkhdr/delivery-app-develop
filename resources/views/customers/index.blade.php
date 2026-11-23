@extends('layouts.panel')

@section('title', 'الزبائن')

@php $canEdit = auth()->user()->hasRole('admin', 'sheet_manager'); @endphp

@section('content')
    <x-page-header title="الزبائن" subtitle="الرصيد الخام من ملف الأرصدة، والمستحق بعد طرح الطلبيات المؤجلة" icon="fa-store">
        @if ($canEdit && $reviewCount > 0)
            <a href="{{ route('customers.review') }}" class="btn btn-warning fw-bold">
                <i class="fas fa-user-check"></i> مطابقة الزبائن ({{ $reviewCount }})
            </a>
        @endif
    </x-page-header>

    <div class="card mb-3">
        <div class="card-body p-3">
            <form method="GET" action="{{ route('customers.index') }}" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="q" class="form-label small fw-bold text-secondary mb-1">بحث بالاسم:</label>
                    <input type="text" name="q" id="q" value="{{ request('q') }}" class="form-control" placeholder="مثال: ارنبة">
                </div>
                <div class="col-6 col-md-3">
                    <label for="f_area" class="form-label small fw-bold text-secondary mb-1">المنطقة:</label>
                    <select name="area" id="f_area" class="form-select">
                        <option value="">الكل</option>
                        @foreach ($areas as $area)
                            <option value="{{ $area }}" @selected(request('area') === $area)>{{ $area }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label for="f_block" class="form-label small fw-bold text-secondary mb-1">الكتلة:</label>
                    <select name="block" id="f_block" class="form-select">
                        <option value="">الكل</option>
                        @foreach ($blocks as $block)
                            <option value="{{ $block }}" @selected(request('block') === $block)>{{ $block }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary fw-bold flex-grow-1"><i class="fas fa-filter"></i> تطبيق</button>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="show_hidden" value="1" id="show_hidden"
                            @checked(request()->boolean('show_hidden')) onchange="this.form.submit()">
                        <label class="form-check-label small" for="show_hidden">إظهار الزبائن المخفيين</label>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">الزبون</th>
                            <th>الكتلة / المنطقة</th>
                            <th>الرصيد الخام</th>
                            <th>المستحق</th>
                            <th>تاريخ الرصيد</th>
                            @if ($canEdit)
                                <th class="text-center pe-3">إجراء</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $customer)
                            <tr class="{{ $customer->is_hidden ? 'opacity-50' : '' }}">
                                <td class="ps-3">
                                    <span class="fw-bold">{{ $customer->name }}</span>
                                    @if ($customer->needs_review)
                                        <span class="badge bg-warning-subtle text-warning-emphasis ms-1">بحاجة لمطابقة</span>
                                    @endif
                                    @if ($customer->is_hidden)
                                        <span class="badge text-bg-secondary ms-1">مخفي</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="d-block text-muted">{{ $customer->block ?? '—' }}</span>
                                    {{ $customer->area ?? 'بلا منطقة' }}
                                </td>
                                <td class="font-monospace text-nowrap">{{ number_format($customer->balance, 2) }}</td>
                                <td class="font-monospace text-nowrap fw-bold {{ ($due[$customer->id] ?? 0) < 0 ? 'text-danger' : '' }}">
                                    {{ number_format($due[$customer->id] ?? 0, 2) }}
                                    @if ($cb = ($currentBalances[$customer->id] ?? null))
                                        <span class="badge rounded-pill text-bg-light border fw-normal" title="{{ $cb->breakdown() }}" tabindex="0" role="button" aria-label="{{ $cb->breakdown() }}">؟</span>
                                    @endif
                                </td>
                                <td class="font-monospace text-nowrap text-muted">{{ $customer->balance_as_of?->format('Y-m-d') ?? '—' }}</td>
                                @if ($canEdit)
                                    <td class="text-center pe-3">
                                        <form action="{{ route('customers.visibility', $customer) }}" method="POST" class="m-0">
                                            @csrf
                                            <button type="submit" class="btn btn-sm {{ $customer->is_hidden ? 'btn-outline-success' : 'btn-outline-secondary' }}">
                                                <i class="fas {{ $customer->is_hidden ? 'fa-eye' : 'fa-eye-slash' }}"></i>
                                                {{ $customer->is_hidden ? 'إظهار' : 'إخفاء' }}
                                            </button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6"><x-empty-state icon="fa-store" title="لا يوجد زبائن مطابقون" /></td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($customers->hasPages())
                <div class="p-3 bg-light d-flex justify-content-center border-top">{{ $customers->links() }}</div>
            @endif
        </div>
    </div>
@endsection
