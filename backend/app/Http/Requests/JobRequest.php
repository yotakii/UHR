<?php

namespace App\Http\Requests;

use App\Models\JobOpening;
use App\Support\L;
use App\Support\Tenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/** إنشاء/تعديل شاغر من نظام الشركة (M16 الوظائف + لوحة مسؤول التوظيف) */
class JobRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title_ar' => ['required', 'string', 'max:150'], 'title_en' => ['required', 'string', 'max:150'],
            'department_id' => ['required', Rule::exists('departments', 'id')->where('company_id', Tenant::id())],
            'location_id' => ['required', Rule::exists('locations', 'id')],
            'employment_type' => ['required', Rule::in(array_keys(JobOpening::TYPES))],
            'openings' => ['required', 'integer', 'between:1,500'],
            'min_years' => ['required', 'integer', 'between:0,40'],
            'degree' => ['required', 'integer', 'between:0,4'],
            'salary_min' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'salary_max' => ['required', 'numeric', 'gte:salary_min', 'max:1000000'],
            'currency' => ['required', Rule::in(['USD', 'SYP'])],
            'summary_ar' => ['required', 'string', 'max:2000'], 'summary_en' => ['required', 'string', 'max:2000'],
            'requirements' => ['required', 'array', 'min:1', 'max:12'],
            'requirements.*.ar' => ['required', 'string', 'max:200'], 'requirements.*.en' => ['required', 'string', 'max:200'],
            'visibility' => ['required', Rule::in(JobOpening::VISIBILITY)],
            'closes_at' => ['nullable', 'date', 'after_or_equal:today'],
            'hard_to_fill' => ['boolean'], 'requires_photo' => ['boolean'],
            // أوزان مطابقة خاصة بالشاغر (اختياري) — مجموعها 100، وإلا تُستخدم أوزان الشركة
            'match_weights' => ['nullable', 'array'], 'match_weights.exp' => ['required_with:match_weights', 'integer', 'between:0,100'], 'match_weights.deg' => ['required_with:match_weights', 'integer', 'between:0,100'],
            'match_weights.sal' => ['required_with:match_weights', 'integer', 'between:0,100'], 'match_weights.eng' => ['required_with:match_weights', 'integer', 'between:0,100'],
            'match_weights.file' => ['required_with:match_weights', 'integer', 'between:0,100'],
            'status' => ['required', Rule::in(['draft', 'open', 'closed'])],
        ];
    }

    public function withValidator(Validator $v): void
    {
        $v->after(function ($v) {
            $w = $this->input('match_weights');
            if (is_array($w) && array_sum(array_map('intval', $w)) !== 100) $v->errors()->add('match_weights', L::t('مجموع الأوزان يجب أن يكون 100', 'Weights must add up to 100'));
        });
    }

    public function messages(): array
    {
        return [
            'required' => L::t('هذا الحقل مطلوب', 'This field is required'),
            'requirements.min' => L::t('أضف متطلباً واحداً على الأقل', 'Add at least one requirement'),
            'salary_max.gte' => L::t('الحد الأعلى للراتب أقل من الحد الأدنى', 'Maximum salary is below the minimum'),
            'closes_at.after_or_equal' => L::t('تاريخ الإغلاق لا يكون في الماضي', 'Closing date cannot be in the past'),
            'in' => L::t('اختر قيمة من القائمة', 'Choose a value from the list'), 'exists' => L::t('اختر قيمة من القائمة', 'Choose a value from the list'),
            'between' => L::t('القيمة خارج المجال المسموح', 'Value out of range'), 'max' => L::t('القيمة أطول من المسموح', 'Value is too long'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['message' => L::t('راجع الحقول المعلّمة.', 'Check the highlighted fields.'), 'code' => 'validation', 'errors' => $validator->errors()], 422));
    }
}
