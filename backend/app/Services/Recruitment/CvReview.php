<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Jobs\ReviewCandidateCv;
use App\Models\Candidate;
use App\Models\CandidateReview;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Policies\TextExtractor;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * قراءة السيرة الآلية (بالخلفية، نموذج محلي مجاني): عند كل طلب توظيف تُقرأ المؤهلات والخبرات ونص السيرة مقابل متطلبات الشاغر،
 * فيظهر للموارد البشرية تقييم استرشادي (0–100) وملخص ونقاط القوة والنواقص. لا تُرسل الهوية ولا بيانات الاتصال، ولا يُرفض أحد آلياً.
 * التشغيل والإيقاف والحد الشهري لكل شركة من لوحة المنصة فقط — الشركات لا تضبط شيئاً.
 */
class CvReview
{
    public function __construct(private AiClient $ai) {}

    /** إعداد المنصة: {enabled, monthly_limit, companies: {id: {enabled, monthly_limit}}} */
    public static function settings(): array
    {
        return array_merge(['enabled' => true, 'monthly_limit' => 300, 'companies' => []], Settings::platform('ai_cv') ?? []);
    }

    public static function forCompany(int $companyId): array
    {
        $s = self::settings(); $c = $s['companies'][(string) $companyId] ?? [];
        return ['enabled' => $s['enabled'] && ($c['enabled'] ?? true), 'monthly_limit' => (int) ($c['monthly_limit'] ?? $s['monthly_limit'])];
    }

    public static function usedThisMonth(int $companyId): int
    {
        return CandidateReview::allCompanies()->where('company_id', $companyId)->where('created_at', '>=', now()->startOfMonth())->count();
    }

    /** بعد إنشاء الطلب: يُجدول القراءة إن كانت الميزة متاحة للشركة ضمن حدها — وإلا لا شيء */
    public function queue(Candidate $c, bool $force = false): ?CandidateReview
    {
        if (Audit::muted() || ! $this->ai->available()) return null;   // الخدمة غير مشغّلة ← لا شيء
        if (! $force && $c->stage === 'rejected') return null;
        $cfg = self::forCompany($c->company_id);
        if (! $cfg['enabled']) return null;
        $existing = CandidateReview::allCompanies()->where('candidate_id', $c->id)->first();
        if (! $existing && self::usedThisMonth($c->company_id) >= $cfg['monthly_limit']) return null;
        $r = CandidateReview::allCompanies()->updateOrCreate(['candidate_id' => $c->id], ['company_id' => $c->company_id, 'status' => 'pending', 'error' => null]);
        ReviewCandidateCv::dispatch($r->id)->onQueue('ai')->afterCommit();
        return $r;
    }

    /** طلب إعادة القراءة من ملف المتقدم */
    public function rerun(Candidate $c, User $by): CandidateReview
    {
        if (! $this->ai->available() || ! self::forCompany($c->company_id)['enabled'])
            throw new ApiException(L::t('قراءة السيرة غير متاحة حالياً.', 'CV reading is not available right now.'), 'review_unavailable', 409);
        $r = CandidateReview::where('candidate_id', $c->id)->first();
        if ($r && in_array($r->status, ['pending', 'running'], true)) throw new ApiException(L::t('القراءة جارية — انتظر قليلاً.', 'The reading is in progress — please wait.'), 'review_running', 409);
        if (! $r && self::usedThisMonth($c->company_id) >= self::forCompany($c->company_id)['monthly_limit'])
            throw new ApiException(L::t('بلغت الشركة الحد الشهري لقراءة السير.', 'The company reached its monthly CV-reading limit.'), 'review_limit', 409);
        $r = $this->queue($c, true);
        Audit::record('cv.review_requested', $c, null, ['by' => $by->id]);
        return $r;
    }

