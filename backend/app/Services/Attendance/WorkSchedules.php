<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;
use App\Models\Employee;
use App\Models\Location;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\L;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Support\Facades\Validator;

/**
 * جداول الدوام (ملاحظات الاختبار 24 و76 و80): أي جدول يسري على الموظف في يوم معيّن، بالترتيب:
 *  1. إسناد بفترة (وردية أو قالب موسمي) يغطي التاريخ — الأحدث بدءاً؛
 *  2. جدول الموظف الدائم؛
 *  3. جدول الإدارة ثم الشركة من سياسات الدوام (كما كان).
 * الجدول يحدد بداية الوردية ونهايتها والسماح وأيام العمل؛ بقية القيم (الاستراحة، الإضافي، الليل) من السياسة النافذة.
 * المنطقة الزمنية = منطقة فرع الموظف (مقر العمل)، وإلا منطقة النظام.
 */
class WorkSchedules
{
    private static array $schedules = [];   // [tenant][id] => WorkSchedule
    private static array $assign = [];      // [tenant][employee] => [ShiftAssignment…] الأحدث أولاً
    private static array $tz = [];          // [location] => tz

    public static function forget(): void { self::$schedules = []; self::$assign = []; self::$tz = []; }

    /** @return array{shift: array, work_days: int[], schedule: ?WorkSchedule, shift_key: ?string, tz: string, source: string} */
    public static function resolve(Employee $e, string $date): array
    {
        $attrs = $e->getAttributes();
        $dept = $attrs['department_id'] ?? null;
        $base = ['shift' => Settings::schedule('shift', $date, $dept), 'work_days' => Settings::schedule('work_days', $date, $dept), 'schedule' => null, 'shift_key' => null,
            'tz' => self::tzOf($attrs['location_id'] ?? null), 'source' => 'policy'];
        $t = Tenant::id() ?? 0;
        $a = collect(self::$assign[$t][$e->id] ??= ShiftAssignment::where('employee_id', $e->id)->orderByDesc('from')->orderByDesc('id')->get()->all())
            ->first(fn ($x) => $x->from->toDateString() <= $date && (! $x->to || $x->to->toDateString() >= $date));
        $s = $a ? self::schedule($a->work_schedule_id) : (($sid = $attrs['work_schedule_id'] ?? null) ? self::schedule($sid) : null);
        if (! $s || ! $s->active && ! $a) return $base;
        $shift = collect($s->shifts)->firstWhere('key', $a?->shift_key) ?? ($s->shifts[0] ?? null);
        if (! $shift) return $base;
        return ['shift' => array_merge($base['shift'], ['start' => (int) $shift['start'], 'end' => (int) $shift['end']], isset($shift['grace']) ? ['grace' => (int) $shift['grace']] : []),
            'work_days' => $s->work_days ?: $base['work_days'], 'schedule' => $s, 'shift_key' => $shift['key'] ?? null, 'tz' => $base['tz'], 'source' => $a ? 'assignment' : 'employee'];
    }

    public static function tz(Employee $e): string { return self::tzOf($e->getAttributes()['location_id'] ?? null); }

    private static function tzOf(?int $locationId): string
    {
        if (! $locationId) return config('app.timezone');
        return self::$tz[$locationId] ??= (Location::withoutGlobalScopes()->whereKey($locationId)->value('timezone') ?: config('app.timezone'));
    }

    private static function schedule(int $id): ?WorkSchedule
    {
        $t = Tenant::id() ?? 0;
        if (! isset(self::$schedules[$t])) self::$schedules[$t] = WorkSchedule::all()->keyBy('id')->all();
        return self::$schedules[$t][$id] ?? null;
    }

