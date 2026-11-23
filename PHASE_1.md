# المرحلة 1: الأساس

> تعليمات لـ Claude Code: اقرأ هذا الملف كاملاً قبل أي تعديل، ثم نفّذه بالترتيب كما هو مكتوب دون تجاوز أي بند. في النهاية قدّم التسليم المطلوب في آخر الملف.

---

أنت تعمل على مشروع Laravel (الإصدار حسب composer.json، 11 أو أحدث) اسمه "تطبيق الموزعين" لمستودع نيو هبة: نظام ويب بواجهات Blade وBootstrap 5 RTL لإدارة التوزيع الميداني وتحصيل الدفعات من الصيدليات. الأدوار الحالية: supervisor وdistributor وaccountant (العمود users.role نصي).

هذه "المرحلة 1: الأساس" من خطة أكبر. مهمتها: إصلاح الأخطاء والثغرات، وتنظيم الصلاحيات، وتوحيد التصميم، وإضافة سجل نشاط. لا تغيّر بنية الفواتير والدفعات، ولا صيغة ملف الإكسل أو منطق الاستيراد والتحميل، ولا تضف طباعة أو عملات. هذه مراحل لاحقة.

## قواعد العمل
1. اقرأ الكود أولاً (routes، الكونترولرز، الموديلات، الـ migrations، كل ملفات resources/views) ثم اعرض خطة قصيرة قبل التعديل.
2. نفّذ المجموعات A ثم B ثم C ثم D ثم E بالترتيب، وcommit منفصل لكل مجموعة إن كان المشروع على git.
3. لا تعدّل migrations القديمة. أضف migrations جديدة فقط ولا تحذف بيانات.
4. لا تشغّل migrate:fresh على قاعدة التطوير. للاختبار استخدم قاعدة SQLite منفصلة أو in-memory.
5. حافظ على سلوك الواجهة الحالي. لا تكسر منطق الأوفلاين في distributor/show (طابور localStorage والمزامنة)، ولا الـ ids التي تعتمد عليها السكربتات.
6. نصوص الواجهة بالعربية الواضحة المبسطة.

## A) أخطاء وثغرات
1. احذف المسار /debug-sheet-download/{id} نهائياً.
2. عطّل التسجيل العام: احذف مسارات register (GET وPOST) من routes/auth.php وأي رابط لها. الحسابات يُنشئها الأدمن فقط.
3. أضف 'role' إلى $fillable في User، وأنشئ Enum اسمه Role بالقيم: admin, supervisor, distributor, accountant, rep (مع دالة label() بالعربية). اجعل users.role يُحوَّل إليه (cast) وحدّث كل المقارنات النصية المتفرقة في الكود والواجهات.
4. تحقق من ملكية الكشف في DistributorController (showSheet وupdateInvoice وaddExternalPayment): الموزع يصل فقط إلى كشوفه، وإلا يرجع 403. نفّذ ذلك عبر DistributionSheetPolicy (view/update) ليسهل توسيعه لاحقاً.
5. في SupervisorController::upload استبدل supervisor_id الثابت (1) بـ Auth::id().
6. في DistributorController::updateInvoice استبدل where('id','!=',$id) بـ $firstInvoice->id، حتى لا يُصفَّر السطر الأساسي بعد تحديثه.
7. اضبط حالة الكشف على in_progress عند أول دفعة أو زيارة تُسجَّل على كشف حالته pending (في updateInvoice وaddExternalPayment).
8. رتّب كشوفات الموزع في DistributorController::index بـ latest().
9. اجعل الحد الأدنى لكلمة المرور 8 خانات في إنشاء الحساب وتعديله.
10. اعرض في شاشة الرفع أخطاء الـ validation ورسائل error (ستُحل عبر المكوّن x-flash في C).
11. عطّل جدولة excel:clean في routes/console.php (علّقها مع ملاحظة)، لأن حذف الملفات بعد 7 أيام يكسر تحميل الكشوفات القديمة، وستُحل جذرياً في مرحلة لاحقة.
12. تأكد أن database/database.sqlite وملفات .env ضمن .gitignore وغير متتبَّعة. إن كانت متتبَّعة فأزلها من التتبع (git rm --cached) دون حذفها محلياً.

