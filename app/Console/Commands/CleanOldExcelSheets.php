<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CleanOldExcelSheets extends Command
{
    // الاسم الذي سننادي به الأمر في السيرفر
    protected $signature = 'excel:clean';

    // وصف الأمر
    protected $description = 'حذف ملفات الإكسل القديمة التي مر عليها أكثر من 7 أيام لتوفير مساحة السيرفر';

    public function handle()
    {
        $directory = 'excel_sheets';

        // 1. التحقق من وجود المجلد أولاً
        if (!Storage::exists($directory)) {
            $this->info('المجلد غير موجود أصلاً.');
            return;
        }

        // 2. جلب كافة الملفات داخل المجلد
        $files = Storage::files($directory);
        $now = Carbon::now();
        $deletedCount = 0;

        foreach ($files as $file) {
            // جلب وقت آخر تعديل أو رفع للملف على السيرفر
            $lastModified = Carbon::createFromTimestamp(Storage::lastModified($file));

            // حساب الفرق بالأيام (إذا كان أكبر من أو يساوي 7 أيام)
            if ($lastModified->diffInDays($now) >= 7) {
                Storage::delete($file);
                $deletedCount++;
            }
        }

        $this->info("تم بنجاح تنظيف السيرفر وحذف {$deletedCount} ملف إكسل قديم!");
    }
}
