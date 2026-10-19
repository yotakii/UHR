<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\Candidate;
use App\Models\CandidateTest;
use App\Models\JobOpening;
use App\Models\JobTest;
use App\Models\User;
use App\Notifications\CandidateMessage;
use App\Services\Ai\AiClient;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * الاختبار الأولي (SRS §10.9، D-34):
 * 1) HR يفعّله للشاغر ← 2) يقترح الذكاء الاصطناعي الأسئلة من المتطلبات (اختيار من متعدد، قصيرة، سيناريو)
 * 3) HR يراجع ويعدّل ويعتمد ← 4) رابط للمتقدم بمدة وعدد محاولات وموعد انتهاء (للكل أو للمطابقين فوق الحد)
 * 5) التصحيح: الموضوعية آلياً، والمفتوحة بتقييم مقترح، وHR يراجع ويقرر ← 6) النتيجة بجانب المطابقة، ولا رفض آلي بالاختبار.
 * يُسجَّل في التدقيق: الأسئلة المولّدة وما عُدّل، والإجابات، والنتيجة، والمراجع.
 */
class PreliminaryTests
{
    public const TYPES = ['mcq', 'short', 'scenario'];

    public function __construct(private AiClient $ai) {}

    /* ------------------------------------------------------------ أسئلة الشاغر */

