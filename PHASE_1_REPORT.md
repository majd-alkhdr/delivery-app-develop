# تقرير تسليم المرحلة 1: الأساس

**المشروع:** تطبيق الموزعين — مستودع نيو هبة
**الإطار:** Laravel 12.59 / PHP 8.2
**تاريخ التسليم:** 2026-10-14
**النتيجة:** نُفّذت المجموعات A وB وC وD وE كلها، ونجحت كل الاختبارات (52 اختباراً، 176 تحققاً).

---

## ملاحظات قبل البدء

- **لا توجد commits:** المشروع ليس مستودع git، لذلك لم يُنشأ commit منفصل لكل مجموعة كما يطلب البند 2 من قواعد العمل.
- **الملفات المحذوفة نُقلت ولم تُحذف:** وضع الأمان في الجلسة منع الحذف النهائي (لعدم وجود git للتراجع). لذلك نُقل كل ملف مُزال إلى المجلد `_phase1_removed/` في جذر المشروع مع الحفاظ على مساره الأصلي. المجلد مُضاف إلى `.gitignore`، ولا يحمّله Laravel (خارج `app/` و`resources/views/` و`tests/`). يمكن حذفه يدوياً بعد المراجعة.
- **قاعدة التطوير لم تُمسّ:** الاختبارات تعمل على SQLite في الذاكرة (`:memory:`) حسب `phpunit.xml`، ولم يُشغَّل `migrate:fresh` ولا أي migration على MySQL.

---

## A) الأخطاء والثغرات

| # | البند | ما تم |
|---|------|-------|
| 1 | مسار `/debug-sheet-download/{id}` | حُذف من `routes/web.php`. |
| 2 | التسجيل العام | حُذف مسارا `register` (GET وPOST) من `routes/auth.php`. نُقل `RegisteredUserController` وصفحة `auth/register` واختبار `RegistrationTest` إلى `_phase1_removed/`. لا توجد أي روابط للتسجيل في الواجهات. |
| 3 | `role` وEnum | أُضيف `role` إلى `$fillable` في `User`. أُنشئ `App\Enums\Role` بالقيم `admin, supervisor, distributor, accountant, rep` مع `label()` بالعربية، ودوال مساعدة: `icon()` و`badgeClass()` و`homeRoute()` و`options()`. صار `users.role` يُحوَّل إلى Enum (cast). استُبدلت كل المقارنات النصية في الكود والواجهات. أُضيفت `hasRole()` و`isAdmin()` إلى `User`. |
| 4 | ملكية الكشف | أُنشئ `App\Policies\DistributionSheetPolicy` بقاعدتين: `view` (للمدير والمشرف والمحاسب، والموزع لكشفه فقط) و`update` (للموزع صاحب الكشف فقط). يُطبَّق عبر `Gate::authorize` في `showSheet` و`updateInvoice` و`addExternalPayment`، ويرجع 403 برسالة عربية. |
| 5 | `supervisor_id` الثابت | في `SupervisorController::upload` صار `Auth::id()` بدل `1`. أُضيف أيضاً تحقق أن `distributor_id` لمستخدم موجود دوره موزع. |
| 6 | تصفير السطر الأساسي | في `updateInvoice` صار الاستثناء `where('id', '!=', $firstInvoice->id)` بدل `$id`. في السابق، عند وصول الطلب على سطر غير الأساسي، كان السطر الأساسي يُصفَّر مباشرة بعد تحديثه. |
| 7 | حالة الكشف | تتحول من `pending` إلى `in_progress` عند أول زيارة (`updateInvoice`) أو أول دفعة خارجية ناجحة (`addExternalPayment`)، عبر الدالة `markSheetInProgress()`. |
| 8 | ترتيب كشوفات الموزع | `DistributorController::index` يستخدم `latest()`. |
| 9 | كلمة المرور | الحد الأدنى 8 خانات في إنشاء الحساب وتعديله، مع رسائل عربية. |
| 10 | أخطاء شاشة الرفع | تُعرض عبر المكوّن `x-flash` الموجود في الـ layout الموحد: النجاح والخطأ وأخطاء التحقق. |
| 11 | جدولة `excel:clean` | عُلّقت في `routes/console.php` مع ملاحظة السبب. الأمر نفسه باقٍ ويمكن تشغيله يدوياً. |
| 12 | `.gitignore` | `.env` و`database/*.sqlite*` كانت مُستثناة أصلاً. أُضيف `.env.*` (مع `!.env.example`) و`/database/*.sqlite` و`/_phase1_removed`. لا يوجد git، لذا لا ملفات متتبَّعة لإزالتها بـ `git rm --cached`. |

