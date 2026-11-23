{{-- زيارة الزبون ودفعاته في الكشف (للإدارة): إلغاء دفعة قبل التصدير، وفك قفل الصيدلية --}}
@php
    $authUser = auth()->user();
    $canVoid = $authUser->hasRole('admin', 'supervisor', 'accountant');
    $canUnlock = $authUser->hasRole('admin', 'supervisor');
@endphp

@if ($visit)
    <span class="badge {{ $visit->status === 'visited' ? 'text-bg-success' : 'text-bg-secondary' }} p-2 fs-7 text-wrap text-start">
        @if ($visit->status === 'visited')
            <i class="fas fa-check me-1"></i> تمت الزيارة {{ $visit->visited_at?->format('Y-m-d g:i A') }}
        @else
            <i class="fas fa-xmark me-1"></i> غير مزارة: {{ $visit->reason }}
        @endif
        — {{ $visit->user?->name }}
    </span>
    @if ($visit->location_flag === 'far')
        <span class="badge text-bg-danger">بعيد عن الموقع{{ $visit->distance_m ? ' (' . number_format($visit->distance_m) . ' م)' : '' }}</span>
    @endif
    @include('partials.before-ack-badge', ['record' => $visit])
@elseif ($payments->isEmpty())
    <span class="badge text-bg-secondary p-2 fs-7"><i class="fas fa-hourglass-half me-1"></i> لم تتم الزيارة بعد</span>
@endif

@if ($lock)
    <div class="small mt-2 d-flex flex-wrap align-items-center gap-2">
        <span class="text-muted"><i class="fas fa-lock me-1"></i> مقفلة لـ {{ $lock->holder?->name }}</span>
        @if ($canUnlock)
            <form action="{{ route('locks.release', $lock) }}" method="POST" class="m-0">
                @csrf
                <button class="btn btn-sm btn-outline-warning py-0 fw-bold" onclick="return confirm('فك قفل هذه الصيدلية ليستطيع موزع آخر التسجيل عليها؟')">فك القفل</button>
            </form>
        @endif
    </div>
@endif

@if ($payments->isNotEmpty())
    <ul class="list-unstyled small mt-2 mb-0">
        @foreach ($payments as $payment)
            <li class="d-flex flex-wrap align-items-center gap-2 border-bottom py-1 {{ $payment->isVoided() ? 'text-decoration-line-through text-muted' : '' }}">
                <span class="font-monospace" dir="ltr">{{ $payment->receipt_number }}</span>
                <strong dir="ltr">{{ number_format($payment->amount, $payment->currency === 'USD' ? 2 : 0) }} {{ $payment->currency === 'USD' ? '$' : 'ل.س' }}</strong>
                @if ($payment->currency === 'USD')
                    <span class="text-muted">{{ $payment->exchange_rate ? '= ' . number_format($payment->amount_syp, 0) . ' ل.س' : 'بلا سعر (تقديري)' }}</span>
                @endif
                @if ($payment->source === 'external')<span class="badge bg-warning-subtle text-warning-emphasis">خارجية</span>@endif
                @if ($payment->outside_sheet)<span class="badge text-bg-danger outside-sheet">صيدلية خارج الكشف</span>@endif
                @include('partials.before-ack-badge', ['record' => $payment])
                @if ($payment->location_flag === 'far')<span class="badge bg-danger-subtle text-danger-emphasis">بعيد</span>@endif
                <span class="text-muted">{{ $payment->enteredBy?->name }} — {{ $payment->collected_at?->format('g:i A') }}</span>
                @if ($payment->note)<span class="text-muted">({{ $payment->note }})</span>@endif
                @if ($payment->isVoided())
                    <span class="badge text-bg-dark">ملغاة: {{ $payment->void_reason }}</span>
                @elseif ($canVoid && ! $payment->isExported())
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-auto" data-bs-toggle="collapse" data-bs-target="#void-{{ $payment->id }}">إلغاء</button>
                    <form action="{{ route('payments.void', $payment) }}" method="POST" class="collapse w-100 mt-1" id="void-{{ $payment->id }}">
                        @csrf
                        <div class="d-flex gap-1">
                            <input type="text" name="reason" class="form-control form-control-sm" required minlength="3" placeholder="سبب الإلغاء (إلزامي)">
                            <button class="btn btn-sm btn-danger fw-bold text-nowrap">تأكيد الإلغاء</button>
                        </div>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>
@endif
