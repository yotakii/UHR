<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\Referral;
use App\Models\Role;
use App\Models\StoredFile;
use App\Models\User;
use App\Notifications\ApplicationReceived;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * استلام طلب التوظيف F-28 (submitApplication في الديمو):
 * إنشاء الملف وحساب الدخول أو الإلحاق بالملف السابق، ربط الإحالة، بوابة الأتمتة، إشعار HR، وبريد التأكيد.
 */
class ApplicationService
{
    /** يرفض مسبقاً ما لا يمكن قبوله — نفس الفحص يعمل بعد الخطوة 1 في المعالج */
    public static function guard(DuplicateCheck $dup): void
    {
        if ($dup->emailBelongsToStaff()) {
            throw ApiException::field('email', L::t('هذا البريد مرتبط بحساب موظف. قدّم طلباً داخلياً من بوابة الموظف.', 'This email belongs to an employee account. Apply internally from the employee portal.'), 'staff_email');
        }
        if ($dup->emailTaken()) {
            throw ApiException::field('email', L::t('هذا البريد مستخدم لحساب آخر. استخدم بريدك المسجّل في طلبك السابق.', 'This email is used by another account. Use the email of your previous application.'), 'email_taken');
        }
        if ($dup->locked()) {
            $ref = $dup->candidate->ref;
            throw new ApiException(L::t("لديك طلب قيد المعالجة ({$ref}) في مرحلة متقدمة. سجّل الدخول لمتابعته.", "You have an application in progress ({$ref}) at an advanced stage. Sign in to follow it."), 'application_in_progress');
        }
    }

    public function submit(array $data, DuplicateCheck $dup, ?UploadedFile $cv): Candidate
    {
        self::guard($dup);
        if ($dup->account && ! Hash::check($data['password'], $dup->account->password)) {
            throw ApiException::field('password', L::t('لديك حساب سابق. أدخل كلمة مرور حسابك الحالية، أو استخدم «نسيت كلمة المرور».', 'You already have an account. Enter your current password, or use "Forgot your password?".'), 'existing_account');
        }

        $job = JobOpening::where('code', $data['job'])->where('status', 'open')->firstOrFail();
        $storedCv = $cv ? $this->storeCv($cv) : null;   // خارج المعاملة: لا نحجز القاعدة أثناء الكتابة على القرص

        try {
            $cand = DB::transaction(function () use ($data, $dup, $job, $storedCv) {
                $attrs = $this->attributes($data, $job) + ($storedCv ? ['cv_file_id' => $storedCv->id] : []);
                $cand = $dup->candidate ? $this->merge($dup->candidate, $attrs, $job) : $this->create($attrs);
                $this->ensureAccount($cand, $dup->account, $data);
                if ($data['source'] === 'referral' && ! empty($data['ref_code'])) $this->linkReferral($cand, $data['ref_code']);
                $this->automationGate($cand, $job);
                Notify::hr("طلب توظيف جديد من {$cand->name} على وظيفة {$job->title_ar}", "New application from ".($cand->name_en ?: $cand->name)." for {$job->title_en}", "cand:{$cand->id}");
                return $cand;
            });
        } catch (\Throwable $e) {
            if ($storedCv) { Storage::disk($storedCv->disk)->delete($storedCv->path); $storedCv->delete(); }
            throw $e;
        }

        $cand->load('user')->user->notify((new ApplicationReceived($cand->ref, $job->title_ar, $job->title_en))->afterCommit());
        return $cand->load('jobOpening');
    }

    private function attributes(array $d, JobOpening $job): array
    {
        $exp = array_values(array_filter($d['experience'] ?? [], fn ($x) => trim((string) ($x['company'] ?? '')) !== ''));
        return [
            'job_opening_id' => $job->id, 'stage' => 'new', 'stage_at' => now()->toDateString(), 'auto_flag' => null, 'reject_reason' => null,
            'name' => trim($d['name']), 'name_en' => trim((string) ($d['name_en'] ?? '')) ?: null, 'gender' => $d['gender'], 'marital' => $d['marital'],
            'nationality' => $d['nationality'], 'dob' => $d['dob'], 'pob' => $d['pob'] ?? null, 'national_id' => $d['national_id'], 'blood' => $d['blood'] ?? null,
            'email' => $d['email'], 'mobile' => trim($d['mobile']), 'address' => $d['address'], 'linkedin' => $d['linkedin'] ?? null,
            'education' => [['phase' => MatchScore::DEGREES[(int) $d['degree']]['ar'], 'institute' => $d['institute'], 'major' => $d['major'], 'year' => (string) ($d['grad_year'] ?? ''), 'grade' => (string) ($d['grade'] ?? '')]],
            'experience' => array_map(fn ($x) => ['company' => $x['company'], 'position' => $x['position'] ?? '', 'from' => (string) ($x['from'] ?? ''), 'to' => (string) ($x['to'] ?? ''), 'tasks' => '', 'reason' => $x['reason'] ?? ''], $exp),
            'refs' => ! empty($d['ref_name']) ? [['name' => $d['ref_name'], 'job' => $d['ref_job'] ?? '', 'phone' => $d['ref_phone'] ?? '']] : [],
            'english' => $d['english'], 'shifts' => (bool) $d['shifts'], 'travel' => (bool) $d['travel'], 'public_sector' => (bool) $d['public_sector'], 'conviction' => (bool) $d['conviction'],
            'salary' => $d['salary'], 'availability' => $d['availability'] ?? null, 'source' => $d['source'],
            'years_exp' => (int) $d['years_exp'], 'degree_level' => (int) $d['degree'],
        ];
    }