**إصلاحات إضافية صغيرة أثناء التنفيذ:**
- رسائل فشل الدخول وتجاوز عدد المحاولات صارت بالعربية (`LoginRequest`).
- اسم الصيدلية يُمرَّر إلى JavaScript عبر `@js()`، وكان الاسم الذي يحتوي فاصلة علوية `'` يكسر زر تسجيل الزيارة.
- الملاحظات المعروضة بعد الحفظ المحلي تُهرَّب (escape) قبل إدراجها في الصفحة.

---

## B) الأدوار والصلاحيات

- **`CheckRole`:** يقبل أكثر من دور (`role:admin,supervisor`). إذا كان المستخدم مندوباً (`rep`) وطلب صفحة غير مسموحة، يُحوَّل إلى صفحة توضيحية بدل 403.
- **توزيع المسارات:**

| المسارات | الأدوار |
|----------|---------|
| شاشات الكشوفات `/supervisor/*` (رفع، تفاصيل، تحميل، إغلاق، حذف) | `admin, supervisor` |
| سجل النشاط `/activity` | `admin, supervisor` |
| إدارة المستخدمين `/supervisor/users*` | `admin` فقط |
| شاشات المحاسب `/accountant/*` | `admin, accountant` |
| شاشات الموزع `/distributor/*` | `distributor` |
| صفحة `/no-interface` | أي مستخدم مسجل (لغير المندوب تعيد التوجيه لشاشته) |

- **المشرف:** لا يرى رابط إدارة المستخدمين ولا يصل إليها (403). يرى قائمة الموزعين في شاشة الرفع فقط.
- **المندوب `rep`:** يرى صفحة "لا توجد واجهة متاحة لهذا الحساب بعد".
- **التوجيه حسب الدور:** بعد الدخول وفي `/` و`/dashboard` عبر `Role::homeRoute()`:
  - `admin` و`supervisor` → `supervisor.index`
  - `distributor` → `distributor.index`
  - `accountant` → `accountant.index`
  - `rep` → `no-interface`
- **الأمر `php artisan user:make-admin {email}`:** يرقّي حساباً موجوداً فقط، ويُسجَّل الحدث في سجل النشاط. لا يتغير أي حساب تلقائياً، ولا يوجد migration أو seeder يرقّي أحداً على الإنتاج.
- **حماية آخر مدير:** لا يمكن حذف آخر `admin` ولا تغيير دوره. هذا منفّذ في الخادم، وفي الواجهة يُخفى زر الحذف ويُقفل حقل الدور. بقيت حماية المستخدم من حذف نفسه أو تغيير دوره، وبقيت حماية الحسابات المرتبطة بكشوفات من الحذف.

---

## C) توحيد التصميم

- **`layouts/panel.blade.php`:** layout واحد (RTL، Bootstrap 5.3 RTL، Font Awesome) تستخدمه كل الشاشات: `supervisor/index` و`sheet-show` و`users`، و`distributor/index` و`show`، و`accountant/index`، إضافة إلى `profile/edit` و`activity/index` و`no-interface`.
- **الشريط العلوي الموحد:** فيه الشعار، واسم التطبيق من `config('app.name')` (صار **"نيو هبة للتوزيع"** في `config/app.php` و`.env` و`.env.example`)، واسم المستخدم وشارة دوره، وزر الخروج، وروابط حسب الدور. يُطوى في قائمة على الموبايل.
- **ملفات محلية بدل CDN** داخل `public/vendor/`:
  - `bootstrap/` (5.3.3: `bootstrap.rtl.min.css` و`bootstrap.bundle.min.js`)
  - `fontawesome/` (6.4.0: css وwebfonts)
  - `fonts/tajawal/` (أوزان 400/500/700/800، عربي ولاتيني)
- **مكوّنات Blade** في `resources/views/components/`:
  - `x-flash` (success وerror وstatus وأخطاء التحقق)
  - `x-stat-card` (يدعم `value-id` للعدادات التي تحدّثها السكربتات)
  - `x-status-badge` (pending وin_progress وcompleted)
  - `x-page-header`
  - `x-empty-state`
  - أُضيف أيضاً `partials/invoices-table` المشترك و`layouts/partials/logo`.
