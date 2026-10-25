<?php

namespace App\Services\Performance;

use App\Exceptions\ApiException;
use App\Models\Competency;
use App\Models\Employee;
use App\Models\MonthSheet;
use App\Models\PerformanceAward;
use App\Models\PerformanceCycle;
use App\Models\PerformanceForm;
use App\Models\PerformanceReview;
use App\Models\PointLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * M13 تقييم 360 (SRS M13): دورة ← نماذج ذاتي/مدير/زملاء بدرجة لكل كفاءة ← النتيجة = متوسط الكفاءات لـ
 * (المدير × w + الزملاء × w + الذاتي × w)/مجموع الأوزان المتوفرة ← مستوى الأداء من حدود الإعدادات والإمكانات من نموذج المدير
 * ← معايرة HR بالسحب على 9-Box ← اعتماد النتائج ← استحقاق المكافأة من جدول الشركة (D-28).
 */
class Reviews
{
    public static function config(): array { return Settings::performance(); }

    public static function competencies(): Collection { return Competency::where('active', true)->orderBy('sort')->orderBy('id')->get(); }

    /** المشمولون: على رأس عملهم بعد المباشرة (لا قيد المباشرة ولا المنتهية خدمتهم) */
    public static function eligible(PerformanceCycle $c): Collection
    {
        return Employee::whereNotIn('status', ['onboarding', 'terminated'])->when($c->department_id, fn ($q) => $q->where('department_id', $c->department_id))->orderBy('code')->get();
    }

    // ── الدورة ──

    public function save(?PerformanceCycle $c, array $in, User $by): PerformanceCycle
    {
        if ($c && $c->status !== 'draft') throw new ApiException(L::t('الدورة المفتوحة لا تُعدَّل.', 'An opened cycle cannot be edited.'), 'cycle_locked', 409);
        $d = Validator::make($in, ['name_ar' => ['required', 'string', 'min:3', 'max:190'], 'name_en' => ['required', 'string', 'min:3', 'max:190'],
            'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'department_id' => ['nullable', 'integer', 'exists:departments,id']],
            ['ends_on.after_or_equal' => L::t('نهاية الدورة بعد بدايتها.', 'The end must follow the start.')],
            ['name_ar' => L::t('اسم الدورة', 'Cycle name'), 'name_en' => L::t('الاسم بالإنكليزية', 'English name')])->validate();
        $row = ['name_ar' => trim($d['name_ar']), 'name_en' => trim($d['name_en']), 'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'], 'department_id' => $d['department_id'] ?? null];
        if ($c) { $c->update($row); return $c; }
        return PerformanceCycle::create($row + ['status' => 'draft', 'weights' => self::fractions(self::config()['weights']), 'created_by' => $by->id]);
    }

