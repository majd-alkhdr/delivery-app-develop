{{-- تعديلات الكشف بعد نشره (المرحلة 6B-D3).
     $mode = 'unseen': شريط غير المقروء (الموزع) مع زر «اطلعت»؛ $mode = 'recent': «آخر التعديلات» (المشرفان ومسؤول الكشوف). --}}
@php
    $notifier = app(\App\Services\SheetChangeNotifier::class);
    $mode = $mode ?? 'recent';
    $notices = $mode === 'unseen' ? $notifier->unseen($sheet, auth()->user()) : $notifier->recent($sheet, 20);
    $unseenCount = $mode === 'unseen' ? $notices->count() : $notifier->unseen($sheet, auth()->user())->count();
@endphp
@if ($notices->isNotEmpty())
    @if ($mode === 'unseen')
        <div class="alert alert-warning mb-3" id="sheet-notices">
            <div class="fw-bold mb-1"><i class="fas fa-bell me-1"></i> تعديلات جديدة على هذا الكشف ({{ $notices->count() }})</div>
            <ul class="small mb-2 ps-3">
                @foreach ($notices as $n)
                    <li class="sheet-notice">{{ $n->summary }} <span class="text-muted" dir="ltr">{{ $n->created_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</span></li>
                @endforeach
            </ul>
            <form action="{{ route('sheets.notices.seen', $sheet) }}" method="POST" class="m-0">
                @csrf
                <button class="btn btn-sm btn-warning fw-bold"><i class="fas fa-check"></i> اطلعت</button>
            </form>
        </div>
    @else
        <details class="card mb-3" id="sheet-recent-changes" @if ($unseenCount > 0) open @endif>
            <summary class="card-header bg-white fw-bold py-2 small">
                <i class="fas fa-clock-rotate-left text-primary me-1"></i> آخر التعديلات بعد النشر ({{ $notices->count() }})
                @if ($unseenCount > 0)<span class="badge text-bg-warning">تعديل جديد</span>@endif
            </summary>
            <ul class="list-group list-group-flush small">
                @foreach ($notices as $n)
                    <li class="list-group-item sheet-notice">{{ $n->summary }}
                        <span class="text-muted">— {{ $n->creator?->name }}</span>
                        <span class="text-muted" dir="ltr">{{ $n->created_at?->copy()->setTimezone('Asia/Damascus')->format('Y-m-d H:i') }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($unseenCount > 0)
                <form action="{{ route('sheets.notices.seen', $sheet) }}" method="POST" class="card-footer bg-white m-0">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary fw-bold"><i class="fas fa-check"></i> اطلعت</button>
                </form>
            @endif
        </details>
    @endif
@endif