    /** إنشاء/تعديل جدول: ورديات ضمن اليوم نفسه (البداية قبل النهاية)، مفاتيح فريدة، يوم عمل واحد على الأقل */
    public function save(?WorkSchedule $s, array $in): WorkSchedule
    {
        $d = Validator::make($in, [
            'name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['nullable', 'string', 'max:120'], 'kind' => ['required', 'in:fixed,shifts'],
            'shifts' => ['required', 'array', 'min:1', 'max:6'], 'shifts.*.key' => ['required', 'string', 'regex:/^[a-z0-9_]{1,20}$/', 'distinct'],
            'shifts.*.ar' => ['required', 'string', 'max:60'], 'shifts.*.en' => ['nullable', 'string', 'max:60'],
            'shifts.*.start' => ['required', 'integer', 'between:0,1439'], 'shifts.*.end' => ['required', 'integer', 'between:1,1440'], 'shifts.*.grace' => ['nullable', 'integer', 'between:0,120'],
            'work_days' => ['required', 'array', 'min:1', 'max:7'], 'work_days.*' => ['integer', 'between:0,6', 'distinct'],
            'season_from' => ['nullable', 'date'], 'season_to' => ['nullable', 'date', 'after_or_equal:season_from'],
            'color' => ['nullable', 'in:brand,brass,info,danger,ink-3'], 'active' => ['boolean'],
        ], [], ['name_ar' => L::t('اسم الجدول', 'Schedule name')])->validate();
        foreach ($d['shifts'] as $i => $sh) if ($sh['end'] <= $sh['start']) throw ApiException::field("shifts.$i.end", L::t('نهاية الوردية بعد بدايتها في اليوم نفسه.', 'A shift ends after it starts, on the same day.'), 'shift_order');
        if ($d['kind'] === 'fixed') $d['shifts'] = [array_values($d['shifts'])[0]];
        $row = ['name_ar' => trim($d['name_ar']), 'name_en' => trim((string) ($d['name_en'] ?? '')) ?: null, 'kind' => $d['kind'],
            'shifts' => array_map(fn ($x) => ['key' => $x['key'], 'ar' => $x['ar'], 'en' => $x['en'] ?? $x['ar'], 'start' => (int) $x['start'], 'end' => (int) $x['end']] + (isset($x['grace']) ? ['grace' => (int) $x['grace']] : []), array_values($d['shifts'])),
            'work_days' => array_values(array_map('intval', $d['work_days'])), 'season_from' => $d['season_from'] ?? null, 'season_to' => $d['season_to'] ?? null,
            'color' => $d['color'] ?? 'brand', 'active' => $d['active'] ?? true];
        $s = $s ? tap($s)->update($row) : WorkSchedule::create($row);
        self::forget();
        return $s;
    }

    /**
     * إسناد جدول لموظفين: دائم (يغيّر جدول الموظف) أو لفترة (مناوبة/موسم) بوردية محددة.
     * الفترة لا تتداخل مع إسناد آخر للموظف نفسه.
     */
    public function assign(array $in, User $by): int
    {
        $d = Validator::make($in, ['employee_ids' => ['required', 'array', 'min:1', 'max:500'], 'employee_ids.*' => ['integer'], 'work_schedule_id' => ['nullable', 'integer'],
            'mode' => ['required', 'in:permanent,period'], 'shift_key' => ['nullable', 'string', 'max:20'], 'from' => ['required_if:mode,period', 'nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'], 'note' => ['nullable', 'string', 'max:300']])->validate();
        $s = $d['work_schedule_id'] ? WorkSchedule::findOrFail($d['work_schedule_id']) : null;
        if ($d['mode'] === 'period' && ! $s) throw ApiException::field('work_schedule_id', L::t('اختر الجدول.', 'Choose the schedule.'), 'required');
        if ($s && ($d['shift_key'] ?? null) && ! collect($s->shifts)->contains('key', $d['shift_key'])) throw ApiException::field('shift_key', L::t('الوردية غير موجودة في الجدول.', 'Unknown shift in this schedule.'), 'unknown');
        $emps = Employee::current()->whereIn('id', $d['employee_ids'])->get();
        foreach ($emps as $e) {
            if ($d['mode'] === 'permanent') {
                if ($e->work_schedule_id !== $s?->id) {
                    $e->update(['work_schedule_id' => $s?->id]);
                    $e->log('جدول الدوام: '.($s?->name_ar ?? 'جدول الشركة'), 'Work schedule: '.($s?->name_en ?? $s?->name_ar ?? 'company schedule'), 'info');
                }
                continue;
            }
            $to = $d['to'] ?? null;
            $clash = ShiftAssignment::where('employee_id', $e->id)->where('from', '<=', $to ?? '9999-12-31')->where(fn ($q) => $q->whereNull('to')->orWhere('to', '>=', $d['from']))->exists();
            if ($clash) throw ApiException::field('from', L::t("لدى {$e->name} إسناد آخر يتداخل مع هذه الفترة — احذفه أو غيّر التواريخ.", "{$e->name_en} already has an assignment overlapping this period."), 'assignment_overlap');
            ShiftAssignment::create(['employee_id' => $e->id, 'work_schedule_id' => $s->id, 'shift_key' => $d['shift_key'] ?? $s->shifts[0]['key'], 'from' => $d['from'], 'to' => $to,
                'note' => $d['note'] ?? null, 'created_by' => $by->id]);
            $e->log("إسناد {$s->name_ar} من {$d['from']}".($to ? " إلى {$to}" : ''), "Assigned {$s->name_ar} from {$d['from']}".($to ? " to {$to}" : ''), 'info');
        }
        self::forget();
        return $emps->count();
    }
}