    /** فتح الدورة: مراجعة لكل مشمول + نموذج ذاتي + نموذج المدير + زملاء من نفس الإدارة (يتوزع العبء بالتدوير) */
    public function open(PerformanceCycle $c, User $by): PerformanceCycle
    {
        if ($c->status !== 'draft') throw new ApiException(L::t('الدورة مفتوحة أو مغلقة.', 'The cycle is already open or closed.'), 'cycle_locked', 409);
        $other = PerformanceCycle::where('status', 'open')->where('id', '!=', $c->id)
            ->when($c->department_id, fn ($q) => $q->where(fn ($w) => $w->whereNull('department_id')->orWhere('department_id', $c->department_id)));
        if ($other->exists()) {
            throw new ApiException(L::t('توجد دورة تقييم مفتوحة لنفس الموظفين — اعتمد نتائجها أولاً.', 'Another open cycle covers these employees — approve it first.'), 'cycle_open', 409);
        }
        $emps = self::eligible($c);
        if ($emps->isEmpty()) throw new ApiException(L::t('لا موظفين مشمولين بالدورة.', 'No employees are covered by this cycle.'), 'empty', 422);
        $n = (int) self::config()['peers'];
        $users = User::where('active', true)->whereIn('employee_id', Employee::select('id'))->pluck('id', 'employee_id');
        $tasks = [];
        DB::transaction(function () use ($c, $by, $emps, $n, $users, &$tasks) {
            foreach ($emps as $e) {
                $r = PerformanceReview::create(['performance_cycle_id' => $c->id, 'employee_id' => $e->id, 'ratings' => [], 'status' => 'open']);
                $raters = [['self', $e->id]];
                if ($e->manager_id && $e->manager_id !== $e->id) $raters[] = ['manager', $e->manager_id];
                foreach (self::pickPeers($e, $emps, $n) as $p) $raters[] = ['peer', $p->id];
                foreach ($raters as [$role, $rid]) {
                    PerformanceForm::create(['performance_review_id' => $r->id, 'employee_id' => $e->id, 'role' => $role, 'rater_employee_id' => $rid, 'rater_user_id' => $users[$rid] ?? null]);
                    if (isset($users[$rid])) $tasks[$users[$rid]] = ($tasks[$users[$rid]] ?? 0) + 1;
                }
            }
            $c->update(['status' => 'open', 'opened_at' => now(), 'opened_by' => $by->id, 'weights' => self::fractions(self::config()['weights'])]);
        });
        foreach ($tasks as $uid => $k) Notify::user($uid, "بدأت {$c->name_ar}: لديك {$k} نموذج تقييم بانتظارك حتى {$c->ends_on->toDateString()}", "{$c->name_en} started: {$k} review form(s) await you until {$c->ends_on->toDateString()}", 'reviews');
        Audit::record('performance.opened', $c, null, ['reviews' => $emps->count(), 'forms' => array_sum($tasks)]);
        return $c->refresh();
    }

    /** زملاء من نفس الإدارة غير المدير — بالتدوير بعد ترتيب الموظف ليتوزع العبء */
    public static function pickPeers(Employee $e, Collection $pool, int $n): Collection
    {
        if ($n < 1) return collect();
        $same = $pool->where('department_id', $e->department_id)->sortBy('id')->values();
        $i = $same->search(fn ($x) => $x->id === $e->id);
        $out = collect();
        for ($k = 1; $k < $same->count() && $out->count() < $n; $k++) {
            $p = $same[(($i === false ? 0 : $i) + $k) % $same->count()];
            if ($p->id !== $e->id && $p->id !== $e->manager_id) $out->push($p);
        }
        return $out;
    }

    public function remind(PerformanceCycle $c): int
    {
        if ($c->status !== 'open') throw new ApiException(L::t('الدورة غير مفتوحة.', 'The cycle is not open.'), 'cycle_locked', 409);
        $pending = PerformanceForm::whereHas('review', fn ($q) => $q->where('performance_cycle_id', $c->id))->where('status', 'pending')->whereNotNull('rater_user_id')
            ->selectRaw('rater_user_id, count(*) n')->groupBy('rater_user_id')->pluck('n', 'rater_user_id');
        foreach ($pending as $uid => $k) Notify::user($uid, "تذكير: {$k} نموذج تقييم بانتظارك ضمن {$c->name_ar}", "Reminder: {$k} review form(s) await you in {$c->name_en}", 'reviews');
        return $pending->count();
    }

    // ── الزملاء ──

    public function addPeer(PerformanceReview $r, Employee $p): PerformanceForm
    {
        $this->editable($r);
        if ($p->id === $r->employee_id || PerformanceForm::where('performance_review_id', $r->id)->where('rater_employee_id', $p->id)->exists()) {
            throw ApiException::field('employee_id', L::t('هذا الموظف مقيِّم في هذه المراجعة.', 'This employee already rates this review.'), 'duplicate');
        }
        $f = PerformanceForm::create(['performance_review_id' => $r->id, 'employee_id' => $r->employee_id, 'role' => 'peer', 'rater_employee_id' => $p->id, 'rater_user_id' => User::where('employee_id', $p->id)->where('active', true)->value('id')]);
        if ($f->rater_user_id) Notify::user($f->rater_user_id, 'طُلب منك تقييم زميل — نموذج بانتظارك', 'You were asked to review a colleague', 'reviews');
        return $f;
    }

