@extends('layouts.panel')

@section('title', 'إدارة المستخدمين')

@php
    $roleOptions = \App\Enums\Role::options();
    $roleHints = [
        'admin' => 'كل الشاشات وإدارة المستخدمين',
        'sheet_manager' => 'رفع الملفات وتشكيل الكشوف وإرسالها',
        'supervisor' => 'إسناد الكشوف ومتابعتها وإغلاقها',
        'distributor' => 'تطبيق ميداني',
        'accountant' => 'عرض وتحميل فقط',
        'rep' => 'ذممه وتحصيله بصورة إيصال وكشوفه',
        'rep_supervisor' => 'عرض فقط: الكشوف والقسم المالي',
    ];
@endphp

@section('content')
    <x-page-header title="إدارة المستخدمين" subtitle="إنشاء الحسابات وتعديل بياناتها وأدوارها" icon="fa-users-gear" />

    <div class="row g-3 g-lg-4">
        <div class="col-lg-4">
            <div class="card overflow-hidden">
                <div class="card-header text-bg-primary fw-bold py-3">
                    <i class="fas fa-user-plus me-1"></i> إنشاء حساب جديد
                </div>
                <div class="card-body p-4">
                    <form action="{{ route('supervisor.users.store') }}" method="POST">
                        @csrf

                        <div class="mb-3">
                            <label for="new_name" class="form-label fw-bold small">الاسم الكامل:</label>
                            <input type="text" name="name" id="new_name" class="form-control" value="{{ old('name') }}"
                                placeholder="مثال: محمد خالد" required>
                        </div>

                        <div class="mb-3">
                            <label for="new_email" class="form-label fw-bold small">البريد الإلكتروني (لتسجيل الدخول):</label>
                            <input type="email" name="email" id="new_email" class="form-control" dir="ltr"
                                value="{{ old('email') }}" placeholder="name@hibadrug.com" required>
                        </div>

                        <div class="mb-3">
                            <label for="new_role" class="form-label fw-bold small">الدور:</label>
                            <select name="role" id="new_role" class="form-select" required>
                                @foreach ($roleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected(old('role', 'distributor') === $value)>
                                        {{ $label }} ({{ $roleHints[$value] ?? '' }})</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3 rep-cost-center {{ old('role') === 'rep' ? '' : 'd-none' }}" data-for="new_role">
                            <label for="new_cost_center" class="form-label fw-bold small">مركز الكلفة (كود-اسم):</label>
                            <input type="text" name="cost_center_label" id="new_cost_center" class="form-control" maxlength="255"
                                value="{{ old('cost_center_label') }}" placeholder="مثل: 655231-كرم الخالدي">
                            <small class="text-muted">مطلوب للمندوب، كما يرد في ملف الحركة. الكود قبل الشرطة يجب ألا يتكرر بين المندوبين.</small>
                        </div>

                        <div class="mb-4">
                            <label for="new_password" class="form-label fw-bold small">كلمة المرور الابتدائية:</label>
                            <input type="password" name="password" id="new_password" class="form-control" dir="ltr"
                                minlength="8" placeholder="8 خانات على الأقل" required autocomplete="new-password">
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary fw-bold">
                                <i class="fas fa-save me-1"></i> إنشاء الحساب
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card overflow-hidden">
                <div class="card-header bg-white fw-bold py-3">
                    <i class="fas fa-list text-primary me-1"></i> الحسابات الحالية
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-3">الاسم</th>
                                    <th>البريد الإلكتروني</th>
                                    <th>الدور</th>
                                    <th>تاريخ الإنشاء</th>
                                    <th class="text-center pe-3">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($users as $user)
                                    @php
                                        $isLastAdmin = $user->isAdmin() && $adminsCount <= 1;
                                    @endphp
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold">{{ $user->name }}</div>
                                            <small class="text-muted">#{{ $user->id }}</small>
                                        </td>
                                        <td class="text-muted" dir="ltr">{{ $user->email }}</td>
                                        <td>
                                            <span class="badge rounded-pill px-3 py-2 {{ $user->role?->badgeClass() }}">
                                                <i class="fas {{ $user->role?->icon() }} me-1"></i>{{ $user->role?->label() }}
                                            </span>
                                            @if ($user->role === \App\Enums\Role::Rep)
                                                <div class="small mt-1 {{ $user->hasCostCenter() ? 'text-muted' : 'text-danger fw-bold' }}">{{ $user->cost_center_label ?: 'بلا مركز كلفة' }}</div>
                                            @endif
                                        </td>
                                        <td class="text-muted small text-nowrap">{{ $user->created_at?->format('Y-m-d') }}</td>
                                        <td class="text-center pe-3">
                                            @if ($user->id === Auth::id())
                                                <span class="text-muted small">حسابك الحالي</span>
                                            @else
                                                <div class="d-flex justify-content-center gap-1 flex-nowrap">
                                                    <button type="button" class="btn btn-sm btn-outline-primary edit-user-btn"
                                                        data-bs-toggle="modal" data-bs-target="#editUserModal"
                                                        data-id="{{ $user->id }}" data-name="{{ $user->name }}"
                                                        data-email="{{ $user->email }}" data-role="{{ $user->role?->value }}"
                                                        data-cost-center="{{ $user->cost_center_label }}"
                                                        data-last-admin="{{ $isLastAdmin ? 'true' : 'false' }}">
                                                        <i class="fas fa-edit"></i> تعديل
                                                    </button>

                                                    @unless ($isLastAdmin)
                                                        <form action="{{ route('supervisor.users.delete', $user->id) }}" method="POST"
                                                            onsubmit="return confirm('حذف هذا الحساب؟ سيتم إلغاء وصوله للنظام فوراً.');"
                                                            class="m-0">
                                                            @csrf
                                                            @method('DELETE')
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-user-times"></i> حذف
                                                            </button>
                                                        </form>
                                                    @endunless
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5"><x-empty-state icon="fa-users" title="لا توجد حسابات" /></td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('modals')
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-header text-bg-primary">
                    <h5 class="modal-title fw-bold fs-6" id="editUserModalLabel">
                        <i class="fas fa-user-edit me-1"></i> تعديل بيانات الحساب
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="إغلاق"></button>
                </div>
                <form id="editUserForm" method="POST" action="">
                    @csrf
                    @method('PUT')
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label fw-bold small">الاسم الكامل:</label>
                            <input type="text" name="name" id="edit_name" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_email" class="form-label fw-bold small">البريد الإلكتروني:</label>
                            <input type="email" name="email" id="edit_email" class="form-control" dir="ltr" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_role" class="form-label fw-bold small">الدور:</label>
                            <select name="role" id="edit_role" class="form-select" required>
                                @foreach ($roleOptions as $value => $label)
                                    <option value="{{ $value }}">{{ $label }} ({{ $roleHints[$value] ?? '' }})</option>
                                @endforeach
                            </select>
                            <small id="edit_role_locked" class="text-muted d-none">
                                <i class="fas fa-lock me-1"></i> هذا آخر مدير في النظام، لا يمكن تغيير دوره.
                            </small>
                        </div>

                        <div class="mb-3 rep-cost-center d-none" data-for="edit_role">
                            <label for="edit_cost_center" class="form-label fw-bold small">مركز الكلفة (كود-اسم):</label>
                            <input type="text" name="cost_center_label" id="edit_cost_center" class="form-control" maxlength="255" placeholder="مثل: 655231-كرم الخالدي">
                            <small class="text-muted">مطلوب للمندوب. الكود قبل الشرطة يجب ألا يتكرر بين المندوبين.</small>
                        </div>

                        <div class="mb-1">
                            <label for="edit_password" class="form-label fw-bold small">كلمة مرور جديدة (اختياري):</label>
                            <input type="password" name="password" id="edit_password" class="form-control" dir="ltr"
                                minlength="8" placeholder="اتركها فارغة لعدم التغيير" autocomplete="new-password">
                            <small class="text-muted d-block mt-1">إن كتبت كلمة مرور جديدة فيجب ألا تقل عن 8 خانات.</small>
                        </div>
                    </div>
                    <div class="modal-footer bg-light gap-2">
                        <button type="button" class="btn btn-light border px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-primary px-4 fw-bold">
                            <i class="fas fa-check me-1"></i> حفظ التغييرات
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endpush

@push('scripts')
    <script>
        // حقل مركز الكلفة: يظهر ويُطلب عند اختيار دور المندوب
        document.querySelectorAll('.rep-cost-center').forEach(box => {
            const select = document.getElementById(box.dataset.for);
            const input = box.querySelector('input');
            const toggle = () => { const rep = select.value === 'rep'; box.classList.toggle('d-none', !rep); input.required = rep; };
            select.addEventListener('change', toggle);
            toggle();
        });

        document.querySelectorAll('.edit-user-btn').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const roleSelect = document.getElementById('edit_role');
                const isLastAdmin = this.getAttribute('data-last-admin') === 'true';

                // تعبئة الحقول بالبيانات الحالية
                document.getElementById('edit_name').value = this.getAttribute('data-name');
                document.getElementById('edit_email').value = this.getAttribute('data-email');
                document.getElementById('edit_password').value = '';
                roleSelect.value = this.getAttribute('data-role');
                document.getElementById('edit_cost_center').value = this.getAttribute('data-cost-center') || '';
                roleSelect.dispatchEvent(new Event('change'));

                // آخر مدير: قفل حقل الدور (والخادم يمنع ذلك أيضاً)
                Array.from(roleSelect.options).forEach(opt => {
                    opt.disabled = isLastAdmin && opt.value !== 'admin';
                });
                document.getElementById('edit_role_locked').classList.toggle('d-none', !isLastAdmin);

                const baseUrl = "{{ route('supervisor.users.update', ':id') }}";
                document.getElementById('editUserForm').action = baseUrl.replace(':id', id);
            });
        });
    </script>
@endpush
