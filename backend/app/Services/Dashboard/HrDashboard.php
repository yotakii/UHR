<?php

namespace App\Services\Dashboard;

use App\Models\Appointment;
use App\Models\Asset;
use App\Models\AttendanceDay;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Offer;
use App\Models\Resignation;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Attendance\DayCalc;
use App\Support\Perm;
use Carbon\Carbon;

/**
 * لوحة المتابعة (M15 — Dashboard.tsx في الديمو) محسوبة على الخادم.
 * كل قسم يظهر حسب صلاحية عرض وحدته؛ «بانتظار موافقتك» موحّدة الأحدث أولاً، والمتأخر عن 24 ساعة مُعلَّم (SRS §10.1).
 */
class HrDashboard
{
    private array $perms;
    private string $today;

    public function __construct(private User $user)
    {
        $this->perms = Perm::for($user);
        $this->today = now()->toDateString();
    }

    private function can(string $module, string $action = 'view'): bool
    {
        return in_array($action, $this->perms[$module]['actions'] ?? [], true);
    }

    public function build(): array
    {
        $out = ['today' => $this->today, 'name' => ['ar' => $this->user->displayName('ar'), 'en' => $this->user->displayName('en')]];
        $employees = $this->can('employees') ? Employee::current()->with(['department', 'location'])->get() : null;
        $active = $employees?->count() ?? 0;

        if ($employees) {
            $out['people'] = ['total' => $active, 'departments' => Department::where('active', true)->count(), 'cities' => $employees->pluck('location_id')->unique()->count(),
                'mix' => $employees->groupBy('department_id')->map(fn ($g) => ['dept' => ['ar' => $g[0]->department->name_ar, 'en' => $g[0]->department->name_en], 'count' => $g->count()])
                    ->sortByDesc('count')->take(6)->values()];
        }
        if ($this->can('attendance')) $out['attendance'] = $this->attendance($active ?: Employee::current()->count());
        $tasks = $this->tasks();
        $out['tasks'] = $tasks;
        $out['alerts'] = $this->alerts();
        if ($this->can('candidates')) {
            $counts = Candidate::selectRaw('stage, count(*) n')->groupBy('stage')->pluck('n', 'stage');
            $out['funnel'] = collect(['new', 'screening', 'interview', 'technical', 'offer', 'hired'])->map(fn ($s) => ['stage' => $s, 'count' => (int) ($counts[$s] ?? 0)])->values();
        }
        return $out;
    }

    /** حضور اليوم + آخر 7 أيام عمل + آخر الحركات */
    private function attendance(int $active): array
    {
        $days = DayCalc::workDays($this->today, 7);
        $rows = AttendanceDay::with('employee.location')->whereIn('date', $days)->whereNotNull('in_at')->get()->groupBy(fn ($d) => $d->date->toDateString());
        $series = array_map(function ($d) use ($rows, $active) {
            $list = $rows[$d] ?? collect();
            return ['date' => $d, 'present' => $list->count(), 'rate' => (int) round($list->count() / max(1, $active) * 100),
                'late' => $list->filter(fn ($x) => DayCalc::day($d, $x->in_at)['late'] > 0)->count()];
        }, $days);
        $today = $rows[$this->today] ?? collect();
        $latest = $today->sortByDesc(fn ($d) => ($d->out_at ?? $d->in_at)->timestamp)->take(9)->map(function ($d) {
            $c = DayCalc::day($this->today, $d->in_at, $d->out_at);
            return ['employee_id' => $d->employee_id, 'name' => ['ar' => $d->employee->name, 'en' => $d->employee->name_en],
                'city' => ['ar' => $d->employee->location->name_ar, 'en' => $d->employee->location->name_en],
                'at' => ($d->out_at ?? $d->in_at)->toIso8601String(), 'kind' => $d->out_at ? 'out' : ($c['late'] ? 'late' : 'in')];
        })->values();
        $isToday = end($days) === $this->today;
        return ['series' => $series, 'today_is_workday' => $isToday, 'present' => $isToday ? end($series)['present'] : 0, 'rate' => $isToday ? end($series)['rate'] : 0,
            'late' => $isToday ? end($series)['late'] : 0, 'grace' => (int) \App\Support\Settings::policy('shift')['grace'], 'latest' => $latest];
    }

    /** «بانتظار موافقتك»: كل ما ينتظر قراراً أو إجراءً من الموارد البشرية، حسب الصلاحيات */
    private function tasks(): array
    {
        $t = [];
        $add = function (string $key, array $who, array $what, ?string $tag, $at, ?string $link, string $kind) use (&$t) {
            $at = $at ? Carbon::parse($at) : now();
            $t[] = ['key' => $key, 'who' => $who, 'what' => ['ar' => $what[0], 'en' => $what[1]], 'tag' => $tag, 'at' => $at->toIso8601String(), 'link' => $link, 'kind' => $kind,
                'overdue' => $at->lt(now()->subDay())];
        };
        $cn = fn ($c) => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name];
        $en = fn ($e) => ['ar' => $e->name, 'en' => $e->name_en];