    public function removePeer(PerformanceForm $f): void
    {
        $this->editable($f->review);
        if ($f->role !== 'peer' || $f->status === 'submitted') throw new ApiException(L::t('يُحذف نموذج زميل لم يُرسل بعد فقط.', 'Only an unsent peer form can be removed.'), 'locked', 409);
        $f->delete();
    }

    // ── النماذج ──

    public function fill(PerformanceForm $f, User $u, array $in, bool $submit): PerformanceForm
    {
        if ($f->rater_user_id !== $u->id) throw new ApiException(L::t('هذا النموذج ليس لك.', 'This form is not yours.'), 'forbidden', 403);
        $this->editable($f->review);
        if ($f->status === 'submitted') throw new ApiException(L::t('أُرسل هذا النموذج.', 'This form was already submitted.'), 'submitted', 409);
        $scale = (int) self::config()['scale'];
        $comps = self::competencies();
        $ratings = [];
        $errors = [];
        foreach ($comps as $c) {
            $v = $in['ratings'][$c->id] ?? $in['ratings'][(string) $c->id] ?? null;
            if ($v === null || $v === '') { if ($submit) $errors["ratings.{$c->id}"] = [L::t("قيّم «{$c->name_ar}».", "Rate «{$c->name_en}».")]; continue; }
            if (! is_numeric($v) || (int) $v != $v || $v < 1 || $v > $scale) { $errors["ratings.{$c->id}"] = [L::t("الدرجة من 1 إلى {$scale}.", "Score 1 to {$scale}.")]; continue; }
            $ratings[$c->id] = (int) $v;
        }
        $potential = $in['potential'] ?? null;
        if ($f->role === 'manager' && $submit && ! in_array((int) $potential, [1, 2, 3], true)) $errors['potential'] = [L::t('حدّد الإمكانات (منخفضة/متوسطة/مرتفعة).', 'Choose the potential (low/mid/high).')];
        $comment = trim((string) ($in['comment'] ?? ''));
        if (mb_strlen($comment) > 2000) $errors['comment'] = [L::t('الملاحظة طويلة جداً.', 'The comment is too long.')];
        if ($errors) throw \Illuminate\Validation\ValidationException::withMessages($errors);
        $f->update(['ratings' => $ratings, 'potential' => $f->role === 'manager' && $potential ? (int) $potential : null, 'comment' => $comment ?: null]
            + ($submit ? ['status' => 'submitted', 'submitted_at' => now()] : []));
        if ($submit) self::recompute($f->review()->firstOrFail());
        return $f;
    }

    // ── الاحتساب ──

    /** متوسطات كل كفاءة لكل دور + النتيجة؛ ومستوى الأداء والإمكانات ما لم تُعايَر يدوياً */
    public static function recompute(PerformanceReview $r, ?array $cfg = null, bool $save = true): PerformanceReview
    {
        $cfg ??= self::config();
        $forms = PerformanceForm::where('performance_review_id', $r->id)->where('status', 'submitted')->get();
        $by = ['self' => $forms->where('role', 'self'), 'manager' => $forms->where('role', 'manager'), 'peers' => $forms->where('role', 'peer')];
        $ratings = [];
        $sum = 0; $n = 0;
        foreach (self::competencies() as $c) {
            $row = [];
            foreach ($by as $role => $fs) {
                $vals = $fs->map(fn ($f) => $f->ratings[$c->id] ?? $f->ratings[(string) $c->id] ?? null)->filter(fn ($v) => $v !== null);
                $row[$role] = $vals->isEmpty() ? null : round($vals->avg(), 2);
            }
            $w = 0; $acc = 0;
            foreach ($row as $role => $v) if ($v !== null) { $acc += $v * $cfg['weights'][$role]; $w += $cfg['weights'][$role]; }
            $row['total'] = $w > 0 ? round($acc / $w, 2) : null;
            if ($w > 0) { $sum += $acc / $w; $n++; }
            $ratings[$c->id] = $row;
        }
        $score = $n ? round($sum / $n, 1) : null;
        $r->ratings = $ratings;
        $r->score = $score;
        if (! $r->calibrated) {
            $r->performance = $score === null ? null : self::level($score, $cfg);
            $pot = $by['manager']->pluck('potential')->filter();
            $r->potential = $pot->isEmpty() ? null : (int) round($pot->avg());
        }
        if ($save) $r->save();
        return $r;
    }

