<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\PublicController;
use App\Models\JobOpening;
use App\Models\Referral;
use App\Services\Recruitment\DuplicateCheck;
use App\Support\L;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * نموذج طلب التوظيف F-28 (SRS M02) — نفس قواعد المعالج في الواجهة تُعاد هنا كاملة.
 * أسماء الحقول = أسماء حقول الواجهة، فتظهر كل رسالة تحت حقلها.
 */
class ApplicationRequest extends FormRequest
{
    public const MARITAL = ['single', 'married', 'divorced', 'widowed'];
    public const BLOOD = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
    public const ENGLISH = ['excellent', 'good', 'fair', 'weak'];
    public const SOURCES = ['website', 'linkedin', 'referral', 'expo'];
    public const CV_MAX_KB = 10240;

    public const PHOTO_MAX_KB = 2048;

    private ?DuplicateCheck $dup = null;
    private ?JobOpening $job = null;

    public function authorize(): bool { return true; }

    /** الشاغر من المسار /public/jobs/{slug}/{code}/apply — 404 إذا أُغلق أو انتهى موعده */
    public function job(): JobOpening
    {
        return $this->job ??= PublicController::openJob((string) $this->route('slug'), (string) $this->route('code'));
    }

    protected function prepareForValidation(): void
    {
        // المتقدم المسجّل يقدّم ببريد حسابه دائماً
        $user = $this->user();
        $email = $user && $user->portal() === 'candidate' ? $user->email : $this->input('email');
        $this->merge([
            'email' => strtolower(trim((string) $email)),
            'national_id' => trim((string) $this->input('national_id')),
            'ref_code' => strtoupper(trim((string) $this->input('ref_code'))) ?: null,
        ]);
    }

    /** الملف السابق بنفس الرقم الوطني أو البريد (إن وُجد) */
    public function previousApplication(): DuplicateCheck
    {
        return $this->dup ??= DuplicateCheck::find($this->job(), $this->input('national_id'), (string) $this->input('email'), $this->user());
    }

