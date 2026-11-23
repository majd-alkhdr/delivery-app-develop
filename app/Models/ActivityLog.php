<?php

namespace App\Models;

use App\Services\ActivityLogVisibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class ActivityLog extends Model
{
    // الجدول يحتوي created_at فقط
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'old_values',
        'new_values',
        'meta',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * أسماء الأحداث المعروفة بالعربية (للعرض والفلترة).
     */
    public const ACTIONS = [
        'auth.login' => 'تسجيل دخول',
        'auth.logout' => 'تسجيل خروج',
        'user.created' => 'إنشاء حساب',
        'user.updated' => 'تعديل حساب',
        'user.deleted' => 'حذف حساب',
        'sheet.uploaded' => 'رفع كشف',
        'import.failed' => 'تعذّر إكمال رفع',
        'sheet.sent_without_cost_center' => 'إرسال دفعة بلا عمود مركز الكلفة',
        'sheet.completed' => 'إغلاق كشف',
        'sheet.deleted' => 'حذف كشف (قديم)',
        'sheet.assigned' => 'إسناد كشف',
        'sheet.reassigned' => 'إعادة إسناد كشف',
        'sheet.drafted' => 'إغلاق كمسودة',
        'sheet.reopened' => 'إعادة تفعيل مسودة',
        'sheet.created_from_batch' => 'إنشاء كشف من دفعة رفع',
        // المرحلة 7H
        'sheet.supplement_created' => 'إنشاء ملحق لأمر كشفه مكتمل',
        'sheet.notice_acked' => 'اطلاع الموزع على تعديل',
        'payment.recorded_before_ack' => 'دفعة سُجّلت قبل اطلاع الموزع على تعديل',
        'visit.recorded_before_ack' => 'زيارة سُجّلت قبل اطلاع الموزع على تعديل',
        'settings.ack_types_changed' => 'تعديل أنواع الإشعارات الإلزامية',
        'sheet.sent' => 'إرسال كشف للمشرف',
        'sheet.recalled' => 'سحب كشف من المشرف',
        'sheet.exported' => 'تصدير كشف إلى Excel',
        'batch.conflicts_acknowledged' => 'إقرار بتعارضات الزبائن',
        'invoice.placed' => 'وضع فاتورة في كشف',
        'invoice.moved' => 'نقل فاتورة',
        'invoice.removed' => 'إخراج فاتورة من كشف',
        'invoice.excluded' => 'استبعاد فاتورة',
        'invoice.restored' => 'إعادة فاتورة مستبعدة',
        'invoice.cancelled' => 'إلغاء طلبية مؤجلة',
        'invoice.kept' => 'إبقاء طلبية مؤجلة',
        'invoice.area_changed' => 'تغيير منطقة فاتورة',
        'invoice.change_applied' => 'تطبيق تحديث فاتورة',
        'invoice.change_rejected' => 'رفض تحديث فاتورة',
        'payment.recorded' => 'تسجيل زيارة/دفعة (قديم)',
        'payment.external_added' => 'دفعة خارجية (قديم)',
        'payment.created' => 'تسجيل دفعة',
        'payment.voided' => 'إلغاء دفعة',
        'payment.receipt_viewed' => 'عرض بيانات إيصال',
        'visit.recorded' => 'تسجيل زيارة',
        'pharmacy.unlocked' => 'فك قفل صيدلية',
        'customer.location_pinned' => 'تثبيت موقع صيدلية',
        'customer.location_pin_skipped' => 'تجاوز تثبيت موقع صيدلية',
        'visit.voided' => 'إلغاء زيارة (خروج الزبون من الكشف)',
        'settings.location_changed' => 'تعديل إعدادات الموقع',
        'settings.printing_changed' => 'تعديل إعدادات الطباعة',
        'print.success' => 'طباعة ناجحة',
        'print.failed' => 'فشل طباعة',
        'settings.allocation_group_changed' => 'تعديل مجموعة تقسيم سلسلة',
        'customers.balances_imported' => 'استيراد أرصدة',
        'customer.aliased' => 'ربط زبون باسم بديل',
        'customer.created_from_review' => 'اعتماد زبون جديد',
        'customer.hidden' => 'إخفاء زبون',
        'customer.unhidden' => 'إظهار زبون',
        'finance.exported' => 'تصدير للمالية',
        'finance.export_downloaded' => 'تنزيل ملف تصدير مالية',
        'finance.rate_set' => 'ضبط سعر دفعة دولار',
        'finance.surplus_cost_center_set' => 'تعديل مركز كلفة الفائض',
        'finance.unpaid_exported' => 'تصدير تقرير غير المسدد',
        'cash.counted' => 'مطابقة الصندوق',
        'settings.finance_changed' => 'تعديل إعدادات المالية',
        'rep.cost_center_set' => 'ضبط مركز كلفة مندوب',
        'rep.image_uploaded' => 'رفع صورة إيصال مندوب',
        'rep.image_replaced' => 'استبدال صورة إيصال مندوب',
        'rep.payment_reviewed' => 'مراجعة دفعة مندوب',
        'finance.rep_exported' => 'تصدير دفعات المندوبين',
        'finance.rep_export_downloaded' => 'تنزيل ملف تصدير المندوبين',
        'finance.rep_export_override' => 'شمول دفعة مندوب بحاجة مراجعة',
        'finance.rep_receivables_exported' => 'تصدير تقرير ذمم المندوبين',
        'finance.rep_payments_list_exported' => 'تصدير قائمة دفعات المندوبين',
        'invoice.change_applied_after_print' => 'تطبيق تحديث على فاتورة مطبوعة سابقاً',
        // المرحلة 7A: قرارات «مراجعة الفروق»
        'import.item_applied' => 'تطبيق عنصر من دفعة رفع',
        'import.item_rejected' => 'تجاهل عنصر من دفعة رفع',
        // المرحلة 7B: الأرصدة
        'customers.balances_applied_after_hold' => 'تطبيق أرصدة محجوزة',
        'customers.balances_ignored' => 'تجاهل ملف أرصدة',
        'customers.exported_unreflected' => 'دفعات مصدَّرة لم تنعكس في الأرصدة',
        'customers.balance_change_reviewed' => 'مراجعة تغيّر رصيد',
        'payment.balance_mismatch' => 'فرق رصيد الإيصال بين الجهاز والخادم',
        // المرحلة 7C: التعديلات اللاحقة
        'invoice.adjustment_applied' => 'تطبيق تعديل لاحق على فاتورة',
        'invoice.adjustment_reverted' => 'تراجع عن تعديل لاحق',
        'invoice.adjustment_ignored' => 'تجاهل تعديل لاحق من الملف',
        'finance.allocation_clipped' => 'قصّ مبلغ موزَّع على فاتورة خُفّضت',
        // المرحلة 7F: التراجع عن دفعة والإجراءات الجماعية
        'import.rolled_back' => 'التراجع عن دفعة رفع',
        'import.columns_confirmed' => 'تأكيد رفع ملف ينقصه عمود حرج',
        // المرحلة 8B: خطة المندوب
        'rep_plan.template_added' => 'إضافة بند للخطة الأسبوعية لمندوب',
        'rep_plan.template_removed' => 'إزالة بند من الخطة الأسبوعية لمندوب',
        'rep_plan.template_deleted' => 'حذف بند خطة معطَّل',
        'rep_plan.template_moved' => 'نقل بند خطة لمشرف آخر',
        'rep_plan.template_disabled' => 'تعطيل بند خطة (نطاق بلا مشرف)',
        'rep_plan.override_added' => 'تعديل خطة مندوب بتاريخ محدد',
        'rep_plan.override_removed' => 'إلغاء تعديل خطة بتاريخ محدد',
        'rep_plan.override_moved' => 'نقل تعديل خطة لمشرف آخر',
        'rep_plan.override_disabled' => 'تعطيل تعديل خطة (نطاق بلا مشرف)',
        'rep_plan.visit_recorded' => 'تسجيل زيارة مندوب',
        'rep_plan.visit_corrected' => 'تصحيح زيارة مندوب',
        'settings.rep_plan_changed' => 'تعديل إعدادات خطة المندوب',
        'invoice.uncancelled' => 'إعادة طلبية ملغاة إلى المؤجلة',
        'invoices.bulk_excluded' => 'استبعاد جماعي لفواتير',
        'invoices.bulk_cancelled' => 'إلغاء جماعي لطلبيات مؤجلة',
        'invoices.bulk_restored' => 'إعادة إدراج جماعية لفواتير',
        // المرحلة 8A: إسناد المندوبين لمشرفي المندوبين
        'rep_assignment.created' => 'إسناد مندوب لمشرف',
        'rep_assignment.ended' => 'إنهاء إسناد مندوب',
        'rep_assignment.moved' => 'نقل نطاق مندوب لمشرف آخر',
        'rep_scoping.enabled' => 'تفعيل حصر رؤية مشرفي المندوبين',
        'rep_scoping.disabled' => 'إيقاف حصر رؤية مشرفي المندوبين',
        // المرحلة 8D
        'settings.rep_full_statement_changed' => 'تعديل مفتاح كشف الصيدلية الكامل للمندوب',
    ];

    /**
     * المرحلة 8C: ما يراه المستخدم من السجل حسب منفّذ الإجراء (المصدر الوحيد: ActivityLogVisibility).
     * حلّت محل قاعدة بادئات الأحداث السابقة (prefixesFor).
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return ActivityLogVisibility::apply($query, $viewer);
    }

    /**
     * أي مفتاح يحتوي أحد هذه الكلمات لا يُخزَّن أبداً.
     */
    private const SENSITIVE_KEYS = ['password', 'token', 'secret', 'remember'];

    /**
     * تسجيل حدث في سجل النشاط. لا يرمي استثناء أبداً حتى لا يعطّل العملية الأصلية.
     */
    public static function record(string $action, ?Model $subject = null, array $old = [], array $new = [], array $meta = []): ?self
    {
        try {
            $request = request();

            // في أحداث الدخول والخروج قد لا يكون المستخدم مرتبطاً بالجلسة لحظة التسجيل
            $userId = Auth::id();
            if ($userId === null && $subject instanceof User && str_starts_with($action, 'auth.')) {
                $userId = $subject->getKey();
            }

            return static::create([
                'user_id' => $userId,
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'old_values' => static::clean($old) ?: null,
                'new_values' => static::clean($new) ?: null,
                'meta' => static::clean($meta) ?: null,
                'ip' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::warning('تعذر تسجيل النشاط: '.$e->getMessage(), ['action' => $action]);

            return null;
        }
    }

    /**
     * المرحلة 7E2: عدة أحداث دفعة واحدة بالصفات نفسها التي يكتبها record() (إدخال جماعي).
     *
     * @param  array<int, array{0: string, 1: ?Model, 2: array, 3: array, 4: array}>  $entries  [الحدث، الموضوع، قبل، بعد، meta]
     */
    public static function recordMany(array $entries): void
    {
        $request = request();
        $models = array_map(fn ($e) => new static([
            'user_id' => Auth::id(),
            'action' => $e[0],
            'subject_type' => $e[1]?->getMorphClass(),
            'subject_id' => $e[1]?->getKey(),
            'old_values' => static::clean($e[2]) ?: null,
            'new_values' => static::clean($e[3]) ?: null,
            'meta' => static::clean($e[4]) ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]), $entries);
        \App\Support\BulkWrite::insertModels($models);
    }

    /**
     * حذف أي قيمة حساسة (كلمات المرور والرموز) بشكل متداخل.
     */
    public static function clean(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            $lower = strtolower((string) $key);
            foreach (self::SENSITIVE_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    continue 2;
                }
            }
            $clean[$key] = is_array($value) ? static::clean($value) : $value;
        }

        return $clean;
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
