<?php

namespace App\Services\Externals;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\CodeItem;
use App\Models\Deliverable;
use App\Models\ExpertRating;
use App\Models\External;
use App\Models\User;
use App\Services\Codes\Codes;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * قائمة الخبراء الخارجيين (E.4): البحث والترتيب، التكليف بشروط مرجعية وقائمة مختصرة ومبرر اختيار، العقد والتعهدات،
 * المخرجات (تسليم، مراجعة، اعتماد يولّد طلب دفع للمالية)، التقييم والمتوسط التراكمي، والمراجعة الدورية للقائمة.
 */
class Roster
{
    public const LEVELS = ['junior', 'mid', 'senior', 'expert'];
    public const LIVE = ['selected', 'contracted', 'in_progress', 'delivered'];
    private static function min(): int { return (int) Settings::policy('leave_rules')['reject_min']; }

    /** AC-RS-01: المعتمدون المطابقون مرتبين بمتوسط التقييم ثم آخر تعاون */
    public static function search(array $f): Builder
    {
        $q = External::whereIn('kind', ['expert', 'consultant'])->whereIn('status', ($f['all'] ?? false) ? ['applicant', 'vetting', 'approved', 'assigned', 'inactive', 'excluded', 'rejected'] : ['approved', 'assigned']);
        if (! empty($f['status'])) $q->where('status', $f['status']);
        if (! empty($f['specialty'])) {
            $ids = self::withChildren((int) $f['specialty']);
            $q->where(fn ($w) => array_map(fn ($id) => $w->orWhereJsonContains('specialties', $id), $ids));
        }
        if (! empty($f['years'])) $q->where('years', '>=', (int) $f['years']);
        if (! empty($f['level'])) $q->where('level', $f['level']);
        if (! empty($f['country'])) $q->where('country', $f['country']);
        if (! empty($f['city'])) $q->where('city', 'ilike', '%'.$f['city'].'%');
        if (! empty($f['language'])) $q->whereJsonContains('languages', $f['language']);
        if (! empty($f['available'])) $q->where(fn ($w) => $w->where('available', 'now')->orWhere(fn ($x) => $x->where('available', 'from')->where('available_from', '<=', now()->toDateString())));
        if (! empty($f['rating'])) $q->where('avg_rating', '>=', (float) $f['rating']);
        if (! empty($f['q'])) { $t = '%'.$f['q'].'%'; $q->where(fn ($w) => $w->where('name', 'ilike', $t)->orWhere('name_en', 'ilike', $t)->orWhere('projects', 'ilike', $t)->orWhereRaw('keywords::text ilike ?', [$t])->orWhereRaw('sectors::text ilike ?', [$t])); }
        return $q->orderByRaw('avg_rating desc nulls last')->orderByRaw('last_engaged_at desc nulls last')->orderBy('id');
    }

    /** التخصص الرئيسي يشمل فروعه */
    public static function withChildren(int $id): array
    {
        return array_merge([$id], CodeItem::where('parent_id', $id)->pluck('id')->all());
    }

    /** FR-RS-016: اقتراح الأنسب من الشروط المرجعية — التخصصات أولاً، ثم الكلمات المشتركة، ثم التقييم */
    public static function suggest(Assignment $a, int $n = 8): array
    {
        $spec = collect($a->tor['specialties'] ?? [])->flatMap(fn ($id) => self::withChildren((int) $id))->unique()->all();
        $words = collect(preg_split('/[\s،,.؛;:]+/u', mb_strtolower(($a->tor['objective'] ?? '').' '.($a->tor['outputs'] ?? '').' '.$a->title)))->filter(fn ($w) => mb_strlen($w) > 3)->unique();
        return External::whereIn('kind', ['expert', 'consultant'])->whereIn('status', ['approved', 'assigned'])->get()->map(function (External $x) use ($spec, $words) {
            $hit = count(array_intersect($spec, $x->specialties ?? []));
            $hay = mb_strtolower(implode(' ', array_merge($x->keywords ?? [], $x->sectors ?? [], [$x->projects ?? ''])));
            $kw = $words->filter(fn ($w) => str_contains($hay, $w))->count();
            return ['x' => $x, 'score' => $hit * 10 + $kw * 3 + (float) $x->avg_rating];
        })->filter(fn ($r) => $r['score'] > 0)->sortByDesc('score')->take($n)->map(fn ($r) => ['id' => $r['x']->id, 'score' => round($r['score'], 1)])->values()->all();
    }

