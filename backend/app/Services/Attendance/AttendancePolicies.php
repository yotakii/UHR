<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Policy;
use App\Models\User;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * سياسات الدوام بتاريخ نفاذ (SRS §10.6، M16): كل تعديل نسخة جديدة تبدأ من تاريخها (رمضان، صيفي/شتوي…)، والنسخ النافذة
 * أو الماضية لا تُعدَّل (حتى لا يتغير ماضٍ محسوب)؛ النسخة المستقبلية تُعدَّل أو تُحذف. الوردية وأيام العمل تقبل نسخة لإدارة بعينها.
 */
class AttendancePolicies
{
    /** المفاتيح التي تديرها صفحة «سياسات الدوام» — وما يقبل تجاوزاً لإدارة */
    public const KEYS = ['shift', 'work_days', 'late_deduction', 'overtime_rates', 'overtime', 'terminal'];
    public const DEPT_KEYS = ['shift', 'work_days'];

    /** كل النسخ لكل مفتاح (والإدارات)، والنافذة اليوم، والقيم الافتراضية */
    public function all(): array
    {
        $today = now()->toDateString();
        $rows = Policy::where(fn ($q) => $q->whereIn('key', self::KEYS)->orWhere(fn ($w) => $w->where('key', 'like', 'shift@%')->orWhere('key', 'like', 'work_days@%')))
            ->orderBy('key')->orderByDesc('effective_from')->get();
        $versions = $rows->groupBy('key')->map(fn ($list, $key) => $list->map(fn (Policy $p) => [
            'id' => $p->id, 'effective_from' => $p->effective_from->toDateString(), 'value' => $this->shape($key, $p->value),
            'state' => $p->effective_from->toDateString() > $today ? 'future' : ($p->id === $list->first(fn ($x) => $x->effective_from->toDateString() <= $today)?->id ? 'current' : 'past'),
        ])->values());
        return ['versions' => $versions, 'current' => collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => Settings::policy($k)]), 'defaults' => array_intersect_key(Settings::POLICY_DEFAULTS, array_flip(self::KEYS)),
            'departments' => Department::where('active', true)->orderBy('sort')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en])];
    }

    /** إضافة نسخة (أو تعديل نسخة مستقبلية بنفس التاريخ) */
    public function save(string $key, ?int $deptId, string $from, array $value, User $by): Policy
    {
        [$base, $full] = $this->key($key, $deptId);
        if ($from < now()->toDateString()) throw ApiException::field('effective_from', L::t('تاريخ النفاذ اليوم أو بعده — الأيام الماضية محسوبة على النسخة السابقة.', 'The effective date is today or later — past days stay on the earlier version.'), 'past_effective');
        $value = $this->validate($base, $value);
        $p = Policy::where('key', $full)->whereDate('effective_from', $from)->first();
        // التدقيق تلقائي على النموذج: policy.created / policy.updated / policy.deleted
        $p ? $p->update(['value' => $value, 'created_by' => $by->id]) : $p = Policy::create(['key' => $full, 'effective_from' => $from, 'value' => $value, 'created_by' => $by->id]);
        Settings::bumpPolicies();
        return $p;
    }

    /** حذف نسخة لم تنفذ بعد فقط */
    public function delete(Policy $p): void
    {
        if (! in_array(explode('@', $p->key)[0], self::KEYS, true)) throw new ApiException(L::t('هذه السياسة لا تُدار من هنا.', 'This policy is not managed here.'), 'policy_key', 422);
        if ($p->effective_from->toDateString() <= now()->toDateString()) throw new ApiException(L::t('النسخة نافذة أو ماضية — أضف نسخة جديدة بدلاً من حذفها.', 'This version is in force or past — add a new version instead.'), 'policy_in_force', 409);
        $p->delete();
        Settings::bumpPolicies();
    }

    /**
     * معاينة قبل الحفظ: آخر 14 يوماً لكل الموظفين بالسياسة الحالية مقابل المقترحة — عدد أيام التأخير، دقائق الحسم، الإضافي، الغياب.
     */
    public function preview(string $key, ?int $deptId, array $value): array
    {
        [$base, $full] = $this->key($key, $deptId);
        $value = $this->validate($base, $value);
        $to = now()->toDateString(); $from = Carbon::parse($to)->subDays(13)->toDateString();
        $scope = Employee::query()->when($deptId, fn ($q) => $q->where('department_id', $deptId));
        $sum = function () use ($scope, $from, $to) {
            $rows = collect(app(Attendance::class)->period($scope, $from, $to)['rows']);
            return ['late_days' => $rows->sum('late_days'), 'deduct' => round($rows->sum('deduct'), 1), 'ot' => $rows->sum('ot'), 'absent' => $rows->sum('absent'), 'employees' => $rows->count()];
        };
        $now = $sum();
        $new = Settings::withPolicies([$full => $value] + ($deptId ? [] : [$base => $value]), $sum);
        return ['from' => $from, 'to' => $to, 'current' => $now, 'proposed' => $new];
    }

    private function key(string $key, ?int $deptId): array
    {
        if (! in_array($key, self::KEYS, true)) throw ApiException::field('key', L::t('سياسة غير معروفة.', 'Unknown policy.'), 'policy_key');
        if ($deptId && ! in_array($key, self::DEPT_KEYS, true)) throw ApiException::field('department_id', L::t('هذه السياسة للشركة كلها.', 'This policy applies company-wide.'), 'policy_company_only');
        if ($deptId && ! Department::whereKey($deptId)->exists()) throw ApiException::field('department_id', L::t('الإدارة غير موجودة', 'Unknown department'), 'unknown');
        return [$key, $deptId ? "{$key}@{$deptId}" : $key];
    }

    /** تحقق وتطبيع قيمة السياسة بترتيب مفاتيح الافتراضي */
    public function validate(string $key, array $v): array
    {
        $m = 'between:0,1439';
        $rules = match ($key) {
            'work_days' => ['days' => ['required', 'array', 'min:1', 'max:7'], 'days.*' => ['integer', 'between:0,6', 'distinct']],
            'shift' => ['start' => ['required', 'integer', $m], 'end' => ['required', 'integer', $m, 'gt:start'], 'grace' => ['required', 'integer', 'between:0,120'], 'break' => ['required', 'integer', 'between:0,240'],
                'break_after' => ['required', 'integer', 'between:0,720'], 'ot_threshold' => ['required', 'integer', 'between:0,240'], 'night_from' => ['required', 'integer', $m]],
            'overtime_rates' => ['day' => ['required', 'numeric', 'between:0,5'], 'night' => ['required', 'numeric', 'between:0,5'], 'rest' => ['required', 'numeric', 'between:0,5'], 'holiday' => ['required', 'numeric', 'between:0,5']],
            'late_deduction' => ['tiers' => ['required', 'array', 'min:1', 'max:10'], 'tiers.*.from' => ['required', 'integer', 'between:1,600'], 'tiers.*.to' => ['required', 'integer', 'between:1,600'],
                'tiers.*.factor' => ['required', 'numeric', 'between:0,10'], 'exempt_upto' => ['required', 'integer', 'between:0,120'], 'exempt_per_month' => ['required', 'integer', 'between:0,31'], 'absent_after' => ['required', 'integer', 'between:1,600']],
            'overtime' => ['weekly_hours' => ['required', 'integer', 'between:1,84'], 'daily_max' => ['required', 'integer', 'between:0,720'], 'requires_assignment' => ['required', 'boolean'], 'max_continuous_hours' => ['required', 'integer', 'between:1,24']],
            'terminal' => ['cooldown' => ['required', 'integer', 'between:0,3600'], 'incomplete_after' => ['required', 'integer', 'between:0,720']],
        };
        $d = Validator::make($key === 'work_days' ? ['days' => $v] : $v, $rules)->validate();
        if ($key === 'work_days') { $days = array_map('intval', $d['days']); sort($days); return $days; }
        if ($key === 'late_deduction') {
            $tiers = collect($d['tiers'])->map(fn ($t) => ['from' => (int) $t['from'], 'to' => (int) $t['to'], 'factor' => (float) $t['factor']])->sortBy('from')->values()->all();
            foreach ($tiers as $i => $t) {
                if ($t['to'] < $t['from'] || ($i && $t['from'] !== $tiers[$i - 1]['to'] + 1)) throw ApiException::field('tiers', L::t('الشرائح متتالية بلا فجوات أو تداخل (مثال 1–15 ثم 16–30).', 'Tiers must be consecutive without gaps or overlaps (e.g. 1–15 then 16–30).'), 'tiers_order');
            }
            if ($tiers[0]['from'] !== 1) throw ApiException::field('tiers', L::t('أول شريحة تبدأ من الدقيقة 1.', 'The first tier starts at minute 1.'), 'tiers_order');
            if ($d['absent_after'] < end($tiers)['to']) throw ApiException::field('absent_after', L::t('حد الغياب بعد آخر شريحة.', 'The absence limit comes after the last tier.'), 'absent_after');
        }
        $def = Settings::POLICY_DEFAULTS[$key];
        $out = [];
        foreach ($def as $k => $x) $out[$k] = match (true) {
            $k === 'tiers' => $tiers, is_bool($x) => (bool) $d[$k], is_float($x) => (float) $d[$k], default => is_numeric($d[$k]) && floor((float) $d[$k]) == $d[$k] && ! is_float($x) ? (int) $d[$k] : $d[$k],
        };
        return $out;
    }

    /** قيمة محفوظة قديمة بشكل الافتراضي */
    private function shape(string $key, mixed $v): mixed
    {
        $def = Settings::POLICY_DEFAULTS[explode('@', $key)[0]] ?? null;
        return is_array($def) && ! array_is_list($def) && is_array($v) ? array_merge($def, array_intersect_key($v, $def)) : $v;
    }
}
