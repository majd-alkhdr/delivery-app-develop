<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * كتابة جماعية بعدد استعلامات ثابت لكل دفعة (المرحلة 7D): بدل استعلام لكل صف.
 * تعمل على MySQL وSQLite (UPDATE … CASE id WHEN … وINSERT متعدد الصفوف).
 */
class BulkWrite
{
    public const CHUNK = 500;

    /**
     * تحديث صفوف مختلفة القيم بالمعرّف: [id => [عمود => قيمة]]. الصفوف بالأعمدة نفسها تُحدَّث معاً.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function updateById(string $table, array $rows, int $chunk = self::CHUNK): void
    {
        $groups = [];
        foreach ($rows as $id => $values) {
            $columns = array_keys($values);
            sort($columns);
            $groups[implode(',', $columns)][$id] = $values;
        }

        foreach ($groups as $group) {
            foreach (array_chunk($group, $chunk, true) as $part) {
                $columns = array_keys(reset($part));
                $sets = [];
                $bindings = [];
                foreach ($columns as $column) {
                    $cases = [];
                    foreach ($part as $id => $values) {
                        $cases[] = 'WHEN ? THEN ?';
                        $bindings[] = $id;
                        $bindings[] = $values[$column];
                    }
                    $sets[] = DB::getQueryGrammar()->wrap($column).' = CASE '.DB::getQueryGrammar()->wrap('id').' '.implode(' ', $cases).' END';
                }
                $ids = array_keys($part);
                $bindings = array_merge($bindings, $ids);

                DB::update('UPDATE '.DB::getQueryGrammar()->wrapTable($table).' SET '.implode(', ', $sets)
                    .' WHERE '.DB::getQueryGrammar()->wrap('id').' IN ('.implode(', ', array_fill(0, count($ids), '?')).')', $bindings);
            }
        }
    }

    /**
     * إدخال نماذج جديدة على دفعات بالقيم نفسها التي يكتبها Model::create (التحويلات والطوابع الزمنية)،
     * بترتيبها (المعرّفات تصاعدية بترتيب الإدخال). الأعمدة الغائبة عن نموذج تُكتب null.
     *
     * @param  array<int, Model>  $models  نماذج غير محفوظة من الصنف نفسه
     */
    public static function insertModels(array $models, int $chunk = self::CHUNK): void
    {
        if (! $models) {
            return;
        }
        $rows = [];
        $columns = [];
        foreach ($models as $model) {
            if ($model->usesTimestamps()) {
                $model->updateTimestamps();
            }
            $attributes = $model->getAttributes();
            $rows[] = $attributes;
            $columns += array_flip(array_keys($attributes));
        }
        $columns = array_keys($columns);
        $table = $models[0]->getTable();

        foreach (array_chunk($rows, $chunk) as $part) {
            DB::table($table)->insert(array_map(fn ($row) => array_merge(array_fill_keys($columns, null), $row), $part));
        }
    }
}
