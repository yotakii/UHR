<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\Candidate;
use App\Models\Interview;
use App\Models\Offer;
use App\Models\User;
use App\Notifications\ApplicationClosed;
use App\Notifications\CandidateMessage;
use App\Services\Referrals\ReferralPoints;
use App\Support\L;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;

/**
 * مسار التوظيف (M03/M04) — moveStage وaddNote وsaveInterview وsendOffer من actions.ts بقواعد الإنتاج:
 * لا نقل بعد فتح قرار التعيين (إلا عدم القبول)، التعيين عبر القرار فقط، «عرض العمل» يحتاج عرضاً صادراً،
 * وعدم القبول بسبب إلزامي (SRS §10.1) يصل للمتقدم بإشعار مهذب.
 */
class Pipeline
{
    public function __construct(private ReferralPoints $points) {}

    public const STAGES = ['new' => ['طلبات جديدة', 'New'], 'screening' => ['فرز أولي', 'Screening'], 'interview' => ['المقابلة الأولية', 'HR interview'],
        'technical' => ['التقييم الفني', 'Technical review'], 'offer' => ['عرض العمل', 'Offer'], 'hired' => ['تم التعيين', 'Hired'], 'rejected' => ['غير مقبول', 'Not selected']];

    public function move(Candidate $c, string $to, ?string $reason, User $by): Candidate
    {
        if ($c->stage === $to) return $c;
        $c->loadMissing(['offer', 'appointment', 'jobOpening', 'user']);
        if ($to === 'hired') throw new ApiException(L::t('التعيين يتم عبر قرار التعيين بعد قبول العرض.', 'Hiring happens through the appointment decision after the offer is accepted.'), 'hire_via_decision');
        if ($c->appointment && $to !== 'rejected') throw new ApiException(L::t('لا يمكن نقل المتقدم بعد قبول العرض — أكمل قرار التعيين.', 'The applicant cannot be moved after accepting the offer — complete the appointment decision.'), 'locked_after_offer');
        if ($c->stage === 'hired') throw new ApiException(L::t('هذا المتقدم تم تعيينه.', 'This applicant has been hired.'), 'already_hired');
        if ($to === 'offer' && ! $c->offer) throw new ApiException(L::t('أصدر عرض العمل أولاً من تبويب «عرض العمل».', 'Issue the job offer first from the Offer tab.'), 'offer_required');
        if ($to === 'rejected' && mb_strlen(trim((string) $reason)) < 10) throw ApiException::field('reason', L::t('اكتب سبب عدم القبول (10 أحرف على الأقل).', 'Write the reason for not selecting (at least 10 characters).'), 'reason_required');

        [$fa, $fe] = self::STAGES[$c->stage]; [$ta, $te] = self::STAGES[$to];
        DB::transaction(function () use ($c, $to, $reason, $fa, $fe, $ta, $te, $by) {
            $c->update(['stage' => $to, 'stage_at' => now()->toDateString(), 'reject_reason' => $to === 'rejected' ? trim($reason) : $c->reject_reason]);
            $r = $to === 'rejected' ? ' — '.trim($reason) : '';
            $c->log("نقل من «{$fa}» إلى «{$ta}»{$r} ({$by->displayName('ar')})", "Moved from \"{$fe}\" to \"{$te}\"{$r} ({$by->displayName('en')})", $to === 'rejected' ? 'danger' : 'info');
            $this->points->sync($c);
        });

        $job = $c->jobOpening; $co = $job->company()->first();
        if ($to === 'rejected' && $c->user) {
            $c->user->notify((new ApplicationClosed($job->title_ar, $job->title_en, $co->name_ar, $co->name_en))->afterCommit());
            Notify::user($c->user_id, "انتهت مراجعة طلبك على وظيفة {$job->title_ar}", "Your application for {$job->title_en} has been reviewed", "my:{$c->id}");
        }
        if ($to === 'interview' && $c->user) {
            $c->user->notify(new CandidateMessage('interview_invite', ['ar' => ['دعوة لمقابلة', 'ندعوك لمقابلة على وظيفة :job. سنتواصل معك لتأكيد الموعد.', 'تابع تفاصيل طلبك من حسابك.', 'متابعة طلبي'],
                'en' => ['Interview invitation', 'You are invited to interview for :job. We will contact you to confirm the time.', 'Follow your application from your account.', 'Track my application']],
                $job->title_ar, $job->title_en, $c->ref, ['ar' => $co->name_ar, 'en' => $co->name_en], "/my/{$c->id}"));
            $c->log('إرسال دعوة المقابلة للمتقدم بالبريد', 'Interview invitation sent by email');
            Notify::user($c->user_id, "دعوة لمقابلة على وظيفة {$job->title_ar}", "Interview invitation for {$job->title_en}", "my:{$c->id}");
        }
        return $c->refresh();
    }