        // قرارات التعيين: لصاحب المستوى الحالي («اعتماداتي») حتى بلا صلاحية المتقدمين، ولفريق التوظيف للمتابعة
        Appointment::with(['candidate', 'approvals'])->get()->each(function ($a) use ($add, $cn) {
            $p = \App\Services\Recruitment\AppointmentFlow::current($a);
            if (! $p) return;
            $mine = \App\Services\Recruitment\AppointmentFlow::canDecide($this->user, $p);
            if ($mine) $add("ap{$a->id}", $cn($a->candidate), ["قرار تعيين بانتظار اعتمادك — {$p->role_ar}", "Appointment awaiting your approval — {$p->role_en}"], 'F-31', $a->issued_at, 'approvals', 'appointment');
            elseif ($this->can('candidates')) $add("ap{$a->id}", $cn($a->candidate), ["قرار تعيين بانتظار اعتماد {$p->role_ar}", "Appointment awaiting {$p->role_en}"], 'F-31', $a->issued_at, "cand:{$a->candidate_id}", 'appointment');
        });
        if ($this->can('candidates')) {
            Offer::with('candidate')->where('status', 'negotiation')->get()->each(fn ($o) => $add("ng{$o->id}", $cn($o->candidate), ['طلب تعديل بنود عرض العمل', 'Requested offer changes'], 'F-29', $o->responded_at, "cand:{$o->candidate_id}", 'offer'));
            Candidate::with('jobOpening')->where('stage', 'new')->get()->each(fn ($c) => $add("nw{$c->id}", $cn($c), ["طلب توظيف جديد — {$c->jobOpening->title_ar}", "New application — {$c->jobOpening->title_en}"], 'F-28', $c->created_at, "cand:{$c->id}", 'application'));
        }
        if ($this->can('leaves', 'decide')) {
            LeaveRequest::with('employee')->pending()->get()->each(function ($l) use ($add, $en) {
                $type = LeaveRequest::TYPES[$l->type] ?? [$l->type, $l->type];
                $lvl = $l->status === 'pending_hr' ? ['اعتماد الموارد البشرية', 'HR approval'] : ['اعتماد المدير المباشر', 'manager approval'];
                $add("lv{$l->id}", $en($l->employee), ["{$type[0]} · {$lvl[0]}", "{$type[1]} · {$lvl[1]}"], 'F-03', $l->created_at, 'leaves', 'leave');
            });
        }
        if ($this->can('employees')) {
            ServiceRequest::with('employee')->where('status', 'pending')->get()->each(function ($x) use ($add, $en) {
                $k = ServiceRequest::KINDS[$x->kind] ?? [$x->kind, $x->kind];
                $add("sv{$x->id}", $en($x->employee), ["طلب خدمة: {$k[0]}", "Service: {$k[1]}"], null, $x->created_at, 'leaves', 'service');
            });
            Resignation::with('employee')->whereNotIn('status', ['closed', 'rejected'])->get()->each(fn ($r) => $add("rs{$r->id}", $en($r->employee),
                $r->status === 'clearance' ? ['إبراء ذمة قيد الإنجاز', 'Clearance in progress'] : ['طلب استقالة بانتظار الاعتماد', 'Resignation awaiting approval'], 'F-02', $r->created_at, 'offboarding', 'resignation'));
            Employee::with('onboarding')->where('status', 'onboarding')->get()->each(fn ($e) => $add("ob{$e->id}", $en($e),
                ['إجراءات المباشرة '.($e->onboarding?->doneCount() ?? 0).'/28', 'Onboarding '.($e->onboarding?->doneCount() ?? 0).'/28'], 'F-19', $e->hire_date, "onboard:{$e->id}", 'onboarding'));
        }
        usort($t, fn ($a, $b) => strcmp($b['at'], $a['at']));
        return $t;
    }

    /** التنبيهات الثابتة: مهلة المراحل، المتأخرون اليوم، العهد بانتظار التوقيع، فترة التجربة */
    private function alerts(): array
    {
        $a = [];
        if ($this->can('candidates')) {
            $late = Candidate::whereNotIn('stage', ['hired', 'rejected'])->get()->filter(fn ($c) => $c->slaState($this->today) === 'late')->count();
            if ($late) $a[] = ['tone' => 'danger', 'text' => ['ar' => "{$late} متقدمين تجاوزوا مهلة مرحلتهم", 'en' => "{$late} applicants are past their stage limit"], 'link' => 'recruitment'];
        }
        if ($this->can('attendance')) {
            $late = AttendanceDay::where('date', $this->today)->whereNotNull('in_at')->get()->filter(fn ($d) => DayCalc::day($this->today, $d->in_at)['late'] > 0)->count();
            if ($late) $a[] = ['tone' => 'warn', 'text' => ['ar' => "{$late} موظفين متأخرون اليوم", 'en' => "{$late} employees late today"], 'link' => 'attendance'];
        }
        if ($this->can('assets')) {
            $n = Asset::where('status', 'pending_ack')->count();
            if ($n) $a[] = ['tone' => 'info', 'text' => ['ar' => "{$n} عهد بانتظار توقيع الاستلام", 'en' => "{$n} assets awaiting signature"], 'link' => 'custody'];
        }
        if ($this->can('employees')) {
            $n = Employee::where('status', 'probation')->count();
            if ($n) $a[] = ['tone' => 'info', 'text' => ['ar' => "{$n} موظفين في فترة التجربة", 'en' => "{$n} employees on probation"], 'link' => 'employees'];
        }
        return $a;
    }
}
