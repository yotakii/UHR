<?php

namespace App\Services\Recruitment;

use App\Exceptions\ApiException;
use App\Models\JobOpening;
use App\Support\Counters;
use App\Support\L;
use App\Support\Tenant;

/**
 * حفظ الشاغر ونشره (SRS §10.15): مكان النشر، وحالة البوابة حسب إعداد الشركة
 * (مراجعة المنصة ← pending، بدونها ← approved). أي تعديل على شاغر عام يعيده للمراجعة إن كانت مفعّلة.
 */
class JobPublishing
{
    /** حقول تغيّر محتوى الإعلان — تعديلها يعيد المراجعة */
    private const CONTENT = ['title_ar', 'title_en', 'summary_ar', 'summary_en', 'requirements', 'salary_min', 'salary_max', 'employment_type', 'location_id', 'visibility'];

    public function save(array $data, ?JobOpening $job = null): JobOpening
    {
        $company = Tenant::company();
        if ($data['visibility'] === 'public' && ! $company->portal_enabled) {
            throw ApiException::field('visibility', L::t('النشر على البوابة العامة غير مفعّل لشركتك. راجع مدير المنصة.', 'Publishing on the public portal is not enabled for your company. Contact the platform admin.'), 'portal_disabled');
        }
        $job ??= new JobOpening(['code' => Counters::jobCode(), 'portal_status' => 'none', 'created_by' => auth()->id()]);
        $job->fill($data);
        if ($job->status === 'open' && ! $job->posted_at) $job->posted_at = now()->toDateString();

        if ($job->visibility !== 'public') $job->portal_status = 'none';
        elseif (! $job->exists || $job->portal_status === 'none' || $job->portal_status === 'rejected' || $job->isDirty(self::CONTENT)) {
            $job->portal_status = $company->portal_review ? 'pending' : 'approved';
            $job->portal_note = null;
        }
        $reweighted = $job->exists && $job->isDirty(['match_weights', 'min_years', 'degree', 'salary_min', 'salary_max']);
        if (array_key_exists('match_weights', $data) && is_array($data['match_weights'])) $job->match_weights = array_map('intval', array_intersect_key($data['match_weights'], array_flip(MatchScore::KEYS)));
        $job->save();
        if ($reweighted) MatchScore::recompute($job);
        return $job;
    }

    public function setStatus(JobOpening $job, string $status): JobOpening
    {
        $job->status = $status;
        if ($status === 'open' && ! $job->posted_at) $job->posted_at = now()->toDateString();
        if ($status === 'open' && $job->closes_at?->isPast() && ! $job->closes_at->isToday()) $job->closes_at = null;
        $job->save();
        return $job;
    }
}
