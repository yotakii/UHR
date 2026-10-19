<?php

namespace App\Services\Cv;

use App\Exceptions\ApiException;
use App\Models\Candidate;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * قاعدة السير الذاتية (SRS §10.9، D-02): المتقدمون الذين وافقوا على «الاحتفاظ بسيرتي لفرص قادمة» فقط.
 * بحث وفلترة بالاختصاص والخبرة والمؤهل والمدينة والكلمات؛ كل عرض جماعي أو تصدير يُسجَّل (من، متى، كم، أي حقول)؛
 * الحالات: نشطة ← مؤرشفة (يدوياً أو بانتهاء مدة الاحتفاظ) ← محذوفة بطلب المتقدم (أثر مجهّل).
 */
class CvDatabase
{
    public const EXPORT_FIELDS = ['ref', 'name', 'email', 'mobile', 'city', 'degree', 'major', 'years_exp', 'last_position', 'job', 'stage', 'match', 'applied_at'];

    /** @param array{q?:string,degree?:int,years?:int,city?:string,status?:string,job?:int} $f */
    public function query(array $f): Builder
    {
        $q = Candidate::with(['jobOpening.location', 'cv'])->where('keep_cv', true)->where('cv_status', ($f['status'] ?? 'active') === 'archived' ? 'archived' : 'active');
        if (isset($f['degree']) && $f['degree'] !== '') $q->where('degree_level', '>=', (int) $f['degree']);
        if (isset($f['years']) && $f['years'] !== '') $q->where('years_exp', '>=', (int) $f['years']);
        if (! empty($f['job'])) $q->where('job_opening_id', (int) $f['job']);
        if ($city = trim((string) ($f['city'] ?? ''))) $q->where(fn ($w) => $w->where('address', 'ilike', "%{$city}%")->orWhereHas('jobOpening.location', fn ($l) => $l->where('code', $city)));
        if ($t = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.mb_strtolower($t).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(coalesce(name_en, \'\')) like ?', [$like])
                ->orWhereRaw('lower(education::text) like ?', [$like])->orWhereRaw('lower(experience::text) like ?', [$like])
                ->orWhereHas('jobOpening', fn ($j) => $j->whereRaw('lower(title_ar) like ?', [$like])->orWhereRaw('lower(title_en) like ?', [$like])));
        }
        return $q->latest('created_at')->latest('id');
    }

    /** صف التصدير — الحقول المعلنة في EXPORT_FIELDS فقط */
    public static function exportRow(Candidate $c): array
    {
        $ed = $c->education[0] ?? []; $ex = $c->experience[0] ?? [];
        return [$c->ref, $c->name, $c->email, $c->mobile, $c->address, $ed['phase'] ?? '', $ed['major'] ?? '', $c->years_exp, trim(($ex['position'] ?? '').' — '.($ex['company'] ?? ''), ' —'),
            $c->jobOpening->title_ar, $c->stage, $c->match_score, $c->created_at->toDateString()];
    }

    public function archive(Candidate $c, User $by): void
    {
        if ($c->cv_status !== 'active') throw new ApiException(L::t('السيرة ليست نشطة.', 'This CV is not active.'), 'not_active');
        $c->update(['cv_status' => 'archived', 'cv_archived_at' => now()]);
        $c->log("أرشفة السيرة في قاعدة السير ({$by->displayName('ar')})", "CV archived in the CV database ({$by->displayName('en')})", 'warn');
    }

    public function restore(Candidate $c, User $by): void
    {
        if ($c->cv_status !== 'archived') throw new ApiException(L::t('السيرة ليست مؤرشفة.', 'This CV is not archived.'), 'not_archived');
        $c->update(['cv_status' => 'active', 'cv_archived_at' => null]);
        $c->log("إعادة تنشيط السيرة في قاعدة السير ({$by->displayName('ar')})", "CV restored to the CV database ({$by->displayName('en')})");
    }

    /** مدة الاحتفاظ (M16): أرشفة السير النشطة الأقدم من المدة — يعمل يومياً من المجدول */
    public function archiveExpired(): int
    {
        $months = max(1, (int) Settings::rule('cv_retention_months'));
        $n = 0;
        Candidate::where('keep_cv', true)->where('cv_status', 'active')->whereNotIn('stage', ['hired'])->where('updated_at', '<', now()->subMonths($months))
            ->each(function (Candidate $c) use (&$n, $months) {
                $c->update(['cv_status' => 'archived', 'cv_archived_at' => now()]);
                $c->log("أرشفة تلقائية للسيرة بعد انتهاء مدة الاحتفاظ ({$months} شهراً)", "CV archived automatically after the retention period ({$months} months)", 'warn');
                $n++;
            });
        return $n;
    }

    /**
     * حذف بطلب المتقدم: تُمسح الملفات والبيانات الشخصية ويبقى أثر مجهّل (الرقم، الشاغر، المرحلة، التواريخ، السجل).
     * لا حذف لطلب صار تعييناً أو قيد قرار التعيين.
     */
    public function erase(Candidate $c): void
    {
        $c->loadMissing(['cv', 'photo', 'appointment', 'offer.signature']);
        if ($c->stage === 'hired' || ($c->appointment && $c->stage !== 'rejected')) {
            throw new ApiException(L::t('لا يمكن حذف طلب في مرحلة قرار التعيين أو بعد التعيين — تواصل مع الموارد البشرية.', 'An application at the appointment stage or after hiring cannot be deleted — contact HR.'), 'erase_locked');
        }
        if ($c->cv_status === 'erased') return;
        $files = array_filter([$c->cv, $c->photo, $c->offer?->signature]);
        DB::transaction(function () use ($c) {
            // بلا نسخ القيم الشخصية القديمة إلى التدقيق، وتُحجب ما سبق تسجيله منها لهذا الطلب
            Audit::mute(fn () => $c->offer?->update(['signature_file_id' => null]));
            DB::table('audit_logs')->where('subject_type', $c->getMorphClass())->where('subject_id', $c->id)->whereIn('action', ['candidate.created', 'candidate.updated'])
                ->update(['before' => null, 'after' => json_encode(['redacted' => true])]);
            Audit::mute(fn () => $c->update([
                'name' => 'محذوف بطلب المتقدم', 'name_en' => 'Erased on request', 'email' => "erased-{$c->id}@invalid.local", 'mobile' => '—', 'mobile2' => null,
                'national_id' => null, 'dob' => null, 'pob' => null, 'blood' => null, 'marital' => null, 'nationality' => null, 'address' => null, 'linkedin' => null,
                'education' => [], 'experience' => [], 'refs' => [], 'cv_file_id' => null, 'photo_file_id' => null, 'user_id' => null, 'keep_cv' => false,
                'cv_status' => 'erased', 'erased_at' => now(),
                'stage' => in_array($c->stage, ['hired', 'rejected'], true) ? $c->stage : 'rejected', 'reject_reason' => $c->stage === 'rejected' ? $c->reject_reason : 'حذف بطلب المتقدم',
            ]));
            $c->log('حذف البيانات الشخصية والملفات بطلب المتقدم — بقي أثر مجهّل', 'Personal data and files erased at the applicant\'s request — anonymised trace kept', 'danger');
            Audit::record('candidate.erased', $c, null, ['ref' => $c->ref]);
        });
        foreach ($files as $f) { Storage::disk($f->disk)->delete($f->path); $f->delete(); }
    }
}
