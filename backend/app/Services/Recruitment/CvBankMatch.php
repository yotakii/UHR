<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\User;
use App\Notifications\CandidateMessage;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Collection;

/**
 * مطابقة بنك السير الذاتية مع شاغر جديد (ملاحظة الاختبار 46): السير المحفوظة بموافقة أصحابها (keep_cv) في قاعدة الشركة
 * تُقاس على متطلبات الشاغر بنفس معادلة المطابقة، وتُعرض الأعلى مع دعوة للتقديم بالبريد. لا يُنشأ طلب ولا يُنقل أحد تلقائياً.
 */
class CvBankMatch
{
    /** السير المطابقة لشاغر: آخر طلب لكل شخص (بالبريد)، غير المحذوفة، ولم يتقدم لهذا الشاغر، ونسبتها ≥ $min */
    public function forJob(JobOpening $job, ?int $min = null, int $limit = 30): Collection
    {
        $min ??= (int) Settings::rule('review_min');
        $applied = Candidate::where('job_opening_id', $job->id)->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->flip();
        $invited = $this->invited($job);
        $cfg = MatchScore::config($job);
        return Candidate::with('jobOpening')->where('keep_cv', true)->where('cv_status', 'active')->where('job_opening_id', '!=', $job->id)
            ->whereNotIn('stage', ['hired'])->whereNull('internal_employee_id')->latest('created_at')->limit(1500)->get()
            ->unique(fn ($c) => mb_strtolower((string) $c->email))
            ->reject(fn ($c) => $applied->has(mb_strtolower((string) $c->email)))
            ->map(fn (Candidate $c) => ['c' => $c, 'score' => MatchScore::total($c, $job, $cfg)])
            ->filter(fn ($x) => $x['score'] >= $min)->sortByDesc('score')->take($limit)
            ->map(fn ($x) => ['id' => $x['c']->id, 'ref' => $x['c']->ref, 'name' => ['ar' => $x['c']->name, 'en' => $x['c']->name_en ?: $x['c']->name], 'score' => $x['score'],
                'years' => $x['c']->years_exp, 'degree' => $x['c']->degree_level, 'major' => collect($x['c']->education ?? [])->pluck('major')->filter()->first(),
                'last_job' => ['ar' => $x['c']->jobOpening?->title_ar, 'en' => $x['c']->jobOpening?->title_en], 'applied_at' => $x['c']->created_at?->toDateString(),
                'stage' => $x['c']->stage, 'invited' => $invited->has($x['c']->id)])
            ->values();
    }

    /** دعوة صاحب السيرة للتقديم على الشاغر (مرة واحدة لكل شاغر) — رابط صفحة التقديم */
    public function invite(JobOpening $job, Candidate $c, User $by): void
    {
        if (! $c->keep_cv || $c->cv_status !== 'active') throw new ApiException(L::t('هذه السيرة غير متاحة للتواصل.', 'This CV is not available for contact.'), 'cv_unavailable', 409);
        if ($this->invited($job)->has($c->id)) throw new ApiException(L::t('أُرسلت الدعوة لهذا الشخص سابقاً.', 'This person was already invited.'), 'already_invited', 409);
        if (! $job->acceptingApplications()) throw new ApiException(L::t('الشاغر لا يستقبل طلبات الآن.', 'This job is not accepting applications now.'), 'job_closed', 409);
        $co = $job->company()->first();
        \Illuminate\Support\Facades\Notification::route('mail', $c->email)->notify(new CandidateMessage('job_invite', [
            'ar' => ['فرصة عمل قد تناسبك', 'لدى '.$co->name_ar.' شاغر جديد قد يناسب خبرتك: :job.', 'سيرتك محفوظة لدينا بموافقتك. إن رغبت بالتقدم فاضغط الزر وأكمل الطلب.', 'التقديم على الوظيفة'],
            'en' => ['A job that may suit you', $co->name_en.' has a new opening that may fit your experience: :job.', 'Your CV is kept with your consent. If you are interested, use the button to apply.', 'Apply for the job']],
            $job->title_ar, $job->title_en, $c->ref, ['ar' => $co->name_ar, 'en' => $co->name_en], "/apply/{$co->slug}/".rawurlencode($job->code)));
        $c->log("دعوة للتقديم على شاغر {$job->code} — {$job->title_ar}", "Invited to apply for {$job->code}", 'info');
        Audit::record('cv.job_invite', $c, null, ['job' => $job->id, 'code' => $job->code, 'by' => $by->id]);
    }

    /** تنبيه الموارد البشرية عند نشر شاغر جديد بعدد السير المطابقة في القاعدة */
    public function announce(JobOpening $job): int
    {
        $n = $this->forJob($job, (int) Settings::rule('match_threshold'), 200)->count();
        if ($n > 0) Notify::hr("{$n} سيرة ذاتية في قاعدة الشركة تطابق الشاغر الجديد «{$job->title_ar}»", "{$n} CVs in the company database match the new job “{$job->title_en}”", "job-matches:{$job->id}");
        return $n;
    }

    private function invited(JobOpening $job): Collection
    {
        return AuditLog::where('action', 'cv.job_invite')->where('after->job', $job->id)->pluck('subject_id')->flip();
    }
}
