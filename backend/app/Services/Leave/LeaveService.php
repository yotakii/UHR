<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\AttendanceDay;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RequestStep;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Attendance\Attendance;
use App\Services\Attendance\DayCalc;
use App\Services\People\Directory;
use App\Services\Workflow\Workflow;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * الإجازات (M09، HR.01-01-F-03 — SRS §10.6) بقواعد الشركة من السياسات: أنواع الإجازات وشروطها، مهلة التقديم حسب المدة،
 * البديل الإلزامي، الرصيد (سنوي/مرضي/طارئ/غير مدفوع)، التعارض في نفس الإدارة (ملاحظة إلزامية للمعتمد)، المستويات
 * (+المدير التنفيذي للطويلة وغير المدفوعة؛ إجازة المدير التنفيذي للرئيس التنفيذي حصراً)، وتبرير التأخير/الغياب (D-18).
 */
class LeaveService
{
    public function __construct(private Workflow $flow) {}

    public static function rules(?string $on = null): array { return Settings::policy('leave_rules', $on); }

    public static function type(string $key): ?array
    {
        return collect(Settings::policy('leave_types'))->firstWhere('key', $key);
    }

    /** الرصيد: الاستحقاق، المستخدم، المحجوز لطلبات قيد الاعتماد، المتبقي، المرضي، الطارئ وغير المدفوع لهذه السنة */
    public function balance(Employee $e): array
    {
        $ent = Directory::entitlement($e);
        $year = now()->year;
        $mine = LeaveRequest::where('employee_id', $e->id)->whereIn('status', [...LeaveRequest::PENDING, 'approved'])->get();
        $bal = fn (LeaveRequest $l) => self::type($l->type)['balance'] ?? null;
        $held = $mine->filter(fn ($l) => in_array($l->status, LeaveRequest::PENDING, true) && $bal($l) === 'annual')->sum(fn ($l) => (float) $l->days);
        $r = self::rules();
        return ['entitlement' => $ent, 'used' => (float) $e->leave_used, 'held' => round($held, 2), 'remaining' => round($ent - $e->leave_used - $held, 2), 'sick' => (float) $e->sick_used,
            'emergency' => ['used' => $mine->where('type', 'emergency')->filter(fn ($l) => $l->from->year === $year)->count(), 'limit' => (int) $r['emergency_per_year']],
            'unpaid' => ['used' => round($mine->where('type', 'unpaid')->filter(fn ($l) => $l->from->year === $year)->sum(fn ($l) => (float) $l->days), 2), 'limit' => (int) $r['unpaid_max_per_year']],
            'probation' => in_array($e->status, ['onboarding', 'probation'], true)];
    }

    /** مدة الطلب: أيام عمل (بلا عطل أسبوعية ورسمية حسب جدول الإدارة) أو أيام تقويمية، أو ساعات ÷ ساعات اليوم */
    public function days(Employee $e, array $t, string $from, string $to, ?int $hours = null): float
    {
        if ($t['unit'] === 'hour') return round(($hours ?? 0) / max(1, (int) self::rules($from)['hourly_day_hours']), 2);
        $n = 0;
        for ($d = Carbon::parse($from.' 12:00:00'); $d->toDateString() <= $to; $d->addDay()) {
            $x = $d->toDateString();
            if ($t['calendar'] || (! DayCalc::weekend($x, $e->department_id) && ! DayCalc::holiday($x))) $n++;
        }
        return (float) $n;
    }

