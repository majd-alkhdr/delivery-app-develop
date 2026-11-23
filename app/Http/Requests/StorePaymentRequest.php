<?php

namespace App\Http\Requests;

use App\Support\Numbers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تسجيل دفعة (من الكشف أو خارجية). الأرقام العربية وفواصل الآلاف تُحوَّل قبل التحقق.
 * تحقق العملة والسعر والحد الأقصى في PaymentService.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // الصلاحية التفصيلية في الكونترولر (Policy + نطاق الموزع)
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'amount' => $this->filled('amount') ? (Numbers::parse($this->input('amount')) ?? $this->input('amount')) : null,
            'exchange_rate' => $this->filled('exchange_rate') ? (Numbers::parse($this->input('exchange_rate')) ?? $this->input('exchange_rate')) : null,
            'currency' => strtoupper((string) ($this->input('currency') ?: 'SYP')),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            // المرحلة 7H: من الطابور دون اتصال (لا من طلب حي)، ووقت التسجيل على الجهاز، وآخر إشعار عرفه الجهاز
            'offline' => ['nullable', 'boolean'],
            'recorded_at' => ['nullable', 'string', 'max:40'],
            'known_notice_id' => ['nullable', 'integer', 'min:0'],
            'receipt_number' => ['nullable', 'string', 'max:40'],
            'customer_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', Rule::in(['SYP', 'USD'])],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'rate_confirmed' => ['nullable', 'boolean'],
            // دفعة دولار قديمة من الطابور المحلي سُجّلت قبل أن يصبح السعر إلزامياً (المرحلة 5)
            'legacy_no_rate' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            // تثبيت الموقع المرجعي (اختياري): دقة GPS بالأمتار، وطلب التثبيت أو «ليس الآن»
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'pin_reference' => ['nullable', 'boolean'],
            'pin_declined' => ['nullable', 'boolean'],
            // المرحلة 7B: الرصيد قبل/بعد كما احتسبه الجهاز عند طباعة الإيصال دون اتصال
            'device_balance_before' => ['nullable', 'numeric'],
            'device_balance_after' => ['nullable', 'numeric'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_uuid.required' => 'معرّف الدفعة مفقود (أعد تحميل الصفحة).',
            'amount.required' => 'أدخل المبلغ.',
            'amount.numeric' => 'المبلغ غير صالح.',
            'amount.gt' => 'المبلغ يجب أن يكون أكبر من صفر.',
            'currency.in' => 'العملة غير صالحة.',
            'exchange_rate.numeric' => 'سعر الصرف غير صالح.',
            'exchange_rate.gt' => 'سعر الصرف يجب أن يكون أكبر من صفر.',
        ];
    }

    /**
     * @return array<string, mixed> مدخلات PaymentService
     */
    public function paymentInput(): array
    {
        return [
            'client_uuid' => strtolower($this->input('client_uuid')),
            'receipt_number' => $this->input('receipt_number'),
            'amount' => (float) $this->input('amount'),
            'currency' => $this->input('currency'),
            'exchange_rate' => $this->filled('exchange_rate') ? (float) $this->input('exchange_rate') : null,
            'rate_confirmed' => $this->boolean('rate_confirmed'),
            'legacy_no_rate' => $this->boolean('legacy_no_rate'),
            'note' => $this->input('note'),
            'latitude' => $this->filled('latitude') ? (float) $this->input('latitude') : null,
            'longitude' => $this->filled('longitude') ? (float) $this->input('longitude') : null,
            'accuracy_m' => $this->filled('accuracy_m') ? (float) $this->input('accuracy_m') : null,
            'pin_reference' => $this->boolean('pin_reference'),
            'pin_declined' => $this->boolean('pin_declined'),
            'device_balance_before' => $this->filled('device_balance_before') ? (float) $this->input('device_balance_before') : null,
            'device_balance_after' => $this->filled('device_balance_after') ? (float) $this->input('device_balance_after') : null,
        ];
    }
}
