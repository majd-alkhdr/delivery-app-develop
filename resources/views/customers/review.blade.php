@extends('layouts.panel')

@section('title', 'مطابقة الزبائن')

@section('content')
    <x-page-header title="مطابقة الزبائن"
        subtitle="زبائن ظهروا في الحركة اليومية ولم يُعثر عليهم في القائمة. اربطهم بزبون موجود أو اعتمدهم كزبائن جدد."
        icon="fa-user-check">
        <a href="{{ route('customers.index') }}" class="btn btn-light border fw-bold"><i class="fas fa-arrow-right"></i> الزبائن</a>
    </x-page-header>

    @if ($searchResults->isNotEmpty() || request()->filled('q'))
        <div class="card mb-3 border-primary">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-2">نتائج البحث عن "{{ request('q') }}"</h2>
                @php $forCustomer = $pending->firstWhere('id', $searchFor); @endphp
                @forelse ($searchResults as $result)
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 border-bottom py-2">
                        <span>{{ $result->name }} <span class="text-muted small">({{ $result->area ?? 'بلا منطقة' }})</span></span>
                        @if ($forCustomer)
                            <form action="{{ route('customers.link', $forCustomer) }}" method="POST" class="m-0">
                                @csrf
                                <input type="hidden" name="target_id" value="{{ $result->id }}">
                                <button type="submit" class="btn btn-sm btn-primary fw-bold">
                                    اربط "{{ $forCustomer->name }}" به
                                </button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-muted small mb-0">لا توجد نتائج.</p>
                @endforelse
            </div>
        </div>
    @endif

    @forelse ($pending as $customer)
        <div class="card mb-3">
            <div class="card-body p-3 p-md-4">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">{{ $customer->name }}</h2>
                    <span class="badge bg-light text-dark border">{{ $customer->invoices_count }} فاتورة</span>
                </div>

                <div class="row g-3">
                    <div class="col-lg-6">
                        <h3 class="small fw-bold text-secondary"><i class="fas fa-link me-1"></i> اربط بزبون موجود</h3>
                        <form action="{{ route('customers.link', $customer) }}" method="POST" class="d-flex gap-2 mb-2">
                            @csrf
                            <select name="target_id" class="form-select form-select-sm" required aria-label="الزبون الموجود">
                                <option value="">— اختر من الاقتراحات —</option>
                                @foreach ($suggestions[$customer->id] ?? [] as $s)
                                    <option value="{{ $s['id'] }}">{{ $s['name'] }} ({{ $s['score'] }}٪)</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-primary fw-bold text-nowrap">ربط</button>
                        </form>
                        <form action="{{ route('customers.review') }}" method="GET" class="d-flex gap-2">
                            <input type="hidden" name="for" value="{{ $customer->id }}">
                            <input type="text" name="q" class="form-control form-control-sm" placeholder="أو ابحث باسم آخر..."
                                value="{{ $searchFor === $customer->id ? request('q') : '' }}">
                            <button type="submit" class="btn btn-sm btn-outline-secondary text-nowrap"><i class="fas fa-search"></i> بحث</button>
                        </form>
                    </div>

                    <div class="col-lg-6">
                        <h3 class="small fw-bold text-secondary"><i class="fas fa-user-plus me-1"></i> اعتمده كزبون جديد</h3>
                        <form action="{{ route('customers.approve', $customer) }}" method="POST" class="row g-2">
                            @csrf
                            <div class="col-sm-6">
                                <input type="text" name="area" class="form-control form-control-sm" list="areasList" required
                                    placeholder="المنطقة (إلزامي)" aria-label="المنطقة">
                            </div>
                            <div class="col-sm-6">
                                <input type="text" name="block" class="form-control form-control-sm" list="blocksList"
                                    placeholder="الكتلة (اختياري)" aria-label="الكتلة">
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-success fw-bold">اعتماد كزبون جديد</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="card">
            <x-empty-state icon="fa-circle-check" title="لا يوجد زبائن بحاجة لمطابقة" />
        </div>
    @endforelse

    <datalist id="areasList">
        @foreach ($areas as $area)
            <option value="{{ $area }}">
        @endforeach
    </datalist>
    <datalist id="blocksList">
        @foreach ($blocks as $block)
            <option value="{{ $block }}">
        @endforeach
    </datalist>
@endsection
