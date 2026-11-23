<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');



// معطّل مؤقتاً (المرحلة 1): حذف ملفات الإكسل بعد 7 أيام يكسر تحميل الكشوفات القديمة
// وإضافة الدفعات الخارجية عليها. سيُعالج جذرياً في مرحلة لاحقة قبل إعادة التفعيل.
// Schedule::command('excel:clean')->daily();