    // ———————————————— التكليف ————————————————

    public function request(array $v, User $u, ?Assignment $a = null): Assignment
    {
        if ($a && ! in_array($a->status, ['request', 'shortlist'], true)) throw new ApiException(L::t('لا تُعدَّل الشروط بعد الاختيار.', 'The terms cannot change after selection.'), 'locked', 409);
        foreach ($v['tor']['specialties'] ?? [] as $sid) if (! Codes::resolve('specialties', $sid)) throw ApiException::field('tor.specialties', L::t('تخصص غير موجود.', 'Unknown specialty.'), 'invalid');
        $data = ['title' => $v['title'], 'tor' => ['objective' => $v['tor']['objective'], 'outputs' => $v['tor']['outputs'] ?? '', 'place' => $v['tor']['place'] ?? '', 'specialties' => array_map('intval', $v['tor']['specialties'] ?? [])],
            'department_id' => $v['department_id'] ?? null, 'starts_on' => $v['starts_on'] ?? null, 'ends_on' => $v['ends_on'] ?? null, 'budget' => $v['budget'] ?? null, 'currency' => $v['currency'] ?? 'USD'];
        if ($a) { $a->update($data); return $a->fresh(); }
        return Assignment::create($data + ['ref' => 'ASG-'.now()->year.'-'.str_pad((string) Counters::next('assignment-'.now()->year), 4, '0', STR_PAD_LEFT), 'status' => 'request', 'requested_by' => $u->id]);
    }

    public function shortlist(Assignment $a, array $items): Assignment
    {
        if (! in_array($a->status, ['request', 'shortlist'], true)) throw new ApiException(L::t('اختير الخبير من قبل.', 'An expert was already selected.'), 'locked', 409);
        $ok = External::whereIn('id', array_column($items, 'external_id'))->whereIn('kind', ['expert', 'consultant'])->whereIn('status', ['approved', 'assigned'])->pluck('id')->all();
        if (count($ok) !== count($items)) throw ApiException::field('items', L::t('القائمة المختصرة من الخبراء المعتمدين فقط (POL-RS-01).', 'The shortlist takes approved roster experts only (POL-RS-01).'), 'not_approved');
        $a->update(['shortlist' => array_map(fn ($i) => ['external_id' => (int) $i['external_id'], 'note' => $i['note'] ?? null], $items), 'status' => $items ? 'shortlist' : 'request']);
        return $a;
    }