    /** اقتراح الأسئلة — يُرسل للذكاء الاصطناعي متطلبات الشاغر فقط */
    public function generate(JobOpening $job, User $by): JobTest
    {
        $job->loadMissing('department');
        $req = implode("\n", array_map(fn ($r) => '- '.($r['ar'] ?? ''), $job->requirements ?? []));
        $degree = MatchScore::DEGREES[(int) $job->degree]['ar'] ?? '';
        $prompt = "الوظيفة: {$job->title_ar} ({$job->title_en})\nالإدارة: {$job->department->name_ar}\nالخبرة المطلوبة: {$job->min_years} سنوات على الأقل\nالمؤهل: {$degree}\n"
            ."الوصف: {$job->summary_ar}\nالمتطلبات:\n{$req}\n\n"
            .'اكتب اختباراً أولياً من 8 أسئلة: 5 اختيار من متعدد (4 خيارات، إجابة واحدة صحيحة)، وسؤالان قصيران، وسؤال سيناريو عملي. '
            .'لكل سؤال مفتوح اكتب معيار تصحيح مختصراً (rubric) يصف الإجابة الجيدة. الدرجات: 10 لكل سؤال اختيار، 15 للقصير، 20 للسيناريو.';
        $system = 'أنت خبير توظيف يكتب اختبارات فرز أولية قصيرة وعادلة باللغة العربية الفصحى المبسطة لوظائف في شركات سورية. '
            .'الأسئلة تقيس المعرفة المهنية والمهارات المطلوبة للوظيفة فقط، بلا أسئلة شخصية أو تمييزية، ويمكن الإجابة عنها خلال 20 إلى 40 دقيقة.';
        $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['questions'], 'properties' => ['questions' => ['type' => 'array', 'items' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['type', 'text', 'options', 'answer', 'points', 'rubric'],
            'properties' => ['type' => ['type' => 'string', 'enum' => self::TYPES], 'text' => ['type' => 'string'], 'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                'answer' => ['type' => 'integer', 'description' => 'رقم الخيار الصحيح من 0 لأسئلة الاختيار، و-1 للأسئلة المفتوحة'], 'points' => ['type' => 'integer'], 'rubric' => ['type' => 'string']],
        ]]]];
        $out = $this->ai->json($system, $prompt, $schema);
        $questions = $this->normalize($out['questions'] ?? []);
        if (! $questions) throw new ApiException(L::t('لم تُقترح أسئلة صالحة — حاول مجدداً أو اكتبها يدوياً.', 'No valid questions were suggested — try again or write them manually.'), 'ai_empty', 422);
        $t = JobTest::updateOrCreate(['job_opening_id' => $job->id], ['questions' => $questions, 'generated' => $questions, 'generated_at' => now(), 'status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        Audit::record('test.generated', $t, null, ['job' => $job->code, 'questions' => count($questions)]);
        return $t;
    }

    /** حفظ تعديلات HR (يعيد الاختبار مسودة)، أو حفظ واعتماد */
    public function save(JobOpening $job, array $questions, bool $approve, User $by): JobTest
    {
        $questions = $this->normalize($questions);
        if ($approve && ! $questions) throw ApiException::field('questions', L::t('أضف سؤالاً واحداً على الأقل قبل الاعتماد.', 'Add at least one question before approving.'), 'no_questions');
        $t = JobTest::firstOrNew(['job_opening_id' => $job->id]);
        $t->fill(['questions' => $questions, 'status' => $approve ? 'approved' : 'draft', 'approved_by' => $approve ? $by->id : null, 'approved_at' => $approve ? now() : null])->save();
        if ($approve) Audit::record('test.approved', $t, null, ['job' => $job->code, 'questions' => count($questions), 'edited' => $t->generated && $t->generated !== $questions]);
        return $t;
    }

    /** أسئلة نظيفة: نوع معروف، نص، خيارات للاختيار (2–6) وإجابة ضمنها، درجة 1–100 */
    private function normalize(array $qs): array
    {
        $out = [];
        foreach (array_slice($qs, 0, 30) as $q) {
            $type = in_array($q['type'] ?? '', self::TYPES, true) ? $q['type'] : 'short';
            $text = trim((string) ($q['text'] ?? ''));
            if ($text === '') continue;
            $options = $type === 'mcq' ? array_values(array_filter(array_map(fn ($o) => trim((string) $o), $q['options'] ?? []), fn ($o) => $o !== '')) : [];
            if ($type === 'mcq' && (count($options) < 2 || count($options) > 6)) continue;
            $answer = $type === 'mcq' ? (int) ($q['answer'] ?? 0) : -1;
            if ($type === 'mcq' && ($answer < 0 || $answer >= count($options))) $answer = 0;
            $out[] = ['id' => (string) ($q['id'] ?? '') ?: 'q'.Str::lower(Str::random(6)), 'type' => $type, 'text' => mb_substr($text, 0, 2000), 'options' => $options, 'answer' => $answer,
                'points' => max(1, min(100, (int) ($q['points'] ?? ($type === 'mcq' ? 10 : 15)))), 'rubric' => mb_substr(trim((string) ($q['rubric'] ?? '')), 0, 1000)];
        }
        return $out;
    }

    /* ------------------------------------------------------------ اختبار المتقدم */

    /** بعد التقديم: يُرسل تلقائياً إن كان الاختبار مفعّلاً ومعتمداً وضمن الجمهور (الكل أو المطابقون فوق الحد) */
    public function afterApplication(Candidate $c): ?CandidateTest
    {
        $job = $c->jobOpening;
        if (! $job->test_enabled || $c->stage === 'rejected') return null;
        if ($job->test_audience === 'passed' && $c->match_band !== 'normal') return null;
        $t = JobTest::where('job_opening_id', $job->id)->where('status', 'approved')->first();
        return $t ? $this->send($c, $t, null) : null;
    }

    /** إرسال يدوي من ملف المتقدم (أو إعادة إرسال ضمن عدد المحاولات) */
    public function send(Candidate $c, ?JobTest $t = null, ?User $by = null): CandidateTest
    {
        $job = $c->jobOpening()->first();
        $t ??= JobTest::where('job_opening_id', $job->id)->first();
        if (! $t || $t->status !== 'approved') throw new ApiException(L::t('اعتمد أسئلة الاختبار للشاغر أولاً.', 'Approve the test questions for this job first.'), 'test_not_approved');
        if (in_array($c->stage, ['hired', 'rejected'], true) || $c->cv_status === 'erased') throw new ApiException(L::t('الطلب مغلق.', 'This application is closed.'), 'closed');
        $last = CandidateTest::where('candidate_id', $c->id)->orderByDesc('attempt')->first();
        if ($last && in_array($last->status, ['sent', 'started'], true) && ! $last->overdue()) throw new ApiException(L::t('للمتقدم اختبار مفتوح لم ينتهِ بعد.', 'The applicant already has an open test.'), 'test_open');
        if ($last && in_array($last->status, ['completed', 'reviewed'], true)) throw new ApiException(L::t('أنهى المتقدم الاختبار.', 'The applicant has completed the test.'), 'test_done');
        if ($last && $last->attempt >= max(1, $job->test_attempts)) throw new ApiException(L::t('استُنفدت المحاولات المسموحة لهذا الاختبار.', 'All allowed attempts are used.'), 'no_attempts');
        if ($last && $last->status !== 'expired') $this->expire($last);

        $ct = DB::transaction(function () use ($c, $t, $job, $last, $by) {
            $ct = CandidateTest::create(['candidate_id' => $c->id, 'job_test_id' => $t->id, 'token' => Str::random(48), 'attempt' => ($last?->attempt ?? 0) + 1, 'status' => 'sent',
                'questions' => $t->questions, 'minutes' => max(5, (int) $job->test_minutes), 'sent_at' => now(), 'expires_at' => now()->addDays(max(1, (int) $job->test_valid_days))]);
            $who = $by ? " ({$by->displayName('ar')})" : '';
            $c->log("إرسال رابط الاختبار الأولي — المحاولة {$ct->attempt}، المدة {$ct->minutes} دقيقة{$who}", "Preliminary test link sent — attempt {$ct->attempt}, {$ct->minutes} minutes".($by ? " ({$by->displayName('en')})" : ''));
            return $ct;
        });
        $co = $job->company()->first();
        if ($c->user_id) {
            $c->loadMissing('user');
            $c->user->notify((new CandidateMessage('test_invite', ['ar' => ['اختبار أولي لطلبك', 'لاستكمال طلبك على وظيفة :job أجب عن اختبار قصير مدته '.$ct->minutes.' دقيقة. الرابط صالح حتى '.$ct->expires_at->format('d-m-Y H:i').'.', 'يبدأ الوقت عند فتح الأسئلة، وتُحفظ الإجابات عند الإرسال.', 'بدء الاختبار'],
                'en' => ['Preliminary test for your application', 'To continue your application for :job, take a short '.$ct->minutes.'-minute test. The link is valid until '.$ct->expires_at->format('d-m-Y H:i').'.', 'The timer starts when you open the questions; answers are saved when you submit.', 'Start the test']],
                $job->title_ar, $job->title_en, $c->ref, ['ar' => $co->name_ar, 'en' => $co->name_en], "/test/{$ct->token}"))->afterCommit());
            Notify::user($c->user_id, "اختبار أولي بانتظارك لوظيفة {$job->title_ar}", "A preliminary test awaits you for {$job->title_en}", "my:{$c->id}");
        }
        return $ct;
    }

    /** المتقدم يفتح الأسئلة: يبدأ الوقت */
    public function start(CandidateTest $ct): CandidateTest
    {
        if ($ct->status === 'expired') throw new ApiException(L::t('انتهت صلاحية رابط الاختبار.', 'This test link has expired.'), 'test_expired', 410);
        if ($ct->overdue()) { $this->expire($ct); throw new ApiException(L::t('انتهت صلاحية رابط الاختبار.', 'This test link has expired.'), 'test_expired', 410); }
        if ($ct->status === 'sent') {
            $ct->update(['status' => 'started', 'started_at' => now(), 'deadline_at' => now()->addMinutes($ct->minutes)]);
            $ct->candidate()->first()?->log('بدأ المتقدم الاختبار الأولي', 'The applicant started the preliminary test');
        }
        if ($ct->status !== 'started') throw new ApiException(L::t('أُرسلت إجاباتك لهذا الاختبار.', 'Your answers to this test were already submitted.'), 'test_submitted', 409);
        return $ct;
    }

    /** إرسال الإجابات: تصحيح الموضوعية، واقتراح درجات المفتوحة (إن توفر الذكاء الاصطناعي) — بلا رفض آلي */
    public function submit(CandidateTest $ct, array $answers): CandidateTest
    {
        if ($ct->status === 'expired') throw new ApiException(L::t('انتهى وقت الاختبار قبل الإرسال.', 'The test time ended before submission.'), 'test_expired', 410);
        if ($ct->status !== 'started') throw new ApiException(L::t('افتح الاختبار أولاً.', 'Open the test first.'), 'test_not_started', 409);
        if ($ct->deadline_at->copy()->addMinute()->isPast()) { $this->expire($ct); throw new ApiException(L::t('انتهى وقت الاختبار قبل الإرسال.', 'The test time ended before submission.'), 'test_expired', 410); }
        $clean = [];
        foreach ($ct->questions as $q) {
            $a = $answers[$q['id']] ?? null;
            $clean[$q['id']] = $q['type'] === 'mcq' ? (is_numeric($a) ? (int) $a : null) : mb_substr(trim((string) $a), 0, 4000);
        }
        [$scores, $auto] = $this->autoScore($ct->questions, $clean);
        $ct->update(['answers' => $clean, 'scores' => $scores, 'auto_pct' => $auto, 'status' => 'completed', 'submitted_at' => now()]);
        $c = $ct->candidate()->with('jobOpening')->first();
        $c->log("أكمل المتقدم الاختبار الأولي — الأسئلة الموضوعية {$auto}٪ (بانتظار المراجعة)", "Preliminary test completed — objective questions {$auto}% (awaiting review)", 'info');
        Notify::hr("أكمل {$c->name} الاختبار الأولي — بانتظار المراجعة", ($c->name_en ?: $c->name).' completed the preliminary test — awaiting review', "cand:{$c->id}");
        Audit::record('test.submitted', $ct, null, ['answers' => count(array_filter($clean, fn ($v) => $v !== null && $v !== ''))]);
        $this->suggest($ct);
        return $ct->refresh();
    }

    /** تقييم مقترح للأسئلة المفتوحة: السؤال ومعياره وإجابة المتقدم فقط */
    public function suggest(CandidateTest $ct): void
    {
        $open = array_values(array_filter($ct->questions, fn ($q) => $q['type'] !== 'mcq' && trim((string) ($ct->answers[$q['id']] ?? '')) !== ''));
        if (! $open || ! $this->ai->configured()) return;
        $items = array_map(fn ($q) => ['id' => $q['id'], 'question' => $q['text'], 'rubric' => $q['rubric'], 'max' => $q['points'], 'answer' => $ct->answers[$q['id']]], $open);
        try {
            $out = $this->ai->json(
                'أنت مصحح اختبارات توظيف عادل. قيّم كل إجابة مقابل معيار التصحيح فقط، وأعطِ درجة من 0 حتى الحد الأقصى، وملاحظة قصيرة بالعربية تبرر الدرجة. الدرجة اقتراح يراجعه مسؤول الموارد البشرية.',
                json_encode(['answers' => $items], JSON_UNESCAPED_UNICODE),
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['scores'], 'properties' => ['scores' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['id', 'score', 'note'], 'properties' => ['id' => ['type' => 'string'], 'score' => ['type' => 'integer'], 'note' => ['type' => 'string']]]]]],
            );
        } catch (ApiException $e) {
            report($e);
            return;   // بلا اقتراح: HR يصحح يدوياً
        }
        $max = array_column($open, 'points', 'id'); $sug = [];
        foreach ($out['scores'] ?? [] as $s) if (isset($max[$s['id'] ?? ''])) $sug[$s['id']] = ['score' => max(0, min($max[$s['id']], (int) $s['score'])), 'note' => mb_substr((string) $s['note'], 0, 500)];
        $ct->update(['suggested' => $sug]);
    }

    /** مراجعة HR: درجات الأسئلة المفتوحة (افتراضياً المقترحة) ← النتيجة النهائية */
    public function review(CandidateTest $ct, array $scores, ?string $note, User $by): CandidateTest
    {
        if (! in_array($ct->status, ['completed', 'reviewed'], true)) throw new ApiException(L::t('لم يُكمل المتقدم الاختبار بعد.', 'The applicant has not completed the test yet.'), 'test_not_completed');
        $final = $ct->scores ?? [];
        foreach ($ct->questions as $q) {
            if ($q['type'] === 'mcq') continue;
            $v = $scores[$q['id']] ?? ($ct->suggested[$q['id']]['score'] ?? null);
            if ($v === null || ! is_numeric($v)) throw ApiException::field("scores.{$q['id']}", L::t('أدخل درجة كل سؤال مفتوح.', 'Enter a score for every open question.'), 'score_required');
            $final[$q['id']] = max(0, min((int) $q['points'], (int) $v));
        }
        $total = array_sum(array_column($ct->questions, 'points'));
        $pct = $total ? (int) round(array_sum($final) / $total * 100) : 0;
        $ct->update(['scores' => $final, 'pct' => $pct, 'status' => 'reviewed', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note ?: null]);
        $n = $note ? ' — '.trim($note) : '';
        $ct->candidate()->first()->log("مراجعة الاختبار الأولي: {$pct}٪ ({$by->displayName('ar')}){$n}", "Preliminary test reviewed: {$pct}% ({$by->displayName('en')}){$n}", 'ok');
        Audit::record('test.reviewed', $ct, null, ['pct' => $pct, 'scores' => $final, 'note' => $note]);
        return $ct->refresh();
    }

    /** «غير مكتمل» عند انتهاء المدة (الأمر المجدول أو عند الوصول للرابط) */
    public function expire(CandidateTest $ct): void
    {
        if (! in_array($ct->status, ['sent', 'started'], true)) return;
        $ct->update(['status' => 'expired']);
        $ct->candidate()->first()?->log('انتهت مدة الاختبار الأولي دون إكماله', 'The preliminary test expired without completion', 'warn');
    }

    /** الموضوعية آلياً: الدرجة كاملة للإجابة الصحيحة. يعيد [الدرجات، نسبة الموضوعية] */
    public static function autoScore(array $questions, array $answers): array
    {
        $scores = []; $got = 0; $max = 0;
        foreach ($questions as $q) {
            if ($q['type'] !== 'mcq') continue;
            $ok = ($answers[$q['id']] ?? null) === $q['answer'];
            $scores[$q['id']] = $ok ? (int) $q['points'] : 0;
            $got += $scores[$q['id']]; $max += (int) $q['points'];
        }
        return [$scores, $max ? (int) round($got / $max * 100) : 0];
    }
}