    public static function level(float $score, ?array $cfg = null): int
    {
        $cfg ??= self::config();
        return $score >= (float) $cfg['high_from'] ? 3 : ($score >= (float) $cfg['mid_from'] ? 2 : 1);
    }

    /** المعايرة: سحب الموظف بين مربعات 9-Box (أداء × إمكانات) — يبقى بعد إعادة الاحتساب */
    public function place(PerformanceReview $r, int $p, int $q, ?string $note, User $by): PerformanceReview
    {
        $this->editable($r);
        if (! in_array($p, [1, 2, 3], true) || ! in_array($q, [1, 2, 3], true)) throw ApiException::field('performance', L::t('القيم من 1 إلى 3.', 'Values are 1 to 3.'), 'invalid');
        $r->update(['performance' => $p, 'potential' => $q, 'calibrated' => true, 'calibration_note' => trim((string) $note) ?: $r->calibration_note, 'calibrated_by' => $by->id, 'calibrated_at' => now()]);
        return $r;
    }

    /** اعتماد النتائج: تُغلق الدورة، تُعتمد المراجعات ذات النتيجة، ويُحتسب استحقاق كل منها حسب جدول المكافآت */
    public function approve(PerformanceCycle $c, User $by): array
    {
        if ($c->status !== 'open') throw new ApiException(L::t('الدورة غير مفتوحة.', 'The cycle is not open.'), 'cycle_locked', 409);
        $cfg = self::config();
        $out = ['approved' => 0, 'skipped' => 0, 'awards' => 0, 'review' => 0];
        DB::transaction(function () use ($c, $by, $cfg, &$out) {
            foreach (PerformanceReview::with('employee')->where('performance_cycle_id', $c->id)->get() as $r) {
                self::recompute($r, $cfg);
                if ($r->score === null) { $out['skipped']++; continue; }
                $r->update(['status' => 'approved', 'approved_at' => now()]);
                $out['approved']++;
                $a = $this->award($r, $cfg);
                $out[$a->status === 'review' ? 'review' : 'awards']++;
                if ($uid = User::where('employee_id', $r->employee_id)->value('id')) Notify::user($uid, "صدرت نتيجة تقييمك في {$c->name_ar}", "Your result in {$c->name_en} is out", 'reviews');
            }
            PerformanceForm::whereHas('review', fn ($q) => $q->where('performance_cycle_id', $c->id))->where('status', 'pending')->delete();
            $c->update(['status' => 'closed', 'approved_at' => now(), 'approved_by' => $by->id, 'weights' => self::fractions($cfg['weights'])]);
        });
        if ($out['awards'] + $out['review']) Notify::hr("مكافآت {$c->name_ar}: {$out['awards']} محتسبة و{$out['review']} للمراجعة", "{$c->name_en} rewards: {$out['awards']} calculated, {$out['review']} to review", 'performance');
        Audit::record('performance.approved', $c, null, $out);
        return $out;
    }