    /**
     * فحص الطلب قبل الإرسال: errors تمنع الإرسال (المهلة، البديل، الأهلية، الحد…)، warnings تُحفظ ويحتاج معتمدها ملاحظة
     * (تجاوز الرصيد، تعارض في الإدارة). + المستويات المتوقعة.
     */
    public function check(Employee $e, array $d, ?User $by = null): array
    {
        $err = []; $warn = [];
        $t = self::type($d['type'] ?? '');
        if (! $t) return ['errors' => ['type' => L::t('نوع الإجازة غير معروف.', 'Unknown leave type.')], 'warnings' => [], 'days' => 0, 'levels' => []];
        $r = self::rules();
        $from = $d['from'] ?? null; $to = $t['unit'] === 'hour' ? $from : ($d['to'] ?? $from);
        if (! $from || ! $to || $to < $from) return ['errors' => ['to' => L::t('حدّد فترة صحيحة.', 'Choose a valid period.')], 'warnings' => [], 'days' => 0, 'levels' => []];
        $hours = $t['unit'] === 'hour' ? (int) ($d['hours'] ?? 0) : null;
        if ($t['unit'] === 'hour' && ($hours < 1 || $hours > $r['hourly_max_per_day'])) $err['hours'] = L::t("الإجازة الساعية من 1 إلى {$r['hourly_max_per_day']} ساعات باليوم.", "Hourly leave is 1 to {$r['hourly_max_per_day']} hours a day.");
        $days = $this->days($e, $t, $from, $to, $hours);
        if ($t['unit'] === 'day' && $days <= 0) $err['to'] = L::t('الفترة لا تتضمن أيام عمل.', 'The period has no working days.');

        // الأهلية: شرط الخدمة، بعد التجربة، مرة واحدة، الحد الأقصى
        $months = $e->hire_date ? $e->hire_date->diffInMonths(Carbon::parse($from)) : 0;
        if ($t['after_months'] && $months < $t['after_months']) $err['type'] = L::t("تُستحق بعد {$t['after_months']} شهراً من الخدمة.", "Available after {$t['after_months']} months of service.");
        if ($t['confirmed'] && in_array($e->status, ['onboarding', 'probation'], true)) $err['type'] = L::t('تُستحق بعد تثبيت التعيين (انتهاء فترة التجربة).', 'Available after the probation period.');
        if ($t['once'] && LeaveRequest::where('employee_id', $e->id)->where('type', $t['key'])->whereIn('status', [...LeaveRequest::PENDING, 'approved'])->exists()) $err['type'] = L::t('تُمنح مرة واحدة خلال الخدمة.', 'Granted once during service.');
        if ($t['max_days'] && $days > $t['max_days']) $err['to'] = L::t("الحد الأقصى {$t['max_days']} يوماً لهذا النوع.", "The maximum for this type is {$t['max_days']} days.");
        $b = $this->balance($e);
        if ($t['key'] === 'annual' && $b['probation'] && ! $r['probation_paid']) $err['type'] = L::t('خلال فترة التجربة لا استحقاق مدفوع — اطلب إجازة غير مدفوعة بموافقة المدير.', 'No paid leave during probation — request unpaid leave with manager approval.');
        if ($t['key'] === 'emergency' && $b['emergency']['used'] >= $b['emergency']['limit']) $err['type'] = L::t("استُنفد عدد الإجازات الطارئة لهذه السنة ({$b['emergency']['limit']}).", "This year's emergency leaves are used up ({$b['emergency']['limit']}).");
        if ($t['balance'] === 'unpaid' && $b['unpaid']['used'] + $days > $b['unpaid']['limit']) $err['to'] = L::t("الحد السنوي للإجازة غير المدفوعة {$b['unpaid']['limit']} يوماً.", "The yearly unpaid limit is {$b['unpaid']['limit']} days.");
        if ($t['balance'] === 'annual' && $days > $b['remaining']) $warn['over_balance'] = ['days' => $days, 'remaining' => $b['remaining']];

        // مهلة التقديم حسب المدة (الطارئة والمرضية والوفاة وما شابهها معفاة)
        if ($t['notice']) {
            $need = $t['unit'] === 'hour' ? (int) $r['hourly_notice_days'] : (int) collect($r['notice'])->sortBy('max_days')->first(fn ($n) => $days <= $n['max_days'], ['days_before' => 14])['days_before'];
            $ahead = (int) Carbon::parse(now()->toDateString())->diffInDays(Carbon::parse($from), false);
            if ($ahead < $need) $err['from'] = L::t("هذه الإجازة تُقدَّم قبل {$need} يوماً على الأقل من بدايتها (حسب مدتها). للظروف المفاجئة اختر «إجازة طارئة».", "This leave must be requested at least {$need} days ahead (by its length). For sudden needs choose “Emergency leave”.");
        } elseif ($t['key'] === 'emergency' && Carbon::parse($from)->lt(now()->subHours((int) $r['emergency_report_hours'])->startOfDay())) {
            $err['from'] = L::t("الإجازة الطارئة يُبلَّغ عنها خلال {$r['emergency_report_hours']} ساعة من الغياب.", "Emergency leave must be reported within {$r['emergency_report_hours']} hours of the absence.");
        }

        // البديل الإلزامي (لإجازات الأيام)
        $sub = isset($d['substitute_id']) && $d['substitute_id'] ? Employee::current()->find((int) $d['substitute_id']) : null;
        if ($t['unit'] === 'day' && $r['substitute_required'] && ! $sub) $err['substitute_id'] = L::t('الشخص البديل إلزامي — الطلب بدونه مرفوض.', 'A substitute is required — the request is refused without one.');
        if ($sub && $sub->id === $e->id) $err['substitute_id'] = L::t('لا يمكن أن تكون بديل نفسك.', 'You cannot be your own substitute.');
        elseif ($sub && LeaveRequest::where('employee_id', $sub->id)->whereIn('status', [...LeaveRequest::PENDING, 'approved'])->whereNotIn('type', ['hourly', 'justify'])->where('from', '<=', $to)->where('to', '>=', $from)->exists()) {
            $err['substitute_id'] = L::t('البديل في إجازة خلال نفس الفترة.', 'The substitute is on leave in the same period.');
        }

        // تقاطع مع طلباتك، وتعارض مع زملاء الإدارة
        if (LeaveRequest::where('employee_id', $e->id)->whereIn('status', [...LeaveRequest::PENDING, 'approved'])->where('type', '!=', 'justify')->where('from', '<=', $to)->where('to', '>=', $from)->exists()) {
            $err['from'] = L::t('يتقاطع مع طلب آخر لديك في نفس الفترة.', 'This overlaps another request of yours.');
        }
        if ($t['unit'] === 'day') {
            $clash = LeaveRequest::with('employee')->whereHas('employee', fn ($q) => $q->where('department_id', $e->department_id))->where('employee_id', '!=', $e->id)
                ->whereIn('status', [...LeaveRequest::PENDING, 'approved'])->whereNotIn('type', ['hourly', 'justify'])->where('from', '<=', $to)->where('to', '>=', $from)->get();
            if ($clash->isNotEmpty()) $warn['conflict'] = $clash->map(fn ($l) => ['ar' => $l->employee->name, 'en' => $l->employee->name_en, 'from' => $l->from->toDateString(), 'to' => $l->to->toDateString()])->values()->all();
        }
        return ['errors' => $err, 'warnings' => $warn, 'days' => $days, 'from' => $from, 'to' => $to, 'levels' => array_map(fn ($l) => ['ar' => $l['role_ar'], 'en' => $l['role_en']], $this->levels($e, $t, $days, $by))];
    }

