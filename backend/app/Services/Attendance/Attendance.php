<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;
use App\Models\AttendanceDay;
use App\Models\Device;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Scan;
use App\Models\User;
use App\Support\Audit;
use App\Support\CardUid;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use App\Support\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * M08 — الحضور (Attendance في الديمو + SRS §10.6، §10.10): حالة اليوم، السجل اليومي، ملخص الفترة،
 * التصحيح اليدوي بسبب، وتسجيل الحركة من جهاز الحضور (nfcScan): دخول ثم خروج، تجاهل التكرار، وبند F-19 رقم 15 عند أول تمرير.
 */
class Attendance
{
    public const STATUSES = ['before_start', 'leave', 'holiday', 'weekend', 'not_in', 'absent', 'incomplete', 'justified', 'late', 'present'];

    /**
     * حالة يوم موظف بعد الحساب: قبل المباشرة، إجازة، عطلة، لم يسجل بعد (اليوم)، غياب (أو تأخير فوق absent_after)،
     * ناقص (دخول بلا خروج بعد نهاية الوردية + incomplete_after)، تأخير مبرر، متأخر، حاضر.
     */
    public static function status(?AttendanceDay $r, array $c, string $date, Employee $e, bool $onLeave, ?CarbonInterface $now = null): string
    {
        $now ??= now();
        $today = $now->toDateString();
        $hire = $e->hire_date?->toDateString();
        if ($hire && $hire > $date) return 'before_start';
        if ($onLeave || $r?->status === 'leave') return 'leave';
        if (! $r?->in_at) return $c['rest'] ? ($c['holiday'] ? 'holiday' : 'weekend') : ($date === $today ? 'not_in' : ($date > $today ? 'not_in' : 'absent'));
        if (! $r->out_at) {
            $shift = Settings::schedule('shift', $date, $e->department_id);
            $limit = Carbon::parse($date, config('app.timezone'))->startOfDay()->addMinutes($shift['end'] + (int) Settings::policy('terminal', $date)['incomplete_after']);
            if ($date < $today || $now->greaterThan($limit)) return 'incomplete';
        }
        if ($c['late'] > 0 && ! $r->justified && DayCalc::lateAbsent($c['late'], $date)) return 'absent';
        if ($c['late'] > 0) return $r->justified ? 'justified' : 'late';
        return 'present';
    }

    /** صف يوم واحد لموظف (السجل اليومي، الملف، الجهاز) */
    public static function row(Employee $e, string $date, ?AttendanceDay $r, bool $onLeave): array
    {
        $c = DayCalc::day($date, $r?->in_at, $r?->out_at, $e->department_id);
        return ['date' => $date, 'in' => self::hm($r?->in_at), 'out' => self::hm($r?->out_at), 'on_shift' => $r?->in_at && ! $r->out_at && $date === now()->toDateString(),
            'worked' => $c['worked'], 'late' => $c['late'], 'early' => $c['early'], 'ot' => $c['ot'], 'ot_rate' => $c['ot_rate'], 'deduct' => $c['deduct'],
            'status' => self::status($r, $c, $date, $e, $onLeave), 'source' => $r?->source, 'corrected' => (bool) $r?->corrected_at, 'note' => $r?->correction_note];
    }

    /** السجل اليومي: كل من باشر حتى التاريخ في نطاق المستخدم — مع العدادات */
    public function daily(Builder $scope, string $date, ?int $deptId = null): array
    {
        $emps = (clone $scope)->current()->with('department')->where('hire_date', '<=', $date)->when($deptId, fn ($q) => $q->where('department_id', $deptId))->orderBy('code')->get();
        $days = AttendanceDay::whereIn('employee_id', $emps->pluck('id'))->where('date', $date)->get()->keyBy('employee_id');
        $leave = self::onLeave($emps->pluck('id'), $date, $date);
        $rows = $emps->map(fn (Employee $e) => ['employee' => self::person($e)] + self::row($e, $date, $days->get($e->id), isset($leave[$e->id][$date])))->values();
        $count = fn (array $s) => $rows->whereIn('status', $s)->count();
        return ['date' => $date, 'rows' => $rows->sortBy(fn ($r) => $r['in'] ?? 'z')->values(),
            'counts' => ['present' => $rows->whereNotNull('in')->count(), 'late' => $count(['late', 'justified']), 'absent' => $count(['absent']), 'leave' => $count(['leave']), 'incomplete' => $count(['incomplete'])]];
    }

