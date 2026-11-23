{{-- المرحلة 7H: شارة «بانتظار اطلاع الموزع (N)» على الكشف في القوائم --}}
@if (($count ?? 0) > 0)
    <span class="badge text-bg-danger ack-pending-badge" title="إشعارات تعديل لم يطّلع عليها الموزع بعد">
        <i class="fas fa-bell me-1"></i>بانتظار اطلاع الموزع ({{ $count }})
    </span>
@endif