    /** تنفيذ القراءة (من الطابور) */
    public function run(CandidateReview $r): CandidateReview
    {
        $c = Candidate::allCompanies()->with(['jobOpening.department', 'cv'])->findOrFail($r->candidate_id);
        $r->update(['status' => 'running', 'attempts' => $r->attempts + 1]);
        try {
            $out = $this->ai->json(self::SYSTEM, $this->prompt($c), self::SCHEMA);
            $score = max(0, min(100, (int) ($out['score'] ?? 0)));
            $clean = fn ($a) => array_values(array_slice(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 200), (array) $a)), 0, 5));
            $r->update(['status' => 'done', 'score' => $score, 'fit' => self::fit($score), 'summary' => mb_substr(trim((string) ($out['summary'] ?? '')), 0, 600),
                'strengths' => $clean($out['strengths'] ?? []), 'gaps' => $clean($out['gaps'] ?? []), 'model' => $this->ai->model(), 'error' => null, 'reviewed_at' => now()]);
        } catch (\Throwable $e) {
            $r->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
        return $r->refresh();
    }

    public static function fit(int $score): string { return $score >= 75 ? 'strong' : ($score >= 60 ? 'good' : ($score >= 40 ? 'partial' : 'weak')); }

    public const SYSTEM = 'أنت مختص توظيف محايد. تقيّم مدى ملاءمة متقدم لشاغر اعتماداً على المؤهلات والخبرات والمهارات المرتبطة بمتطلبات الشاغر فقط. '
        .'لا تأخذ بالاعتبار العمر أو الجنس أو الجنسية أو الحالة الاجتماعية أو الدين أو مكان السكن أو أي صفة شخصية. '
        .'أعطِ درجة من 0 إلى 100، وملخصاً من جملتين، وحتى خمس نقاط قوة وحتى خمسة نواقص مقارنة بالمتطلبات. اكتب بالعربية الفصحى المختصرة.';

    public const SCHEMA = ['type' => 'object', 'required' => ['score', 'summary', 'strengths', 'gaps'], 'properties' => [
        'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100], 'summary' => ['type' => 'string'],
        'strengths' => ['type' => 'array', 'items' => ['type' => 'string']], 'gaps' => ['type' => 'array', 'items' => ['type' => 'string']],
    ]];

    /** الشاغر + ملف المتقدم بلا هوية ولا بيانات اتصال */
    public function prompt(Candidate $c): string
    {
        $j = $c->jobOpening;
        $req = implode("\n", array_map(fn ($x) => '- '.(is_array($x) ? ($x['ar'] ?? $x['en'] ?? '') : $x), $j->requirements ?? []));
        $deg = MatchScore::DEGREES[(int) $j->degree]['ar'] ?? '';
        $cdeg = MatchScore::DEGREES[(int) $c->degree_level]['ar'] ?? '';
        $edu = implode("\n", array_map(fn ($e) => '- '.trim(($e['phase'] ?? '').' — '.($e['major'] ?? '').' — '.($e['institute'] ?? '').' '.($e['year'] ?? '')), $c->education ?? []));
        $exp = implode("\n", array_map(fn ($e) => '- '.trim(($e['position'] ?? '').' في '.($e['company'] ?? '').' ('.($e['from'] ?? '').'–'.($e['to'] ?? '').') '.($e['tasks'] ?? '')), $c->experience ?? []));
        $cv = $this->scrub($c, $this->cvText($c));
        return "الشاغر: {$j->title_ar}\nالإدارة: {$j->department->name_ar}\nالخبرة المطلوبة: {$j->min_years} سنوات على الأقل\nالمؤهل المطلوب: {$deg}\n"
            ."الوصف: ".($j->summary_ar ?? '')."\nالمتطلبات:\n{$req}\n\n"
            ."المتقدم:\nالمؤهل: {$cdeg}\nسنوات الخبرة: {$c->years_exp}\nالإنكليزية: {$c->english}\nالتعليم:\n{$edu}\nالخبرات:\n{$exp}\n"
            .($cv ? "\nنص السيرة الذاتية:\n{$cv}\n" : '');
    }

    private function cvText(Candidate $c): string
    {
        $f = $c->cv;
        if (! $f || ! $f->path || ! Storage::disk($f->disk ?: 'local')->exists($f->path)) return '';
        $ext = strtolower(pathinfo((string) ($f->name ?: $f->path), PATHINFO_EXTENSION));
        if (! in_array($ext, ['pdf', 'docx', 'txt'], true)) return '';
        return mb_substr((string) app(TextExtractor::class)->extract(Storage::disk($f->disk ?: 'local')->path($f->path), $ext), 0, 6000);
    }

    /** يحذف الاسم والبريد والهاتف والرقم الوطني والعنوان والروابط من النص قبل إرساله */
    public function scrub(Candidate $c, string $text): string
    {
        if ($text === '') return '';
        foreach (array_filter([$c->name, $c->name_en, $c->email, $c->mobile, $c->mobile2, $c->national_id, $c->address, $c->linkedin]) as $v) {
            $text = str_ireplace((string) $v, '[…]', $text);
            foreach (preg_split('/\s+/u', (string) $v) as $w) if (mb_strlen($w) >= 3 && in_array($v, [$c->name, $c->name_en], true)) $text = str_ireplace($w, '[…]', $text);
        }
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '[…]', $text);
        $text = preg_replace('#(https?://|www\.)\S+#iu', '[…]', $text);
        $text = preg_replace('/\+?\d[\d\s().-]{6,}\d/u', '[…]', $text);
        return trim(preg_replace("/[ \t]+/u", ' ', $text));
    }

    public static function row(?CandidateReview $r, bool $full = false): ?array
    {
        if (! $r) return null;
        $base = ['status' => $r->status, 'score' => $r->score, 'fit' => $r->fit];
        return $full ? $base + ['summary' => $r->summary, 'strengths' => $r->strengths ?? [], 'gaps' => $r->gaps ?? [], 'reviewed_at' => $r->reviewed_at?->toIso8601String()] : $base;
    }
}