- **`public/css/app-panel.css`:** ملف واحد فيه تعريف الخط، ومتغيرات الألوان على `:root`، والقواعد المشتركة.
- **كلاسات غير صالحة أُزيلت:** مثل `py-2.5` و`px-3.5` و`mb-1.5` و`w-full` و`font-bold` و`opacity-30` و`border-dashed` و`btn-white`، وقاعدة `bg-indigo` المكتوبة بلا نقطة. `fs-7` و`min-w-0` صارتا معرّفتين فعلياً في ملف CSS.
- **صفحة الدخول وصفحات كلمة المرور:** الدخول، والنسيان، وإعادة التعيين، والتأكيد، والتحقق من البريد أُعيدت كتابتها بـ Bootstrap عبر `layouts/guest` جديد. أُزيل Tailwind CDN و`@vite`.
- **ملفات Breeze/Tailwind غير المستخدمة** نُقلت إلى `_phase1_removed/` بعد التأكد أن لا شيء يشير إليها: `layouts/app`، `layouts/navigation`، `layouts/guest` القديم، `dashboard`، `welcome`، 13 مكوّناً قديماً، `AppLayout` و`GuestLayout`، و`profile/partials/*`. صفحة الحساب وتغيير كلمة المرور أُعيد بناؤهما بـ Bootstrap.
- **الموبايل أولاً لشاشات الموزع** (كلاس `touch-ui`):
  - أزرار وحقول بارتفاع 44px على الأقل.
  - أزرار رئيسية واضحة بعرض كامل على الشاشات الصغيرة.
  - نوافذ ملء الشاشة على الموبايل.
  - `overflow-x: clip` لمنع التمرير الأفقي. الجداول تتمرر داخل حاوياتها فقط.
- **عبارة "لاااااا تنسى ختم الايصال":** استُبدلت بشارة رسمية "تذكير: اختم الإيصال" (`#stamp-reminder-{md5}`). تظهر عند الزيارة المسجلة أو المحفوظة محلياً أو بعد المزامنة.
- **الشعار البديل "Sadana":** حُذف (مع رابط placehold.co الخارجي). عند تعذر تحميل الشعار تظهر أيقونة محايدة `fa-truck-fast`.
- **منطق الأوفلاين محفوظ:** طابورا `offlinePaymentsQueue` و`externalOfflineQueue`، والمزامنة، وكل الـ ids التي تعتمد عليها السكربتات باقية كما هي (`card-customer-*` و`status-badge-*` و`btn-trigger-*` و`global-collected` و`stat-*-pharmacies`… إلخ). أُزيل فقط العنصران `modal_amount_error` و`ext_amount_error` لأن البند E يطلب إلغاء رسالة الخطأ، وحُدّثت السكربتات بما يتوافق مع ذلك.

---

## D) سجل النشاط

- **الجدول `activity_logs`:** `id`، `user_id` (nullable ومفهرس، بلا مفتاح أجنبي حتى يبقى السجل بعد حذف المستخدم)، `action` (مفهرس)، `subject_type` و`subject_id` (nullable، مع فهرس مركّب)، `old_values` و`new_values` و`meta` (json، nullable)، `ip`، `user_agent`، `created_at`.
- **الموديل `App\Models\ActivityLog`:**
  - الدالة `ActivityLog::record($action, $subject = null, $old = [], $new = [], $meta = [])`.
  - لا ترمي استثناء أبداً حتى لا تعطّل العملية الأصلية، وتكتب تحذيراً في السجل عند الفشل.
  - تحذف تلقائياً، وبشكل متداخل، أي مفتاح يحتوي `password` أو `token` أو `secret` أو `remember`.
- **الأحداث المسجلة:**

| الحدث | المصدر | القيم |
|-------|--------|-------|
| `auth.login` / `auth.logout` | مستمعا الحدثين `Login` و`Logout` في `AppServiceProvider` | `meta.via_cookie` للدخول التلقائي |
| `user.created` | `storeUser` | الاسم والبريد والدور، بلا كلمة مرور |
| `user.updated` | `update` وأمر `user:make-admin` | قبل وبعد، و`credentials_changed` (نعم/لا) فقط |
| `user.deleted` | `deleteUser` | صورة الحساب قبل الحذف |
| `sheet.uploaded` | `upload` | اسم الملف والموزع وعدد الفواتير |
| `sheet.completed` | `completeSheet` | الحالة قبل وبعد |
| `sheet.deleted` | `destroy` | اسم الملف والحالة وعدد الفواتير وإجمالي المقبوض |
| `payment.recorded` | `updateInvoice` | المقبوض والملاحظات ووقت الزيارة قبل وبعد |
| `payment.external_added` | `addExternalPayment` | الصيدلية والمبلغ والملاحظات وسطر الإكسل |