    /** المستويات: سلسلة leave (المدير ← HR) + المدير التنفيذي للطويلة/غير المدفوعة؛ إجازة المدير التنفيذي للرئيس التنفيذي حصراً */
    public function levels(Employee $e, array $t, float $days, ?User $by = null): array
    {
        $u = $by ?? User::with('roles')->where('employee_id', $e->id)->first();
        if ($u?->hasRole('executive')) return [Workflow::level('ceo')];
        $levels = Workflow::chain('leave');
        if (($t['exec'] || ($t['unit'] === 'day' && $days > self::rules()['exec_after_days'])) && ! collect($levels)->contains('role_key', 'executive')) $levels[] = Workflow::level('executive');
        return $levels;
    }

    public function submit(Employee $e, array $d, User $by, ?UploadedFile $doc = null): LeaveRequest
    {
        $c = $this->check($e, $d, $by);
        if ($c['errors']) throw \Illuminate\Validation\ValidationException::withMessages(array_map(fn ($m) => [$m], $c['errors']));
        $t = self::type($d['type']);
        return DB::transaction(function () use ($e, $d, $by, $doc, $c, $t) {
            $l = LeaveRequest::create(['employee_id' => $e->id, 'type' => $t['key'], 'from' => $c['from'], 'to' => $c['to'], 'hours' => $t['unit'] === 'hour' ? (int) $d['hours'] : null,
                'reason' => trim((string) ($d['reason'] ?? '')) ?: $t['ar'], 'substitute_id' => $d['substitute_id'] ?? null, 'days' => $c['days'], 'warnings' => $c['warnings'] ?: null,
                'doc_file_id' => $doc ? $this->store($doc)->id : null, 'requested_by' => $by->id, 'status' => 'pending_manager', 'chain' => null]);
            $e->log("تقديم طلب {$t['ar']}: ".$c['from'].($c['to'] !== $c['from'] ? " — {$c['to']}" : '')." ({$c['days']})", "{$t['en']} requested: ".$c['from'].($c['to'] !== $c['from'] ? " — {$c['to']}" : '')." ({$c['days']})", 'info', 'F-03');
            $this->flow->start($l, $e, $this->levels($e, $t, $c['days'], $by), $by);
            return $l->refresh();
        });
    }

