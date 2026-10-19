<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\Referral;
use App\Models\Role;
use App\Models\StoredFile;
use App\Models\User;
use App\Notifications\ApplicationClosed;
use App\Notifications\ApplicationReceived;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * استلام طلب التوظيف F-28 (submitApplication في الديمو) على شاغر شركة:
 * حساب المتقدم (على المنصة)، الطلب الجديد أو الإلحاق بالسابق داخل الشركة، الإحالة، بوابة المطابقة بنطاقاتها،
 * إشعار HR، وبريد التأكيد. كل الكتابة تتم داخل شركة الشاغر (Tenant).
 */
class ApplicationService
{
    /** يرفض مسبقاً ما لا يمكن قبوله — نفس الفحص يعمل بعد الخطوة 1 في المعالج */
    public static function guard(DuplicateCheck $dup): void
    {
        if ($dup->staffOfThisCompany()) {
            throw ApiException::field('email', L::t('هذا البريد مرتبط بحساب موظف في هذه الشركة. قدّم طلباً داخلياً من بوابة الموظف.', 'This email belongs to an employee of this company. Apply internally from the employee portal.'), 'staff_email');
        }
        if ($dup->staff()) {
            throw ApiException::field('email', L::t('هذا البريد مرتبط بحساب عمل على المنصة. استخدم بريدك الشخصي للتقديم.', 'This email belongs to a work account on the platform. Use your personal email to apply.'), 'staff_email');
        }
        if ($dup->ownedByAnother()) {
            throw ApiException::field('national_id', L::t('يوجد طلب سابق بهذا الرقم الوطني مرتبط بحساب آخر. سجّل الدخول بذلك الحساب للمتابعة.', 'A previous application with this national ID belongs to another account. Sign in with that account to continue.'), 'nid_taken');
        }
        if ($dup->locked()) {
            $ref = $dup->candidate->ref;
            throw new ApiException(L::t("لديك طلب قيد المعالجة ({$ref}) في مرحلة متقدمة لدى هذه الشركة. سجّل الدخول لمتابعته.", "You have an application in progress ({$ref}) at an advanced stage with this company. Sign in to follow it."), 'application_in_progress');
        }
    }

    public function submit(array $data, DuplicateCheck $dup, ?UploadedFile $cv, ?UploadedFile $photo = null): Candidate
    {
        self::guard($dup);
        if ($dup->needsPassword() && ! Hash::check((string) ($data['password'] ?? ''), $dup->account->password)) {
            throw ApiException::field('password', L::t('لديك حساب سابق بهذا البريد. أدخل كلمة مرور حسابك، أو سجّل الدخول ثم قدّم، أو استخدم «نسيت كلمة المرور».', 'You already have an account with this email. Enter its password, sign in first, or use "Forgot your password?".'), 'existing_account');
        }

        $job = $dup->job;
        return Tenant::run($job->company_id, function () use ($data, $dup, $job, $cv, $photo) {
            // الملفات خارج المعاملة: لا نحجز القاعدة أثناء الكتابة على القرص
            $files = array_filter(['cv_file_id' => $cv ? $this->store($cv, 'cv') : null, 'photo_file_id' => $photo ? $this->store($photo, 'photos') : null]);
            try {
                $cand = DB::transaction(function () use ($data, $dup, $job, $files) {
                    $account = $dup->account ?? $this->createAccount($data);
                    $attrs = $this->attributes($data, $job) + array_map(fn ($f) => $f->id, $files)
                        + ['user_id' => $account->id, 'consent_at' => now(), 'keep_cv' => (bool) ($data['keep_cv'] ?? false)];
                    $cand = $dup->candidate ? $this->merge($dup->candidate, $attrs, $job) : $this->create($attrs);
                    // المرشح الموجود مسبقاً لا ينشئ إحالة جديدة (منع التحايل — D-04)
                    if (! $dup->candidate && $data['source'] === 'referral' && ! empty($data['ref_code'])) $this->linkReferral($cand, $data['ref_code'], $account);
                    $this->automationGate($cand, $job);
                    Notify::hr("طلب توظيف جديد من {$cand->name} على وظيفة {$job->title_ar}", 'New application from '.($cand->name_en ?: $cand->name)." for {$job->title_en}", "cand:{$cand->id}");
                    return $cand;
                });
            } catch (\Throwable $e) {
                foreach ($files as $f) { Storage::disk($f->disk)->delete($f->path); $f->delete(); }
                throw $e;
            }

            $cand->load(['user', 'jobOpening.company']);
            $co = $job->company;
            $cand->user->notify((new ApplicationReceived($cand->ref, $job->title_ar, $job->title_en, $co->name_ar, $co->name_en))->afterCommit());
            if ($cand->stage === 'rejected') $cand->user->notify((new ApplicationClosed($job->title_ar, $job->title_en, $co->name_ar, $co->name_en))->afterCommit());
            else $this->alertManagers($cand, $job);
            app(PreliminaryTests::class)->afterApplication($cand);
            return $cand;
        });
    }

