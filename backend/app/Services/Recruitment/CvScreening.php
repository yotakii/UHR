<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\Candidate;
use App\Models\CandidateReview;
use App\Models\ReviewBatch;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Support\Audit;
use App\Support\L;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * فرز السير (11f): يختار مسؤول التوظيف المتقدمين بالفلاتر (الشاغر، الإدارة، الاختصاص، المؤهل، الخبرة، المدينة، المرحلة، حالة القراءة ونتيجتها، تاريخ التقديم)
 * ثم يطلب قراءة جماعية؛ النتائج تُرتَّب تلقائياً عند وصولها. للسرعة: تُرسل الدفعة مرتبة حسب الشاغر، فيُعيد النموذج استخدام
 * الجزء المشترك من الطلب (تعليمات القراءة + بيانات الشاغر) بين المتقدمين المتتالين بدل معالجته كل مرة.
 */
class CvScreening
{
    public const MAX_BATCH = 300;
    public const SORTS = ['fit', 'match', 'years', 'newest'];

    public function __construct(private CvReview $reviews, private AiClient $ai) {}

    /** @param array<string, mixed> $f */
    public function query(array $f): Builder
    {
        $q = Candidate::query()->with(['jobOpening.department', 'jobOpening.location', 'review', 'cv']);
        if (! empty($f['job'])) $q->where('job_opening_id', (int) $f['job']);
        if (! empty($f['dept'])) $q->whereHas('jobOpening', fn ($j) => $j->where('department_id', (int) $f['dept']));
        if ($m = trim((string) ($f['major'] ?? ''))) $q->whereRaw('education::text ilike ?', ['%'.$m.'%']);
        if ($t = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.$t.'%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $like)->orWhere('name_en', 'ilike', $like)->orWhere('ref', 'ilike', $like)
                ->orWhereRaw('experience::text ilike ?', [$like])->orWhereRaw('education::text ilike ?', [$like]));
        }
        if (isset($f['degree']) && $f['degree'] !== '' && $f['degree'] !== null) $q->where('degree_level', '>=', (int) $f['degree']);
        if (isset($f['years_min']) && $f['years_min'] !== '' && $f['years_min'] !== null) $q->where('years_exp', '>=', (int) $f['years_min']);
        if (isset($f['years_max']) && $f['years_max'] !== '' && $f['years_max'] !== null) $q->where('years_exp', '<=', (int) $f['years_max']);
        if ($city = trim((string) ($f['city'] ?? ''))) $q->where(fn ($w) => $w->where('address', 'ilike', "%{$city}%")->orWhereHas('jobOpening.location', fn ($l) => $l->where('code', $city)));
        if (! empty($f['english'])) $q->where('english', $f['english']);
        $stage = $f['stage'] ?? 'open';
        if ($stage === 'open') $q->whereNotIn('stage', ['hired', 'rejected']);
        elseif ($stage !== 'all') $q->where('stage', $stage);
        if (! empty($f['from'])) $q->whereDate('created_at', '>=', $f['from']);
        if (! empty($f['to'])) $q->whereDate('created_at', '<=', $f['to']);
        if (isset($f['match_min']) && $f['match_min'] !== '' && $f['match_min'] !== null) $q->where('match_score', '>=', (int) $f['match_min']);
        match ($f['read'] ?? '') {
            'none' => $q->whereDoesntHave('review'),
            'done' => $q->whereHas('review', fn ($r) => $r->where('status', 'done')),
            'running' => $q->whereHas('review', fn ($r) => $r->whereIn('status', ['pending', 'running'])),
            'failed' => $q->whereHas('review', fn ($r) => $r->where('status', 'failed')),
            default => null,
        };
        if (! empty($f['fit'])) $q->whereHas('review', fn ($r) => $r->where('status', 'done')->where('fit', $f['fit']));
        $sub = CandidateReview::query()->select('score')->whereColumn('candidate_reviews.candidate_id', 'candidates.id')->where('status', 'done')->limit(1);
        return match ($f['sort'] ?? 'fit') {
            'match' => $q->orderByDesc('match_score')->orderByDesc('id'),
            'years' => $q->orderByDesc('years_exp')->orderByDesc('match_score'),
            'newest' => $q->orderByDesc('created_at')->orderByDesc('id'),
            default => $q->orderByRaw('('.$sub->toSql().') desc nulls last', $sub->getBindings())->orderByDesc('match_score')->orderByDesc('id'),
        };
    }

    public static function row(Candidate $c, int $rank): array
    {
        $ed = collect($c->education ?? [])->first() ?? []; $ex = collect($c->experience ?? [])->first() ?? [];
        $j = $c->jobOpening;
        return [
            'id' => $c->id, 'rank' => $rank, 'ref' => $c->ref, 'name' => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name], 'stage' => $c->stage, 'match' => $c->match_score,
            'degree' => $c->degree_level, 'major' => $ed['major'] ?? null, 'phase' => $ed['phase'] ?? null, 'years' => $c->years_exp, 'english' => $c->english, 'city' => $c->address,
            'last' => $ex ? trim(($ex['position'] ?? '').' — '.($ex['company'] ?? ''), ' —') : null,
            'job' => ['id' => $j->id, 'code' => $j->code, 'title' => ['ar' => $j->title_ar, 'en' => $j->title_en], 'dept' => $j->department ? ['ar' => $j->department->name_ar, 'en' => $j->department->name_en] : null],
            'cv' => (bool) $c->cv_file_id, 'applied_at' => $c->created_at?->toDateString(),
            'review' => CvReview::row($c->review, true),
        ];
    }

    /** الاختصاصات الدراسية الموجودة لدى متقدمي الشركة (لقائمة الاقتراحات) */
    public function majors(): array
    {
        return Candidate::query()->pluck('education')->flatMap(fn ($e) => collect($e ?? [])->pluck('major'))
            ->map(fn ($m) => trim((string) $m))->filter()->countBy()->sortDesc()->keys()->take(80)->values()->all();
    }

    /** الحالة: الخدمة، الحد الشهري، متوسط مدة القراءة */
    public function status(): array
    {
        $cid = Tenant::id(); $cfg = CvReview::forCompany($cid);
        return ['available' => $cfg['enabled'] && $this->ai->available(), 'enabled' => $cfg['enabled'], 'used' => CvReview::usedThisMonth($cid), 'limit' => $cfg['monthly_limit'],
            'avg_seconds' => CvReview::avgSeconds($cid), 'queue' => CandidateReview::query()->whereIn('status', ['pending', 'running'])->where('updated_at', '>=', now()->subMinutes(20))->count()];
    }

    /**
     * قراءة جماعية للمحددين: المقروء سابقاً يُتخطى إلا مع «إعادة القراءة»، والقراءات الجديدة ضمن الحد الشهري.
     * الترتيب: حسب الشاغر ثم المطابقة الأعلى أولاً — تظهر أهم النتائج أولاً ويُستفاد من الجزء المشترك للشاغر.
     */
    public function start(User $u, array $ids, bool $force, array $filters = []): ReviewBatch
    {
        $cid = Tenant::id(); $cfg = CvReview::forCompany($cid);
        if (! $cfg['enabled'] || ! $this->ai->available()) throw new ApiException(L::t('قراءة السير غير متاحة حالياً.', 'CV reading is not available right now.'), 'review_unavailable', 409);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) throw ApiException::field('ids', L::t('اختر متقدماً واحداً على الأقل.', 'Select at least one applicant.'), 'empty');
        if (count($ids) > self::MAX_BATCH) throw ApiException::field('ids', L::t('الحد الأقصى '.self::MAX_BATCH.' متقدماً في الدفعة الواحدة.', 'At most '.self::MAX_BATCH.' applicants per batch.'), 'too_many');
        /** @var Collection<int, Candidate> $list */
        $list = Candidate::query()->with('review')->whereIn('id', $ids)->orderBy('job_opening_id')->orderByDesc('match_score')->get();
        $room = max(0, $cfg['monthly_limit'] - CvReview::usedThisMonth($cid));
        $counts = ['selected' => count($ids), 'queued' => 0, 'already' => 0, 'running' => 0, 'limit' => 0];
        $batch = ReviewBatch::create(['user_id' => $u->id, 'candidate_ids' => [], 'counts' => $counts, 'filters' => $filters ?: null, 'force' => $force]);
        $queued = [];
        foreach ($list as $c) {
            $r = $c->review;
            $live = $r && in_array($r->status, ['pending', 'running'], true) && $r->updated_at?->gt(now()->subMinutes(20));
            if ($live) { $counts['running']++; continue; }
            if ($r && $r->status === 'done' && ! $force) { $counts['already']++; continue; }
            if (! $r) { if ($room <= 0) { $counts['limit']++; continue; } $room--; }
            $this->reviews->queue($c, true, $batch->id);
            $queued[] = $c->id; $counts['queued']++;
        }
        $batch->update(['candidate_ids' => $queued, 'counts' => $counts]);
        Audit::record('cv.batch_review', null, null, ['batch' => $batch->id] + $counts + ['force' => $force]);
        return $batch;
    }

    /** تقدّم الدفعة: المنجز، الفاشل، المتبقي، والوقت المتبقي التقديري */
    public function progress(ReviewBatch $b): array
    {
        $ids = $b->candidate_ids ?? [];
        $rows = CandidateReview::query()->whereIn('candidate_id', $ids)->get(['status', 'updated_at', 'batch_id']);
        $stale = fn ($r) => in_array($r->status, ['pending', 'running'], true) && $r->updated_at?->lt(now()->subMinutes(20));
        $done = $rows->where('status', 'done')->count();
        $failed = $rows->filter(fn ($r) => $r->status === 'failed' || $stale($r))->count();
        $left = max(0, count($ids) - $done - $failed);
        // المهام قبل هذه الدفعة في الطابور تُحسب في الانتظار أيضاً
        $ahead = $left ? CandidateReview::query()->whereIn('status', ['pending', 'running'])->where('updated_at', '>=', now()->subMinutes(20))->whereNotIn('candidate_id', $ids)
            ->where('updated_at', '<', $b->created_at)->count() : 0;
        $avg = CvReview::avgSeconds((int) $b->company_id);
        return ['id' => $b->id, 'total' => count($ids), 'done' => $done, 'failed' => $failed, 'left' => $left, 'running' => $left > 0,
            'eta_seconds' => (int) round(($left + $ahead) * $avg), 'avg_seconds' => $avg, 'counts' => $b->counts, 'force' => $b->force, 'at' => $b->created_at?->toIso8601String()];
    }

    public function latest(User $u): ?ReviewBatch
    {
        return ReviewBatch::query()->where('user_id', $u->id)->latest('id')->first();
    }
}
