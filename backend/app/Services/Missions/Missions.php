<?php

namespace App\Services\Missions;

use App\Exceptions\ApiException;
use App\Models\AttendanceDay;
use App\Models\CodeItem;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\Mission;
use App\Models\MissionComment;
use App\Models\PayLine;
use App\Models\User;
use App\Services\Codes\CodeGen;
use App\Services\Codes\Codes;
use App\Services\People\Directory;
use App\Services\Security\SalaryVault;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * المهمات والسفر (الجزء B — WF-TM-01/02): الإنشاء والإسناد (موظف/فريق/فرع)، الحساب التقديري بقاعدة المحافظة وجدول المعدلات،
 * الاعتماد بالمسار الموحد للأنواع ذات الأثر المالي، السلفة، التنفيذ بالمراحل والإثبات، التسوية بالحدود، ثم بنود الراتب.
 */
class Missions
{
    public const DEFAULT_CHAIN = [['role_ar' => 'المدير المباشر', 'role_en' => 'Direct manager', 'role_key' => 'manager'], ['role_ar' => 'الموارد البشرية', 'role_en' => 'Human Resources', 'role_key' => 'hr_director'],
        ['role_ar' => 'المدير المالي', 'role_en' => 'Finance director', 'role_key' => 'finance'], ['role_ar' => 'الرئيس التنفيذي', 'role_en' => 'CEO', 'role_key' => 'ceo']];
    public const OPEN = ['draft', 'pending_manager', 'pending_hr', 'pending_exec', 'approved', 'assigned', 'in_progress', 'review', 'returned', 'done', 'settling'];
    public const ZONES = ['local', 'foreign'];

    public static function cfg(): array { return Settings::merged('missions'); }
    public static function reject(): int { return (int) Settings::policy('leave_rules')['reject_min']; }

    /** اسم الوجهة (المحافظة أو الدولة) من الدليل الموحد — للعرض فقط */
    public static function place(array $t): ?array
    {
        $key = ($t['scope'] ?? 'local') === 'foreign' ? 'countries' : 'governorates';
        $code = $key === 'countries' ? ($t['country'] ?? null) : ($t['governorate'] ?? null);
        if (! $code) return null;
        foreach (Codes::items($key, false) as $r) if ($r['code'] === $code) return ['ar' => $r['ar'], 'en' => $r['en']];
        return null;
    }

    public static function isTravel(?CodeItem $t): bool { return (bool) $t?->attr('travel', false); }
    public static function financial(?CodeItem $t): bool { return (bool) $t?->attr('financial', false); }

    /** معدلات درجة الموظف النافذة في تاريخ (جدول درجة × نطاق × عملة × تاريخ سريان) */
    public static function rates(?Employee $e, ?string $on = null): array
    {
        $on ??= now()->toDateString(); $cfg = self::cfg();
        $grade = $e?->grade_id ? CodeItem::find($e->grade_id)?->code : null;
        $rows = collect($cfg['rates'])->filter(fn ($r) => ($r['valid_from'] ?? '2000-01-01') <= $on)->sortByDesc('valid_from');
        // FR-CS-018: الدرجة تحمل فئة بدل سفر — صف الفئة إن عُرّف، وإلا صف الدرجة
        $class = $e?->grade_id ? CodeItem::find($e->grade_id)?->attr('travel_class') : null;
        if ($class && ! empty($cfg['rates_by_class'][$class])) return $cfg['rates_by_class'][$class] + ['grade' => $grade, 'class' => $class];
        return $rows->firstWhere('grade', $grade ?? $cfg['default_grade']) ?? $rows->firstWhere('grade', $cfg['default_grade']) ?? (array) collect($cfg['rates'])->first();
    }

    /** محافظة فرع الموظف (ريف دمشق تابع لدمشق — POL-TM-02) */
    public static function homeGov(?Employee $e): ?string
    {
        $g = $e?->location_id ? Location::find($e->location_id)?->governorate : null;
        return $g === 'RDM' ? 'DAM' : $g;
    }

    public static function kmPrice(): float
    {
        $c = self::cfg();
        return round((float) $c['fuel_price'] / max(1, (float) $c['km_per_tank']) * (1 + (float) $c['depreciation_pct'] / 100), 2);
    }

    public static function days(array $t): float
    {
        if (empty($t['depart_on']) || empty($t['return_on'])) return 0;
        $d = Carbon::parse($t['depart_on'])->diffInDays(Carbon::parse($t['return_on'])) + 1;   // شاملاً الطرفين (BR-TM-07)
        return self::cfg()['half_travel_days'] && $d >= 2 ? $d - 1 : (float) $d;
    }

    /** بطاقة التكلفة التقديرية + التحذيرات (التعارضات، قاعدة المحافظة) — FR-TM-010/011/022 */
    public function estimate(Mission $m): array
    {
        $t = $m->travel ?? []; $e = $m->employee; $type = $m->type; $cfg = self::cfg();
        $out = ['lines' => [], 'warnings' => [], 'days' => 0];
        if ($e) {
            $from = $t['depart_on'] ?? $m->starts_on?->toDateString(); $to = $t['return_on'] ?? $m->due_on?->toDateString();
            if ($from && $to) {
                if (LeaveRequest::where('employee_id', $e->id)->whereIn('status', ['approved', 'pending_manager', 'pending_hr', 'pending_exec'])->where('from', '<=', $to)->where('to', '>=', $from)->exists())
                    $out['warnings'][] = ['key' => 'leave', 'ar' => 'تتعارض مع إجازة للموظف في الفترة نفسها', 'en' => 'Overlaps a leave of the employee'];
                if (Mission::where('employee_id', $e->id)->whereKeyNot($m->id ?? 0)->whereIn('status', self::OPEN)->whereNotNull('travel')
                    ->where(fn ($q) => $q->whereRaw("(travel->>'depart_on') <= ?", [$to])->whereRaw("(travel->>'return_on') >= ?", [$from]))->exists())
                    $out['warnings'][] = ['key' => 'mission', 'ar' => 'تتعارض مع مهمة سفر أخرى للموظف', 'en' => 'Overlaps another travel mission'];
            }
        }
        if (! self::isTravel($type) && ! ($t['km'] ?? 0)) return MissionExtras::adjust($m, $out);
        $days = self::isTravel($type) ? self::days($t) : 0; $out['days'] = $days;
        $r = self::rates($e, $t['depart_on'] ?? null);
        $zone = ($t['scope'] ?? 'local') === 'foreign' ? 'foreign' : 'local';
        $same = $zone === 'local' && ($g = self::homeGov($e)) && ($dest = ($t['governorate'] ?? null) === 'RDM' ? 'DAM' : ($t['governorate'] ?? null)) && $g === $dest;
        if ($same) $out['warnings'][] = ['key' => 'same_gov', 'ar' => 'الوجهة ضمن محافظة فرع الموظف أو ريفها: لا بدل سفر، ومصاريف الانتقال فقط', 'en' => "The destination is in the employee's branch governorate: no travel allowance, transport only"];
        if (self::isTravel($type) && ! $same && $days > 0) {
            $out['lines'][] = ['code' => $zone === 'foreign' ? 'E-111' : 'E-110', 'qty' => $days, 'rate' => (float) $r[$zone], 'amount' => round($days * (float) $r[$zone], 2), 'currency' => $r[$zone.'_currency']];
            $nights = max(0, (int) ceil($days) - 1);
            $limit = (float) ($r['hotel_'.$zone] ?? 0);
            if ($nights && $limit > 0) $out['lines'][] = ['code' => 'E-130', 'qty' => $nights, 'rate' => $limit, 'amount' => round($nights * $limit, 2), 'currency' => $r['hotel_'.$zone.'_currency'], 'limit' => true];
            $out['ticket'] = $r['ticket'] ?? 'economy';
        }
        if (($t['transport'] ?? '') === 'car' && ($km = (float) ($t['km'] ?? 0)) > 0)
            $out['lines'][] = ['code' => 'E-120', 'qty' => $km, 'rate' => self::kmPrice(), 'amount' => round($km * self::kmPrice(), 2), 'currency' => $cfg['fuel_currency']];
        $out['zone'] = $zone; $out['same_gov'] = (bool) $same; $out['grade'] = $r['grade'] ?? null;
        if ($e?->grade_id && ($tk = CodeItem::find($e->grade_id)?->attr('ticket'))) $out['ticket'] = $tk;
        $out['totals'] = collect($out['lines'])->groupBy('currency')->map(fn ($g) => round($g->sum('amount'), 2))->all();
        return MissionExtras::adjust($m, $out);
    }