    public function note(Candidate $c, string $text, User $by): void
    {
        $c->log("ملاحظة ({$by->displayName('ar')}): {$text}", "Note ({$by->displayName('en')}): {$text}");
    }

    /** F-25: حفظ التقييم، والتوصية بالتعيين تنقل تلقائياً للتقييم الفني (كما في الديمو) */
    public function interview(Candidate $c, array $ratings, ?string $notes, string $rec, User $by): Interview
    {
        if (in_array($c->stage, ['hired', 'rejected'], true)) throw new ApiException(L::t('الطلب مغلق — لا يمكن تعديل التقييم.', 'This application is closed — the evaluation cannot be changed.'), 'closed');
        $ratings = array_map('intval', array_intersect_key($ratings, array_flip(F25::keys())));
        $s = F25::score($ratings);
        return DB::transaction(function () use ($c, $ratings, $notes, $rec, $by, $s) {
            $iv = Interview::updateOrCreate(['candidate_id' => $c->id], ['ratings' => $ratings, 'notes' => $notes, 'recommendation' => $rec,
                'interviewer' => $by->displayName('ar'), 'interviewer_id' => $by->id, 'date' => now()->toDateString(), 'pct' => $s['pct']]);
            [$ra, $re] = ['recommended' => ['موصى به', 'recommended'], 'reserve' => ['احتياط', 'reserve'], 'rejected' => ['غير موصى به', 'not recommended']][$rec];
            $c->log("اعتماد تقييم المقابلة الأولية: {$s['pct']}٪ — {$ra}", "HR interview saved: {$s['pct']}% — {$re}", $rec === 'rejected' ? 'danger' : 'ok', 'F-25');
            if ($rec === 'recommended' && in_array($c->stage, ['new', 'screening', 'interview'], true)) {
                $c->update(['stage' => 'technical', 'stage_at' => now()->toDateString()]);
                $c->log('انتقال تلقائي إلى التقييم الفني', 'Auto-moved to technical review');
                $this->points->sync($c);
            }
            return $iv;
        });
    }

    /** F-29: إصدار العرض (أو تعديله بعد طلب المتقدم) وإرساله — المتقدم يرد من حسابه */
    public function offer(Candidate $c, array $o, User $by): Offer
    {
        $c->loadMissing(['offer', 'appointment', 'jobOpening', 'user']);
        if ($c->appointment || in_array($c->stage, ['hired', 'rejected'], true)) throw new ApiException(L::t('لا يمكن إصدار عرض لهذا الطلب الآن.', 'An offer cannot be issued for this application now.'), 'offer_locked');
        if ($c->offer && $c->offer->status === 'accepted') throw new ApiException(L::t('قبل المتقدم العرض ووقّعه — لا يُعدَّل.', 'The applicant accepted and signed the offer — it cannot be changed.'), 'offer_locked');
        $job = $c->jobOpening;
        $offer = DB::transaction(function () use ($c, $o, $by) {
            $revised = (bool) $c->offer;
            $offer = Offer::updateOrCreate(['candidate_id' => $c->id], $o + ['issued_at' => now(), 'status' => 'sent', 'responded_at' => null, 'note' => null, 'issued_by' => $by->id]);
            $c->update(['stage' => 'offer', 'stage_at' => now()->toDateString()]);
            $c->log($revised ? 'إصدار عرض عمل معدّل وإرساله للمرشح' : 'إصدار عرض العمل وإرساله للمرشح عبر البريد والبوابة', $revised ? 'Revised offer issued and sent' : 'Offer issued and sent via email and portal', 'info', 'F-29');
            return $offer;
        });
        if ($c->user) {
            $co = $job->company()->first();
            $c->user->notify(new CandidateMessage('offer_sent', ['ar' => ['صدر لك عرض عمل', 'صدر لك عرض عمل على وظيفة :job — ادخل حسابك للرد.', 'راجع البنود ووقّع العرض أو اطلب تعديلها من حسابك.', 'عرض العرض'],
                'en' => ['You have a job offer', 'A job offer for :job is waiting in your account.', 'Review the terms and sign, or request changes, from your account.', 'View the offer']],
                $job->title_ar, $job->title_en, $c->ref, ['ar' => $co->name_ar, 'en' => $co->name_en], "/my/{$c->id}"));
            Notify::user($c->user_id, "صدر لك عرض عمل على وظيفة {$job->title_ar}", "You have a job offer for {$job->title_en}", "my:{$c->id}");
        }
        return $offer;
    }
}