- **الشاشة `/activity`:** للأدوار `admin, supervisor`.
  - جدول بترقيم صفحات (25 في الصفحة) يعرض الوقت والمستخدم والحدث والتفاصيل (قبل ← بعد) وعنوان IP.
  - فلاتر: المستخدم، ونوع الحدث، والتاريخ من وإلى.
  - المدير يرى كل الأحداث، والمشرف يرى فقط ما يبدأ بـ `sheet.` أو `payment.`. هذا القيد في الاستعلام نفسه، فلا يُتجاوز بتغيير الفلتر في الرابط.

---

## E) تحسين حقول المبلغ للموزع

- حقلا المبلغ (`modal_collected_amount` و`ext_collected_amount`) عليهما `inputmode="numeric"` لإظهار لوحة الأرقام في الجوال.
- الأرقام العربية-الهندية (٠-٩) والفارسية (۰-۹) تتحول إلى أرقام إنجليزية تلقائياً أثناء الكتابة عبر `normalizeDigits()`، مع الإبقاء على تنسيق الفواصل وتنبيه فارق الـ 50 ل.س.
- أُلغيت رسالة الخطأ "يجب إدخال أرقام إنجليزية فقط".

---

## الـ migrations الجديدة

| الملف | الوصف |
|-------|-------|
| `database/migrations/2026_10_14_000001_create_activity_logs_table.php` | إنشاء جدول `activity_logs` |

لم يُعدَّل أي migration قديم، ولا يوجد أي حذف للبيانات.

---

## الأوامر المطلوبة على السيرفر

```bash
# 1. تحقق قبل النشر: يجب أن تكون القيم من الخمسة المعروفة فقط
#    (admin, supervisor, distributor, accountant, rep)
#    SELECT DISTINCT role FROM users;

# 2. تحديث الاعتماديات وملف التحميل التلقائي
composer install --no-dev --optimize-autoloader

# 3. إنشاء جدول سجل النشاط
php artisan migrate

# 4. ترقية الحساب الذي سيدير النظام (حساب موجود مسبقاً)
php artisan user:make-admin admin@example.com

# 5. مسح الكاش
php artisan optimize:clear
```

وفي ملف `.env` على السيرفر:

```
APP_NAME="نيو هبة للتوزيع"
```

> **مهم:** قبل تشغيل `user:make-admin` لا يوجد أي مدير في النظام. المشرفون الحاليون يحتفظون بشاشات الكشوفات لكنهم يفقدون شاشة إدارة المستخدمين، فرقِّ حساباً واحداً على الأقل مباشرة بعد النشر.

---

## نتيجة الاختبارات

```
php vendor/bin/phpunit
PHPUnit 11.5.55 — PHP 8.2.12
OK (52 tests, 176 assertions)   الزمن ≈ 11 ثانية
```

**الاختبارات الجديدة** في `tests/Feature/Phase1/`:

| الملف | ما يغطيه |
|-------|----------|
| `RoleAccessTest.php` | مصفوفة وصول كل دور إلى كل شاشة (مسموح 200 / ممنوع 403)؛ المندوب يُوجَّه لصفحته بدل 403؛ التوجيه حسب الدور في `/` و`/dashboard` وبعد الدخول؛ المشرف لا يرى رابط المستخدمين؛ `/register` ومسار الـ debug يرجعان 404؛ الزوار يُحوَّلون للدخول |
| `SheetOwnershipTest.php` | الموزع لا يفتح كشف غيره ولا يسجّل عليه زيارة أو دفعة خارجية (403) ولا تتغير البيانات؛ الدفعة تُسجَّل على السطر الأساسي ولا يُصفَّر؛ التحول إلى `in_progress` وتسجيل `payment.recorded`؛ الكشف المغلق يرفض الدفعات؛ قائمة الموزع تعرض كشوفه فقط والأحدث أولاً؛ عرض التفاصيل للمدير والمشرف والمحاسب؛ ظهور شارة "تذكير: اختم الإيصال" و`inputmode` وغياب "Sadana" |
| `UserManagementTest.php` | تحويل `role` إلى Enum؛ المشرف لا يدير المستخدمين؛ الحد الأدنى 8 خانات؛ عدم ظهور كلمة المرور في السجل عند الإنشاء والتعديل؛ منع حذف آخر مدير أو تغيير دوره؛ منع حذف النفس وتغيير الدور الذاتي؛ أمر `user:make-admin` |
| `ActivityLogTest.php` | تسجيل الدخول والخروج؛ حذف القيم الحساسة؛ المشرف يرى `sheet.*` و`payment.*` فقط حتى مع فلتر ممنوع؛ المدير يرى الكل والفلاتر تعمل |

