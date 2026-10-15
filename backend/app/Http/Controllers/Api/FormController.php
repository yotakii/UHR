<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FormTemplate;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * سجل النماذج وقالب الطباعة (M16 — SRS §10.4، §10.5):
 * - HR: قائمة النماذج (الرمز المطبوع، الإصدار، تاريخه، حساس) وقالب الشعار.
 * - كل مستخدم: بيانات رأس/تذييل النموذج قبل الطباعة، وتسجيل طباعة النماذج الحساسة.
 */
class FormController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => FormTemplate::orderBy('id')->get()->map(fn ($f) => $this->present($f)), 'template' => Settings::get('print')]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $f = FormTemplate::findOrFail($id);
        $f->update($request->validate([
            'ref' => ['required', 'string', 'max:80'], 'title_ar' => ['required', 'string', 'max:150'], 'title_en' => ['required', 'string', 'max:150'],
            'issue' => ['required', 'string', 'max:10'], 'issued_on' => ['required', 'date'], 'sensitive' => ['boolean'],
        ], ['required' => L::t('هذا الحقل مطلوب', 'This field is required')]));
        return response()->json(['data' => $this->present($f)]);
    }

    public function updateTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(['logo' => ['required', 'boolean'], 'logo_height' => ['required', 'integer', 'between:24,120'], 'align' => ['required', Rule::in(['start', 'center', 'end'])],
            'margin_mm' => ['required', 'integer', 'between:5,30']]);
        $before = Settings::get('print');
        Settings::put('print', $data);
        Audit::record('settings.print_template', null, $before, $data);
        return response()->json(['template' => $data]);
    }

    /** رأس النموذج وتذييله: هوية الشركة + رمز النموذج وإصداره وتاريخه + القالب */
    public function printMeta(Request $request, string $code): JsonResponse
    {
        return $this->inCompany($request, function () use ($code) {
            $f = FormTemplate::where('code', $code)->first();
            $c = Tenant::company();
            $s = Settings::get('company');
            return response()->json(['data' => [
                'company' => ['name' => ['ar' => $s['name_ar'] ?? $c->name_ar, 'en' => $s['name_en'] ?? $c->name_en], 'dept' => ['ar' => $s['hr_dept_ar'] ?? '', 'en' => $s['hr_dept_en'] ?? ''],
                    'logo' => $c->card()['logo']],
                'form' => $f ? $this->present($f) : ['code' => $code, 'ref' => \App\Support\Forms::ref($code), 'issue' => '01', 'issued_on' => null, 'sensitive' => false, 'title' => null],
                'template' => Settings::get('print'),
            ]]);
        });
    }

    /** تسجيل الطباعة: النماذج الحساسة فقط (رواتب، قرارات، مخالفات، شهادات) */
    public function logPrint(Request $request, string $code): JsonResponse
    {
        $subject = $request->validate(['subject' => ['nullable', 'string', 'max:120']])['subject'] ?? null;
        return $this->inCompany($request, function () use ($code, $subject) {
            $f = FormTemplate::where('code', $code)->first();
            if ($f?->sensitive) Audit::record('print', $f, null, ['code' => $f->ref, 'subject' => $subject]);
            return response()->json(['logged' => (bool) $f?->sensitive]);
        });
    }

    /** موظف/HR: شركته. المتقدم (بلا شركة): الشركة التي قدّم لها، بـ ?company=slug */
    private function inCompany(Request $request, \Closure $fn): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->company_id) return $fn();
        $c = Company::where('slug', (string) $request->query('company'))->first();
        abort_unless($c && $user->candidates()->where('company_id', $c->id)->exists(), 404, L::t('النموذج غير متاح.', 'This form is not available.'));
        return Tenant::run($c, $fn);
    }

    private function present(FormTemplate $f): array
    {
        return ['id' => $f->id, 'code' => $f->code, 'ref' => $f->ref, 'title' => ['ar' => $f->title_ar, 'en' => $f->title_en], 'module' => $f->module,
            'issue' => $f->issue, 'issued_on' => $f->issued_on?->toDateString(), 'sensitive' => $f->sensitive];
    }
}