    /** D-28: البند الذي تقع فيه النتيجة ← مبلغ (نسبة من الراتب أو مبلغ ثابت) أو نقاط؛ بلا بند ← مراجعة */
    public function award(PerformanceReview $r, ?array $cfg = null): PerformanceAward
    {
        $cfg ??= self::config();
        $e = $r->employee;
        $band = collect($cfg['rewards'])->first(fn ($b) => $r->score >= (float) $b['from'] && $r->score <= (float) $b['to']);
        $row = ['employee_id' => $e->id, 'score' => $r->score, 'band' => $band, 'currency' => $e->currency ?? 'USD', 'kind' => $band['kind'] ?? null, 'amount' => 0, 'points' => 0, 'status' => $band ? 'calculated' : 'review'];
        if ($band) {
            if ($band['kind'] === 'percent') $row['amount'] = round((float) $e->salary * (float) $band['value'] / 100, 2);
            elseif ($band['kind'] === 'amount') $row['amount'] = (float) $band['value'];
            else $row['points'] = (int) $band['value'];
        }
        return PerformanceAward::updateOrCreate(['performance_review_id' => $r->id], $row);
    }

    /** اعتماد صاحب الصلاحية: النقاط تُضاف فوراً (مصروفة)، والمبلغ يدخل كشف الشهر المفتوح ويُصرف عند إغلاقه */
    public function decideAward(PerformanceAward $a, User $by, bool $approve, array $in): PerformanceAward
    {
        if (! in_array($a->status, ['review', 'calculated'], true)) throw new ApiException(L::t('سبق القرار في هذا الاستحقاق.', 'This award was already decided.'), 'decided', 409);
        $note = trim((string) ($in['note'] ?? '')) ?: null;
        $min = (int) Settings::policy('leave_rules')['reject_min'];
        if (! $approve) {
            if (mb_strlen((string) $note) < $min) throw ApiException::field('note', L::t("السبب إلزامي ({$min} أحرف على الأقل).", "A reason is required (at least {$min} characters)."), 'reason_required');
            $a->update(['status' => 'rejected', 'note' => $note, 'decided_by' => $by->id, 'decided_at' => now()]);
            Audit::record('performance.award_rejected', $a, null, ['note' => $note]);
            return $a;
        }
        // خارج السلّم: صاحب الصلاحية يحدد النوع والقيمة
        if ($a->status === 'review' || isset($in['kind'])) {
            $kind = $in['kind'] ?? null; $value = $in['value'] ?? null;
            if (! in_array($kind, ['amount', 'points'], true) || ! is_numeric($value) || $value < 0) throw ApiException::field('value', L::t('حدّد نوع المكافأة وقيمتها.', 'Set the reward type and value.'), 'invalid');
            if (! $note) throw ApiException::field('note', L::t('اكتب سبب القيمة المحددة يدوياً.', 'Explain the manual value.'), 'reason_required');
            $a->kind = $kind; $a->amount = $kind === 'amount' ? (float) $value : 0; $a->points = $kind === 'points' ? (int) $value : 0;
        }
        DB::transaction(function () use ($a, $by, $note) {
            $base = ['note' => $note, 'decided_by' => $by->id, 'decided_at' => now()];
            if ($a->kind === 'points' || ($a->amount <= 0 && $a->points > 0)) {
                $e = Employee::whereKey($a->employee_id)->lockForUpdate()->firstOrFail();
                $e->update(['points' => $e->points + $a->points, 'lifetime_points' => $e->lifetime_points + $a->points]);
                PointLog::create(['employee_id' => $e->id, 'points' => $a->points, 'reason_ar' => "مكافأة التقييم (النتيجة {$a->score})", 'reason_en' => "Performance reward (score {$a->score})", 'by_user_id' => $by->id, 'at' => now()]);
                $a->fill($base + ['status' => 'paid', 'paid_at' => now()])->save();
            } elseif ($a->amount <= 0) {
                $a->fill($base + ['status' => 'rejected'])->save();
            } else {
                $month = now()->format('Y-m');
                if (MonthSheet::where('month', $month)->where('status', 'closed')->exists()) $month = now()->addMonthNoOverflow()->format('Y-m');
                $a->fill($base + ['status' => 'approved', 'month' => $month])->save();
            }
        });
        if ($uid = User::where('employee_id', $a->employee_id)->value('id')) {
            Notify::user($uid, $a->points ? "أُضيفت {$a->points} نقطة مكافأة تقييمك" : "اعتُمدت مكافأة تقييمك وتُصرف مع كشف {$a->month}", $a->points ? "{$a->points} points added for your review" : "Your review reward is approved — paid with the {$a->month} sheet", $a->points ? 'rewards' : 'payslip');
        }
        Audit::record('performance.award_approved', $a, null, ['kind' => $a->kind, 'amount' => $a->amount, 'points' => $a->points, 'month' => $a->month]);
        return $a->refresh();
    }