    /** ملخص فترة لكل موظف: أيام الحضور، الغياب، مجموع التأخير ودقائق الحسم، الإضافي، الأيام الناقصة */
    public function period(Builder $scope, string $from, string $to, ?int $deptId = null): array
    {
        $emps = (clone $scope)->current()->with('department')->where('hire_date', '<=', $to)->when($deptId, fn ($q) => $q->where('department_id', $deptId))->orderBy('code')->get();
        $days = AttendanceDay::whereIn('employee_id', $emps->pluck('id'))->whereBetween('date', [$from, $to])->get()->groupBy('employee_id');
        $leave = self::onLeave($emps->pluck('id'), $from, $to);
        $dates = [];
        for ($d = Carbon::parse($from.' 12:00:00'); $d->toDateString() <= $to; $d->addDay()) $dates[] = $d->toDateString();
        $rows = $emps->map(function (Employee $e) use ($days, $leave, $dates) {
            $mine = ($days->get($e->id) ?? collect())->keyBy(fn ($d) => $d->date->toDateString());
            $t = ['present' => 0, 'absent' => 0, 'late' => 0, 'late_days' => 0, 'deduct' => 0.0, 'ot' => 0, 'incomplete' => 0, 'leave' => 0];
            foreach ($dates as $date) {
                if ($date > now()->toDateString()) break;
                $r = self::row($e, $date, $mine->get($date), isset($leave[$e->id][$date]));
                if ($r['in']) $t['present']++;
                if ($r['status'] === 'absent') $t['absent']++;
                if ($r['status'] === 'incomplete') $t['incomplete']++;
                if ($r['status'] === 'leave') $t['leave']++;
                if ($r['late'] && $r['status'] !== 'absent') { $t['late'] += $r['late']; $t['late_days']++; if ($r['status'] === 'late') $t['deduct'] += $r['deduct']; }
                $t['ot'] += $r['ot'];
            }
            return ['employee' => self::person($e)] + $t;
        })->values();
        return ['from' => $from, 'to' => $to, 'rows' => $rows];
    }

    /** تصحيح يدوي من الموارد البشرية (اليوم الناقص، نسيان البطاقة…): سبب إلزامي، يُسجَّل في التدقيق والسجل الوظيفي */
    public function correct(Employee $e, string $date, ?string $in, ?string $out, string $note, User $by): AttendanceDay
    {
        if ($date > now()->toDateString()) throw ApiException::field('date', L::t('لا يمكن تصحيح يوم لم يأتِ بعد.', 'You cannot correct a future day.'), 'future_day');
        if ($e->hire_date && $e->hire_date->toDateString() > $date) throw ApiException::field('date', L::t('اليوم قبل تاريخ المباشرة.', 'The day is before the start date.'), 'before_start');
        $at = fn (?string $hm) => $hm ? Carbon::parse("{$date} {$hm}:00", config('app.timezone')) : null;
        [$i, $o] = [$at($in), $at($out)];
        if ($o && ! $i) throw ApiException::field('in', L::t('أدخل وقت الدخول أولاً.', 'Enter the check-in time first.'), 'in_required');
        if ($i && $o && $o->lte($i)) throw ApiException::field('out', L::t('وقت الخروج بعد الدخول.', 'Check-out must be after check-in.'), 'out_before_in');
        return DB::transaction(function () use ($e, $date, $i, $o, $note, $by) {
            $d = AttendanceDay::firstOrNew(['employee_id' => $e->id, 'date' => $date]);
            $before = ['in' => self::hm($d->in_at), 'out' => self::hm($d->out_at)];
            $d->fill(['in_at' => $i, 'out_at' => $o, 'source' => 'manual', 'corrected_by' => $by->id, 'corrected_at' => now(), 'correction_note' => $note]);
            $c = DayCalc::day($date, $i, $o, $e->department_id);
            $d->status = ! $i ? ($c['rest'] ? 'weekend' : 'absent') : ($c['late'] ? 'late' : 'present');
            $d->save();
            $after = ['in' => self::hm($i), 'out' => self::hm($o)];
            Audit::record('attendance.corrected', $e, $before, $after + ['date' => $date, 'note' => $note]);
            $e->log("تصحيح دوام {$date}: ".($after['in'] ?? '—').' → '.($after['out'] ?? '—')." — {$note} ({$by->displayName('ar')})",
                "Attendance corrected {$date}: ".($after['in'] ?? '—').' → '.($after['out'] ?? '—')." — {$note} ({$by->displayName('en')})", 'warn');
            Notify::user($e->user()->value('id'), "صُحّح دوامك ليوم {$date}: {$note}", "Your attendance for {$date} was corrected: {$note}");
            return $d;
        });
    }

