<?php

namespace App\Http\Requests;

use App\Models\SheetVisit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تسجيل زيارة: مزار / غير مزار + سبب من القائمة (و«أخرى» بنص).
 */
class StoreVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'uuid'],
            // المرحلة 7H: من الطابور دون اتصال (لا من طلب حي)، ووقت التسجيل على الجهاز، وآخر إشعار عرفه الجهاز
            'offline' => ['nullable', 'boolean'],
            'recorded_at' => ['nullable', 'string', 'max:40'],
            'known_notice_id' => ['nullable', 'integer', 'min:0'],
            'customer_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(['visited', 'not_visited'])],
            'reason' => ['nullable', 'required_if:status,not_visited', Rule::in(SheetVisit::REASONS)],
            'reason_other' => ['nullable', 'required_if:reason,أخرى', 'string', 'max:200'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            // تثبيت الموقع المرجعي (اختياري): دقة GPS بالأمتار، وطلب التثبيت أو «ليس الآن»
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'pin_reference' => ['nullable', 'boolean'],
            'pin_declined' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required_if' => 'اختر سبب عدم الزيارة.',
            'reason.in' => 'سبب غير معروف.',
            'reason_other.required_if' => 'اكتب السبب عند اختيار «أخرى».',
        ];
    }

    /**
     * @return array{accuracy_m: ?float, pin_reference: bool, pin_declined: bool}
     */
    public function pinInput(): array
    {
        return [
            'accuracy_m' => $this->filled('accuracy_m') ? (float) $this->input('accuracy_m') : null,
            'pin_reference' => $this->boolean('pin_reference'),
            'pin_declined' => $this->boolean('pin_declined'),
        ];
    }

    public function reasonText(): ?string
    {
        if ($this->input('status') !== 'not_visited') {
            return null;
        }

        return $this->input('reason') === 'أخرى'
            ? 'أخرى: '.trim((string) $this->input('reason_other'))
            : $this->input('reason');
    }
}
