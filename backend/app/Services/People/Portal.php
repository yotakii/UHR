<?php

namespace App\Services\People;

use App\Exceptions\ApiException;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Scan;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Attendance\Attendance;
use App\Services\Attendance\DayCalc;
use App\Services\Leave\LeaveService;
use App\Services\Onboarding\OnboardingFlow;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * بوابة الموظف M14 (PortalShell في الديمو): الرئيسية (دوام اليوم + التسجيل من الجوال، الأرصدة، الطلبات الأخيرة، فريقي اليوم، رحلتي)،
 * «فريقي»، و«ملفي الشخصي». التسجيل من البوابة حسب سياسة terminal (portal_checkin + نطاق جغرافي اختياري).
 */
class Portal
{
    public function __construct(private LeaveService $leaves) {}

    public function home(Employee $e): array
    {
        $e->loadMissing(['department', 'manager', 'onboarding']);
        $today = now()->toDateString();
        $d = AttendanceDay::where('employee_id', $e->id)->where('date', $today)->first();
        $onLeave = isset(Attendance::onLeave(collect([$e->id]), $today, $today)[$e->id][$today]);
        $row = Attendance::row($e, $today, $d, $onLeave);
        $bal = $this->leaves->balance($e);
        $sick = collect(Settings::policy('leave_types'))->firstWhere('balance', 'sick');
        $recent = LeaveRequest::where('employee_id', $e->id)->whereNotIn('type', ['justify'])->latest('id')->limit(6)->get()
            ->map(fn ($l) => ['kind' => 'leave', 'id' => $l->id, 'type' => $l->type, 'title' => LeaveService::type($l->type) ? ['ar' => LeaveService::type($l->type)['ar'], 'en' => LeaveService::type($l->type)['en']] : ['ar' => $l->type, 'en' => $l->type],
                'date' => $l->from?->toDateString(), 'status' => $l->status, 'at' => $l->created_at?->toIso8601String()])
            ->concat(ServiceRequest::where('employee_id', $e->id)->latest('id')->limit(6)->get()
                ->map(fn ($s) => ['kind' => 'service', 'id' => $s->id, 'type' => $s->kind, 'title' => null, 'date' => $s->created_at?->toDateString(), 'status' => $s->status, 'at' => $s->created_at?->toIso8601String()]))
            ->sortByDesc('at')->take(6)->values();
        $ob = $e->onboarding;
        return ['me' => Attendance::person($e) + ['status' => $e->status, 'hire_date' => $e->hire_date?->toDateString(), 'points' => (int) $e->points,
                'manager' => $e->manager ? ['ar' => $e->manager->name, 'en' => $e->manager->name_en] : null, 'probation_end' => OnboardingFlow::probationEnd($e, $ob),
                'tenure_days' => $e->hire_date ? (int) $e->hire_date->diffInDays(now()) : 0, 'resigned' => in_array($e->status, ['resigning', 'terminated'], true), 'onboarding' => (bool) $ob],
            'today' => ['date' => $today, 'in' => $row['in'], 'out' => $row['out'], 'late' => $row['late'], 'status' => $row['status'], 'checkin' => $this->canCheckIn($e, $d, $row['status'])],
            'geo' => $this->geo(), 'balance' => ['annual' => ['left' => $bal['remaining'], 'of' => $bal['entitlement'], 'used' => $bal['used']],
                'sick' => ['used' => $bal['sick'], 'of' => $sick['max_days'] ?? null], 'points' => (int) $e->points],
            'recent' => $recent, 'team' => $this->team($e, 8)];
    }

    /** زر التسجيل: السياسة مفعّلة، يوم عمل بلا إجازة، ولم يُسجَّل الخروج بعد */
    private function canCheckIn(Employee $e, ?AttendanceDay $d, string $status): bool
    {
        if (! Settings::policy('terminal')['portal_checkin'] || $e->status === 'terminated') return false;
        if (in_array($status, ['weekend', 'holiday', 'leave'], true)) return false;
        return ! $d?->out_at;
    }

    private function geo(): ?array
    {
        $t = Settings::policy('terminal');
        return (int) $t['geo_radius'] > 0 ? ['lat' => (float) $t['geo_lat'], 'lng' => (float) $t['geo_lng'], 'radius' => (int) $t['geo_radius']] : null;
    }