    /**
     * تمرير بطاقة على جهاز مسجّل: يطابق الرقم بكل صيغه، يتجاهل التكرار خلال cooldown ثانية،
     * الحركة الأولى في اليوم دخول وما بعدها خروج (آخر خروج يُعتمد).
     * @return array{kind: 'ok'|'dup'|'unknown'|'inactive', employee?: array, type?: string, time?: string, late?: int, worked?: int, ot?: int, uid: ?string}
     */
    public function scan(Device $device, string $raw, string $via = 'usb', ?CarbonInterface $at = null): array
    {
        $at = ($at ?? now())->copy()->setTimezone(config('app.timezone'));
        $uid = CardUid::format($raw);
        $e = CardUid::match(Employee::query(), $raw)->with('department')->first();
        $device->update(['last_seen_at' => now(), 'last_ip' => request()?->ip()]);
        self::rememberRead($device, $uid ?? CardUid::norm($raw), (bool) $e);
        if (! $e) return ['kind' => 'unknown', 'uid' => $uid ?? CardUid::norm($raw)];
        if (in_array($e->status, ['terminated'], true)) return ['kind' => 'inactive', 'employee' => self::person($e), 'uid' => $uid];
        $cool = (int) Settings::policy('terminal')['cooldown'];
        $last = Scan::where('employee_id', $e->id)->latest('ts')->first();
        if ($last && $last->ts->diffInSeconds($at, true) < $cool) return ['kind' => 'dup', 'employee' => self::person($e), 'uid' => $uid];

        return DB::transaction(function () use ($e, $device, $at, $via, $raw, $uid) {
            $date = $at->toDateString();
            $d = AttendanceDay::where('employee_id', $e->id)->where('date', $date)->lockForUpdate()->first() ?? new AttendanceDay(['employee_id' => $e->id, 'date' => $date]);
            $type = $d->in_at ? 'out' : 'in';
            if ($type === 'in') { $d->in_at = $at; $d->source = 'device'; } else $d->out_at = $at;
            $c = DayCalc::day($date, $d->in_at, $d->out_at, $e->department_id);
            $d->status = $c['late'] ? 'late' : ($c['rest'] ? 'weekend' : 'present');
            $d->save();
            Scan::create(['employee_id' => $e->id, 'ts' => $at, 'device_id' => $device->id, 'device_ar' => $device->name_ar, 'device_en' => $device->name_en, 'type' => $type, 'raw_uid' => mb_substr($raw, 0, 40), 'via' => $via]);
            $this->firstScan($e);
            return ['kind' => 'ok', 'employee' => self::person($e), 'type' => $type, 'time' => $at->format('H:i'), 'late' => $c['late'], 'worked' => $c['worked'], 'ot' => $c['ot'], 'in' => self::hm($d->in_at), 'uid' => $uid];
        });
    }

    /** F-19 البند 15 «تعريف الموظف على النظام وجهاز الحضور» يُنجز عند أول تمرير (كما في الديمو) */
    private function firstScan(Employee $e): void
    {
        $ob = $e->onboarding()->first();
        if (! $ob || $ob->confirmed_at || ($ob->checklist[14] ?? false)) return;
        $list = $ob->checklist; $list[14] = true;
        $ob->update(['checklist' => $list]);
        $e->log('أول تسجيل على جهاز الحضور — إنجاز البند 15 من F-19', 'First tap on the attendance terminal — F-19 item 15 done', 'ok', 'F-19');
    }

    /**
     * آخر بطاقة قُرئت على أي جهاز للشركة (دقيقتان): قارئ ACS الموصول بالحاسوب (PC/SC) لا يكتب في المتصفح،
     * فيرسل الجسر للخادم، وتلتقط نافذة «ربط البطاقة» في ملف الموظف الرقم من هنا.
     */
    public static function rememberRead(Device $device, string $uid, bool $known): void
    {
        Cache::put('card-read:'.Tenant::id(), ['uid' => $uid, 'known' => $known, 'at' => now()->toIso8601String(), 'device' => ['ar' => $device->name_ar, 'en' => $device->name_en]], 120);
    }

    public static function lastRead(): ?array { return Cache::get('card-read:'.Tenant::id()); }

    /** آخر الحركات على جهاز (شاشة الجهاز) */
    public static function feed(Device $device, int $n = 7): Collection
    {
        return Scan::with('employee')->where('device_id', $device->id)->where('ts', '>=', now()->startOfDay())->latest('ts')->limit($n)->get()
            ->map(fn (Scan $s) => ['id' => $s->id, 'name' => ['ar' => $s->employee->name, 'en' => $s->employee->name_en], 'type' => $s->type, 'time' => $s->ts->copy()->setTimezone(config('app.timezone'))->format('H:i'), 'via' => $s->via]);
    }

    /** [employee_id][date] => true لأيام الإجازات المعتمدة (عدا الساعية والتبرير) */
    public static function onLeave(Collection $ids, string $from, string $to): array
    {
        $out = [];
        LeaveRequest::whereIn('employee_id', $ids)->where('status', 'approved')->whereNotIn('type', ['hourly', 'justify'])->where('to', '>=', $from)->where('from', '<=', $to)->get(['employee_id', 'from', 'to'])
            ->each(function ($l) use (&$out, $from, $to) {
                for ($d = Carbon::parse(max($l->from->toDateString(), $from).' 12:00:00'); $d->toDateString() <= min($l->to->toDateString(), $to); $d->addDay()) $out[$l->employee_id][$d->toDateString()] = true;
            });
        return $out;
    }

    public static function person(Employee $e): array
    {
        return ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en],
            'dept' => $e->relationLoaded('department') && $e->department ? ['id' => $e->department_id, 'ar' => $e->department->name_ar, 'en' => $e->department->name_en] : null];
    }

    public static function hm(?CarbonInterface $t): ?string
    {
        return $t?->copy()->setTimezone(config('app.timezone'))->format('H:i');
    }
}
