<?php

namespace App\Services\People;

use App\Exceptions\ApiException;
use App\Models\Advance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\MonthSheet;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Payroll\Payroll;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * طلبات الخدمات (M09) بالمسار الموحد (سلسلة service / advance ← الموارد البشرية):
 * - شهادة راتب (D-16): بيانات الجهة إلزامية (الاسم، السجل التجاري، البريد، الهاتف) + موافقة إبلاغها عند انتهاء الخدمة؛ تُصدر برقم تسلسلي.
 * - شهادة عمل / تعريف: الجهة اختيارية.
 * - سلفة: ضمن الرصيد المتاح من الدوام (Payroll::advanceAvailable) ← تُخصم من كشف الشهر عند إغلاقه.
 * - تحديث بيانات: الجوال يُحدَّث عند الاعتماد، وغيره بملاحظة للموارد البشرية.
 */
class ServiceDesk
{
    public function __construct(private Workflow $flow) {}

    public function submit(Employee $e, array $in, User $by): ServiceRequest
    {
        $kind = $in['kind'] ?? '';
        if (! isset(ServiceRequest::KINDS[$kind])) throw ApiException::field('kind', L::t('نوع الخدمة غير معروف.', 'Unknown service type.'), 'unknown');
        $rules = ['note' => ['nullable', 'string', 'max:1000']] + match ($kind) {
            'salary_cert' => ['addressee.name' => ['required', 'string', 'max:190'], 'addressee.cr_no' => ['required', 'string', 'max:60'], 'addressee.email' => ['required', 'email', 'max:190'],
                'addressee.phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9\s()-]{6,39}$/'], 'notify_consent' => ['nullable', 'boolean']],
            'experience_cert' => ['addressee.name' => ['nullable', 'string', 'max:190']],
            'advance' => ['amount' => ['required', 'numeric', 'min:1']],
            'data_update' => ['data.phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9\s()-]{6,39}$/'], 'note' => ['required', 'string', 'min:5', 'max:1000']],
        };
        $d = Validator::make($in, $rules, [], ['addressee.name' => L::t('اسم الجهة', 'Organisation name'), 'addressee.cr_no' => L::t('رقم السجل التجاري', 'Commercial registration no.'),
            'addressee.email' => L::t('البريد الرسمي للجهة', 'Organisation email'), 'addressee.phone' => L::t('هاتف الجهة', 'Organisation phone'), 'amount' => L::t('المبلغ', 'Amount'),
            'note' => L::t('التفاصيل', 'Details'), 'data.phone' => L::t('الجوال الجديد', 'New mobile')])->validate();
        if (ServiceRequest::where('employee_id', $e->id)->where('kind', $kind)->whereIn('status', LeaveRequest::PENDING)->exists()) {
            throw ApiException::field('kind', L::t('لديك طلب من هذا النوع قيد التنفيذ.', 'You already have a request of this type in progress.'), 'service_pending');
        }
        if ($kind === 'advance') {
            $av = Payroll::advanceAvailable($e);
            if ($d['amount'] > $av['available']) throw ApiException::field('amount', L::t("المتاح لك الآن {$av['available']} {$e->currency}.", "You can request up to {$av['available']} {$e->currency} now."), 'advance_limit');
        }
        return DB::transaction(function () use ($e, $kind, $d, $by) {
            $s = ServiceRequest::create(['employee_id' => $e->id, 'kind' => $kind, 'note' => trim((string) ($d['note'] ?? '')) ?: null, 'amount' => $kind === 'advance' ? round((float) $d['amount'], 2) : null,
                'addressee' => isset($d['addressee']) ? array_filter($d['addressee'], fn ($x) => $x !== null && $x !== '') ?: null : null, 'notify_consent' => (bool) ($d['notify_consent'] ?? false),
                'data' => isset($d['data']) ? array_filter($d['data']) ?: null : null, 'status' => 'pending_hr', 'requested_by' => $by->id]);
            $e->log('طلب خدمة: '.$s->workflowLabel('ar'), 'Service request: '.$s->workflowLabel('en'));
            $this->flow->start($s, $e, Workflow::chain($kind === 'advance' ? 'advance' : 'service'), $by);
            return $s->refresh();
        });
    }

    public function decide(ServiceRequest $s, User $u, bool $approve, ?string $note): ServiceRequest
    {
        $guard = function (ServiceRequest $x) {
            if ($x->kind !== 'advance') return;
            // عند الاعتماد: ضمن السقف ناقصاً السلف المعتمدة هذا الشهر، وضمن عدد السلف المسموح
            $av = Payroll::advanceAvailable($x->employee);
            $approved = Advance::where('employee_id', $x->employee_id)->where('month', $av['month'])->where('status', '!=', 'cancelled')->count();
            if ($x->amount > $av['cap'] - $av['taken'] + 0.001 || $approved >= (int) Settings::policy('payroll')['advance_per_month']) {
                throw new ApiException(L::t('تجاوز الطلب رصيد السلفة المتاح الآن.', 'The request now exceeds the available advance balance.'), 'advance_limit', 409);
            }
        };
        return $this->flow->decide($s, $u, $approve, $note, false, fn (ServiceRequest $x) => $this->fulfil($x, $u), $guard);
    }

    /** تنفيذ الطلب عند الاعتماد النهائي */
    private function fulfil(ServiceRequest $s, User $u): void
    {
        $e = Employee::findOrFail($s->employee_id);
        $upd = ['done_at' => now(), 'decided_by_user_id' => $u->id, 'decided_by' => $u->displayName('ar')];
        if (str_ends_with($s->kind, '_cert')) {
            $y = now()->year;
            $upd += ['ref_no' => 'CRT-'.$y.'-'.str_pad((string) Counters::next("cert-{$y}"), 4, '0', STR_PAD_LEFT), 'issued_at' => now(), 'issued_by' => $u->id];
        }
        if ($s->kind === 'advance') {
            $month = Payroll::currentMonth();
            // كشف الشهر مغلق ← الخصم من الشهر التالي
            if (MonthSheet::where('month', $month)->where('status', 'closed')->exists()) $month = Carbon::parse($month.'-01')->addMonth()->format('Y-m');
            Advance::create(['employee_id' => $e->id, 'service_request_id' => $s->id, 'amount' => $s->amount, 'currency' => $e->currency, 'month' => $month]);
        }
        if ($s->kind === 'data_update' && ($phone = $s->data['phone'] ?? null)) {
            $e->update(['phone' => $phone]);
            $e->log("تحديث الجوال بطلب الموظف ({$u->displayName('ar')})", "Mobile updated on request ({$u->displayName('en')})", 'info');
        }
        $s->update($upd);
        if (isset($upd['ref_no'])) Audit::record('certificate.issued', $s, null, ['ref' => $upd['ref_no'], 'kind' => $s->kind, 'to' => $s->addressee['name'] ?? null]);
    }

    /** بيانات الشهادة للطباعة (Paper) */
    public static function certificate(ServiceRequest $s, bool $open = false): array
    {
        $e = $s->employee;
        $issuer = $s->issuer;
        return ['ref_no' => $s->ref_no, 'kind' => $s->kind, 'issued_at' => $s->issued_at?->toIso8601String(), 'addressee' => $s->addressee, 'notify_consent' => $s->notify_consent,
            'employee' => ['name' => ['ar' => $e->name, 'en' => $e->name_en], 'gender' => $e->gender, 'code' => $e->code, 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en],
                'national_id' => $e->national_id, 'hire_date' => $e->hire_date?->toDateString()],
            'salary' => $s->kind === 'salary_cert' ? ['amount' => $open ? (float) $e->salary : null, 'currency' => $e->currency] : null, 'masked' => $s->kind === 'salary_cert' && ! $open,
            'company' => ['ar' => Settings::company('name_ar'), 'en' => Settings::company('name_en')],
            'issuer' => $issuer ? ['ar' => $issuer->displayName('ar'), 'en' => $issuer->displayName('en')] : null, 'form' => $s->kind === 'salary_cert' ? 'SAL' : 'EMP'];
    }
}