    /** تسجيل حضور/خروج من البوابة (الجهاز: «بوابة الموظف — الجوال») */
    public function checkIn(Employee $e, ?float $lat, ?float $lng): array
    {
        $today = now()->toDateString();
        $d = AttendanceDay::where('employee_id', $e->id)->where('date', $today)->first();
        $onLeave = isset(Attendance::onLeave(collect([$e->id]), $today, $today)[$e->id][$today]);
        $status = Attendance::row($e, $today, $d, $onLeave)['status'];
        if (! Settings::policy('terminal')['portal_checkin']) throw new ApiException(L::t('التسجيل من البوابة غير مفعّل — استخدم جهاز الحضور.', 'Portal check-in is off — use the attendance terminal.'), 'portal_checkin_off', 403);
        if (! $this->canCheckIn($e, $d, $status)) throw new ApiException(L::t('لا تسجيل اليوم: عطلة أو إجازة أو سجّلت خروجك.', 'No check-in today: day off, leave, or already checked out.'), 'checkin_closed', 409);
        if ($g = $this->geo()) {
            if ($lat === null || $lng === null) throw new ApiException(L::t('اسمح بتحديد موقعك لتسجيل الحضور.', 'Allow location access to check in.'), 'location_required', 422);
            $dist = self::distance($lat, $lng, $g['lat'], $g['lng']);
            if ($dist > $g['radius']) throw new ApiException(L::t("أنت خارج نطاق المقر (حوالي ".round($dist)." متر).", "You are outside the office radius (about ".round($dist)." m)."), 'out_of_range', 422);
        }
        $cool = (int) Settings::policy('terminal')['cooldown'];
        $last = Scan::where('employee_id', $e->id)->latest('ts')->first();
        if ($last && $last->ts->diffInSeconds(now(), true) < $cool) throw new ApiException(L::t('سُجّلت حركتك للتو.', 'Your tap was just recorded.'), 'dup', 409);
        $at = now()->setTimezone(config('app.timezone'));
        return DB::transaction(function () use ($e, $at, $today, $lat, $lng) {
            $d = AttendanceDay::where('employee_id', $e->id)->where('date', $today)->lockForUpdate()->first() ?? new AttendanceDay(['employee_id' => $e->id, 'date' => $today]);
            $type = $d->in_at ? 'out' : 'in';
            if ($type === 'in') { $d->in_at = $at; $d->source = 'portal'; } else $d->out_at = $at;
            $c = DayCalc::day($today, $d->in_at, $d->out_at, $e->department_id);
            $d->status = $c['late'] ? 'late' : ($c['rest'] ? 'weekend' : 'present');
            $d->save();
            Scan::create(['employee_id' => $e->id, 'ts' => $at, 'device_id' => null, 'device_ar' => 'بوابة الموظف — الجوال', 'device_en' => 'Portal — mobile', 'type' => $type,
                'raw_uid' => $lat !== null ? round($lat, 5).','.round($lng, 5) : 'portal', 'via' => 'phone']);
            return ['type' => $type, 'time' => $at->format('H:i'), 'late' => $c['late']];
        });
    }

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000; $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
        $a = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** «فريقي»: زملاء الإدارة اليوم — موجود منذ / في إجازة / غادر / لم يصل؛ آخر 7 أرقام من الجوال */
    public function team(Employee $e, ?int $limit = null): array
    {
        $today = now()->toDateString();
        $team = Employee::current()->where('department_id', $e->department_id)->orderBy('code')->get();
        $days = AttendanceDay::whereIn('employee_id', $team->pluck('id'))->where('date', $today)->get()->keyBy('employee_id');
        $leave = Attendance::onLeave($team->pluck('id'), $today, $today);
        $rows = $team->map(function (Employee $x) use ($days, $leave, $today, $e) {
            $d = $days->get($x->id);
            $st = isset($leave[$x->id][$today]) || $x->status === 'leave' ? 'leave' : ($d?->out_at ? 'out' : ($d?->in_at ? 'in' : 'none'));
            return ['id' => $x->id, 'name' => ['ar' => $x->name, 'en' => $x->name_en], 'title' => ['ar' => $x->title_ar, 'en' => $x->title_en], 'me' => $x->id === $e->id,
                'state' => $st, 'since' => $st === 'in' ? Attendance::hm($d->in_at) : null, 'phone' => $x->phone ? mb_substr(preg_replace('/\s+/', '', $x->phone), -7) : null];
        })->values();
        $others = $rows->where('me', false)->values();
        return ['dept' => $e->department ? ['ar' => $e->department->name_ar, 'en' => $e->department->name_en] : null,
            'rows' => $limit ? $others->take($limit)->values() : $rows, 'away' => $others->where('state', 'leave')->count(), 'total' => $others->count()];
    }

    public function profile(Employee $e): array
    {
        $e->loadMissing(['department', 'location', 'manager', 'logs']);
        return ['me' => Attendance::person($e) + ['email' => $e->email, 'phone' => $e->phone, 'national_id' => $e->national_id, 'hire_date' => $e->hire_date?->toDateString(),
                'location' => $e->location ? ['ar' => $e->location->name_ar, 'en' => $e->location->name_en] : null, 'card' => $e->nfc_uid, 'status' => $e->status,
                'manager' => $e->manager ? ['ar' => $e->manager->name, 'en' => $e->manager->name_en] : null],
            'log' => $e->logs->sortByDesc('at')->values()->map(fn ($l) => ['at' => $l->at?->toIso8601String(), 'ar' => $l->text_ar, 'en' => $l->text_en, 'kind' => $l->kind, 'form' => $l->form])->take(60)->values()];
    }

    /** تعديل الجوال (updateContact في الديمو): مباشرة + سطر في السجل + إشعار HR + تدقيق */
    public function updatePhone(Employee $e, string $phone, User $by): Employee
    {
        $phone = trim($phone);
        if (! preg_match('/^\+?[0-9 ]{7,20}$/', $phone)) throw ApiException::field('phone', L::t('رقم جوال غير صالح.', 'Invalid mobile number.'), 'invalid');
        if ($phone === $e->phone) return $e;
        $old = $e->phone;
        $e->update(['phone' => $phone]);
        $e->log("تحديث رقم الجوال من البوابة", 'Mobile number updated from the portal');
        Notify::hr("حدّث {$e->name} رقم جواله", "{$e->name_en} updated the mobile number", 'emp:'.$e->id);
        Audit::record('profile.phone', $e, ['phone' => $old], ['phone' => $phone]);
        return $e;
    }
}
