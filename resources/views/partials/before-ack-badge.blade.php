{{-- المرحلة 7H: سجل قادم من الطابور دون اتصال قبل اطلاع الموزع على تعديل (قُبل ولم يضع) --}}
@if ($record->recorded_before_ack ?? false)
    <span class="badge text-bg-warning recorded-before-ack" title="{{ 'سبقه: ' . collect($record->ack_context['notices'] ?? [])->pluck('summary')->implode('؛ ') }}">سُجّل قبل اطلاع الموزع على تعديل</span>
@endif