    public function rules(): array
    {
        $dup = $this->previousApplication();
        $year = (int) now()->year;
        $password = $dup->signedIn ? ['nullable'] : ($dup->account ? ['required', 'string'] : ['required', 'confirmed', AuthController::passwordRule()]);
        return [
            // 1. البيانات الشخصية
            'name' => ['required', 'string', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'gender' => ['required', Rule::in(['M', 'F'])],
            'marital' => ['required', Rule::in(self::MARITAL)],
            'nationality' => ['required', 'string', 'max:60'],
            'dob' => ['required', 'date', 'before:-15 years', 'after:1940-01-01'],
            'pob' => ['nullable', 'string', 'max:80'],
            'national_id' => ['required', 'string', 'max:30'],
            'blood' => ['nullable', Rule::in(self::BLOOD)],
            'mobile' => ['required', 'string', 'max:40', $this->phone(...)],
            'email' => ['required', 'email', 'max:190'],
            'address' => ['required', 'string', 'max:255'],
            'linkedin' => ['nullable', 'string', 'max:255'],
            // 2. المؤهلات والخبرات
            'degree' => ['required', 'integer', 'between:0,4'],
            'institute' => ['required', 'string', 'max:150'],
            'major' => ['required', 'string', 'max:150'],
            'grad_year' => ['nullable', 'integer', 'between:1960,'.($year + 1)],
            'grade' => ['nullable', 'string', 'max:60'],
            'english' => ['required', Rule::in(self::ENGLISH)],
            'years_exp' => ['required', 'integer', 'between:0,60'],
            'experience' => ['nullable', 'array', 'max:3'],
            'experience.*.company' => ['nullable', 'string', 'max:150'],
            'experience.*.position' => ['nullable', 'string', 'max:150'],
            'experience.*.from' => ['nullable', 'integer', 'between:1960,'.$year],
            'experience.*.to' => ['nullable', 'integer', 'between:1960,'.$year],
            'experience.*.reason' => ['nullable', 'string', 'max:255'],
            // 3. المراجع والأسئلة
            'ref_name' => ['nullable', 'string', 'max:120'],
            'ref_job' => ['nullable', 'string', 'max:120'],
            'ref_phone' => ['nullable', 'string', 'max:40'],
            'shifts' => ['required', 'boolean'], 'travel' => ['required', 'boolean'],
            'public_sector' => ['required', 'boolean'], 'conviction' => ['required', 'boolean'],
            'salary' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'availability' => ['nullable', 'date', 'after_or_equal:today'],
            'source' => ['required', Rule::in(self::SOURCES)],
            'ref_code' => ['nullable', 'string', 'max:30', $this->referral(...)],
            'cv' => ['nullable', 'file', 'extensions:pdf,doc,docx', 'mimes:pdf,doc,docx', 'max:'.self::CV_MAX_KB],
            // الصورة فقط إذا طلبها الشاغر (D-19)
            'photo' => [$this->job()->requires_photo ? 'required' : 'nullable', 'file', 'extensions:jpg,jpeg,png', 'mimes:jpg,jpeg,png', 'max:'.self::PHOTO_MAX_KB],
            // 4. المراجعة والإقرار
            'password' => $password,
            'agree_info' => ['accepted'],
            'agree_medical' => ['accepted'],
            // إقرار معالجة البيانات لهذه الشركة، وخيار الاحتفاظ بالسيرة لفرص قادمة (D-02، D-05)
            'agree_data' => ['accepted'],
            'keep_cv' => ['nullable', 'boolean'],
        ];
    }

    private function phone(string $attr, mixed $value, Closure $fail): void
    {
        if (strlen(preg_replace('/\D/', '', (string) $value)) < 10) $fail(L::t('أدخل رقم الجوال كاملاً مع رمز الدولة', 'Enter the full mobile number with country code'));
    }

    private function referral(string $attr, mixed $value, Closure $fail): void
    {
        if ($this->input('source') === 'referral' && $value && ! Referral::allCompanies()->where('company_id', $this->job()->company_id)->whereRaw('upper(code) = ?', [$value])->exists()) {
            $fail(L::t('رمز الإحالة غير موجود. تحقق منه مع الموظف.', 'Referral code not found. Check it with the employee.'));
        }
    }

    public function messages(): array
    {
        $req = L::t('هذا الحقل مطلوب', 'This field is required');
        return [
            'required' => $req,
            'job.exists' => L::t('هذه الوظيفة غير متاحة للتقديم حالياً.', 'This position is not open for applications.'),
            'email.email' => L::t('صيغة البريد غير صحيحة', 'Invalid email format'),
            'dob.before' => L::t('تحقق من تاريخ الولادة', 'Check the date of birth'), 'dob.after' => L::t('تحقق من تاريخ الولادة', 'Check the date of birth'),
            'date' => L::t('تاريخ غير صالح', 'Invalid date'),
            'integer' => L::t('أدخل رقماً صحيحاً', 'Enter a whole number'),
            'between' => L::t('القيمة خارج المجال المسموح', 'Value out of range'),
            'in' => L::t('اختر قيمة من القائمة', 'Choose a value from the list'),
            'max' => L::t('القيمة أطول من المسموح', 'Value is too long'),
            'salary.required' => L::t('حدد الراتب المتوقع بالدولار', 'Enter expected salary in USD'),
            'salary.gt' => L::t('حدد الراتب المتوقع بالدولار', 'Enter expected salary in USD'),
            'availability.after_or_equal' => L::t('تاريخ الجاهزية لا يكون في الماضي', 'Availability date cannot be in the past'),
            'experience.max' => L::t('حتى 3 خبرات فقط', 'Up to 3 experiences'),
            'cv.max' => L::t('الحد الأقصى لحجم الملف 10MB', 'Maximum file size is 10MB'),
            'cv.mimes' => L::t('الصيغ المقبولة: PDF أو DOC أو DOCX', 'Accepted formats: PDF, DOC or DOCX'),
            'cv.extensions' => L::t('الصيغ المقبولة: PDF أو DOC أو DOCX', 'Accepted formats: PDF, DOC or DOCX'),
            'cv.file' => L::t('تعذّر رفع الملف، حاول مجدداً', 'Upload failed, try again'),
            'agree_info.accepted' => L::t('يجب الموافقة على الإقرارين لإرسال الطلب', 'You must accept both declarations to submit'),
            'agree_medical.accepted' => L::t('يجب الموافقة على الإقرارين لإرسال الطلب', 'You must accept both declarations to submit'),
            'agree_data.accepted' => L::t('يجب الموافقة على معالجة بياناتك لإرسال الطلب', 'You must accept the processing of your data to submit'),
            'photo.required' => L::t('هذه الوظيفة تتطلب صورة شخصية', 'This position requires a personal photo'),
            'photo.max' => L::t('الحد الأقصى لحجم الصورة 2MB', 'Maximum photo size is 2MB'),
            'photo.mimes' => L::t('الصورة بصيغة JPG أو PNG', 'Photo must be JPG or PNG'), 'photo.extensions' => L::t('الصورة بصيغة JPG أو PNG', 'Photo must be JPG or PNG'),
        ] + AuthController::passwordMessages() + ['password.required' => L::t('أنشئ كلمة مرور لمتابعة طلبك', 'Create a password to track your application')];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => L::t('راجع الحقول المعلّمة وأكمل البيانات المطلوبة.', 'Check the highlighted fields and complete the required data.'),
            'code' => 'validation', 'errors' => $validator->errors(),
        ], 422));
    }
}
