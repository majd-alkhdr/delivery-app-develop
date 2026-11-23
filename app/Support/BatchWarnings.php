<?php

namespace App\Support;

use App\Models\ImportBatch;

/**
 * المرحلة 7E: تجميع تحذيرات الدفعة (import_batches.warnings) للعرض: التحذير المكرر لكل فاتورة يصير صفاً واحداً بعدده،
 * والقوائم الطويلة تصير صفوفاً (المرجع، التفصيل، النص الأصلي) تُعرض بترقيم في «عرض الكل».
 * التحذيرات المخزّنة لا تتغيّر؛ هذا عرض فقط.
 */
class BatchWarnings
{
    /** قوائم الدفعة (stats.lists) بعنوانها كما يكتبه saveBatch */
    private const LISTS = [
        'no_order' => 'فواتير بلا أمر تسليم (وُضعت في "بلا كشف")',
        'old_delivery' => 'فواتير تاريخ تسليمها أقدم من تاريخ التوزيع',
        'deferred_with_order' => 'فواتير مؤجلة لها أمر تسليم لتاريخ مستقبلي',
        'completed_order' => 'فواتير أمرها لكشف مكتمل (وُضعت في "بلا كشف")',
    ];

    /**
     * @return array<string, array{key: string, level: string, title: string, count: int, rows: array<int, array{0: string, 1: string, 2: string}>, tab: ?string}>
     */
    public static function groups(ImportBatch $batch): array
    {
        $patterns = [
            'invalid_order' => ['/^الفاتورة (.+?): رقم أمر التسليم «(.*?)» غير صالح/u',
                fn ($n) => "{$n} فاتورة أمر تسليمها 0 أو غير صالح، فعوملت بلا أمر تسليم («بلا كشف»، أو مؤجلة إن كان تسليمها بعد تاريخ التوزيع).",
                fn ($m) => [$m[1], 'رقم الأمر «'.$m[2].'»']],
            'mixed_orders' => ['/^الفاتورة (.+?) تحمل أكثر من رقم أمر تسليم بين أسطرها \((.*)\)\. اعتُمد: (.*)\.$/u',
                fn ($n) => "{$n} فاتورة تحمل أكثر من رقم أمر تسليم بين أسطرها (اعتُمد الأكثر تكراراً).",
                fn ($m) => [$m[1], $m[2].' ← '.$m[3]]],
            'negative_due' => ['/^الرصيد المستحق للزبون "(.+?)" سالب بعد طرح المؤجل: (.+)\.$/u',
                fn ($n) => "{$n} زبوناً رصيدهم المستحق سالب بعد طرح المؤجل.",
                fn ($m) => [$m[1], $m[2]]],
            'duplicate_balance_name' => ['/^الاسم "(.+?)" مكرر في صفحة الأرصدة/u',
                fn ($n) => "{$n} اسماً مكرراً في صفحة الأرصدة (بعد التطبيع) جُمعت أرصدتها.",
                fn ($m) => [$m[1], 'جُمع رصيداه']],
            'new_series' => ['/^سلسلة فواتير جديدة "(.+?)"/u',
                fn ($n) => "{$n} سلسلة فواتير جديدة أُضيفت تلقائياً (يمكن تسميتها من شاشة السلاسل).",
                fn ($m) => [$m[1], 'أُضيفت تلقائياً']],
            'completed_order_sheet' => ['/^يوجد كشف مكتمل لأمر التسليم (.+?) بتاريخ/u',
                fn ($n) => "{$n} أمر تسليم له كشف مكتمل بتاريخ التوزيع نفسه، فوُضعت فواتيره الجديدة في «بلا كشف».",
                fn ($m) => [$m[1], 'كشف مكتمل']],
        ];

        $lists = (array) $batch->stat('lists', []);
        $groups = [];
        foreach ((array) ($batch->warnings ?? []) as $warning) {
            $warning = (string) $warning;

            foreach (self::LISTS as $key => $label) {
                if (str_starts_with($warning, $label.' (')) {
                    $rows = array_map(fn ($entry) => self::splitEntry((string) $entry), array_values((array) ($lists[$key] ?? [])));
                    // دفعات قديمة بلا stats.lists: العدد من نص التحذير نفسه
                    $count = $rows ? count($rows) : (preg_match('/^.*? \((\d+)\):/u', $warning, $m) ? (int) $m[1] : 0);
                    $groups[$key] = ['key' => $key, 'level' => 'warning', 'title' => $label, 'count' => $count, 'rows' => $rows,
                        'tab' => $key === 'deferred_with_order' ? 'deferred' : null];

                    continue 2;
                }
            }

            if (preg_match('/^زبائن بلا منطقة \((\d+)\): (.*)\.$/u', $warning, $m)) {
                $names = array_values(array_filter(array_map('trim', explode('، ', $m[2]))));
                $groups['no_area'] = ['key' => 'no_area', 'level' => 'warning', 'title' => 'زبائن بلا منطقة في الكشوف', 'count' => count($names),
                    'rows' => array_map(fn ($name) => [$name, 'بلا منطقة', $name], $names), 'tab' => null];

                continue;
            }

            foreach ($patterns as $key => [$regex, $title, $row]) {
                if (preg_match($regex, $warning, $m)) {
                    $groups[$key] ??= ['key' => $key, 'level' => 'warning', 'title' => '', 'count' => 0, 'rows' => [], 'tab' => null, 'titler' => $title];
                    $groups[$key]['rows'][] = [...$row($m), $warning];
                    $groups[$key]['count']++;

                    continue 2;
                }
            }

            // تحذير مفرد: النص نفسه صفاً واحداً (المتطابق يُجمع)
            $key = 'w_'.substr(md5($warning), 0, 10);
            $groups[$key] ??= ['key' => $key, 'level' => 'warning', 'title' => $warning, 'count' => 0, 'rows' => [], 'tab' => null];
            $groups[$key]['count']++;
        }

        foreach ($groups as $key => $group) {
            if (isset($group['titler'])) {
                $groups[$key]['title'] = ($group['titler'])($group['count']);
                unset($groups[$key]['titler']);
            }
        }

        return $groups;
    }

    /**
     * «G:12 (أمر 4021، تسليم 2026-10-25)» ⇒ [«G:12»، «أمر 4021، تسليم 2026-10-25»، النص كاملاً]
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private static function splitEntry(string $entry): array
    {
        if (preg_match('/^(.+?) \((.*)\)$/u', $entry, $m)) {
            return [$m[1], $m[2], $entry];
        }

        return [$entry, '', $entry];
    }
}