    // ———————————————— الإنشاء والإسناد والاعتماد ————————————————

    public static function canManage(User $u): bool { return Perm::can($u, 'missions', 'edit') && Perm::scope($u, 'missions') === 'all'; }

    /** إنشاء أو تعديل مسودة (FR-TM-001/003/004) */
    public function save(User $u, array $v, ?Mission $m = null): Mission
    {
        if ($m && ! in_array($m->status, ['draft', 'returned'], true)) throw new ApiException(L::t('تُعدَّل المهمة وهي مسودة فقط.', 'A mission can be edited only as a draft.'), 'locked', 409);
        $err = [];
        $type = Codes::resolve('mission_types', $v['type_id'] ?? $m?->type_id) ?? null;
        if (! $type) $err['type_id'] = L::t('اختر نوع المهمة.', 'Choose the mission type.');
        $title = trim((string) ($v['title'] ?? $m?->title));
        if (mb_strlen($title) < 3) $err['title'] = L::t('اكتب عنوان المهمة.', 'Write the mission title.');
        $assign = $v['assignee_type'] ?? $m?->assignee_type ?? 'employee';
        if (! in_array($assign, ['employee', 'team', 'branch'], true)) $assign = 'employee';
        $emp = null;
        if ($assign === 'employee') {
            $id = (int) ($v['employee_id'] ?? $m?->employee_id ?? $u->employee_id);
            $emp = Directory::scoped($u, 'missions')->current()->find($id) ?? ($id === $u->employee_id ? Employee::find($id) : null);
            if (! $emp) $err['employee_id'] = L::t('اختر المنفّذ من نطاقك.', 'Choose the executor from your scope.');
        } elseif (! self::canManage($u) && ! Perm::can($u, 'missions', 'create')) $err['assignee_type'] = L::t('إسناد الفريق أو الفرع للموارد البشرية والمدراء.', 'Team or branch missions are for HR and managers.');
        $ref = $assign === 'employee' ? null : (int) ($v['assignee_ref'] ?? $m?->assignee_ref);
        if ($assign !== 'employee' && ! $ref) $err['assignee_ref'] = L::t('اختر الفريق أو الفرع.', 'Choose the team or branch.');
        $travel = null;
        if (self::isTravel($type)) {
            $t = (array) ($v['travel'] ?? $m?->travel ?? []);
            $scope = ($t['scope'] ?? 'local') === 'foreign' ? 'foreign' : 'local';
            if (empty($t['depart_on']) || empty($t['return_on'])) $err['travel.depart_on'] = L::t('حدّد تاريخي الانطلاق والعودة.', 'Set the departure and return dates.');
            elseif ($t['return_on'] < $t['depart_on']) $err['travel.return_on'] = L::t('تاريخ العودة قبل الانطلاق.', 'Return is before departure.');
            if ($scope === 'local' && ! Codes::resolve('governorates', $t['governorate'] ?? '')) $err['travel.governorate'] = L::t('اختر محافظة الوجهة.', 'Choose the destination governorate.');
            if ($scope === 'foreign' && ! Codes::resolve('countries', $t['country'] ?? '')) $err['travel.country'] = L::t('اختر الدولة.', 'Choose the country.');
            if (mb_strlen(trim((string) ($t['purpose'] ?? ''))) < 3) $err['travel.purpose'] = L::t('اكتب الغرض من السفر.', 'Write the purpose.');
            $travel = ['scope' => $scope, 'country' => $scope === 'foreign' ? strtoupper($t['country']) : 'SY', 'governorate' => $scope === 'local' ? strtoupper((string) ($t['governorate'] ?? '')) : null,
                'city' => mb_substr(trim((string) ($t['city'] ?? '')), 0, 120), 'purpose' => mb_substr(trim((string) ($t['purpose'] ?? '')), 0, 1000),
                'depart_on' => $t['depart_on'] ?? null, 'return_on' => $t['return_on'] ?? null, 'transport' => in_array($t['transport'] ?? 'road', ['road', 'air', 'car'], true) ? ($t['transport'] ?? 'road') : 'road',
                'km' => max(0, (float) ($t['km'] ?? 0)), 'legs' => array_slice(array_values(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 120), (array) ($t['legs'] ?? [])))), 0, 10)];
        }
        if (! empty($v['external_id']) && ! \App\Models\External::whereKey((int) $v['external_id'])->whereIn('status', ['approved', 'assigned', 'active'])->exists()) $err['external_id'] = L::t('اختر خبيراً معتمداً.', 'Choose an approved expert.');
        if ($err) throw new ApiException(L::t('راجع الحقول المظللة.', 'Check the highlighted fields.'), 'invalid', 422, array_map(fn ($x) => [$x], $err));
        // AC-TM-01: سفر محلي ضمن محافظة الفرع ← يُصنَّف مهمة ميدانية MT-20
        if ($type->code === 'MT-30' && $emp && $travel && self::homeGov($emp) && self::homeGov($emp) === ($travel['governorate'] === 'RDM' ? 'DAM' : $travel['governorate'])) {
            $type = Codes::resolve('mission_types', 'MT-20') ?? $type;
            $travel['reclassified'] = 'MT-30';
        }
        $cat = Codes::resolve('mission_categories', $v['category_id'] ?? null);
        $cc = Codes::resolve('cost_centers', $v['cost_center_id'] ?? null);
        $stages = MissionExtras::stagesFor($type) ?? collect(self::cfg()['stages'])->map(fn ($s) => ['name' => $s['name'], 'en' => $s['en'] ?? $s['name'], 'weight' => (int) $s['weight'], 'done_at' => null])->all();
        $data = ['type_id' => $type->id, 'category_id' => $cat?->id, 'title' => $title, 'description' => trim((string) ($v['description'] ?? $m?->description)) ?: null,
            'requester_department_id' => $v['requester_department_id'] ?? $m?->requester_department_id ?? $emp?->department_id, 'owner_id' => $v['owner_id'] ?? $m?->owner_id ?? ($emp?->manager_id ?? $u->employee_id),
            'employee_id' => $emp?->id, 'assignee_type' => $assign, 'assignee_ref' => $ref, 'priority' => in_array($v['priority'] ?? 'normal', ['low', 'normal', 'high', 'urgent'], true) ? ($v['priority'] ?? 'normal') : 'normal',
            'starts_on' => $v['starts_on'] ?? $travel['depart_on'] ?? $m?->starts_on?->toDateString() ?? now()->toDateString(), 'due_on' => $v['due_on'] ?? $travel['return_on'] ?? $m?->due_on?->toDateString(),
            'cost_center_id' => $cc?->id, 'travel' => $travel, 'stages' => $m && $m->type_id === $type->id ? $m->stages : $stages,
            'external_id' => self::canManage($u) ? ($v['external_id'] ?? $m?->external_id) : $m?->external_id,
            'ticket_class' => in_array($v['ticket_class'] ?? null, MissionExtras::TICKETS, true) ? $v['ticket_class'] : $m?->ticket_class];
        $adv = (float) ($v['advance'] ?? ($m?->advance['amount'] ?? 0));
        $data['advance'] = $adv > 0 ? ['amount' => round($adv, 2), 'currency' => strtoupper((string) ($v['advance_currency'] ?? $m?->advance['currency'] ?? 'SYP')), 'status' => 'requested'] : null;
        return DB::transaction(function () use ($u, $m, $data, $type, $emp) {
            if (! $m) {
                $br = $emp?->location_id ? strtoupper((string) Location::find($emp->location_id)?->code) : 'HQ';
                $m = Mission::create($data + ['ref_no' => CodeGen::next('transaction', ['type' => 'MS', 'br' => $br]), 'status' => 'draft', 'requested_by' => $u->id]);
            } else $m->update($data + ['status' => 'draft']);
            Codes::markUsed($type->id, $data['category_id'], $data['cost_center_id']);
            $m->update(['estimate' => $this->estimate($m->load(['type', 'employee']))]);
            self::log($m, $u, 'save');
            return $m->refresh();
        });
    }

    public static function log(Mission $m, ?User $u, string $what, ?string $note = null): void
    {
        $log = $m->log ?? [];
        $log[] = ['what' => $what, 'by' => $u?->displayName(app()->getLocale()), 'at' => now()->toIso8601String(), 'note' => $note];
        $m->forceFill(['log' => array_slice($log, -60)])->saveQuietly();
    }

    /** إرسال: الأنواع ذات الأثر المالي للاعتماد بالمسار (FR-TM-008)، والبقية تُسند مباشرة */
    public function submit(Mission $m, User $u): Mission
    {
        if (! in_array($m->status, ['draft', 'returned'], true)) throw new ApiException(L::t('المهمة ليست مسودة.', 'The mission is not a draft.'), 'locked', 409);
        $m->loadMissing(['type', 'employee']);
        // BR-TM-09: لا مهمة سفر جديدة مع مهمة سفر سابقة تجاوزت مهلة إغلاقها
        if (self::isTravel($m->type) && $m->employee_id && self::cfg()['block_unclosed']
            && Mission::where('employee_id', $m->employee_id)->whereKeyNot($m->id)->whereNotNull('close_due')->where('close_due', '<', now()->toDateString())->whereIn('status', ['approved', 'assigned', 'in_progress', 'review', 'done'])->exists())
            throw new ApiException(L::t('لدى الموظف مهمة سفر سابقة تجاوزت مهلة إغلاقها — أغلقها أولاً.', 'The employee has an earlier travel mission past its closing deadline — close it first.'), 'unclosed_mission', 409);
        $m->update(['estimate' => $this->estimate($m)]);
        MissionExtras::enforceTicket($m);
        if (self::financial($m->type) && $m->employee) {
            $m->update(['status' => 'pending_manager']);
            app(Workflow::class)->start($m, $m->employee, Workflow::chainFor('mission', MissionExtras::chainContext($m)), $u);
            self::log($m, $u, 'submitted');
        } else $this->approved($m->refresh(), $u);
        return $m->refresh();
    }

    public function decide(Mission $m, User $u, bool $approve, ?string $note): Mission
    {
        $m = app(Workflow::class)->decide($m, $u, $approve, $note, false, fn (Mission $x) => $this->approved($x->load(['type', 'employee']), $u));
        if (! $approve) self::log($m, $u, 'rejected', $note);
        return $m->refresh();
    }

    /** بعد الاعتماد: الإسناد، تعليم أيام «في مهمة» بالحضور، وإنشاء المهمات الفرعية للفريق/الفرع */
    public function approved(Mission $m, User $u): void
    {
        $m->update(['status' => 'assigned', 'decided_at' => now(), 'close_due' => self::isTravel($m->type) && ! empty($m->travel['return_on']) ? self::closeDue($m->travel['return_on']) : null]);
        self::log($m, $u, 'approved');
        if ($m->assignee_type !== 'employee') {
            $ids = $m->assignee_type === 'team' ? Employee::current()->where('department_id', $m->assignee_ref)->pluck('id') : Employee::current()->where('location_id', $m->assignee_ref)->pluck('id');
            foreach (Employee::whereIn('id', $ids)->get() as $e) {
                $c = $m->replicate(['ref_no', 'employee_id', 'parent_id', 'assignee_type', 'assignee_ref', 'log', 'estimate']);
                $c->fill(['ref_no' => $m->ref_no.'-'.$e->code, 'employee_id' => $e->id, 'parent_id' => $m->id, 'assignee_type' => 'employee', 'status' => 'assigned', 'log' => []])->save();
                if ($uid = $e->user()->value('id')) Notify::user($uid, "أُسندت إليك مهمة: {$m->title}", "A mission was assigned to you: {$m->title}", 'missions');
            }
        } elseif ($m->employee) {
            if (self::isTravel($m->type)) $this->markDays($m, true);
            if ($uid = $m->employee->user()->value('id')) Notify::user($uid, "أُسندت إليك مهمة: {$m->title} ({$m->ref_no})", "A mission was assigned to you: {$m->title} ({$m->ref_no})", 'missions');
            if (($m->advance['amount'] ?? 0) > 0) Notify::hr("سلفة مهمة {$m->ref_no} بانتظار الصرف من المالية", "Mission advance {$m->ref_no} awaits payment by finance", "mission:{$m->id}");
        }
        Audit::record('mission.approved', $m);
    }

    public static function closeDue(string $return): string
    {
        $d = Carbon::parse($return); $n = (int) self::cfg()['close_days'];
        while ($n > 0) { $d->addDay(); if (! in_array($d->dayOfWeek, [Carbon::FRIDAY], true)) $n--; }
        return $d->toDateString();
    }

    /** BR-TM-12: أيام المهمة المعتمدة = «في مهمة» بالحضور (لا غياب ولا تأخر) */
    public function markDays(Mission $m, bool $on): void
    {
        $t = $m->travel ?? []; if (empty($t['depart_on']) || ! $m->employee_id) return;
        for ($d = Carbon::parse($t['depart_on']); $d->toDateString() <= $t['return_on']; $d->addDay()) {
            $date = $d->toDateString();
            $r = AttendanceDay::where('employee_id', $m->employee_id)->whereDate('date', $date)->first();
            if ($on) { if ($r) $r->update(['status' => 'mission']); else AttendanceDay::create(['employee_id' => $m->employee_id, 'date' => $date, 'status' => 'mission']); }
            elseif ($r && $r->status === 'mission') { if ($r->in_at) $r->update(['status' => 'present']); else $r->delete(); }
        }
    }

    // ———————————————— التنفيذ ————————————————

    public static function isExecutor(User $u, Mission $m): bool { return $u->employee_id && $u->employee_id === $m->employee_id; }
    public static function isOwner(User $u, Mission $m): bool { return ($u->employee_id && $u->employee_id === $m->owner_id) || self::canManage($u); }

    public function progress(Mission $m, User $u, array $v): Mission
    {
        if (! self::isExecutor($u, $m)) throw new ApiException(L::t('التحديث من منفّذ المهمة.', 'Updates are by the executor.'), 'forbidden', 403);
        if (! in_array($m->status, ['assigned', 'in_progress', 'returned'], true)) throw new ApiException(L::t('المهمة ليست قيد التنفيذ.', 'The mission is not in progress.'), 'locked', 409);
        $stages = $m->stages ?? [];
        foreach ((array) ($v['stages'] ?? []) as $i => $done) if (isset($stages[$i])) $stages[$i]['done_at'] = $done ? ($stages[$i]['done_at'] ?? now()->toIso8601String()) : null;
        $total = max(1, array_sum(array_column($stages, 'weight')));
        $pct = isset($v['progress']) && ! $stages ? max(0, min(100, (int) $v['progress'])) : (int) round(array_sum(array_map(fn ($s) => $s['done_at'] ? $s['weight'] : 0, $stages)) * 100 / $total);
        $m->update(['stages' => $stages, 'progress' => $pct, 'status' => 'in_progress']);
        if ($m->parent_id) self::rollUp($m->parent_id);
        return $m->refresh();
    }

    private static function rollUp(int $parentId): void
    {
        $p = Mission::find($parentId); if (! $p) return;
        $kids = Mission::where('parent_id', $parentId)->get();
        $p->update(['progress' => (int) round($kids->avg('progress') ?? 0), 'status' => $kids->every(fn ($k) => in_array($k->status, ['done', 'settling', 'posted', 'closed'], true)) ? 'done' : 'in_progress']);
    }

    /** طلب الإغلاق: تقرير الإنجاز، والإثبات إلزامي للأنواع المحددة (FR-TM-007) */
    public function requestClose(Mission $m, User $u, string $report, array $files): Mission
    {
        if (! self::isExecutor($u, $m)) throw new ApiException(L::t('طلب الإغلاق من المنفّذ.', 'Closing is requested by the executor.'), 'forbidden', 403);
        if (! in_array($m->status, ['assigned', 'in_progress', 'returned'], true)) throw new ApiException(L::t('المهمة ليست قيد التنفيذ.', 'The mission is not in progress.'), 'locked', 409);
        if (mb_strlen(trim($report)) < 10) throw ApiException::field('report', L::t('اكتب تقرير الإنجاز (10 أحرف على الأقل).', 'Write the completion report (at least 10 characters).'), 'invalid');
        $m->loadMissing('type');
        if (in_array($m->type->code, self::cfg()['proof_types'], true) && ! $files && ! $m->attachments) throw ApiException::field('files', L::t('أرفق إثبات الإنجاز.', 'Attach the proof of completion.'), 'proof_required');
        $m->update(['status' => 'review', 'progress' => 100, 'attachments' => array_values(array_unique([...($m->attachments ?? []), ...$files])), 'settlement' => ($m->settlement ?? []) + ['report' => trim($report)]]);
        self::log($m, $u, 'close_requested');
        if ($m->owner_id && ($uid = Employee::find($m->owner_id)?->user()->value('id'))) Notify::user($uid, "مهمة {$m->ref_no} بانتظار مراجعة إنجازها", "Mission {$m->ref_no} awaits your review", 'missions');
        return $m->refresh();
    }

    /** مراجعة المسؤول: منجزة بتقييم (ومكافأة إنجاز إن كان النوع يستحقها) أو معادة بملاحظات وموعد جديد */
    public function review(Mission $m, User $u, bool $accept, ?int $rating, ?string $note, ?string $newDue): Mission
    {
        if (! self::isOwner($u, $m) || self::isExecutor($u, $m)) throw new ApiException(L::t('المراجعة من مسؤول المهمة.', 'Review is by the mission owner.'), 'forbidden', 403);
        if ($m->status !== 'review') throw new ApiException(L::t('المهمة ليست بانتظار المراجعة.', 'The mission is not awaiting review.'), 'locked', 409);
        if (! $accept) {
            if (mb_strlen(trim((string) $note)) < 5) throw ApiException::field('note', L::t('اكتب ملاحظات الإعادة.', 'Write what to fix.'), 'reason');
            $m->update(['status' => 'returned', 'return_note' => trim($note), 'due_on' => $newDue ?: $m->due_on?->toDateString()]);
            if ($uid = $m->employee?->user()->value('id')) Notify::user($uid, "أُعيدت مهمة {$m->ref_no}: {$note}", "Mission {$m->ref_no} was returned: {$note}", 'missions');
            self::log($m, $u, 'returned', $note);
            return $m->refresh();
        }
        $m->loadMissing(['type', 'employee']);
        $travel = self::isTravel($m->type) || ($m->travel['km'] ?? 0) > 0 || ($m->advance['amount'] ?? 0) > 0;
        $m->update(['status' => $travel ? 'done' : 'closed', 'rating' => $rating ? max(1, min(5, $rating)) : null, 'done_at' => now(), 'closed_at' => $travel ? null : now()]);
        self::log($m, $u, 'done');
        if ($m->parent_id) self::rollUp($m->parent_id);
        // FR-TM-021: مكافأة إنجاز آلية إن حُدّد مبلغ للنوع وأُنجزت في موعدها
        $amt = (float) (self::cfg()['completion_bonus'][$m->type->code] ?? 0);
        if ($amt > 0 && $m->employee && (! $m->due_on || $m->done_at->toDateString() <= $m->due_on->toDateString()))
            app(Bonuses::class)->auto($m->employee, 'BN-10', $amt, self::cfg()['expense_currency'], 'mission', $m->id, "مكافأة إنجاز المهمة {$m->ref_no}");
        return $m->refresh();
    }

    // ———————————————— السلفة والتسوية ————————————————

    /** المالية: صرف السلفة والتدقيق الثاني للتسوية */
    public static function isFinance(User $u): bool { $u->loadMissing('roles'); return $u->hasRole('finance') && Perm::can($u, 'missions', 'decide'); }

    public function payAdvance(Mission $m, User $u, string $voucher): Mission
    {
        if (! self::isFinance($u)) throw new ApiException(L::t('صرف السلفة من المالية.', 'Advances are paid by finance.'), 'forbidden', 403);
        if (($m->advance['status'] ?? null) !== 'requested' || ! in_array($m->status, ['assigned', 'in_progress', 'review', 'done', 'returned'], true)) throw new ApiException(L::t('لا سلفة بانتظار الصرف.', 'No advance awaiting payment.'), 'locked', 409);
        if (mb_strlen(trim($voucher)) < 2) throw ApiException::field('voucher_no', L::t('رقم سند الصرف مطلوب.', 'The payment voucher number is required.'), 'invalid');
        $m->update(['advance' => array_merge($m->advance, ['status' => 'paid', 'voucher_no' => trim($voucher), 'paid_at' => now()->toIso8601String()])]);
        self::log($m, $u, 'advance_paid', $voucher);
        return $m->refresh();
    }

    /** الموظف يقدّم التسوية بعد العودة: التواريخ الفعلية، الكيلومترات، الفواتير (FR-TM-015) */
    public function submitSettlement(Mission $m, User $u, array $v): Mission
    {
        if (! self::isExecutor($u, $m)) throw new ApiException(L::t('التسوية من منفّذ المهمة.', 'The settlement is filed by the executor.'), 'forbidden', 403);
        if (! in_array($m->status, ['done', 'review', 'in_progress', 'assigned'], true) || ($m->settlement['status'] ?? null) === 'approved') throw new ApiException(L::t('لا يمكن تقديم التسوية الآن.', 'The settlement cannot be filed now.'), 'locked', 409);
        $t = $m->travel ?? []; $err = [];
        $act = ['depart_on' => $v['depart_on'] ?? $t['depart_on'] ?? null, 'return_on' => $v['return_on'] ?? $t['return_on'] ?? null, 'km' => max(0, (float) ($v['km'] ?? $t['km'] ?? 0))];
        if ($act['depart_on'] && $act['return_on'] && $act['return_on'] < $act['depart_on']) $err['return_on'] = L::t('تاريخ العودة قبل الانطلاق.', 'Return is before departure.');
        $exp = [];
        foreach (array_slice((array) ($v['expenses'] ?? []), 0, 40) as $i => $x) {
            $code = in_array($x['code'] ?? '', ['E-121', 'E-130'], true) ? $x['code'] : null;
            $amt = round((float) ($x['amount'] ?? 0), 2);
            if (! $code || $amt <= 0) { $err["expenses.{$i}.amount"] = L::t('بند ومبلغ صحيحان.', 'A valid item and amount.'); continue; }
            if (empty($x['file_id'])) { $err["expenses.{$i}.file_id"] = L::t('أرفق الفاتورة.', 'Attach the invoice.'); continue; }
            $exp[] = ['code' => $code, 'amount' => $amt, 'currency' => strtoupper((string) ($x['currency'] ?? 'SYP')), 'nights' => max(0, (int) ($x['nights'] ?? 0)), 'file_id' => (int) $x['file_id'],
                'note' => mb_substr(trim((string) ($x['note'] ?? '')), 0, 300), 'approved' => null, 'over' => false];
        }
        if ($err) throw new ApiException(L::t('راجع بنود التسوية.', 'Check the settlement items.'), 'invalid', 422, array_map(fn ($x) => [$x], $err));
        $m->update(['status' => 'settling', 'settlement' => array_merge($m->settlement ?? [], ['actual' => $act, 'expenses' => $exp, 'status' => 'submitted', 'submitted_at' => now()->toIso8601String()])]);
        self::log($m, $u, 'settlement_submitted');
        Notify::hr("تسوية المهمة {$m->ref_no} بانتظار التدقيق", "Settlement of mission {$m->ref_no} awaits audit", "mission:{$m->id}");
        return $m->refresh();
    }

    /** الاستحقاقات النهائية من التسوية بحدود السياسة (POL-TM-05/12، AC-TM-03) */
    public function entitlements(Mission $m): array
    {
        $s = $m->settlement ?? []; $act = $s['actual'] ?? [];
        $tmp = clone $m; $tmp->travel = array_merge($m->travel ?? [], array_filter(['depart_on' => $act['depart_on'] ?? null, 'return_on' => $act['return_on'] ?? null, 'km' => $act['km'] ?? null], fn ($x) => $x !== null));
        $est = $this->estimate($tmp->setRelation('type', $m->type)->setRelation('employee', $m->employee));
        $lines = array_values(array_filter($est['lines'], fn ($l) => $l['code'] !== 'E-130'));   // الإقامة بالفاتورة لا بالتقدير
        $r = self::rates($m->employee, $act['depart_on'] ?? null); $zone = $est['zone'] ?? 'local';
        foreach ($s['expenses'] ?? [] as $i => $x) {
            $limit = null;
            if ($x['code'] === 'E-130') $limit = round((float) ($r['hotel_'.$zone] ?? 0) * max(1, (int) $x['nights']), 2);
            if ($x['code'] === 'E-121' && (float) self::cfg()['expense_cap'] > 0) $limit = (float) self::cfg()['expense_cap'];
            $approved = $x['approved'] ?? ($limit !== null ? min($x['amount'], $limit) : $x['amount']);
            $lines[] = ['code' => $x['code'], 'amount' => round((float) $approved, 2), 'claimed' => $x['amount'], 'limit' => $limit, 'over' => $limit !== null && $x['amount'] > $limit, 'currency' => $x['currency'], 'expense' => $i];
        }
        $adv = ($m->advance['status'] ?? null) === 'paid' ? (float) $m->advance['amount'] : 0;
        return ['lines' => $lines, 'advance' => $adv, 'advance_currency' => $m->advance['currency'] ?? null, 'warnings' => $est['warnings']];
    }

    /** تدقيق التسوية: HR ثم المالية؛ المالية تولّد بنود الراتب (FR-TM-016) */
    public function audit(Mission $m, User $u, array $approved, ?string $note): Mission
    {
        $s = $m->settlement ?? [];
        $stage = ($s['status'] ?? '') === 'submitted' ? 'hr' : (($s['status'] ?? '') === 'hr' ? 'finance' : null);
        if (! $stage || $m->status !== 'settling') throw new ApiException(L::t('لا تسوية بانتظار التدقيق.', 'No settlement awaiting audit.'), 'locked', 409);
        if ($stage === 'hr' && ! self::canManage($u)) throw new ApiException(L::t('التدقيق الأول من الموارد البشرية.', 'The first audit is by HR.'), 'forbidden', 403);
        if ($stage === 'finance' && ! self::isFinance($u)) throw new ApiException(L::t('التدقيق الثاني من المالية.', 'The second audit is by finance.'), 'forbidden', 403);
        if (self::isExecutor($u, $m)) throw new ApiException(L::t('لا تدقق تسويتك.', 'You cannot audit your own settlement.'), 'forbidden', 403);
        foreach ($approved as $i => $amt) if (isset($s['expenses'][$i]) && $amt !== null && $amt !== '') {
            $a = round((float) $amt, 2);
            if ($a < 0 || $a > $s['expenses'][$i]['amount']) throw ApiException::field("approved.{$i}", L::t('المبلغ المعتمد بين 0 ومبلغ الفاتورة.', 'Approved amount is between 0 and the invoice.'), 'invalid');
            $s['expenses'][$i]['approved'] = $a;
        }
        $s['audit'][$stage] = ['by' => $u->displayName(app()->getLocale()), 'at' => now()->toIso8601String(), 'note' => trim((string) $note) ?: null];
        $s['status'] = $stage;
        $m->update(['settlement' => $s]);
        if ($stage === 'finance') DB::transaction(fn () => $this->post($m->refresh(), $u));
        return $m->refresh();
    }

    /** توليد بنود الراتب من التسوية المعتمدة؛ الصافي السالب يُقسَّط (BR-TM-03) */
    private function post(Mission $m, User $u): void
    {
        $m->loadMissing(['employee', 'type']); $e = $m->employee;
        $ent = $this->entitlements($m);
        $s = $m->settlement; $s['status'] = 'approved'; $s['lines'] = $ent['lines'];
        if (! PayLines::inPayroll($e)) {
            $s['external'] = true; $m->update(['settlement' => $s, 'status' => 'closed', 'closed_at' => now()]);
            self::log($m, $u, 'external'); return;
        }
        $label = fn ($code) => [CodeItem::where('code', $code)->whereHas('list', fn ($q) => $q->where('key', 'pay_elements'))->value('name_ar').' — '.$m->ref_no, CodeItem::where('code', $code)->whereHas('list', fn ($q) => $q->where('key', 'pay_elements'))->value('name_en').' — '.$m->ref_no];
        foreach ($ent['lines'] as $l) PayLines::add($e, $l['code'], $l['amount'], $l['currency'], 'mission', $m->id, $m->ref_no, $label($l['code']), null, $u);
        if ($ent['advance'] > 0) {
            $n = max(1, (int) self::cfg()['installments']);
            $earn = collect($ent['lines'])->where('currency', $ent['advance_currency'])->sum('amount');
            $first = PayLines::period();
            $excess = $n > 1 ? max(0, $ent['advance'] - $earn) : 0;   // قسط واحد = السلفة كاملة في الفترة نفسها
            PayLines::add($e, 'D-310', $ent['advance'] - $excess, $ent['advance_currency'], 'mission', $m->id, $m->ref_no, $label('D-310'), $first, $u);
            for ($i = 0; $i < $n && $excess > 0; $i++) {
                $part = $i === $n - 1 ? $excess : round($excess / ($n - $i), 2);
                PayLines::add($e, 'D-310', $part, $ent['advance_currency'], 'mission', $m->id, $m->ref_no, $label('D-310'), Carbon::parse($first.'-01')->addMonthsNoOverflow($i)->format('Y-m'), $u, 'قسط فرق السلفة');
                $excess -= $part;
            }
            $m->forceFill(['advance' => array_merge($m->advance, ['status' => 'settled'])]);
        }
        $m->update(['settlement' => $s, 'status' => 'posted', 'advance' => $m->advance]);
        self::log($m, $u, 'posted');
        Audit::record('mission.settled', $m, null, ['lines' => count($ent['lines'])]);
        if ($uid = $e->user()->value('id')) Notify::user($uid, "اعتُمدت تسوية المهمة {$m->ref_no} وأُدرجت في الكشف الشهري", "Settlement of {$m->ref_no} approved and added to the monthly sheet", 'payslip');
    }

    /** إلغاء: قبل الاعتماد ممن قدّمها؛ بعده من الإدارة — والسلفة المصروفة تُسترد بالكامل (BR-TM-11) */
    public function cancel(Mission $m, User $u, string $reason): Mission
    {
        if (in_array($m->status, ['posted', 'closed', 'cancelled', 'rejected', 'settling'], true)) throw new ApiException(L::t('لا يمكن إلغاء هذه المهمة.', 'This mission cannot be cancelled.'), 'locked', 409);
        $mine = $m->requested_by === $u->id && in_array($m->status, ['draft', 'returned'], true);
        if (! $mine && ! self::canManage($u)) throw new ApiException(L::t('الإلغاء بعد الإرسال من الموارد البشرية.', 'After sending, HR cancels.'), 'forbidden', 403);
        if (mb_strlen(trim($reason)) < 5) throw ApiException::field('reason', L::t('اكتب سبب الإلغاء.', 'Write the reason.'), 'reason');
        DB::transaction(function () use ($m, $u, $reason) {
            \App\Models\RequestStep::where('subject_type', $m->getMorphClass())->where('subject_id', $m->id)->whereIn('status', ['pending', 'waiting'])->update(['status' => 'cancelled']);
            if (($m->advance['status'] ?? null) === 'paid' && $m->employee) {
                PayLines::add($m->employee, 'D-310', (float) $m->advance['amount'], $m->advance['currency'], 'mission', $m->id, $m->ref_no, ['استرداد سلفة مهمة ملغاة — '.$m->ref_no, 'Advance refund, cancelled mission — '.$m->ref_no], null, $u);
                $m->forceFill(['advance' => array_merge($m->advance, ['status' => 'refunded'])]);
            }
            $this->markDays($m, false);
            $m->update(['status' => 'cancelled', 'return_note' => trim($reason), 'advance' => $m->advance]);
            Mission::where('parent_id', $m->id)->whereIn('status', self::OPEN)->update(['status' => 'cancelled']);
            self::log($m, $u, 'cancelled', $reason);
        });
        return $m->refresh();
    }

    /** تمديد أو تقصير مهمة سفر معتمدة: يعيد الحساب (BR-TM-10) — الزيادة تحتاج صلاحية الاعتماد */
    public function amend(Mission $m, User $u, string $depart, string $return, string $reason): Mission
    {
        if (! self::canManage($u)) throw new ApiException(L::t('التعديل من الموارد البشرية.', 'Amendments are by HR.'), 'forbidden', 403);
        if (! in_array($m->status, ['assigned', 'in_progress', 'review', 'done'], true) || ! $m->travel) throw new ApiException(L::t('لا يمكن تعديل تواريخ هذه المهمة.', 'The dates of this mission cannot be changed.'), 'locked', 409);
        if ($return < $depart) throw ApiException::field('return_on', L::t('تاريخ العودة قبل الانطلاق.', 'Return is before departure.'), 'invalid');
        if (mb_strlen(trim($reason)) < 5) throw ApiException::field('reason', L::t('اكتب سبب التعديل.', 'Write the reason.'), 'reason');
        $old = $m->estimate['totals'] ?? [];
        $this->markDays($m, false);
        $m->travel = array_merge($m->travel, ['depart_on' => $depart, 'return_on' => $return]);
        $est = $this->estimate($m->load(['type', 'employee']));
        $more = collect($est['totals'] ?? [])->contains(fn ($v, $c) => $v > ($old[$c] ?? 0));
        // FR-TM-024: زيادة الكلفة ← طلب تعديل يُعتمد الفرق فيه قبل التطبيق
        if ($more) { $m->travel = $m->getOriginal('travel'); $this->markDays($m, true); app(MissionExtras::class)->requestAmend($m, $u, $depart, $return, $reason, $est); return $m->refresh(); }
        $m->update(['travel' => $m->travel, 'estimate' => $est, 'due_on' => $return, 'close_due' => self::closeDue($return)]);
        $this->markDays($m, true);
        self::log($m, $u, 'amended', "{$depart} → {$return}: {$reason}");
        return $m->refresh();
    }

    public function comment(Mission $m, User $u, string $text, array $files): MissionComment
    {
        if (mb_strlen(trim($text)) < 1) throw ApiException::field('text', L::t('اكتب التعليق.', 'Write the comment.'), 'invalid');
        $c = MissionComment::create(['mission_id' => $m->id, 'user_id' => $u->id, 'text' => mb_substr(trim($text), 0, 3000), 'files' => $files ?: null, 'mentions' => MissionExtras::mentions($text, $m, $u) ?: null]);
        foreach (array_filter([$m->employee?->user()->value('id'), $m->owner_id ? Employee::find($m->owner_id)?->user()->value('id') : null]) as $uid)
            if ($uid !== $u->id) Notify::user($uid, "تعليق جديد على المهمة {$m->ref_no}", "New comment on mission {$m->ref_no}", 'missions');
        return $c;
    }

    /** المهمة اليومية: تذكير بمهلة الإغلاق وتصعيد (FR-TM-023) */
    public function sweep(): int
    {
        $n = 0; $today = now()->toDateString();
        foreach (Mission::with('employee')->whereNotNull('close_due')->where('close_due', '<', $today)->whereIn('status', ['assigned', 'in_progress', 'review', 'done'])->get() as $m) {
            if ($m->reminded_at && $m->reminded_at->toDateString() === $today) continue;
            if ($uid = $m->employee?->user()->value('id')) Notify::user($uid, "مهمة {$m->ref_no} تجاوزت مهلة الإغلاق — قدّم التسوية والتقرير", "Mission {$m->ref_no} is past its closing deadline — file the settlement and report", 'missions');
            Notify::hr("تصعيد: مهمة {$m->ref_no} لم تُغلق بعد مهلتها ({$m->close_due->toDateString()})", "Escalation: mission {$m->ref_no} not closed after {$m->close_due->toDateString()}", "mission:{$m->id}");
            $m->update(['reminded_at' => now()]); $n++;
        }
        return $n;
    }

    /** صف في صندوق الاعتماد الموحد: الوجهة والأيام والتكلفة التقديرية (بعد التحقق من الهوية) */
    public static function inboxRow(Mission $m, User $viewer): array
    {
        $m->loadMissing(['type', 'employee', 'requester']);
        $cur = Workflow::current($m); $show = $m->employee && SalaryVault::sees($viewer, $m->employee);
        return ['kind' => 'mission', 'id' => $m->id, 'type' => 'mission', 'type_name' => ['ar' => $m->type->name_ar, 'en' => $m->type->name_en], 'employee' => \App\Http\Resources\RequestRow::person($m->employee),
            'from' => $m->travel['depart_on'] ?? $m->starts_on?->toDateString(), 'to' => $m->travel['return_on'] ?? $m->due_on?->toDateString(), 'status' => $m->status, 'reason' => $m->title.($m->travel ? ' — '.($m->travel['purpose'] ?? '') : ''),
            'mission' => ['id' => $m->id, 'ref_no' => $m->ref_no, 'destination' => $m->travel ? trim(($m->travel['city'] ?? '').' '.($m->travel['governorate'] ?? $m->travel['country'] ?? '')) : null, 'days' => $m->estimate['days'] ?? null,
                'totals' => $show ? ($m->estimate['totals'] ?? []) : null, 'advance' => $show ? ($m->advance['amount'] ?? null) : null, 'advance_currency' => $m->advance['currency'] ?? null, 'masked' => ! $show],
            'warnings' => [], 'requested_by' => $m->requester ? ['ar' => $m->requester->displayName('ar'), 'en' => $m->requester->displayName('en')] : null,
            'created_at' => $m->created_at?->toIso8601String(), 'steps' => Workflow::steps($m), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($viewer, $cur, $m) : false, 'can_cancel' => false];
    }

    public static function canSee(User $u, Mission $m): bool
    {
        return self::isExecutor($u, $m) || ($u->employee_id && $u->employee_id === $m->owner_id) || $m->requested_by === $u->id
            || (Perm::can($u, 'missions', 'view') && ($m->employee_id ? Directory::scoped($u, 'missions')->whereKey($m->employee_id)->exists() : self::canManage($u)))
            || ($u->hasRole('finance'));
    }

    public static function row(Mission $m, User $u, bool $full = false): array
    {
        $m->loadMissing(['type', 'category', 'employee', 'owner', 'department']);
        $show = $m->employee ? SalaryVault::sees($u, $m->employee) : SalaryVault::open($u);
        $money = fn (?array $x) => $show ? $x : ($x ? ['masked' => true] + array_intersect_key($x, array_flip(['warnings', 'days', 'zone', 'same_gov', 'grade', 'ticket'])) : null);
        $cur = Workflow::current($m);
        $base = ['id' => $m->id, 'ref_no' => $m->ref_no, 'title' => $m->title, 'status' => $m->status, 'progress' => $m->progress, 'priority' => $m->priority,
            'type' => $m->type ? ['code' => $m->type->code, 'ar' => $m->type->name_ar, 'en' => $m->type->name_en, 'travel' => self::isTravel($m->type), 'financial' => self::financial($m->type)] : null,
            'category' => $m->category ? ['ar' => $m->category->name_ar, 'en' => $m->category->name_en] : null, 'assignee_type' => $m->assignee_type, 'parent_id' => $m->parent_id,
            'employee' => $m->employee ? ['id' => $m->employee->id, 'code' => $m->employee->code, 'ar' => $m->employee->name, 'en' => $m->employee->name_en] : null,
            'owner' => $m->owner ? ['id' => $m->owner->id, 'ar' => $m->owner->name, 'en' => $m->owner->name_en] : null,
            'starts_on' => $m->starts_on?->toDateString(), 'due_on' => $m->due_on?->toDateString(), 'close_due' => $m->close_due?->toDateString(),
            'late' => $m->due_on && $m->due_on->toDateString() < now()->toDateString() && in_array($m->status, ['assigned', 'in_progress', 'returned'], true),
            'travel' => $m->travel ? $m->travel + ['place' => self::place($m->travel)] : null, 'advance' => $m->advance ? ($show ? $m->advance : ['status' => $m->advance['status'] ?? null, 'masked' => true]) : null,
            'settlement_status' => $m->settlement['status'] ?? null, 'masked' => ! $show];
        if (! $full) return $base;
        $u->loadMissing('roles');
        $ent = in_array($m->status, ['settling', 'posted', 'closed'], true) && isset($m->settlement['actual']) ? app(self::class)->entitlements($m) : null;
        return $base + ['description' => $m->description, 'stages' => $m->stages, 'estimate' => $money($m->estimate), 'settlement' => $show ? $m->settlement : ($m->settlement ? ['status' => $m->settlement['status'] ?? null, 'report' => $m->settlement['report'] ?? null, 'masked' => true] : null),
            'entitlements' => $show ? $ent : null, 'attachments' => $m->attachments ?? [], 'rating' => $m->rating, 'return_note' => $m->return_note, 'log' => $m->log ?? [],
            'comments' => $m->comments()->with('user')->get()->map(fn ($c) => ['id' => $c->id, 'text' => $c->text, 'files' => $c->files ?? [], 'by' => $c->user?->displayName(app()->getLocale()), 'at' => $c->created_at->toIso8601String()]),
            'invites' => \App\Models\MissionInvite::where('mission_id', $m->id)->latest('id')->get()->map(fn ($i) => ['id' => $i->id, 'guest_name' => $i->guest_name, 'organization' => $i->organization, 'nationality' => $i->nationality,
                'purpose' => $i->purpose, 'from' => $i->from_on->toDateString(), 'to' => $i->to_on->toDateString(), 'status' => $i->status, 'letter_no' => $i->letter_no, 'steps' => Workflow::steps($i)]),
            'amends' => \App\Models\MissionAmend::where('mission_id', $m->id)->latest('id')->get()->map(fn ($a) => ['id' => $a->id, 'depart_on' => $a->depart_on->toDateString(), 'return_on' => $a->return_on->toDateString(), 'reason' => $a->reason, 'status' => $a->status, 'steps' => Workflow::steps($a)]),
            'external' => $m->external_id ? \App\Models\External::whereKey($m->external_id)->first(['id', 'name', 'name_en', 'ref'])?->only(['id', 'name', 'name_en', 'ref']) : null, 'ticket_class' => $m->ticket_class,
            'children' => Mission::with('employee')->where('parent_id', $m->id)->get()->map(fn ($c) => ['id' => $c->id, 'ref_no' => $c->ref_no, 'status' => $c->status, 'progress' => $c->progress, 'employee' => $c->employee ? ['ar' => $c->employee->name, 'en' => $c->employee->name_en] : null]),
            'lines' => PayLine::where('source_type', 'mission')->where('source_id', $m->id)->orderBy('id')->get()->map(fn ($l) => PayLines::row($l, $show)),
            'steps' => Workflow::steps($m), 'step_id' => $cur?->id, 'can' => ['edit' => in_array($m->status, ['draft', 'returned'], true) && ($m->requested_by === $u->id || self::canManage($u)),
                'act' => $cur ? Workflow::canAct($u, $cur, $m) : false, 'progress' => self::isExecutor($u, $m) && in_array($m->status, ['assigned', 'in_progress', 'returned'], true),
                'review' => $m->status === 'review' && self::isOwner($u, $m) && ! self::isExecutor($u, $m), 'settle' => self::isExecutor($u, $m) && in_array($m->status, ['done', 'review', 'in_progress', 'assigned'], true) && (self::isTravel($m->type) || ($m->travel['km'] ?? 0) > 0) && ! in_array($m->settlement['status'] ?? '', ['submitted', 'hr', 'approved'], true),
                'audit' => $m->status === 'settling' && ((($m->settlement['status'] ?? '') === 'submitted' && self::canManage($u)) || (($m->settlement['status'] ?? '') === 'hr' && self::isFinance($u))) && ! self::isExecutor($u, $m),
                'pay_advance' => ($m->advance['status'] ?? null) === 'requested' && self::isFinance($u) && in_array($m->status, ['assigned', 'in_progress', 'review', 'done', 'returned'], true),
                'cancel' => ! in_array($m->status, ['posted', 'closed', 'cancelled', 'rejected', 'settling'], true) && ($m->requested_by === $u->id && in_array($m->status, ['draft', 'returned'], true) || self::canManage($u)),
                'amend' => self::canManage($u) && in_array($m->status, ['assigned', 'in_progress', 'review', 'done'], true) && (bool) $m->travel]];
    }
}
