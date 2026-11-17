<?php

namespace App\Services\Contracts;

use App\Exceptions\ApiException;
use App\Models\CodeItem;
use App\Models\Contract;
use App\Models\ContractAnnex;
use App\Models\ContractCase;
use App\Models\Employee;
use App\Models\User;
use App\Services\Codes\Codes;
use App\Services\Onboarding\OnboardingFlow;
use App\Services\People\Directory;
use App\Services\People\EmployeeDocs;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use App\Support\Signature;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * سجل العقود (الجزء D — FR-CR-001/002/010/011/018): العقد بنوعه من دليل الترميز وتاريخيه وفترة تجربته،
 * نص من القالب، تواقيع متعددة ونسخ ثلاث، تفعيل بعد اكتمال التواقيع فقط (BR-CR-08)، ومنع تداخل عقدين (BR-CR-10)، ورادار الانتهاء.
 */
class Contracts
{
    public const SIGNERS = ['employee' => ['الموظف', 'Employee'], 'hr_director' => ['الموارد البشرية', 'Human Resources'], 'executive' => ['المدير التنفيذي', 'Executive director'], 'ceo' => ['الرئيس التنفيذي', 'CEO']];
    public const COPIES = ['employee' => ['نسخة الموظف', "Employee's copy"], 'file' => ['نسخة الملف', 'File copy'], 'insurance' => ['نسخة التأمينات', 'Social insurance copy']];

    public static function cfg(): array { return Settings::merged('contracts'); }

    public static function ref(string $p): string
    {
        $y = now()->year;
        return "{$p}-{$y}-".str_pad((string) Counters::next(strtolower($p)."-{$y}"), 4, '0', STR_PAD_LEFT);
    }

    /** مدة النوع بالأشهر (0 = غير محدد) وفترة تجربته من خصائص الرمز، وإلا من الإعدادات */
    public static function typeMonths(?CodeItem $t): int { return (int) ($t?->attr('months') ?? self::cfg()['default_months']); }
    public static function typeProbation(?CodeItem $t): int { return (int) ($t?->attr('probation_months') ?? self::cfg()['probation_months']); }