    private function create(array $attrs): Candidate
    {
        $cand = Candidate::create($attrs + ['ref' => Counters::candidateRef()]);
        $cand->log('استلام طلب التوظيف عبر البوابة', 'Application received via portal', 'info', 'F-28');
        $cand->log('إرسال تأكيد الاستلام برقم المتابعة إلى البريد', 'Confirmation with reference sent by email');
        return $cand;
    }

    /** طلب مكرر: يُحدَّث الملف ويعود لمرحلة «جديد» — السيرة السابقة تبقى إن لم تُرفع جديدة */
    private function merge(Candidate $cand, array $attrs, JobOpening $job): Candidate
    {
        $cand->update($attrs);
        $cand->log("طلب جديد على وظيفة {$job->title_ar} — أُلحق بالملف السابق (نفس الرقم الوطني/البريد)", "New application for {$job->title_en} merged into existing file (same national ID/email)", 'warn', 'F-28');
        return $cand;
    }

    private function ensureAccount(Candidate $cand, ?User $account, array $d): void
    {
        if ($account) {
            if ($account->email !== $cand->email) $account->update(['email' => $cand->email]);
            return;
        }
        $user = User::create(['email' => $cand->email, 'password' => $d['password'], 'name' => $cand->name, 'candidate_id' => $cand->id, 'locale' => app()->getLocale()]);
        $user->roles()->attach(Role::where('key', 'candidate')->value('id'));
    }

    private function linkReferral(Candidate $cand, string $code): void
    {
        $ref = Referral::with('employee.user')->whereRaw('upper(code) = ?', [strtoupper($code)])->first();
        if (! $ref) return;
        $cand->update(['referrer_id' => $ref->employee_id, 'ref_code' => $ref->code]);
        if (! $ref->candidate_id || $ref->candidate_id === $cand->id) $ref->update(['candidate_id' => $cand->id, 'status' => 'applied']);
        $e = $ref->employee;
        $cand->log("مرشّح عبر رابط إحالة {$e->name}", "Referred via {$e->name_en}'s link");
        Notify::user($e->user?->id, "{$cand->name} قدّم عبر رابط إحالتك", ($cand->name_en ?: $cand->name).' applied through your referral link', 'referrals');
    }

    /** بوابة الأتمتة (M03): مطابقة ≥ الحد ← «فرز أولي»، وإلا يبقى «جديد» بانتظار مراجعة يدوية */
    private function automationGate(Candidate $cand, JobOpening $job): void
    {
        $m = MatchScore::total($cand, $job);
        $cand->match_score = $m;
        if (Settings::rule('auto_screen')) {
            $t = (int) Settings::rule('match_threshold');
            if ($m >= $t) {
                $cand->fill(['stage' => 'screening', 'auto_flag' => 'passed']);
                $cand->log("اجتاز حد المطابقة ({$m}٪ ≥ {$t}٪) — نُقل تلقائياً للفرز", "Passed match gate ({$m}% ≥ {$t}%) — moved to screening", 'ok');
            } else {
                $cand->auto_flag = 'below';
                $cand->log("دون حد المطابقة ({$m}٪ < {$t}٪) — بانتظار مراجعة يدوية", "Below match gate ({$m}% < {$t}%) — manual review", 'warn');
            }
        }
        $cand->save();
    }

    private function storeCv(UploadedFile $file): StoredFile
    {
        $path = $file->store('cv/'.now()->format('Y/m'), 'local');
        return StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 255), 'size' => $file->getSize(), 'mime' => $file->getMimeType()]);
    }
}
