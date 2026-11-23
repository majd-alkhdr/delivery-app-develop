<?php

namespace Tests\Unit;

use App\Support\ArabicNormalizer;
use PHPUnit\Framework\TestCase;

class ArabicNormalizerTest extends TestCase
{
    public function test_hamza_forms_match(): void
    {
        $this->assertSame(ArabicNormalizer::normalize('أرنبة'), ArabicNormalizer::normalize('ارنبة'));
        $this->assertSame(ArabicNormalizer::normalize('صيدلية الإسراء - خان أرنبة'), ArabicNormalizer::normalize('صيدلية الاسراء - خان ارنبه'));
    }

    public function test_full_normalization_rules(): void
    {
        $this->assertSame('مستشفي', ArabicNormalizer::normalize('مستشفى'));          // ى → ي
        $this->assertSame('صيدليه', ArabicNormalizer::normalize('صيدليّة'));         // تشكيل + ة → ه
        $this->assertSame('ابو الفضل', ArabicNormalizer::normalize('  أبـــو   الفضل '));  // تطويل وطيّ المسافات
        $this->assertSame('ركن الدين - جبل', ArabicNormalizer::normalize('ركن الدين–جبل')); // توحيد الشرطة والمسافات حولها
        $this->assertSame('عياده 12', ArabicNormalizer::normalize('عيادة ١٢'));      // أرقام عربية-هندية → لاتينية
        $this->assertSame('ab vita', ArabicNormalizer::normalize("\u{200F}AB  Vita\u{200E}"));  // علامات الاتجاه وحروف لاتينية صغيرة
        $this->assertSame('ا ا ا ا', ArabicNormalizer::normalize('أ إ آ ٱ'));
    }
}