    /** قرار على طلب إجازة/تبرير: ملاحظة إلزامية عند تجاوز الرصيد أو التعارض، والمستند المطلوب قبل الاعتماد النهائي */
    public function decide(LeaveRequest $l, User $u, bool $approve, ?string $note): LeaveRequest
    {
        $w = $l->warnings ?? [];
        $needs = isset($w['over_balance']) || (isset($w['conflict']) && self::rules()['conflict_note_required']);
        $guard = function (LeaveRequest $l, RequestStep $s) {
            $last = ! RequestStep::where('subject_type', $s->subject_type)->where('subject_id', $s->subject_id)->where('status', 'waiting')->exists();
            if ($last && $l->type !== 'justify' && (self::type($l->type)['doc'] ?? 'none') === 'required' && ! $l->doc_file_id) {
                throw new ApiException(L::t('المستند المطلوب لهذه الإجازة لم يُرفق بعد (تقرير طبي، عقد…). يرفقه الموظف من طلباته ثم يُعتمد.', 'The required document has not been attached yet (medical report, certificate…). The employee attaches it, then approve.'), 'doc_required', 409);
            }
        };
        return $this->flow->decide($l, $u, $approve, $note, $needs, fn (LeaveRequest $x) => $this->applied($x), $guard);
    }

    /** الأثر عند الاعتماد النهائي: خصم الرصيد، الأيام المرضية، أو تبرير يوم الدوام */
    private function applied(LeaveRequest $l): void
    {
        $e = Employee::findOrFail($l->employee_id);
        if ($l->type === 'justify') {
            $day = AttendanceDay::firstOrNew(['employee_id' => $e->id, 'date' => $l->ref_date->toDateString()]);
            if (! $day->exists) $day->status = 'absent';
            $day->justified = true; $day->save();
            $e->log('اعتماد تبرير يوم '.$l->ref_date->toDateString(), 'Justification approved for '.$l->ref_date->toDateString(), 'ok');
            return;
        }
        $t = self::type($l->type);
        if (($t['balance'] ?? null) === 'annual') $e->update(['leave_used' => round($e->leave_used + (float) $l->days, 2)]);
        if (($t['balance'] ?? null) === 'sick') $e->update(['sick_used' => (int) round($e->sick_used + (float) $l->days)]);
        $e->log("اعتماد {$t['ar']} نهائياً: {$l->from->toDateString()}".($l->to->ne($l->from) ? " — {$l->to->toDateString()}" : ''), "{$t['en']} approved: {$l->from->toDateString()}".($l->to->ne($l->from) ? " — {$l->to->toDateString()}" : ''), 'ok', 'F-03');
        if ($l->substitute_id && ($sub = User::where('employee_id', $l->substitute_id)->value('id'))) {
            Notify::user($sub, "أنت البديل عن {$e->name} من {$l->from->toDateString()} إلى {$l->to->toDateString()}", "You cover for {$e->name_en} from {$l->from->toDateString()} to {$l->to->toDateString()}", 'requests');
        }
    }

    public function attach(LeaveRequest $l, UploadedFile $f): LeaveRequest
    {
        if (! in_array($l->status, LeaveRequest::PENDING, true)) throw new ApiException(L::t('الطلب مغلق.', 'The request is closed.'), 'closed', 409);
        $l->update(['doc_file_id' => $this->store($f)->id]);
        return $l;
    }

