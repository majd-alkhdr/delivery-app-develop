{{-- تفاصيل سجل واحد: القيم قبل/بعد وmeta كما في السجل (بلا حجب، المرحلة 8C) --}}
@php
    $fieldLabels = [
        'collected_amount' => 'المقبوض',
        'notes' => 'ملاحظات',
        'visited_at' => 'وقت الزيارة',
        'customer_name' => 'الصيدلية',
        'file_name' => 'الملف',
        'distributor_id' => 'رقم الموزع',
        'status' => 'الحالة',
        'name' => 'الاسم',
        'email' => 'البريد',
        'role' => 'الدور',
        'sheet_id' => 'رقم الكشف',
        'invoices_count' => 'عدد الفواتير',
        'collected_total' => 'إجمالي المقبوض',
        'excel_row' => 'سطر الإكسل',
        'credentials_changed' => 'تغيير كلمة المرور',
        'via_cookie' => 'دخول تلقائي',
        'source' => 'المصدر',
        'assignments' => 'الإسناد',
        'reason' => 'السبب',
        'batch_id' => 'رقم الدفعة',
        'lines' => 'الأسطر',
        'invoices' => 'الفواتير',
        'customers' => 'الزبائن',
        'new_customers' => 'زبائن جدد',
        'deferred' => 'مؤجلة',
        'total_all' => 'إجمالي الملف',
        'distribution_date' => 'تاريخ التوزيع',
        'as_of' => 'تاريخ الأرصدة',
        'rows' => 'الصفوف',
        'matched' => 'مطابَق',
        'created' => 'جديد',
        'zeroed' => 'صُفّر',
        'total_debit' => 'مجموع المدين',
        'alias' => 'الاسم البديل',
        'customer' => 'الزبون',
        'area' => 'المنطقة',
        'block' => 'الكتلة',
        'temporary_customer_id' => 'رقم الزبون المؤقت',
        'invoice_number' => 'رقم الفاتورة',
        'placement' => 'مكان الفاتورة',
        'area_key' => 'مفتاح المنطقة',
        'delivery_order_no' => 'أمر التسليم',
        'amount' => 'القيمة',
        'content_hash' => 'البصمة',
        'proposal_id' => 'رقم الاقتراح',
        'sent_at' => 'وقت الإرسال',
        'had_assignments' => 'كان مسنداً',
        'had_activity' => 'كان عليه نشاط',
        'released_invoices' => 'فواتير أُعيدت لبلا كشف',
        'file_names' => 'الملفات',
        'file_total' => 'إجمالي الملف',
        'sheets_created' => 'كشوف جديدة',
        'unassigned' => 'بلا كشف',
        'proposals' => 'تغييرات مقترحة',
        'conflicts_acknowledged_at' => 'وقت الإقرار',
        'by_role' => 'بدور',
        'scope' => 'النطاق',
        'with_lines' => 'مع الأصناف',
    ];
    $formatValue = function ($value) {
        if (is_bool($value)) {
            return $value ? 'نعم' : 'لا';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return ($value === null || $value === '') ? '—' : (string) $value;
    };
    $old = $log->old_values ?? [];
    $new = $log->new_values ?? [];
    $keys = array_unique(array_merge(array_keys($old), array_keys($new)));
@endphp
@foreach ($keys as $key)
    <div class="text-break-word">
        <span class="text-muted">{{ $fieldLabels[$key] ?? $key }}:</span>
        @if (array_key_exists($key, $old) && array_key_exists($key, $new))
            <span class="text-decoration-line-through text-danger">{{ $formatValue($old[$key]) }}</span>
            <i class="fas fa-arrow-left fa-xs text-muted mx-1"></i>
            <span class="text-success fw-semibold">{{ $formatValue($new[$key]) }}</span>
        @elseif (array_key_exists($key, $new))
            <span class="fw-semibold">{{ $formatValue($new[$key]) }}</span>
        @else
            <span>{{ $formatValue($old[$key]) }}</span>
        @endif
    </div>
@endforeach
@foreach ($log->meta ?? [] as $key => $value)
    <div class="text-muted fs-8 text-break-word">{{ $fieldLabels[$key] ?? $key }}: {{ $formatValue($value) }}</div>
@endforeach