    /** تنبيه مدير الإدارة بوصول سيرة لشاغر مفتوح في إدارته (D-02) — بلا بيانات شخصية في الإشعار */
    private function alertManagers(Candidate $cand, JobOpening $job): void
    {
        $ids = User::where('company_id', $job->company_id)->where('active', true)->whereHas('roles', fn ($r) => $r->where('key', 'manager'))
            ->whereHas('employee', fn ($e) => $e->where('department_id', $job->department_id))->pluck('id');
        foreach ($ids as $id) Notify::user($id, "وصلت سيرة ذاتية جديدة لشاغر {$job->title_ar} في إدارتك", "A new CV arrived for {$job->title_en} in your department");
    }

    private function createAccount(array $d): User
    {
        $user = User::create(['email' => $d['email'], 'password' => $d['password'], 'name' => trim($d['name']), 'name_en' => trim((string) ($d['name_en'] ?? '')) ?: null,
            'company_id' => null, 'locale' => app()->getLocale()]);
        $user->roles()->attach(Role::where('key', 'candidate')->value('id'));
        return $user;
    }

    private function attributes(array $d, JobOpening $job): array
    {
        $exp = array_values(array_filter($d['experience'] ?? [], fn ($x) => trim((string) ($x['company'] ?? '')) !== ''));
        return [
            'job_opening_id' => $job->id, 'stage' => 'new', 'stage_at' => now()->toDateString(), 'auto_flag' => null, 'match_band' => null, 'reject_reason' => null,
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

    /** طلب مكرر: يُحدَّث الملف ويعود لمرحلة «جديد» — الملفات السابقة تبقى إن لم تُرفع جديدة */
    private function merge(Candidate $cand, array $attrs, JobOpening $job): Candidate
    {
        $cand->update($attrs);
        $cand->log("طلب جديد على وظيفة {$job->title_ar} — أُلحق بالملف السابق (نفس الرقم الوطني/البريد)", "New application for {$job->title_en} merged into existing file (same national ID/email)", 'warn', 'F-28');
        return $cand;
    }

    private function linkReferral(Candidate $cand, string $code, User $account): void
    {
        $ref = Referral::with('employee.user')->whereRaw('upper(code) = ?', [strtoupper($code)])->first();
        if (! $ref) return;
        $e = $ref->employee;
        // المُحيل لا يكون هو نفسه المتقدم
        if ($e->user?->id === $account->id || strtolower($e->email) === strtolower($cand->email)) return;
        $cand->update(['referrer_id' => $ref->employee_id, 'ref_code' => $ref->code]);
        if (! $ref->candidate_id || $ref->candidate_id === $cand->id) $ref->update(['candidate_id' => $cand->id, 'status' => 'applied']);
        $cand->log("مرشّح عبر رابط إحالة {$e->name}", "Referred via {$e->name_en}'s link");
        Notify::user($e->user?->id, "{$cand->name} قدّم عبر رابط إحالتك", ($cand->name_en ?: $cand->name).' applied through your referral link', 'referrals');
    }

    /**
     * بوابة المطابقة بنطاقاتها (SRS §10.9):
     * ≥ الحد ← فرز تلقائي؛ ≤ حد الرفض (إذا مفعّل) ← رفض تلقائي بإشعار مهذب؛ وما بينهما ← «مطابقة منخفضة — للمراجعة».
     */
    private function automationGate(Candidate $cand, JobOpening $job): void
    {
        $m = MatchScore::total($cand, $job);
        $band = MatchBands::of($m);
        $cand->match_score = $m;
        $cand->match_band = $band;
        $t = (int) Settings::rule('match_threshold');
        if ($band === MatchBands::NORMAL) {
            if (Settings::rule('auto_screen')) {
                $cand->fill(['stage' => 'screening', 'auto_flag' => 'passed']);
                $cand->log("اجتاز حد المطابقة ({$m}٪ ≥ {$t}٪) — نُقل تلقائياً للفرز", "Passed match gate ({$m}% ≥ {$t}%) — moved to screening", 'ok');
            }
        } elseif ($band === MatchBands::REJECT) {
            $cand->fill(['stage' => 'rejected', 'auto_flag' => 'below', 'reject_reason' => 'المطابقة دون الحد الأدنى']);
            $cand->log("المطابقة {$m}٪ دون الحد الأدنى — رفض تلقائي وإشعار المتقدم", "Match {$m}% below the minimum — auto-declined, applicant notified", 'danger');
        } else {
            $cand->auto_flag = 'below';
            $cand->log("مطابقة منخفضة ({$m}٪) — للمراجعة من مسؤول التوظيف", "Low match ({$m}%) — for recruiter review", 'warn');
        }
        $cand->save();
    }

    private function store(UploadedFile $file, string $dir): StoredFile
    {
        $path = $file->store('companies/'.Tenant::id()."/{$dir}/".now()->format('Y/m'), 'local');
        return StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 255), 'size' => $file->getSize(), 'mime' => $file->getMimeType()]);
    }
}
