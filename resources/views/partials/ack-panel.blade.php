{{-- المرحلة 7H: لوحة «اطلاع الموزع» — لمسؤول الكشوف والمشرف والأدمن فقط (ضمن صلاحية رؤية الكشف) --}}
@if (auth()->user()->hasRole(\App\Enums\Role::Admin, \App\Enums\Role::SheetManager, \App\Enums\Role::Supervisor) && $sheet->isV2() && $sheet->status !== 'preparing')
    @php
        $ackPanel = app(\App\Services\NoticeAckService::class)->panel($sheet);
        $beforeAck = [
            'payments' => \App\Models\Payment::with(['customer:id,name', 'enteredBy:id,name'])->where('distribution_sheet_id', $sheet->id)->where('recorded_before_ack', true)->orderBy('id')->get(),
            'visits' => \App\Models\SheetVisit::with(['customer:id,name', 'user:id,name'])->where('distribution_sheet_id', $sheet->id)->where('recorded_before_ack', true)->orderBy('id')->get(),
        ];
    @endphp
    @if ($ackPanel['total'] > 0 || $beforeAck['payments']->isNotEmpty() || $beforeAck['visits']->isNotEmpty())
        <details class="card mb-3" id="ack-panel" @if ($ackPanel['unacked'] > 0 || $beforeAck['payments']->isNotEmpty()) open @endif>
            <summary class="card-header bg-white fw-bold py-2 small d-flex flex-wrap align-items-center gap-2">
                <i class="fas fa-user-check text-primary"></i> اطلاع الموزع
                <span class="badge {{ $ackPanel['unacked'] > 0 ? 'text-bg-danger' : 'text-bg-success' }}" id="ack-unacked-count">{{ $ackPanel['unacked'] }} لم يطّلع</span>
                <span class="text-muted fw-normal">من {{ $ackPanel['total'] }} إشعاراً إلزامياً</span>
            </summary>
            <div class="card-body p-0">
                @if ($beforeAck['payments']->isNotEmpty() || $beforeAck['visits']->isNotEmpty())
                    <div class="alert alert-warning m-2 small" id="recorded-before-ack">
                        <div class="fw-bold mb-1"><i class="fas fa-triangle-exclamation me-1"></i> سُجّل قبل اطلاع الموزع على تعديل (دون اتصال، قُبل ولم يضع شيء):</div>
                        <ul class="mb-0 ps-3">
                            @foreach ($beforeAck['payments'] as $p)
                                <li class="before-ack-row">دفعة {{ $p->receipt_number }} — {{ $p->customer?->name }} — <span dir="ltr">{{ number_format((float) $p->amount, $p->currency === 'USD' ? 2 : 0) }} {{ $p->currency === 'USD' ? '$' : 'ل.س' }}</span> — {{ $p->enteredBy?->name }}
                                    <span class="text-muted">(سبقها: {{ collect($p->ack_context['notices'] ?? [])->pluck('summary')->implode('؛ ') }})</span></li>
                            @endforeach
                            @foreach ($beforeAck['visits'] as $v)
                                <li class="before-ack-row">زيارة — {{ $v->customer?->name }} ({{ $v->status === 'visited' ? 'مزار' : 'غير مزار' }}) — {{ $v->user?->name }}
                                    <span class="text-muted">(سبقها: {{ collect($v->ack_context['notices'] ?? [])->pluck('summary')->implode('؛ ') }})</span></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($ackPanel['total'] > 0)
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr><th class="ps-3">الحالة</th><th>التعديل</th><th>الوقت</th><th class="pe-3">من اطّلع</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($ackPanel['rows'] as $row)
                                    <tr class="ack-row" data-acked="{{ $row['acked'] ? 1 : 0 }}" data-notice-id="{{ $row['notice']->id }}">
                                        <td class="ps-3 text-nowrap">
                                            @if ($row['acked'])
                                                <span class="badge text-bg-success">اطّلع</span>
                                            @else
                                                <span class="badge text-bg-danger">لم يطّلع</span>
                                            @endif
                                            @unless ($row['blocking'])<span class="badge bg-light text-secondary border" title="بلا صيدلية: لا يمنع التسجيل">عام</span>@endunless
                                        </td>
                                        <td><span class="text-muted">{{ \App\Services\NoticeAckService::typeLabel($row['notice']->type) }}:</span> {{ $row['notice']->summary }}</td>
                                        <td class="text-nowrap text-muted" dir="ltr">{{ $row['notice']->created_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</td>
                                        <td class="pe-3">
                                            @foreach ($row['acks'] as $ack)
                                                <span class="d-block">{{ $ack->user?->name }} <span class="text-muted" dir="ltr">{{ $ack->acked_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</span></span>
                                            @endforeach
                                            @if ($row['waiting'])
                                                <span class="d-block text-danger">لم يطّلع: {{ implode('، ', $row['waiting']) }}</span>
                                            @elseif ($row['acks']->isEmpty())
                                                <span class="d-block text-muted">لا موزع مسند لمنطقته</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($ackPanel['total'] > $ackPanel['limit'])
                        <div class="small text-muted p-2">تُعرض أول {{ $ackPanel['limit'] }} (غير المُطَّلَع عليه أولاً).</div>
                    @endif
                @endif
            </div>
        </details>
    @endif
@endif