**اختبارات Breeze القديمة المعدّلة:**
- `AuthenticationTest`: التوجيه بعد الدخول صار حسب الدور، والخروج يُوجّه إلى `/login`. التوقع القديم `/` كان فاشلاً أصلاً قبل التعديل.
- `ExampleTest`: المسار `/` يُعيد توجيهاً للدخول. التوقع القديم 200 كان فاشلاً أصلاً.
- `ProfileTest`: استُبدل اختبارا حذف الحساب الذاتي باختبار يؤكد أن الحذف الذاتي معطّل (405).
- `RegistrationTest`: نُقل إلى `_phase1_removed/`.

---

## قائمة اختبار يدوي قصيرة

1. نفّذ `user:make-admin` على حسابك وادخل: تظهر روابط الكشوفات والمستخدمين وسجل النشاط وشاشة المحاسب.
2. ادخل كمشرف: لا يظهر رابط المستخدمين، و`/supervisor/users` يرجع 403.
3. ارفع كشفاً بدون ملف أو بصيغة خاطئة: تظهر رسالة الخطأ. ارفع كشفاً صحيحاً: يظهر في سجل النشاط باسمك كرافع.
4. كموزع على الجوال: افتح كشفاً، واكتب المبلغ بأرقام عربية (١٢٣): يتحول إلى 123 فوراً.
5. سجّل زيارة والإنترنت مقطوع، ثم أعده: تتم المزامنة، وتظهر شارة "تذكير: اختم الإيصال"، وتصبح حالة الكشف "قيد التحصيل".
6. سجّل دفعة خارجية أوفلاين ثم اتصل بالإنترنت: تُرفع وتُحقن في ملف الإكسل.
7. غيّر رقم الكشف في الرابط إلى كشف موزع آخر: 403.
8. افتح التطبيق على اتصال ضعيف: الخط والأيقونات تظهر (محلية، بلا CDN).
9. حاول حذف المدير الوحيد أو تغيير دوره: مرفوض. أنشئ حساباً بكلمة مرور من 7 خانات: مرفوض.
10. افتح `/register`: الصفحة غير موجودة (404).
11. ادخل بحساب `rep`: تظهر صفحة "لا توجد واجهة متاحة لهذا الحساب بعد".
12. تأكد أن المحاسب يرى الكشوفات ويحمّلها، ولا يرى أزرار الرفع أو الإغلاق أو الحذف.

---

## الافتراضات المتّخذة

1. **المدير لا يرى شاشات الموزع:** الملف حدّد صلاحياته بشاشات المشرف والمستخدمين وسجل النشاط والمحاسب، ولم يذكر شاشات الموزع.
2. **حذف المستخدم لحسابه بنفسه معطّل:** أُزيل المسار `DELETE /profile` والدالة `ProfileController::destroy`. كان ثغرة تتجاوز حماية آخر مدير، وتسمح لموزع بحذف حسابه فتُحذف كشوفاته المالية تلقائياً بسبب علاقة Cascade. يبقى في "حسابي" تعديل الاسم والبريد وكلمة المرور.
3. **إنشاء أي دور:** المدير يستطيع إنشاء أي دور من الخمسة، بما فيها `admin` و`rep`.
4. **المحاسب يمر بالسياسة نفسها:** يستخدم شاشة التفاصيل نفسها عبر `DistributionSheetPolicy::view`، ويرى كل الكشوفات كما كان.
5. **السجلات القديمة لم تُصحَّح:** الكشوفات المرفوعة سابقاً بـ `supervisor_id = 1` بقيت كما هي.
6. **بيانات التطوير:** عُدّل `DatabaseSeeder` ليستخدم الـ Enum، وأُضيف حسابا مدير ومحاسب تجريبيان، وصارت كلمة المرور `12345678` لتوافق الحد الأدنى. يُستخدم على قاعدة التطوير فقط ويدوياً.
7. **الدخول التلقائي:** الحقل المخفي `remember=1` في صفحة الدخول أُبقي كما هو، لأنه سلوك قائم مقصود للموزعين في الميدان.
8. **نمط الدفعة الخارجية:** الفاتورة الخارجية (`invoice_pattern = external`) تظهر بشارة "A" كما كانت، لأن السلوك الحالي محفوظ ولم يُطلب تغييره.
9. **مشرف حالي بلا مدير:** لا يُرقّى أي حساب تلقائياً، لذلك يجب تنفيذ `user:make-admin` يدوياً بعد النشر.

