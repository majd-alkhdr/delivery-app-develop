<?php

namespace App\Http\Requests;

use App\Support\Numbers;

/**
 * تحصيل المندوب (المرحلة 6): حقول الدفعة نفسها + رقم الإيصال الورقي + صورة الإيصال (multipart).
 * فحص الصورة الحقيقي (المحتوى وإعادة الترميز) في PaymentImageService قبل إنشاء أي سجل.
 */
class StoreRepPaymentRequest extends StorePaymentRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge([
            'book_receipt_no' => $this->filled('book_receipt_no') ? trim(strtr((string) $this->input('book_receipt_no'), [
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ])) : null,
        ]);
    }

    public function rules(): array
    {
        return parent::rules() + [
            'book_receipt_no' => ['required', 'string', 'max:40'],
            'confirm_duplicate_book_no' => ['nullable', 'boolean'],
            // الصورة تُفحص كاملة في الخدمة؛ هنا حد الحجم فقط (3 م.ب)
            'image' => ['nullable', 'file', 'max:3072'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'book_receipt_no.required' => 'رقم الإيصال الورقي مطلوب.',
            'image.max' => 'صورة الإيصال يجب ألا تتجاوز 3 ميغابايت.',
            'image.file' => 'صورة الإيصال غير صالحة.',
        ];
    }

    public function paymentInput(): array
    {
        return parent::paymentInput() + [
            'book_receipt_no' => $this->input('book_receipt_no'),
            'confirm_duplicate_book_no' => $this->boolean('confirm_duplicate_book_no'),
        ];
    }
}