    /** POL-RS-09: الاختيار من القائمة المختصرة مع مبرر؛ FR-RS-013: تكليفان متداخلان يحتاجان تأكيداً */
    public function select(Assignment $a, int $expertId, string $reason, bool $confirm, User $u): Assignment
    {
        if ($a->status !== 'shortlist') throw new ApiException(L::t('أعدّ القائمة المختصرة أولاً.', 'Prepare the shortlist first.'), 'no_shortlist', 409);
        if (! in_array($expertId, array_column($a->shortlist ?? [], 'external_id'), true)) throw ApiException::field('external_id', L::t('الاختيار من القائمة المختصرة فقط.', 'Choose from the shortlist.'), 'not_shortlisted');
        if (mb_strlen(trim($reason)) < self::min()) throw ApiException::field('reason', L::t('وثّق سبب الاختيار.', 'Document the reason for the choice.'), 'reason_required');
        $min = (int) Externals::cfg()['shortlist_min'];
        if (count($a->shortlist) < $min && mb_strlen(trim($reason)) < self::min() * 2) throw ApiException::field('reason', L::t("القائمة أقل من {$min} خبراء — وضّح سبب عدم المفاضلة.", "Fewer than {$min} experts — explain why there was no comparison."), 'short_list');
        if (! $confirm && $a->starts_on && Assignment::where('external_id', $expertId)->whereIn('status', self::LIVE)->whereKeyNot($a->id)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $a->starts_on))->where(fn ($q) => $q->whereNull('starts_on')->orWhere('starts_on', '<=', $a->ends_on ?? $a->starts_on))->exists())
            throw new ApiException(L::t('لهذا الخبير تكليف آخر في الفترة نفسها — أكّد للمتابعة.', 'This expert has another assignment in the same period — confirm to continue.'), 'overlap', 409);
        $a->update(['external_id' => $expertId, 'selection_reason' => $reason, 'status' => 'selected']);
        Audit::record('assignment.selected', $a, ['expert' => $expertId]);
        return $a;
    }

    /** العقد المؤقت (FR-RS-007): الأتعاب، المعاملة الضريبية، المخرجات ودفعاتها، وفحص طبيعة العلاقة */
    public function contract(Assignment $a, array $v, User $u): Assignment
    {
        if ($a->status !== 'selected') throw new ApiException(L::t('اختر الخبير أولاً.', 'Select the expert first.'), 'not_selected', 409);
        $items = $v['deliverables'] ?? [];
        if (! $items) throw ApiException::field('deliverables', L::t('حدد مخرجاً واحداً على الأقل (الدفع مقابل المخرجات).', 'Add at least one deliverable (payment is per deliverable).'), 'required');
        $sum = array_sum(array_map(fn ($d) => (float) ($d['payment'] ?? 0), $items));
        if (abs($sum - (float) $v['total_fee']) > 0.01) throw ApiException::field('total_fee', L::t('مجموع دفعات المخرجات يجب أن يساوي الأتعاب.', 'The deliverable payments must add up to the fee.'), 'mismatch');
        $test = array_map(fn ($k) => (bool) (($v['relation_test'] ?? [])[$k] ?? false), array_combine(Externals::TEST, Externals::TEST));
        DB::transaction(function () use ($a, $v, $items, $test) {
            $a->update(['total_fee' => $v['total_fee'], 'currency' => $v['currency'] ?? $a->currency, 'tax_treatment' => $v['tax_treatment'] ?? null, 'relation_test' => $test,
                'relation_score' => count(array_filter($test)), 'contract_file_id' => $v['file_id'] ?? null, 'contracted_at' => now(), 'status' => 'contracted',
                'starts_on' => $v['starts_on'] ?? $a->starts_on, 'ends_on' => $v['ends_on'] ?? $a->ends_on]);
            foreach ($items as $d) Deliverable::create(['assignment_id' => $a->id, 'title' => $d['title'], 'due_on' => $d['due_on'] ?? null, 'payment' => (float) ($d['payment'] ?? 0)]);
            External::whereKey($a->external_id)->update(['status' => 'assigned', 'last_engaged_at' => now()]);
        });
        $x = External::find($a->external_id);
        app(Externals::class)->account($x);
        if ($x->user_id) Notify::user($x->user_id, "تكليف جديد {$a->ref}: وقّع تعهدي السرية وعدم تضارب المصالح لتبدأ", "New assignment {$a->ref}: sign the confidentiality and conflict-of-interest pledges to start", 'ext');
        if ($a->relation_score >= (int) Externals::cfg()['relation_threshold']) Notify::hr("تكليف {$a->ref} يحمل سمات علاقة عمل — راجعه قانونياً", "Assignment {$a->ref} looks like employment — review it legally", 'externals');
        return $a->fresh();
    }

    /** POL-RS-05: السرية والإفصاح عن تضارب المصالح مع كل تكليف قبل التفعيل */
    public function pledge(Assignment $a, User $u, array $kinds, ?string $conflict): Assignment
    {
        $x = External::find($a->external_id);
        if (! ($x && $x->user_id === $u->id) && ! Externals::canManage($u)) abort(403);
        if ($a->status !== 'contracted') throw new ApiException(L::t('التكليف ليس بانتظار التعهدات.', 'Not awaiting pledges.'), 'not_ready', 409);
        if (array_diff(['confidentiality', 'conflict'], $kinds)) throw ApiException::field('pledges', L::t('وقّع التعهدين كليهما.', 'Sign both pledges.'), 'pledges_required');
        $a->update(['pledges' => ['confidentiality' => now()->toIso8601String(), 'conflict' => now()->toIso8601String(), 'conflict_note' => $conflict], 'status' => 'in_progress']);
        if ($conflict) Notify::hr("أفصح الخبير {$x->name} عن تضارب مصالح في {$a->ref}", "{$x->name} disclosed a conflict of interest in {$a->ref}", 'externals');
        return $a;
    }

    public function submit(Deliverable $d, User $u, ?int $fileId, ?string $note): Deliverable
    {
        $a = Assignment::find($d->assignment_id); $x = External::find($a->external_id);
        if (! ($x && $x->user_id === $u->id) && ! Externals::canManage($u)) abort(403);
        if ($a->status !== 'in_progress' && $a->status !== 'delivered') throw new ApiException(L::t('التكليف غير قيد التنفيذ.', 'The assignment is not in progress.'), 'not_active', 409);
        if (! in_array($d->status, ['pending', 'returned'], true)) throw new ApiException(L::t('سُلّم هذا المخرج.', 'Already submitted.'), 'submitted', 409);
        if (! $fileId) throw ApiException::field('file_id', L::t('أرفق المخرج.', 'Attach the deliverable.'), 'required');
        $d->update(['status' => 'submitted', 'file_id' => $fileId, 'submitted_at' => now(), 'note' => $note]);
        Notify::hr("سُلّم مخرج «{$d->title}» في {$a->ref}", "Deliverable “{$d->title}” submitted in {$a->ref}", 'externals');
        return $d;
    }

    /** AC-RS-02: اعتماد المخرج ← طلب دفع للمالية بقيمة دفعته، ولا شيء في مسيّر الرواتب */
    public function review(Deliverable $d, bool $approve, ?string $note, ?string $due, User $u): Deliverable
    {
        if ($d->status !== 'submitted') throw new ApiException(L::t('المخرج ليس بانتظار المراجعة.', 'Not awaiting review.'), 'not_submitted', 409);
        $a = Assignment::find($d->assignment_id); $x = External::find($a->external_id);
        if (! $approve && mb_strlen(trim((string) $note)) < self::min()) throw ApiException::field('note', L::t('اكتب الملاحظات للخبير.', 'Write the notes for the expert.'), 'reason_required');
        DB::transaction(function () use ($d, $a, $x, $approve, $note, $due, $u) {
            $d->update(['status' => $approve ? 'approved' : 'returned', 'reviewed_at' => now(), 'reviewed_by' => $u->id, 'note' => $note] + (! $approve && $due ? ['due_on' => $due] : []));
            if ($approve && $d->payment > 0) Payments::create($x, 'deliverable', $d->id, "{$a->ref} — {$d->title}", $d->payment, $a->currency, $d->file_id, $u);
            if (Deliverable::where('assignment_id', $a->id)->where('status', '!=', 'approved')->doesntExist()) $a->update(['status' => 'delivered']);
        });
        if ($x->user_id) Notify::user($x->user_id, $approve ? "اعتُمد المخرج «{$d->title}»" : "أُعيد المخرج «{$d->title}» بملاحظات", $approve ? "Deliverable “{$d->title}” approved" : "Deliverable “{$d->title}” returned with notes", 'ext');
        return $d;
    }

    /** POL-RS-06: تقييم إلزامي لإغلاق التكليف، يدخل المتوسط التراكمي وترتيب البحث */
    public function rate(Assignment $a, array $v, User $u): Assignment
    {
        if (! in_array($a->status, ['in_progress', 'delivered'], true)) throw new ApiException(L::t('لا يُقيَّم هذا التكليف الآن.', 'This assignment cannot be rated now.'), 'not_ready', 409);
        if ($a->status === 'in_progress' && empty($v['early'])) throw new ApiException(L::t('لم تُعتمد كل المخرجات — اختر «إنهاء مبكر» إن لزم.', 'Not all deliverables are approved — choose “end early” if needed.'), 'pending_deliverables', 409);
        $s = array_map(fn ($k) => (int) ($v[$k] ?? 0), ['quality', 'timeliness', 'communication', 'value']);
        foreach ($s as $n) if ($n < 1 || $n > 5) throw ApiException::field('quality', L::t('كل معيار من 1 إلى 5.', 'Each criterion 1 to 5.'), 'invalid');
        DB::transaction(function () use ($a, $v, $u, $s) {
            ExpertRating::create(['assignment_id' => $a->id, 'external_id' => $a->external_id, 'quality' => $s[0], 'timeliness' => $s[1], 'communication' => $s[2], 'value' => $s[3],
                'score' => round(array_sum($s) / 4, 2), 'rehire' => (bool) ($v['rehire'] ?? true), 'notes' => $v['notes'] ?? null, 'rated_by' => $u->id]);
            $a->update(['status' => empty($v['early']) ? 'closed' : 'terminated', 'closed_at' => now()]);
            Deliverable::where('assignment_id', $a->id)->whereIn('status', ['pending', 'returned'])->delete();
            $x = External::find($a->external_id);
            $agg = ExpertRating::where('external_id', $x->id)->selectRaw('avg(score) a, count(*) n')->first();
            $live = Assignment::where('external_id', $x->id)->whereIn('status', self::LIVE)->exists();
            $x->update(['avg_rating' => round((float) $agg->a, 2), 'ratings' => (int) $agg->n, 'last_engaged_at' => now(), 'status' => $live ? 'assigned' : 'approved']);
        });
        Audit::record('assignment.rated', $a);
        return $a->fresh();
    }

    public function cancel(Assignment $a, string $reason, User $u): Assignment
    {
        if (! in_array($a->status, ['request', 'shortlist', 'selected', 'contracted'], true)) throw new ApiException(L::t('لا يُلغى التكليف بعد بدء التنفيذ — قيّمه وأنهه.', 'An assignment in progress is ended by rating it.'), 'locked', 409);
        if (mb_strlen(trim($reason)) < self::min()) throw ApiException::field('reason', L::t('اكتب السبب.', 'Write the reason.'), 'reason_required');
        $a->update(['status' => 'cancelled', 'closed_at' => now()]);
        Deliverable::where('assignment_id', $a->id)->delete();
        if ($a->external_id && ! Assignment::where('external_id', $a->external_id)->whereIn('status', self::LIVE)->exists()) External::whereKey($a->external_id)->where('status', 'assigned')->update(['status' => 'approved']);
        return $a;
    }

    /** POL-RS-08 + FR-RS-003: طلب تحديث سنوي وتعطيل من لا يستجيب، وتنبيه قبل انتهاء صلاحية الشهادات */
    public function sweep(): int
    {
        $c = Externals::cfg(); $n = 0;
        foreach (External::whereIn('kind', ['expert', 'consultant'])->where('status', 'approved')->whereNotNull('review_due')->where('review_due', '<=', now()->toDateString())->get() as $x) {
            if (! $x->review_requested_at) {
                $x->update(['review_requested_at' => now()]);
                if ($x->user_id) Notify::user($x->user_id, 'حدّث بياناتك وتوفرك في قائمة الخبراء', 'Please update your roster details and availability', 'ext');
                $n++;
            } elseif ($x->review_requested_at->lt(now()->subDays((int) $c['roster_inactive_days']))) {
                $x->update(['status' => 'inactive', 'status_note' => 'لم يستجب لطلب التحديث الدوري']);
                $n++;
            }
        }
        $limit = now()->addDays((int) $c['doc_alert_days'])->toDateString();
        foreach (External::whereIn('kind', ['expert', 'consultant'])->whereIn('status', ['approved', 'assigned'])->whereNotNull('documents')->get() as $x) {
            foreach ($x->documents as $d) if (! empty($d['valid_to']) && $d['valid_to'] <= $limit && \Illuminate\Support\Facades\Cache::add("ext-doc:{$x->id}:{$d['valid_to']}", 1, 86400 * 40)) {
                Notify::hr("تنتهي صلاحية شهادة للخبير {$x->name} في {$d['valid_to']}", "A certificate of {$x->name} expires on {$d['valid_to']}", 'externals:'.$x->id);
                $n++;
            }
        }
        return $n;
    }

    /** الخبير يحدّث توفره وبياناته من بوابته ← يُجدَّد موعد المراجعة */
    public function selfUpdate(External $x, array $v): External
    {
        $x->update(array_intersect_key($v, array_flip(['phone', 'city', 'country', 'available', 'available_from', 'work_modes', 'languages', 'keywords', 'projects']))
            + ['review_requested_at' => null, 'review_due' => now()->addMonths((int) Externals::cfg()['roster_review_months'])->toDateString()]);
        return $x;
    }
}