---

## ما لم يُنفَّذ ولماذا

| البند | السبب |
|-------|-------|
| commit منفصل لكل مجموعة | المشروع ليس مستودع git. |
| `git rm --cached` للملفات الحساسة | لا يوجد git، والملفات مُستثناة أصلاً في `.gitignore`. |
| الحذف النهائي للملفات المُزالة | وضع الأمان منع الحذف غير القابل للتراجع. نُقلت إلى `_phase1_removed/` ويمكن حذفها يدوياً. |
| إزالة أدوات بناء Vite/Tailwind (`resources/css/app.css`، `tailwind.config.js`، `postcss.config.js`، اعتماديات `package.json`، `public/build/`) | لم تعد أي صفحة تستخدمها، لكن حذفها يغيّر بيئة البناء ولم يُطلب صراحة. يمكن حذفها لاحقاً بأمان. |
| تعريب رسائل Laravel الافتراضية (`APP_LOCALE=ar` وملفات `lang/ar`) | خارج نطاق المرحلة. تفعيل اللغة العربية دون ملفات ترجمة كاملة يُظهر مفاتيح خام بدل الرسائل. كُتبت الرسائل بالعربية مباشرة في الشاشات المعدّلة (الدخول، الرفع، المستخدمين). رسائل إعادة تعيين كلمة المرور بالبريد ما زالت بالإنجليزية. |
| إعادة تفعيل `excel:clean` | معطّل عمداً حسب البند A-11، وسيُعالج في مرحلة لاحقة. |
| أي تعديل على بنية الفواتير والدفعات أو صيغة الإكسل أو الاستيراد والتحميل، أو إضافة طباعة أو عملات | ممنوع صراحة في نطاق المرحلة 1. |

---

## ملخص الملفات

**جديدة:**
- `app/Enums/Role.php`
- `app/Policies/DistributionSheetPolicy.php`
- `app/Models/ActivityLog.php`
- `app/Http/Controllers/ActivityLogController.php`
- `app/Console/Commands/MakeAdmin.php`
- `database/migrations/2026_10_14_000001_create_activity_logs_table.php`
- `resources/views/layouts/panel.blade.php`
- `resources/views/layouts/guest.blade.php`
- `resources/views/layouts/partials/logo.blade.php`
- `resources/views/components/{flash,stat-card,status-badge,page-header,empty-state}.blade.php`
- `resources/views/partials/invoices-table.blade.php`
- `resources/views/activity/index.blade.php`
- `resources/views/no-interface.blade.php`
- `public/css/app-panel.css`
- `public/vendor/**`
- `tests/Feature/Phase1/*`
- `PHASE_1_REPORT.md`

**معدّلة:**
- `routes/web.php`، `routes/auth.php`، `routes/console.php`
- `app/Models/User.php`
- `app/Http/Middleware/CheckRole.php`
- `app/Http/Controllers/{Supervisor,Distributor,Profile}Controller.php`
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Providers/AppServiceProvider.php`
- `database/factories/UserFactory.php`، `database/seeders/DatabaseSeeder.php`
- `config/app.php`، `.env`، `.env.example`، `.gitignore`
- كل واجهات `supervisor` و`distributor` و`accountant` و`auth` و`profile/edit`
- `tests/Feature/{ExampleTest,ProfileTest,Auth/AuthenticationTest}.php`

**منقولة إلى `_phase1_removed/`:**
- `RegisteredUserController` و`auth/register` و`RegistrationTest`
- `layouts/app` و`navigation` و`guest` القديم
- `dashboard` و`welcome`
- 13 مكوّن Breeze
- `View/Components/{AppLayout,GuestLayout}`
- `profile/partials/*`