    private function editable(PerformanceReview $r): void
    {
        $c = $r->cycle()->firstOrFail();
        if ($c->status !== 'open') throw new ApiException(L::t('الدورة ليست مفتوحة للتقييم.', 'The cycle is not open for reviews.'), 'cycle_locked', 409);
    }

    public static function fractions(array $w): array
    {
        $t = array_sum($w) ?: 1;
        return array_map(fn ($v) => round($v / $t, 4), $w);
    }

    // ── العرض ──

    public static function reviewRow(PerformanceReview $r, bool $detail = false): array
    {
        $e = $r->employee;
        $forms = $r->relationLoaded('forms') ? $r->forms : collect();
        $out = ['id' => $r->id, 'employee' => ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en],
            'dept' => $e->department ? ['id' => $e->department->id, 'ar' => $e->department->name_ar, 'en' => $e->department->name_en] : null],
            'performance' => $r->performance, 'potential' => $r->potential, 'score' => $r->score, 'calibrated' => $r->calibrated, 'status' => $r->status,
            'progress' => ['done' => $forms->where('status', 'submitted')->count(), 'total' => $forms->count()]];
        if ($detail) {
            $out['ratings'] = $r->ratings;
            $out['calibration_note'] = $r->calibration_note;
            $out['forms'] = $forms->map(fn (PerformanceForm $f) => ['id' => $f->id, 'role' => $f->role, 'status' => $f->status, 'submitted_at' => $f->submitted_at?->toIso8601String(),
                'rater' => $f->rater ? ['id' => $f->rater->id, 'name' => ['ar' => $f->rater->name, 'en' => $f->rater->name_en]] : null, 'has_user' => (bool) $f->rater_user_id,
                'comment' => $f->comment, 'potential' => $f->potential])->values();
            $out['award'] = $r->award ? self::awardRow($r->award) : null;
        }
        return $out;
    }

    public static function awardRow(PerformanceAward $a): array
    {
        $e = $a->employee;
        return ['id' => $a->id, 'review_id' => $a->performance_review_id, 'employee' => $e ? ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en]] : null,
            'score' => $a->score, 'band' => $a->band, 'kind' => $a->kind, 'amount' => $a->amount, 'currency' => $a->currency, 'points' => $a->points, 'status' => $a->status,
            'month' => $a->month, 'note' => $a->note, 'decided_at' => $a->decided_at?->toIso8601String(), 'paid_at' => $a->paid_at?->toIso8601String()];
    }

    public static function cycleRow(PerformanceCycle $c): array
    {
        return ['id' => $c->id, 'name' => ['ar' => $c->name_ar, 'en' => $c->name_en], 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'starts_on' => $c->starts_on->toDateString(), 'ends_on' => $c->ends_on->toDateString(),
            'status' => $c->status, 'department_id' => $c->department_id, 'department' => $c->department ? ['ar' => $c->department->name_ar, 'en' => $c->department->name_en] : null,
            'weights' => $c->weights, 'opened_at' => $c->opened_at?->toIso8601String(), 'approved_at' => $c->approved_at?->toIso8601String()];
    }
}
