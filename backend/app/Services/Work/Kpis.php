<?php

namespace App\Services\Work;

use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Kpi;
use App\Models\KpiEntry;
use App\Models\User;
use App\Services\People\Directory;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * مؤشرات قياس الأداء (ملاحظة 62): لموظف أو لإدارة أو للشركة، بهدف واتجاه (الأعلى أفضل/الأقل أفضل) وفترة قياس شهرية أو ربعية أو سنوية.
 * المدير يعرّف مؤشرات فريقه ويسجّل قيمها، ورئيس الإدارة مؤشرات إدارته، والموارد البشرية الكل. نسبة التحقيق تُحسب آلياً،
 * وتحقيق مؤشر فردي يضيف نقاطاً للموظف (من إعداد «points»).
 */
class Kpis
{
    public static function isHr(User $u): bool { return Perm::can($u, 'tasks', 'edit') && Perm::scope($u, 'tasks') === 'all'; }

    public static function canManage(User $u, Kpi $k): bool
    {
        if (self::isHr($u)) return true;
        return match ($k->scope) {
            'employee' => Perm::can($u, 'tasks', 'edit') && $k->employee_id !== $u->employee_id && Directory::scoped($u, 'tasks')->whereKey($k->employee_id)->exists(),
            'department' => $u->employee_id && Department::whereKey($k->department_id)->where('head_id', $u->employee_id)->exists(),
            default => false,
        };
    }

    /** المؤشرات التي يراها المستخدم: مؤشراته، مؤشرات فريقه/نطاقه، مؤشرات إدارته، ومؤشرات الشركة */
    public static function visible(User $u)
    {
        $scope = Perm::can($u, 'tasks') ? Directory::scoped($u, 'tasks')->select('id') : null;
        $dept = $u->employee_id ? Employee::whereKey($u->employee_id)->value('department_id') : null;
        $heads = $u->employee_id ? Department::where('head_id', $u->employee_id)->pluck('id')->all() : [];
        $all = self::isHr($u);
        return Kpi::query()->where(function ($w) use ($u, $scope, $dept, $heads, $all) {
            $w->where('scope', 'company');
            if ($all) { $w->orWhereIn('scope', ['department', 'employee']); return; }
            $w->orWhere(fn ($x) => $x->where('scope', 'department')->whereIn('department_id', array_filter([$dept, ...$heads])));
            $w->orWhere(fn ($x) => $x->where('scope', 'employee')->where(fn ($y) => $y->where('employee_id', $u->employee_id ?? 0)->when($scope, fn ($z) => $z->orWhereIn('employee_id', $scope))));
        });
    }

    public function save(?Kpi $k, array $in, User $by): Kpi
    {
        $d = Validator::make($in, [
            'scope' => [$k ? 'nullable' : 'required', Rule::in(['employee', 'department', 'company'])], 'employee_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
            'name_ar' => ['required', 'string', 'min:3', 'max:190'], 'name_en' => ['nullable', 'string', 'max:190'], 'unit' => ['required', Rule::in(Kpi::UNITS)],
            'target' => ['required', 'numeric', 'min:0', 'max:999999999'], 'direction' => ['required', Rule::in(['higher', 'lower'])], 'period' => ['required', Rule::in(Kpi::PERIODS)],
            'weight' => ['nullable', 'integer', 'between:1,100'], 'note' => ['nullable', 'string', 'max:1000'], 'active' => ['nullable', 'boolean'],
        ], [], ['name_ar' => L::t('اسم المؤشر', 'KPI name'), 'target' => L::t('الهدف', 'Target')])->validate();
        $row = ['name_ar' => trim($d['name_ar']), 'name_en' => trim((string) ($d['name_en'] ?? '')) ?: null, 'unit' => $d['unit'], 'target' => $d['target'], 'direction' => $d['direction'],
            'period' => $d['period'], 'weight' => (int) ($d['weight'] ?? 1), 'note' => trim((string) ($d['note'] ?? '')) ?: null, 'active' => (bool) ($d['active'] ?? true)];
        if ($k) {
            if (! self::canManage($by, $k)) throw new ApiException(L::t('لا تملك تعديل هذا المؤشر.', 'You cannot edit this KPI.'), 'forbidden', 403);
            $k->update($row);
            return $k;
        }
        $k = new Kpi($row + ['scope' => $d['scope'], 'created_by' => $by->id]);
        if ($d['scope'] === 'employee') {
            if (empty($d['employee_id']) || ! Employee::current()->whereKey($d['employee_id'])->exists()) throw ApiException::field('employee_id', L::t('اختر الموظف.', 'Choose the employee.'), 'required');
            $k->employee_id = (int) $d['employee_id'];
        } elseif ($d['scope'] === 'department') {
            if (empty($d['department_id']) || ! Department::whereKey($d['department_id'])->exists()) throw ApiException::field('department_id', L::t('اختر الإدارة.', 'Choose the department.'), 'required');
            $k->department_id = (int) $d['department_id'];
        }
        if (! self::canManage($by, $k)) throw new ApiException(L::t('لا تملك تعريف هذا المؤشر — مؤشرات الفريق لمديره، والإدارة لرئيسها، والشركة للموارد البشرية.', 'You cannot define this KPI — team KPIs belong to the manager, department KPIs to its head, company KPIs to HR.'), 'forbidden', 403);
        $k->save();
        if ($k->employee_id && ($uid = User::where('employee_id', $k->employee_id)->value('id'))) Notify::user($uid, "مؤشر أداء جديد لك: «{$k->name_ar}»", "New KPI for you: «".($k->name_en ?: $k->name_ar).'»', 'kpis');
        return $k;
    }

