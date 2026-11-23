{{-- المرحلة 7B (H): دفعات مصدَّرة لم تنعكس في الأرصدة. لا زر ترحيل: الانعكاس تلقائي عند ملف أرصدة لاحق، أو «تمّت المراجعة». --}}
@php
    $unreflected = $unreflected ?? \App\Http\Controllers\BalanceChangesController::unreflectedPayments();
    $fmt = fn ($v) => number_format((float) $v, 2);
@endphp
@if ($unreflected['awaiting']->isNotEmpty() || $unreflected['mismatched']->isNotEmpty())
    <div class="card mb-4 {{ $unreflected['mismatched']->isNotEmpty() || $unreflected['awaiting']->where('overdue', true)->isNotEmpty() ? 'border-danger' : 'border-warning' }}" id="unreflected-payments">
        <div class="card-header bg-white py-3 fw-bold">
            <i class="fas fa-triangle-exclamation text-danger me-1"></i> دفعات مصدَّرة لم تنعكس
            ({{ $unreflected['awaiting']->count() }} بانتظار ملف أرصدة، {{ $unreflected['mismatched']->count() }} لم ينخفض الرصيد بما يقابلها)
        </div>
        <div class="card-body small">
            @if ($unreflected['mismatched']->isNotEmpty())
                <div class="alert alert-danger py-2">
                    <div class="fw-bold mb-1">لم ينخفض الرصيد بما يقابلها: تحقق أن القيود رُحّلت في المحاسبة</div>
                    <ul class="mb-0">
                        @foreach ($unreflected['mismatched'] as $s)
                            <li>{{ $s->customer?->name }}: المصدَّر <span class="font-monospace">{{ $fmt($s->exported_payments_sum) }}</span> — الفرق <span class="font-monospace">{{ $fmt($s->unexplained) }}</span> (ملف {{ $s->as_of?->format('Y-m-d') }})
                                @can('viewBalanceChanges') — <a href="{{ route('finance.balance-changes', ['q' => $s->customer?->name, 'unreviewed' => 1]) }}">مراجعة</a>@endcan
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($unreflected['awaiting']->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>الزبون</th><th>المبلغ</th><th>تاريخ التصدير</th><th>التصدير</th><th>العمر</th></tr></thead>
                        <tbody>
                            @foreach ($unreflected['awaiting'] as $row)
                                <tr class="{{ $row['overdue'] ? 'table-danger' : '' }}">
                                    <td>{{ $row['payment']->customer?->name }}</td>
                                    <td class="font-monospace">{{ $fmt($row['payment']->amount_syp) }}</td>
                                    <td class="font-monospace">{{ $row['payment']->exported_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d') }}</td>
                                    <td>#{{ $row['payment']->financeExport?->sequence ?? '—' }} <span class="text-muted">(إيصال {{ $row['payment']->receipt_number }})</span></td>
                                    <td>{{ $row['age_days'] }} يوم @if ($row['overdue'])<span class="badge text-bg-danger">تجاوز {{ $unreflected['limit_days'] }} أيام</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="text-muted mt-1">تُطرح هذه الدفعات من الرصيد المعروض حتى يصل ملف أرصدة تنعكس فيه.</div>
            @endif
        </div>
    </div>
@endif