## B) الأدوار والصلاحيات
- اجعل middleware الدور (CheckRole) يقبل أكثر من دور، مثل role:admin,supervisor.
- admin: يرى كل شيء، أي شاشات المشرف وإدارة المستخدمين وسجل النشاط وشاشات المحاسب.
- supervisor: شاشات الكشوفات (رفع، تفاصيل، تحميل، إغلاق، وحذف كما هو حالياً) بدون إدارة المستخدمين ولا رابطها. يرى قائمة الموزعين في شاشة الرفع فقط.
- distributor وaccountant: كما هما.
- rep: قيمة محجوزة بلا شاشات بعد. عند دخوله اعرض صفحة بسيطة "لا توجد واجهة متاحة لهذا الحساب بعد" بدل 403.
- مسارات إدارة المستخدمين لـ role:admin فقط.
- أعد التوجيه بعد الدخول وفي / و/dashboard حسب الدور (admin إلى supervisor.index).
- أنشئ أمر artisan اسمه user:make-admin {email} لترقية حساب قائم. لا تغيّر أي حساب تلقائياً.
- امنع حذف آخر أدمن أو تغيير دوره، مع إبقاء حماية المستخدم من حذف نفسه أو تغيير دوره.

## C) توحيد التصميم
- أنشئ layout واحداً layouts/panel.blade.php (RTL، Bootstrap 5.3 RTL، Font Awesome) واستخدمه في كل الشاشات: supervisor (index وsheet-show وusers)، distributor (index وshow)، accountant (index).
- شريط علوي موحّد فيه: الشعار، اسم التطبيق (من config('app.name')، وغيّره إلى اسم مناسب بالعربية)، اسم المستخدم ودوره، زر الخروج، وروابط حسب الدور.
- ضع Bootstrap RTL وFont Awesome وخطاً عربياً (Tajawal أو Cairo) محلياً داخل public/vendor بدل CDN. الموزع قد يكون ضعيف الاتصال، ولا نفترض وجود Node على السيرفر.
- أنشئ مكوّنات Blade: x-flash (success وerror وstatus وأخطاء validation)، x-stat-card، x-status-badge (pending وin_progress وcompleted)، x-page-header، x-empty-state.
- ضع ألوان التصميم كمتغيّرات CSS في ملف واحد (public/css/app-panel.css).
- أزل الكلاسات غير الموجودة في Bootstrap (مثل py-2.5 وpx-3.5 وmb-1.5 وw-full) وقاعدة bg-indigo المكتوبة بلا نقطة، واستبدلها بما يعمل فعلياً.
- أعد كتابة صفحة الدخول بنفس نظام Bootstrap وأزل Tailwind CDN. احذف ملفات Breeze/Tailwind غير المستخدمة (layouts/app وnavigation والمكوّنات غير المستخدمة) بعد التأكد أن لا شيء يشير إليها، وابقِ ما تحتاجه صفحات الحساب وكلمة المرور.
- الموبايل أولاً لشاشات الموزع: أهداف لمس لا تقل عن 44px، أزرار رئيسية واضحة، ولا تمرير أفقي للصفحة.
- استبدل عبارة "لاااااا تنسى ختم الايصال" بتذكير موحد رسمي: "تذكير: اختم الإيصال" داخل شارة. واحذف الشعار البديل "Sadana" واستخدم أيقونة محايدة عند غياب الصورة.

## D) سجل النشاط
- migration جديد لجدول activity_logs: id, user_id (nullable), action (string مفهرس)، subject_type وsubject_id (nullable)، old_values وnew_values وmeta (json, nullable)، ip, user_agent, created_at. مع Model ودالة مساعدة ActivityLog::record($action, $subject = null, $old = [], $new = [], $meta = []).
- سمِّ الأحداث بنقطة: auth.login وauth.logout وuser.created وuser.updated وuser.deleted وsheet.uploaded وsheet.completed وsheet.deleted وpayment.recorded (قيم قبل وبعد) وpayment.external_added.
- لا تُخزِّن أي كلمة مرور أو رمز سري في القيم.
- أنشئ شاشة /activity لـ role:admin,supervisor: جدول بترقيم صفحات وفلاتر (مستخدم، نوع الحدث، تاريخ من وإلى). الأدمن يرى كل الأحداث، والمشرف يرى فقط ما يبدأ بـ sheet. أو payment.

## E) تحسين صغير للموزع
- في حقلي المبلغ (الزيارة والدفعة الخارجية): أضف inputmode="numeric" وحوّل الأرقام العربية-الهندية (٠-٩) إلى إنجليزية تلقائياً أثناء الكتابة، بدل رسالة الخطأ الحالية.

## التسليم
1. ملخص بالعربية: ما تم لكل مجموعة، الـ migrations الجديدة، الأوامر المطلوبة على السيرفر (php artisan migrate وuser:make-admin)، ما لم يُنفَّذ ولماذا، وأي افتراضات اتخذتها.
2. اختبارات Feature بسيطة إن أمكن: وصول كل دور إلى المسارات المسموحة والممنوعة، ومنع الموزع من فتح كشف غيره.
3. قائمة اختبار يدوي قصيرة.
