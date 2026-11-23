{{-- المرحلة 7G: «هذا الملف نفسه رُفع سابقاً» (معلومات، بلا منع) --}}
<div class="alert alert-info d-flex gap-2 align-items-start mt-2" id="duplicate-upload">
    <i class="fas fa-clone mt-1"></i>
    <div>
        <span class="fw-bold" id="duplicate-upload-text">{{ $duplicate['text'] }}</span>
        @if ($duplicate['reclassify'])
            <span class="d-block mt-1" id="duplicate-reclassify"><i class="fas fa-shuffle me-1"></i>{{ $duplicate['reclassify'] }}</span>
        @endif
        <a href="{{ route('sheet-manager.batches.show', $duplicate['batch']) }}" class="d-inline-block small fw-bold mt-1">
            فتح الدفعة السابقة @if ($duplicate['rolled_back'])<span class="badge text-bg-secondary">متراجَع عنها</span>@endif
        </a>
    </div>
</div>