    /** ينشئ عقداً بانتظار التواقيع (يدوياً، أو من قرار التجديد) */
    public function create(User $by, Employee $e, array $v, ?Contract $previous = null, ?ContractCase $case = null, string $source = 'manual'): Contract
    {
        $type = Codes::resolve('contract_types', $v['contract_type_id'] ?? $v['type'] ?? null);
        if (! $type) throw ApiException::field('contract_type_id', L::t('اختر نوع العقد من دليل الترميز.', 'Choose the contract type from the codebook.'), 'invalid');
        $start = Carbon::parse($v['starts_on'] ?? now()->toDateString())->startOfDay();
        $months = array_key_exists('months', $v) && $v['months'] !== null && $v['months'] !== '' ? (int) $v['months'] : self::typeMonths($type);
        if ($months < 0 || $months > 120) throw ApiException::field('months', L::t('المدة بين 0 و120 شهراً.', 'Length is between 0 and 120 months.'), 'invalid');
        $end = ! empty($v['ends_on']) ? Carbon::parse($v['ends_on']) : ($months ? $start->copy()->addMonthsNoOverflow($months)->subDay() : null);
        if ($end && $end->lt($start)) throw ApiException::field('ends_on', L::t('تاريخ النهاية قبل البداية.', 'The end date is before the start.'), 'invalid');
        $prob = array_key_exists('probation_months', $v) && $v['probation_months'] !== null ? (int) $v['probation_months'] : ($source === 'renewal' ? 0 : self::typeProbation($type));
        // BR-CR-10: لا يتداخل عقدان نافذان لشخص واحد
        $overlap = Contract::where('employee_id', $e->id)->whereIn('status', Contract::LIVE)->when($previous, fn ($q) => $q->whereKeyNot($previous->id))
            ->where('starts_on', '<=', $end?->toDateString() ?? '9999-12-31')->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $start->toDateString()))->first();
        if ($overlap) throw ApiException::field('starts_on', str_replace(':ref', $overlap->ref_no, L::t('يتداخل مع العقد النافذ :ref — حدّد بداية بعد نهايته.', 'Overlaps the live contract :ref — start after it ends.')), 'contract_overlap');
        $cfg = self::cfg();
        return DB::transaction(function () use ($by, $e, $v, $type, $start, $end, $prob, $previous, $case, $source, $cfg) {
            $c = Contract::create(['employee_id' => $e->id, 'ref_no' => self::ref('CTR'), 'contract_type_id' => $type->id, 'starts_on' => $start->toDateString(), 'ends_on' => $end?->toDateString(),
                'probation_months' => $prob, 'probation_ends_on' => $prob ? $start->copy()->addMonthsNoOverflow($prob)->subDay()->toDateString() : null, 'status' => 'pending_signatures', 'source' => $source,
                'previous_id' => $previous?->id, 'case_id' => $case?->id, 'exception' => (bool) ($v['exception'] ?? false), 'exception_reason' => $v['exception_reason'] ?? null,
                'note' => trim((string) ($v['note'] ?? '')) ?: null, 'created_by' => $by->id,
                'signatures' => array_values(array_map(fn ($r) => ['role' => $r, 'user_id' => null, 'at' => null, 'file_id' => null], array_filter($cfg['signers'], fn ($r) => isset(self::SIGNERS[$r])))),
                'copies' => array_fill_keys(array_values(array_filter($cfg['copies'], fn ($k) => isset(self::COPIES[$k]))), ['status' => 'pending', 'at' => null])]);
            $c->update(['body' => self::render($c->load(['employee.department', 'employee.location', 'type']), 'ar')]);
            Codes::markUsed($type->id);
            $e->log("إعداد عقد {$c->ref_no} ({$type->name_ar}) بانتظار التواقيع", "Contract {$c->ref_no} ({$type->name_en}) prepared, awaiting signatures", 'info');
            if ($uid = $e->user()->value('id')) Notify::user($uid, "عقد عملك {$c->ref_no} جاهز لتوقيعك", "Your contract {$c->ref_no} is ready for your signature", 'contracts');
            Audit::record('contract.created', $c, null, ['type' => $type->code, 'starts_on' => $c->starts_on->toDateString(), 'ends_on' => $c->ends_on?->toDateString()]);
            return $c;
        });
    }

    /** نص العقد من قالب الإعدادات بحقول الدمج */
    public static function render(Contract $c, string $lang): string
    {
        $cfg = self::cfg(); $e = $c->employee; $t = $c->type;
        $en = $lang === 'en';
        $months = $c->ends_on ? (int) round($c->starts_on->diffInMonths($c->ends_on->copy()->addDay())) : 0;
        $vals = [':name' => $en ? $e->name_en : $e->name, ':code' => $e->code, ':national_id' => $e->national_id ?: '—', ':title' => $en ? $e->title_en : $e->title_ar,
            ':department' => $e->department ? ($en ? $e->department->name_en : $e->department->name_ar) : '—', ':branch' => $e->location ? ($en ? $e->location->name_en : $e->location->name_ar) : '—',
            ':type' => $t ? ($en ? $t->name_en : $t->name_ar) : '', ':company' => (string) Settings::company($en ? 'name_en' : 'name_ar'),
            ':start' => $c->starts_on->toDateString(), ':end_text' => $c->ends_on ? ($en ? ' to '.$c->ends_on->toDateString() : ' وتنتهي في '.$c->ends_on->toDateString()) : '',
            ':duration' => $months ? ($en ? "{$months} month(s)" : "{$months} شهراً") : ($en ? 'open-ended' : 'غير محددة'),
            ':probation' => $c->probation_months ? ($en ? "{$c->probation_months} month(s) until ".$c->probation_ends_on?->toDateString() : "{$c->probation_months} أشهر حتى ".$c->probation_ends_on?->toDateString()) : ($en ? 'none' : 'لا يوجد')];
        $tpl = (string) ($en ? $cfg['template_en'] : $cfg['template_ar']);
        return strtr($tpl, $vals);
    }

    /** هل يحق للمستخدم التوقيع بهذا الدور؟ */
    public static function canSign(User $u, Contract $c, string $role): bool
    {
        $u->loadMissing('roles');
        return match ($role) {
            'employee' => $u->employee_id === $c->employee_id,
            'hr_director' => $u->hasRole('hr_director', 'hr_officer') && Perm::can($u, 'contracts', 'edit'),
            default => $u->hasRole($role),
        } && ($role === 'employee' || $u->employee_id !== $c->employee_id);
    }

    /** توقيع الطرف: الموظف بلوح التوقيع، والبقية بتأكيد من حسابهم */
    public function sign(Contract $c, User $u, string $role, ?string $signature): Contract
    {
        if ($c->status !== 'pending_signatures') throw new ApiException(L::t('العقد ليس بانتظار التواقيع.', 'The contract is not awaiting signatures.'), 'not_pending', 409);
        $sigs = $c->signatures ?? [];
        $i = collect($sigs)->search(fn ($s) => $s['role'] === $role && ! $s['at']);
        if ($i === false) throw new ApiException(L::t('هذا التوقيع تم أو غير مطلوب.', 'This signature is done or not required.'), 'already_signed', 409);
        if (! self::canSign($u, $c, $role)) throw new ApiException(L::t('هذا التوقيع ليس من صلاحيتك.', 'This signature is not yours to give.'), 'forbidden', 403);
        $file = $role === 'employee' ? Signature::store($signature) : null;
        $sigs[$i] = ['role' => $role, 'user_id' => $u->id, 'at' => now()->toIso8601String(), 'file_id' => $file?->id];
        $c->update(['signatures' => $sigs]);
        $c->employee->log("توقيع العقد {$c->ref_no}: ".self::SIGNERS[$role][0]." ({$u->displayName('ar')})", "Contract {$c->ref_no} signed: ".self::SIGNERS[$role][1]." ({$u->displayName('en')})");
        if (collect($sigs)->every(fn ($s) => $s['at'])) $this->signed($c, $u);
        return $c->refresh();
    }

    /** رفع نسخة موقعة يدوياً (حسب الإعداد) = اكتمال التواقيع */
    public function uploadSigned(Contract $c, User $u, UploadedFile $file): Contract
    {
        if (! in_array(self::cfg()['sign_mode'], ['upload', 'both'], true)) throw new ApiException(L::t('رفع النسخة الموقعة غير مفعّل في الإعدادات.', 'Uploading a signed copy is off in the settings.'), 'upload_off', 409);
        if (! in_array($c->status, ['pending_signatures', 'signed', 'active'], true)) throw new ApiException(L::t('لا يمكن رفع نسخة لهذا العقد.', 'A copy cannot be uploaded for this contract.'), 'not_pending', 409);
        $doc = app(EmployeeDocs::class)->upload($c->employee, $file, ['type' => 'contract', 'title' => "عقد {$c->ref_no}"], $u);
        $c->update(['signed_file_id' => $doc->file_id]);
        if ($c->status === 'pending_signatures') {
            $c->update(['signatures' => array_map(fn ($s) => $s['at'] ? $s : $s + ['paper' => true, 'at' => now()->toIso8601String(), 'user_id' => $u->id], $c->signatures ?? [])]);
            $this->signed($c->refresh(), $u);
        }
        return $c->refresh();
    }

    private function signed(Contract $c, User $u): void
    {
        $c->update(['status' => 'signed', 'signed_at' => now()]);
        Audit::record('contract.signed', $c);
        $this->activate($c->refresh());
    }

    /** يفعّل العقد الموقّع حين يحل تاريخ بدايته، ويُغلق السابق «مجدَّد» ويحدّث ملف الموظف (POL-CR-09) */
    public function activate(Contract $c): bool
    {
        if ($c->status !== 'signed' || $c->starts_on->toDateString() > now()->toDateString()) return false;
        DB::transaction(function () use ($c) {
            if ($c->previous_id) Contract::whereKey($c->previous_id)->whereIn('status', ['active', 'expired_no_action'])->update(['status' => 'renewed', 'ended_at' => now()]);
            $c->update(['status' => 'active', 'activated_at' => now()]);
            $t = $c->type()->first();
            $c->employee()->update(['contract_type_id' => $t?->id, 'contract' => $t ? ($t->legacy_code ?: $t->code) : null]);
            $c->employee->log("بدء سريان العقد {$c->ref_no}".($c->ends_on ? " حتى {$c->ends_on->toDateString()}" : ' (غير محدد المدة)'), "Contract {$c->ref_no} in force".($c->ends_on ? " until {$c->ends_on->toDateString()}" : ' (open-ended)'), 'ok');
            if ($c->case_id && ($case = ContractCase::find($c->case_id)) && $case->status !== 'closed') {
                $case->update(['status' => 'closed', 'closed_at' => now()]);
                Audit::record('contract.renewed', $c, null, ['case' => $case->ref_no, 'outcome' => $case->outcome]);
            }
        });
        return true;
    }

    public function copy(Contract $c, string $key, string $status, User $u): Contract
    {
        $copies = $c->copies ?? [];
        if (! array_key_exists($key, $copies) || ! in_array($status, ['pending', 'done'], true)) throw ApiException::field('key', L::t('نسخة غير معروفة.', 'Unknown copy.'), 'invalid');
        $copies[$key] = ['status' => $status, 'at' => $status === 'done' ? now()->toIso8601String() : null, 'by' => $u->id];
        $c->update(['copies' => $copies]);
        return $c->refresh();
    }

    public function cancel(Contract $c, User $u, string $reason): Contract
    {
        if (! in_array($c->status, ['draft', 'pending_signatures'], true)) throw new ApiException(L::t('يُلغى العقد قبل اكتمال تواقيعه فقط.', 'A contract can be cancelled only before it is fully signed.'), 'cancel_locked', 409);
        if (mb_strlen(trim($reason)) < 5) throw ApiException::field('reason', L::t('اكتب سبب الإلغاء.', 'Write the reason.'), 'reason');
        $c->update(['status' => 'cancelled', 'note' => trim($reason)]);
        $c->employee->log("إلغاء العقد {$c->ref_no}: {$reason}", "Contract {$c->ref_no} cancelled: {$reason}", 'warn');
        return $c;
    }

    /** ملحق عقد: تعديل المسمى أو الدرجة بتاريخ سريان (يطبق فوراً إن حل التاريخ) */
    public function annex(Contract $c, User $u, array $v): ContractAnnex
    {
        if ($c->status !== 'active') throw new ApiException(L::t('الملحق على عقد نافذ فقط.', 'An annex applies to a live contract only.'), 'not_active', 409);
        $kind = in_array($v['kind'] ?? '', ['position', 'grade', 'other'], true) ? $v['kind'] : throw ApiException::field('kind', L::t('نوع الملحق غير معروف.', 'Unknown annex type.'), 'invalid');
        if (mb_strlen(trim((string) ($v['text'] ?? ''))) < 10) throw ApiException::field('text', L::t('اكتب نص الملحق (10 أحرف على الأقل).', 'Write the annex text (at least 10 characters).'), 'invalid');
        $changes = [];
        if ($kind === 'position') {
            $job = Codes::resolve('jobs', $v['job_id'] ?? null) ?? throw ApiException::field('job_id', L::t('اختر المسمى من الدليل.', 'Choose the job title from the codebook.'), 'invalid');
            $changes = ['job_id' => $job->id, 'title_ar' => $job->name_ar, 'title_en' => $job->name_en];
        }
        if ($kind === 'grade' || ! empty($v['grade_id'])) {
            $g = Codes::resolve('grades', $v['grade_id'] ?? null) ?? throw ApiException::field('grade_id', L::t('اختر الدرجة من الدليل.', 'Choose the grade from the codebook.'), 'invalid');
            $changes['grade_id'] = $g->id;
        }
        $on = Carbon::parse($v['effective_on'] ?? now())->toDateString();
        $a = ContractAnnex::create(['contract_id' => $c->id, 'employee_id' => $c->employee_id, 'ref_no' => self::ref('ANX'), 'kind' => $kind, 'effective_on' => $on, 'changes' => $changes ?: null,
            'text' => trim($v['text']), 'status' => 'pending', 'created_by' => $u->id]);
        $this->applyAnnex($a);
        return $a->refresh();
    }

    public function applyAnnex(ContractAnnex $a): void
    {
        if ($a->status !== 'pending' || $a->effective_on->toDateString() > now()->toDateString()) return;
        $e = $a->employee;
        $ch = array_intersect_key($a->changes ?? [], array_flip(['job_id', 'title_ar', 'title_en', 'grade_id']));
        if ($ch) { $e->update($ch); Codes::markUsed($ch['job_id'] ?? null, $ch['grade_id'] ?? null); }
        $a->update(['status' => 'applied', 'applied_at' => now()]);
        $e->log("ملحق عقد {$a->ref_no}: ".mb_substr($a->text, 0, 120), "Contract annex {$a->ref_no}: ".mb_substr($a->text, 0, 120), 'info');
    }

    /** رادار الانتهاء: العقود وفترات التجربة التي تنتهي خلال أكبر نافذة (FR-CR-002) */
    public static function radar(User $u, array $f = []): array
    {
        $cfg = self::cfg(); $windows = collect($cfg['radar_days'])->map(fn ($d) => (int) $d)->sort()->values()->all();
        $max = (int) (end($windows) ?: 90); $today = now()->toDateString(); $limit = now()->addDays($max)->toDateString();
        $ids = Directory::scoped($u, 'contracts')->current()
            ->when($f['dept'] ?? null, fn ($q, $d) => $q->where('department_id', $d))->when($f['branch'] ?? null, fn ($q, $b) => $q->where('location_id', $b))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('worker_category_id', $c))->pluck('id');
        $rows = [];
        $open = ContractCase::whereIn('employee_id', $ids)->where('status', '!=', 'closed')->get()->keyBy(fn ($k) => "{$k->kind}:{$k->contract_id}");
        $cs = Contract::with(['employee.department', 'type'])->whereIn('employee_id', $ids)->where('status', 'active')
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('contract_type_id', $t))->get();
        foreach ($cs as $c) {
            foreach ([['renewal', $c->ends_on], ['probation', $c->probation_ends_on]] as [$kind, $d]) {
                if (! $d || $d->toDateString() > $limit) continue;
                if ($kind === 'probation' && $c->employee->status !== 'probation') continue;
                $left = (int) now()->startOfDay()->diffInDays($d, false);
                $bucket = collect($windows)->first(fn ($w) => $left <= $w) ?? $max;
                $case = $open["{$kind}:{$c->id}"] ?? null;
                $rows[] = ['kind' => $kind, 'contract' => ['id' => $c->id, 'ref_no' => $c->ref_no, 'type' => $c->type ? ['code' => $c->type->code, 'ar' => $c->type->name_ar, 'en' => $c->type->name_en] : null],
                    'employee' => ['id' => $c->employee->id, 'code' => $c->employee->code, 'ar' => $c->employee->name, 'en' => $c->employee->name_en,
                        'dept' => $c->employee->department ? ['ar' => $c->employee->department->name_ar, 'en' => $c->employee->department->name_en] : null],
                    'date' => $d->toDateString(), 'left' => $left, 'bucket' => $left < 0 ? 0 : $bucket, 'overdue' => $d->toDateString() < $today,
                    'case' => $case ? ['id' => $case->id, 'ref_no' => $case->ref_no, 'status' => $case->status] : null];
            }
        }
        usort($rows, fn ($a, $b) => $a['left'] <=> $b['left']);
        return ['windows' => $windows, 'rows' => $rows];
    }

    /** عدد السنوات التعاقدية المتواصلة (العقود المتلاحقة دون انقطاع، وإلا من تاريخ التعيين) */
    public static function continuousYears(Employee $e, ?Contract $upTo = null): float
    {
        $chain = Contract::where('employee_id', $e->id)->whereIn('status', ['active', 'renewed', 'expired_no_action', 'signed'])->orderByDesc('starts_on')->get();
        $start = null; $prev = null;
        foreach ($chain as $c) {
            if ($prev && $c->ends_on && $c->ends_on->copy()->addDays(2)->lt($prev->starts_on)) break;
            $start = $c->starts_on; $prev = $c;
        }
        // العقد المسجّل من البيانات السابقة يبدأ منتصف الخدمة: الخدمة المتواصلة من تاريخ التعيين
        if (! $start || $prev?->source === 'imported') $start = $e->hire_date ?? $start;
        $end = $upTo?->ends_on ?? now();
        return $start ? round($start->diffInDays($end) / 365.25, 1) : 0.0;
    }

    public static function row(Contract $c, ?User $u = null): array
    {
        $c->loadMissing(['employee', 'type']);
        $sig = array_map(fn ($s) => $s + ['ar' => self::SIGNERS[$s['role']][0] ?? $s['role'], 'en' => self::SIGNERS[$s['role']][1] ?? $s['role'],
            'can' => $u && ! $s['at'] && $c->status === 'pending_signatures' && self::canSign($u, $c, $s['role'])], $c->signatures ?? []);
        return ['id' => $c->id, 'ref_no' => $c->ref_no, 'status' => $c->status, 'source' => $c->source, 'starts_on' => $c->starts_on->toDateString(), 'ends_on' => $c->ends_on?->toDateString(),
            'probation_months' => $c->probation_months, 'probation_ends_on' => $c->probation_ends_on?->toDateString(), 'exception' => $c->exception, 'exception_reason' => $c->exception_reason,
            'type' => $c->type ? ['id' => $c->type->id, 'code' => $c->type->code, 'ar' => $c->type->name_ar, 'en' => $c->type->name_en] : null,
            'employee' => ['id' => $c->employee->id, 'code' => $c->employee->code, 'ar' => $c->employee->name, 'en' => $c->employee->name_en],
            'signatures' => $sig, 'copies' => collect($c->copies ?? [])->map(fn ($x, $k) => $x + ['key' => $k, 'ar' => self::COPIES[$k][0] ?? $k, 'en' => self::COPIES[$k][1] ?? $k])->values(),
            'signed_file_id' => $c->signed_file_id, 'previous_id' => $c->previous_id, 'case_id' => $c->case_id, 'note' => $c->note,
            'signed_at' => $c->signed_at?->toIso8601String(), 'activated_at' => $c->activated_at?->toIso8601String()];
    }

    /** عقد الموظف الجديد: من المعالج أو قرار التعيين بانتظار التواقيع، ومن الاستيراد نافذاً مباشرة */
    public static function forNewEmployee(Employee $e, User $by, bool $imported = false): ?Contract
    {
        if (Contract::where('employee_id', $e->id)->exists()) return null;
        $t = $e->contract_type_id ? CodeItem::find($e->contract_type_id) : Codes::resolve('contract_types', self::cfg()['fixed_type']);
        if (! $t || ! $e->hire_date) return null;
        if ($imported) return self::backfillOne($e->loadMissing('onboarding'), $t);
        return app(self::class)->create($by, $e, ['contract_type_id' => $t->id, 'starts_on' => $e->hire_date->toDateString()]);
    }

    /** العقد الأول لكل موظف حالي من تاريخ تعيينه ونوع عقده (يُستدعى في الترحيل وبيانات التجربة) */
    public static function backfill(): void
    {
        $fixed = Codes::resolve('contract_types', self::cfg()['fixed_type']);
        foreach (Employee::with(['contractType', 'onboarding'])->where('status', '!=', 'terminated')->get() as $e)
            if ($e->hire_date && ! Contract::where('employee_id', $e->id)->exists()) self::backfillOne($e, $e->contractType ?? $fixed);
    }

    /** عقد نافذ واحد: الفترة الجارية من تاريخ التعيين بمدة النوع، وفترة التجربة لمن ما زال فيها */
    public static function backfillOne(Employee $e, ?CodeItem $t): Contract
    {
        $months = self::typeMonths($t);
        $start = $e->hire_date->copy();
        if ($months > 0) while ($start->copy()->addMonthsNoOverflow($months)->lte(now()->startOfDay())) $start->addMonthsNoOverflow($months);
        $probEnd = in_array($e->status, ['probation', 'onboarding'], true) ? OnboardingFlow::probationEnd($e, $e->onboarding) : null;
        return Contract::create(['employee_id' => $e->id, 'ref_no' => self::ref('CTR'), 'contract_type_id' => $t?->id, 'starts_on' => $start->toDateString(),
            'ends_on' => $months ? $start->copy()->addMonthsNoOverflow($months)->subDay()->toDateString() : null,
            'probation_months' => $probEnd ? self::typeProbation($t) : 0, 'probation_ends_on' => $probEnd, 'status' => 'active', 'source' => 'imported',
            'signatures' => [], 'copies' => [], 'activated_at' => now(), 'note' => 'سُجّل تلقائياً من تاريخ التعيين']);
    }

    /** المهام اليومية: تفعيل العقود الموقعة، تطبيق الملاحق، فتح الحالات والتذكير والتصعيد (contracts:sweep) */
    public function sweep(): array
    {
        $n = ['activated' => 0, 'annexes' => 0];
        foreach (Contract::where('status', 'signed')->whereDate('starts_on', '<=', now()->toDateString())->get() as $c) if ($this->activate($c)) $n['activated']++;
        foreach (ContractAnnex::with('employee')->where('status', 'pending')->whereDate('effective_on', '<=', now()->toDateString())->get() as $a) { $this->applyAnnex($a); $n['annexes']++; }
        return $n + app(ContractCases::class)->sweep();
    }

    public static function tenantId(): ?int { return Tenant::id(); }
}