    public static function periodRule(string $period): string
    {
        return match ($period) { 'month' => '/^\d{4}-(0[1-9]|1[0-2])$/', 'quarter' => '/^\d{4}-Q[1-4]$/', default => '/^\d{4}$/' };
    }

    public static function currentPeriod(string $period): string
    {
        $n = now();
        return match ($period) { 'month' => $n->format('Y-m'), 'quarter' => $n->format('Y').'-Q'.$n->quarter, default => $n->format('Y') };
    }

    /** نسبة التحقيق %: الأعلى أفضل = الفعلي ÷ الهدف، الأقل أفضل = الهدف ÷ الفعلي — بحد أقصى 200 */
    public static function achievement(Kpi $k, float $actual): float
    {
        $t = (float) $k->target;
        $v = $k->direction === 'higher' ? ($t > 0 ? $actual / $t * 100 : ($actual >= $t ? 100 : 0)) : ($actual > 0 ? $t / $actual * 100 : 200);
        return round(min(200, max(0, $v)), 1);
    }

    public function record(Kpi $k, User $by, array $in): KpiEntry
    {
        if (! self::canManage($by, $k)) throw new ApiException(L::t('لا تملك تسجيل قيم هذا المؤشر.', 'You cannot record values for this KPI.'), 'forbidden', 403);
        $d = Validator::make($in, ['period' => ['required', 'string', 'regex:'.self::periodRule($k->period)], 'actual' => ['required', 'numeric', 'min:0', 'max:999999999'], 'note' => ['nullable', 'string', 'max:1000']],
            ['period.regex' => L::t('صيغة الفترة غير صحيحة.', 'Invalid period format.')], ['actual' => L::t('القيمة الفعلية', 'Actual value')])->validate();
        if ($d['period'] > self::currentPeriod($k->period)) throw ApiException::field('period', L::t('لا تُسجَّل قيمة لفترة لم تبدأ.', 'A future period cannot be recorded.'), 'future_period');
        $k->loadMissing('employee');
        $ach = self::achievement($k, (float) $d['actual']);
        $e = KpiEntry::updateOrCreate(['kpi_id' => $k->id, 'period' => $d['period']], ['actual' => $d['actual'], 'achievement' => $ach, 'note' => trim((string) ($d['note'] ?? '')) ?: null, 'entered_by' => $by->id]);
        if ($k->scope === 'employee' && $k->employee) {
            Points::award($k->employee, Points::forKpi($ach), 'kpi', $e->id, "تحقيق مؤشر «{$k->name_ar}» ({$d['period']})", 'KPI achieved «'.($k->name_en ?: $k->name_ar)."» ({$d['period']})", $by->id);
            if ($uid = User::where('employee_id', $k->employee_id)->value('id')) Notify::user($uid, "سُجّلت نتيجة مؤشرك «{$k->name_ar}» لفترة {$d['period']}: {$ach}%", "Your KPI «".($k->name_en ?: $k->name_ar)."» for {$d['period']}: {$ach}%", 'kpis');
        }
        return $e;
    }

    public static function row(Kpi $k, User $u): array
    {
        $entries = $k->relationLoaded('entries') ? $k->entries->take(12) : collect();
        $last = $entries->first();
        return ['id' => $k->id, 'scope' => $k->scope, 'name' => ['ar' => $k->name_ar, 'en' => $k->name_en ?: $k->name_ar], 'name_ar' => $k->name_ar, 'name_en' => $k->name_en,
            'unit' => $k->unit, 'target' => $k->target, 'direction' => $k->direction, 'period' => $k->period, 'weight' => $k->weight, 'note' => $k->note, 'active' => $k->active,
            'employee' => $k->employee ? ['id' => $k->employee->id, 'code' => $k->employee->code, 'name' => ['ar' => $k->employee->name, 'en' => $k->employee->name_en]] : null,
            'department' => $k->department ? ['id' => $k->department->id, 'ar' => $k->department->name_ar, 'en' => $k->department->name_en] : null,
            'current_period' => self::currentPeriod($k->period),
            'latest' => $last ? ['period' => $last->period, 'actual' => $last->actual, 'achievement' => $last->achievement] : null,
            'entries' => $entries->map(fn ($e) => ['period' => $e->period, 'actual' => $e->actual, 'achievement' => $e->achievement, 'note' => $e->note])->values(),
            'can' => ['manage' => self::canManage($u, $k)]];
    }
}