    /** أيام بحاجة لتبرير ضمن المهلة: تأخير، غياب، أو يوم ناقص — مع حالة التبرير إن قُدِّم */
    public function issues(Employee $e): array
    {
        $h = (int) self::rules()['justify_hours'];
        $today = now()->toDateString();
        $from = now()->subHours($h)->subDay()->toDateString();
        $days = AttendanceDay::where('employee_id', $e->id)->whereBetween('date', [$from, $today])->get()->keyBy(fn ($d) => $d->date->toDateString());
        $leave = Attendance::onLeave(collect([$e->id]), $from, $today)[$e->id] ?? [];
        $just = LeaveRequest::where('employee_id', $e->id)->where('type', 'justify')->whereBetween('ref_date', [$from, $today])->whereNotIn('status', ['cancelled'])->get()->keyBy(fn ($l) => $l->ref_date->toDateString());
        $out = [];
        for ($d = Carbon::parse($from.' 12:00:00'); $d->toDateString() <= $today; $d->addDay()) {
            $date = $d->toDateString();
            $row = Attendance::row($e, $date, $days->get($date), isset($leave[$date]));
            if (! in_array($row['status'], ['late', 'absent', 'incomplete', 'justified'], true) || ($row['status'] === 'justified' && ! $just->has($date))) continue;
            $deadline = Carbon::parse($date, config('app.timezone'))->endOfDay()->addHours($h);
            $j = $just->get($date);
            $out[] = $row + ['deadline' => $deadline->toIso8601String(), 'open' => ! $j && now()->lte($deadline) && $row['status'] !== 'justified',
                'justification' => $j ? ['id' => $j->id, 'status' => $j->status] : null];
        }
        return array_reverse($out);
    }

    /** تبرير تأخير/غياب/يوم ناقص (D-18): خلال مهلة justify_hours بعد اليوم، بسبب ومستند اختياري ← المدير ← الموارد البشرية */
    public function justify(Employee $e, string $date, string $reason, User $by, ?UploadedFile $doc = null): LeaveRequest
    {
        $issue = collect($this->issues($e))->firstWhere('date', $date);
        if (! $issue) throw ApiException::field('date', L::t('لا يوجد تأخير أو غياب بحاجة لتبرير في هذا اليوم.', 'There is no lateness or absence to justify on this day.'), 'nothing_to_justify');
        if ($issue['justification']) throw ApiException::field('date', L::t('قُدِّم تبرير لهذا اليوم.', 'A justification was already submitted for this day.'), 'already_justified');
        if (! $issue['open']) throw ApiException::field('date', L::t('انتهت مهلة التبرير لهذا اليوم.', 'The justification window for this day has closed.'), 'justify_closed');
        return DB::transaction(function () use ($e, $date, $reason, $by, $doc, $issue) {
            $l = LeaveRequest::create(['employee_id' => $e->id, 'type' => 'justify', 'from' => $date, 'to' => $date, 'ref_date' => $date, 'minutes' => $issue['late'] ?: null,
                'reason' => $reason, 'doc_file_id' => $doc ? $this->store($doc)->id : null, 'requested_by' => $by->id, 'status' => 'pending_manager', 'days' => 0, 'chain' => null,
                'warnings' => ['issue' => $issue['status']]]);
            $e->log("تقديم تبرير ليوم {$date}", "Justification submitted for {$date}", 'info');
            $this->flow->start($l, $e, Workflow::chain('justification'), $by);
            return $l->refresh();
        });
    }

    private function store(UploadedFile $f): StoredFile
    {
        $cfg = Settings::docs();
        $ext = mb_strtolower($f->getClientOriginalExtension());
        if (! in_array($ext, $cfg['extensions'], true)) throw ApiException::field('file', L::t('صيغة الملف غير مسموحة.', 'File type not allowed.'), 'doc_ext');
        if ($f->getSize() > $cfg['max_mb'] * 1024 * 1024) throw ApiException::field('file', L::t("حجم الملف أكبر من {$cfg['max_mb']} ميغابايت.", "The file is larger than {$cfg['max_mb']} MB."), 'doc_size');
        $path = $f->store('companies/'.Tenant::id().'/leave-docs/'.now()->format('Y/m'), 'local');
        return StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($f->getClientOriginalName(), 0, 255), 'size' => $f->getSize(), 'mime' => $f->getMimeType(), 'uploaded_by' => auth()->id()]);
    }
}
