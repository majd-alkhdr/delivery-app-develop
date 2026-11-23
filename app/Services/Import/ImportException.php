<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * خطأ يمنع قبول الملف (يؤدي إلى رفض الملف كاملاً و rollback).
 */
class ImportException extends RuntimeException
{
}
