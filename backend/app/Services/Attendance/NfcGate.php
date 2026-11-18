<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;
use App\Models\BranchAccess;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Mission;
use App\Models\NfEvent;
use App\Models\AttendanceDay;
use App\Models\Scan;
use App\Models\User;
use App\Support\Audit;
use App\Support\CardUid;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * بوابة الحضور متعددة الفروع (SRS الأنظمة الأساسية C.4): الجهاز ← الشركة والفرع، البطاقة ← الشخص داخل شركة الجهاز فقط،
 * ثم صلاحية الفرع والتكرار والاتجاه والتسجيل. كل قراءة تُحفظ حدثاً خاماً مع القرار وسببه.
 */
class NfcGate
{
    public const REJECT = ['unknown_card', 'lost_card', 'suspended', 'inactive', 'expired', 'off_branch'];

    public function __construct(private Attendance $att) {}

    public static function cfg(): array { return Settings::merged('nfc'); }

    // ———————————————— الاعتمادات ————————————————

    public static function norm(string $type, string $raw): string
    {
        return $type === 'nfc' ? (CardUid::hex($raw) ?? CardUid::norm($raw)) : strtoupper(trim($raw));
    }

    /** البحث داخل الشركة الحالية فقط (FR-NF-006) — كل صيغ رقم البطاقة، الأحدث أولاً */
    public static function credential(string $raw, string $type = 'nfc'): ?Credential
    {
        $keys = $type === 'nfc' ? CardUid::keys($raw) : [strtoupper(trim($raw))];
        if (! $keys) return null;
        return Credential::with('employee')->where('type', $type)->whereIn('norm', $keys)
            ->orderByRaw("case status when 'active' then 0 else 1 end")->latest('id')->first();
    }

    /** إصدار اعتماد (بطاقة جديدة، رقم بصمة…) — البطاقة الفعالة السابقة من النوع نفسه تصبح «مستبدلة» */
    public static function issue(Employee $e, string $type, string $raw, ?User $by, array $extra = []): Credential
    {
        $identifier = $type === 'nfc' ? CardUid::format($raw) : trim($raw);
        if (! $identifier) throw ApiException::field('identifier', L::t('المعرّف غير صالح.', 'Invalid identifier.'), 'card_invalid');
        $norm = self::norm($type, $identifier);
        $taken = Credential::where('type', $type)->whereIn('norm', $type === 'nfc' ? CardUid::keys($identifier) : [$norm])->where('status', 'active')->where('employee_id', '!=', $e->id)->exists();
        if ($taken) throw ApiException::field('identifier', L::t('هذا المعرّف مسجّل لشخص آخر في الشركة.', 'This identifier belongs to someone else in the company.'), 'card_taken');
        return DB::transaction(function () use ($e, $type, $identifier, $norm, $by, $extra) {
            $same = Credential::where('employee_id', $e->id)->where('type', $type)->where('norm', $norm)->where('status', 'active')->first();
            if ($same) return $same;
            if ($type === 'nfc') Credential::where('employee_id', $e->id)->where('type', 'nfc')->where('status', 'active')->update(['status' => 'replaced', 'revoked_at' => now()]);
            $c = Credential::create(['employee_id' => $e->id, 'type' => $type, 'identifier' => $identifier, 'norm' => $norm, 'status' => 'active',
                'label' => $extra['label'] ?? null, 'valid_from' => $extra['valid_from'] ?? null, 'valid_to' => $extra['valid_to'] ?? null, 'issued_by' => $by?->id]);
            if ($type === 'nfc') Employee::whereKey($e->id)->update(['nfc_uid' => $identifier]);
            return $c;
        });
    }

    /** فقد / إيقاف / إعادة تفعيل / إلغاء — بطاقة الحضور الحالية في ملف الموظف تتبع الحالة */
    public static function setStatus(Credential $c, string $status, ?string $reason, ?User $by): Credential
    {
        if (! in_array($status, ['active', 'lost', 'suspended', 'revoked'], true)) throw new ApiException(L::t('حالة غير معروفة.', 'Unknown status.'), 'invalid', 422);
        if ($status === 'active' && $c->type === 'nfc' && Credential::where('employee_id', $c->employee_id)->where('type', 'nfc')->where('status', 'active')->whereKeyNot($c->id)->exists())
            throw new ApiException(L::t('للموظف بطاقة فعالة أخرى — أوقفها أولاً.', 'The employee has another active card — disable it first.'), 'card_active', 409);
        $c->update(['status' => $status, 'reason' => $reason, 'revoked_at' => $status === 'active' ? null : now()]);
        if ($c->type === 'nfc') Employee::whereKey($c->employee_id)->update(['nfc_uid' => $status === 'active' ? $c->identifier : null]);
        $e = Employee::find($c->employee_id);
        $labels = ['active' => ['أُعيد تفعيل', 'reactivated'], 'lost' => ['أُبلغ عن فقد', 'reported lost'], 'suspended' => ['أُوقف', 'suspended'], 'revoked' => ['أُلغي', 'revoked']][$status];
        $e?->log("{$labels[0]} اعتماد الحضور {$c->identifier}".($reason ? " — {$reason}" : ''), "Attendance credential {$c->identifier} {$labels[1]}".($reason ? " — {$reason}" : ''), $status === 'active' ? 'ok' : 'warn');
        Audit::record('credential.'.$status, $c, ['reason' => $reason]);
        return $c;
    }

    /** FR-NF-014: انتهاء الخدمة يعطّل كل اعتمادات الشخص */
    public static function disableAll(Employee $e, string $reason): int
    {
        if (! self::cfg()['auto_disable']) return 0;
        $n = Credential::where('employee_id', $e->id)->where('status', 'active')->update(['status' => 'revoked', 'reason' => $reason, 'revoked_at' => now()]);
        Employee::whereKey($e->id)->update(['nfc_uid' => null]);
        if ($n) $e->log("تعطيل {$n} من اعتمادات الحضور تلقائياً — {$reason}", "{$n} attendance credential(s) disabled automatically — {$reason}", 'warn');
        return $n;
    }

    // ———————————————— صلاحية الفرع ————————————————

    /**
     * FR-NF-007: الفرع الأساسي، أو مصرّح دائم، أو مؤقت/ندب بتاريخين، أو مهمة معتمدة إليه في هذا التاريخ.
     * @return array{ok: bool, kind: ?string, mission_id: ?int}
     */
    public static function access(Employee $e, ?Location $loc, string $date): array
    {
        if (! $loc) return ['ok' => true, 'kind' => 'any', 'mission_id' => null];
        if ((int) ($e->getAttributes()['location_id'] ?? 0) === $loc->id) return ['ok' => true, 'kind' => 'home', 'mission_id' => null];
        $a = BranchAccess::where('employee_id', $e->id)->where('location_id', $loc->id)
            ->where(fn ($q) => $q->whereNull('from')->orWhere('from', '<=', $date))->where(fn ($q) => $q->whereNull('to')->orWhere('to', '>=', $date))
            ->orderByRaw("case kind when 'permanent' then 0 else 1 end")->first();
        if ($a) return ['ok' => true, 'kind' => $a->kind, 'mission_id' => null];
        if (self::cfg()['mission_access'] && ($m = self::missionTo($e, $loc, $date))) return ['ok' => true, 'kind' => 'mission', 'mission_id' => $m];
        return ['ok' => false, 'kind' => null, 'mission_id' => null];
    }

    /** مهمة معتمدة لهذا الموظف تغطي التاريخ ووجهتها هذا الفرع (مهمة فرع، أو سفر إلى محافظة الفرع) */
    public static function missionTo(Employee $e, Location $loc, string $date): ?int
    {
        $rows = Mission::where('employee_id', $e->id)->whereIn('status', ['approved', 'assigned', 'in_progress', 'review'])
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereRaw("travel->>'depart_on' <= ?", [$date])->whereRaw("travel->>'return_on' >= ?", [$date]))
                ->orWhere(fn ($w) => $w->where('starts_on', '<=', $date)->where(fn ($x) => $x->whereNull('due_on')->orWhere('due_on', '>=', $date))))
            ->get(['id', 'parent_id', 'assignee_type', 'assignee_ref', 'travel']);
        $parents = Mission::whereIn('id', $rows->pluck('parent_id')->filter())->get(['id', 'assignee_type', 'assignee_ref'])->keyBy('id');
        foreach ($rows as $m) {
            $p = $m->parent_id ? $parents[$m->parent_id] ?? null : null;
            if (($m->assignee_type === 'branch' && (int) $m->assignee_ref === $loc->id) || ($p && $p->assignee_type === 'branch' && (int) $p->assignee_ref === $loc->id)) return $m->id;
            $t = $m->travel ?? [];
            if ($loc->governorate && ($t['scope'] ?? 'local') === 'local' && ($t['governorate'] ?? null) === $loc->governorate) return $m->id;
        }
        return null;
    }

    // ———————————————— القرار ————————————————

    /**
     * حدث من جهاز (C.4 الخطوات 3–8). $dry = محاكاة دون حفظ.
     * @return array{decision: string, reason: ?string, seq: ?int, name?: ?array, direction?: ?string, time?: string, event_id?: int}
     */
    public function event(Device $d, string $identifier, CarbonInterface $at, ?int $seq = null, string $via = 'nfc', bool $dry = false, ?string $token = null): array
    {
        $at = Carbon::instance($at)->utc();
        // FR-NF-016: ساعة الجهاز منحرفة أكثر من المقبول ← يُصحَّح وقت الحدث بالانحراف المقيس
        if (self::cfg()['drift_correct'] && $d->clock_drift && abs((int) $d->clock_drift) > (int) self::cfg()['drift_seconds']) $at = $at->copy()->subSeconds((int) $d->clock_drift);
        if ($seq !== null && ! $dry && ($old = NfEvent::where('device_id', $d->id)->where('seq', $seq)->first())) return self::out($old, $seq);   // إعادة إرسال بعد انقطاع
        $type = $via === 'zk' ? 'fingerprint' : 'nfc';
        $cred = self::credential($identifier, $type);
        // FR-NF-018: رمز ذاكرة البطاقة حين يكون أمان البطاقة «ذاكرة»
        if ($cred && $cred->memory_token && in_array(self::cfg()['card_security'], ['memory', 'secure'], true) && ! hash_equals((string) $cred->memory_token, (string) $token))
            return $dry ? ['decision' => 'rejected', 'reason' => 'token_mismatch'] : self::out($this->record($d, $cred->employee, $identifier, $at, 'rejected', 'token_mismatch', ['seq' => $seq, 'via' => $via]), $seq);
        // FR-VL-007: بطاقة متطوع/متدرب ← ساعات مشاركة لا حضور موظف
        if ($cred && $cred->external_id && ! $cred->employee_id) return $this->externalTap($d, $cred, $identifier, $at, $seq, $via, $dry);
        $e = $cred?->employee;
        if (! $cred && $type === 'nfc') $e = CardUid::match(Employee::query(), $identifier)->first();   // بطاقة قديمة لم تدخل السجل بعد
        return $this->decide($d, $e, $cred, $identifier, $at, $seq, $via, $dry);
    }

    /** حضور الشخص المعروف مباشرة (ملصق الفرع بالهاتف) — يمر بفحوص الفرع والتكرار نفسها */
    public function person(Device $d, Employee $e, CarbonInterface $at, string $via = 'sticker'): array
    {
        return $this->decide($d, $e, null, 'user:'.$e->id, Carbon::instance($at)->utc(), null, $via, false);
    }

    /** نقرة بطاقة غير موظف: الأولى في اليوم بداية، والتالية نهاية ← سجل ساعات بانتظار مصادقة المشرف */
    private function externalTap(Device $d, Credential $c, string $identifier, Carbon $at, ?int $seq, string $via, bool $dry): array
    {
        if (! self::cfg()['externals']) return ['seq' => $seq, 'decision' => 'rejected', 'reason' => 'unknown'];
        if ($c->status !== 'active') return $dry ? ['decision' => 'rejected', 'reason' => 'suspended'] : self::out($this->record($d, null, $identifier, $at, 'rejected', 'suspended', ['seq' => $seq, 'via' => $via]), $seq);
        $g = \App\Models\Engagement::where('external_id', $c->external_id)->where('status', 'active')->latest('id')->first();
        if (! $g) return $dry ? ['decision' => 'rejected', 'reason' => 'inactive'] : self::out($this->record($d, null, $identifier, $at, 'rejected', 'inactive', ['seq' => $seq, 'via' => $via]), $seq);
        if ($dry) return ['decision' => 'accepted', 'reason' => 'external'];
        $tz = ($d->location_id ? Location::find($d->location_id)?->timezone : null) ?: config('app.timezone');
        $local = $at->copy()->setTimezone($tz); $key = 'nf-ext:'.$c->id.':'.$local->toDateString();
        $ev = $this->record($d, null, $identifier, $at, 'accepted', 'external', ['seq' => $seq, 'via' => $via]);
        if ($start = Cache::pull($key)) {
            $hours = round(max(0, $local->getTimestamp() - $start) / 3600, 2);
            if ($hours >= 0.25) \App\Models\VolunteerLog::create(['engagement_id' => $g->id, 'date' => $local->toDateString(), 'hours' => min(24, $hours), 'activity' => 'تسجيل بالبطاقة — '.($d->name_ar ?? ''), 'via' => 'nfc', 'status' => 'pending']);
        } else Cache::put($key, $local->getTimestamp(), now()->addHours(20));
        return self::out($ev, $seq);
    }

    /** إصدار بطاقة لغير موظف (متطوع، متدرب، خبير) — تُعطَّل آلياً عند انتهاء الاتفاقية (FR-VL-015) */
    public static function issueExternal(\App\Models\External $x, string $raw, ?User $by): Credential
    {
        $identifier = CardUid::format($raw);
        if (! $identifier) throw ApiException::field('identifier', L::t('المعرّف غير صالح.', 'Invalid identifier.'), 'card_invalid');
        if (Credential::where('type', 'nfc')->whereIn('norm', CardUid::keys($identifier))->where('status', 'active')->where(fn ($q) => $q->whereNull('external_id')->orWhere('external_id', '!=', $x->id))->exists())
            throw ApiException::field('identifier', L::t('هذا المعرّف مسجّل لشخص آخر في الشركة.', 'This identifier belongs to someone else in the company.'), 'card_taken');
        Credential::where('external_id', $x->id)->where('type', 'nfc')->where('status', 'active')->update(['status' => 'replaced', 'revoked_at' => now()]);
        $c = Credential::create(['external_id' => $x->id, 'employee_id' => null, 'type' => 'nfc', 'identifier' => $identifier, 'norm' => self::norm('nfc', $identifier), 'status' => 'active', 'valid_from' => now()->toDateString(), 'issued_by' => $by?->id]);
        Audit::record('credential.issued_external', $c);
        return $c;
    }

    public static function disableExternal(int $externalId, string $reason): int
    {
        return Credential::where('external_id', $externalId)->where('status', 'active')->update(['status' => 'revoked', 'reason' => mb_substr($reason, 0, 255), 'revoked_at' => now()]);
    }

    /** FR-NF-018: رمز ذاكرة جديد للبطاقة (يُكتب على البطاقة، ويقرؤه الجسر ويرسله مع الحدث) */
    public static function newToken(Credential $c): string
    {
        $t = strtoupper(substr((string) (Tenant::company()?->slug ?? 'UHR'), 0, 4)).'-'.strtoupper(Str::random(10));   // 15 حرفاً تتسع في 16 بايت (NTAG الصفحات 4–7)
        $c->update(['memory_token' => $t]);
        Audit::record('credential.token', $c);
        return $t;
    }

    /** FR-NF-017: الحضور بالهاتف من جهاز مسجّل — bind_first يربط أول جهاز تلقائياً، registered يتطلب تسجيلاً مسبقاً */
    public static function phoneCheck(Employee $e, ?string $deviceToken): void
    {
        $mode = self::cfg()['sticker_device'];
        if ($mode === 'off') return;
        if (! $deviceToken || strlen($deviceToken) < 16) throw new ApiException(L::t('هذا الجهاز غير مسجّل للحضور.', 'This device is not registered for check-in.'), 'device_not_registered', 403);
        $norm = hash('sha256', $deviceToken);
        $mine = Credential::where('employee_id', $e->id)->where('type', 'phone')->where('status', 'active')->get();
        if ($mine->contains('norm', substr($norm, 0, 60))) return;
        if ($mine->isEmpty() && $mode === 'bind_first') {
            Credential::create(['employee_id' => $e->id, 'type' => 'phone', 'identifier' => 'phone:'.substr($norm, 0, 8), 'norm' => substr($norm, 0, 60), 'label' => mb_substr((string) request()?->userAgent(), 0, 120), 'status' => 'active', 'valid_from' => now()->toDateString()]);
            return;
        }
        throw new ApiException(L::t('هذا الجهاز غير مسجّل للحضور — اطلب من الموارد البشرية إلغاء ربط جهازك السابق.', 'This device is not registered for check-in — ask HR to unlink your previous device.'), 'device_not_registered', 403);
    }

    /**
     * فحوص C.4 الخطوات 3–5 على شخص معروف: حالة الاعتماد، سريانه، حالة الموظف، صلاحية الفرع.
     * @return array{ok: bool, reason: ?string, flag: bool, kind: ?string, mission_id: ?int, loc: ?Location, tz: string, date: string}
     */
    public static function check(Device $d, Employee $e, ?Credential $cred, CarbonInterface $at): array
    {
        $loc = $d->location_id ? Location::find($d->location_id) : null;
        $tz = $loc?->timezone ?: WorkSchedules::tz($e);
        $date = Carbon::instance($at)->setTimezone($tz)->toDateString();
        $r = ['ok' => false, 'flag' => false, 'kind' => null, 'mission_id' => null, 'loc' => $loc, 'tz' => $tz, 'date' => $date];
        if ($cred && in_array($cred->status, ['lost', 'replaced'], true)) return $r + ['reason' => 'lost_card'];
        if ($cred && in_array($cred->status, ['suspended', 'revoked'], true)) return $r + ['reason' => 'suspended'];
        if ($cred && (($cred->valid_from && $cred->valid_from->toDateString() > $date) || ($cred->valid_to && $cred->valid_to->toDateString() < $date))) return $r + ['reason' => 'expired'];
        if ($e->status === 'terminated' || ($e->end_date && $e->end_date->toDateString() < $date)) return $r + ['reason' => 'inactive'];
        $acc = self::access($e, $loc, $date);
        if (! $acc['ok']) {
            if (self::cfg()['off_branch'] === 'reject') return $r + ['reason' => 'off_branch'];
            return ['ok' => true, 'flag' => true, 'reason' => 'off_branch'] + $r;
        }
        return ['ok' => true, 'reason' => $acc['kind'], 'kind' => $acc['kind'], 'mission_id' => $acc['mission_id']] + $r;
    }

    /** حفظ الحدث الخام (وتنبيهه عند الرفض أو التعليم) */
    public function record(Device $d, ?Employee $e, string $identifier, CarbonInterface $at, string $decision, ?string $reason, array $extra = []): NfEvent
    {
        $ev = NfEvent::create(['device_id' => $d->id, 'identifier' => mb_substr($identifier, 0, 60), 'via' => $extra['via'] ?? 'nfc', 'occurred_at' => Carbon::instance($at)->utc(), 'received_at' => now(),
            'seq' => $extra['seq'] ?? null, 'location_id' => $d->location_id, 'employee_id' => $e?->id, 'decision' => $decision, 'reason' => $reason,
            'mission_id' => $extra['mission_id'] ?? null, 'scan_id' => $extra['scan_id'] ?? null, 'direction' => $extra['direction'] ?? null, 'review' => $decision === 'flagged' ? 'pending' : null]);
        if ($decision === 'rejected' || $decision === 'flagged') $this->alert($ev, $d, $e);
        if (in_array($decision, ['accepted', 'flagged'], true)) {
            if ($e && ($extra['date'] ?? null)) AttendanceDay::where('employee_id', $e->id)->whereDate('date', $extra['date'])->update(array_filter(['location_id' => $d->location_id, 'mission_id' => $extra['mission_id'] ?? null]));
            $this->buddy($ev, $d);
        }
        return $ev;
    }

    private function decide(Device $d, ?Employee $e, ?Credential $cred, string $identifier, Carbon $at, ?int $seq, string $via, bool $dry): array
    {
        $x = ['via' => $via, 'seq' => $seq];
        if (! $e) return $dry ? ['decision' => 'rejected', 'reason' => 'unknown_card', 'seq' => $seq] : self::out($this->record($d, null, $identifier, $at, 'rejected', 'unknown_card', $x), $seq);
        $c = self::check($d, $e, $cred, $at);
        $name = ['ar' => $e->name, 'en' => $e->name_en, 'code' => $e->code];
        if (! $c['ok']) return $dry ? ['decision' => 'rejected', 'reason' => $c['reason'], 'seq' => $seq] : self::out($this->record($d, $e, $identifier, $at, 'rejected', $c['reason'], $x), $seq);
        $win = (int) self::cfg()['duplicate_seconds'];
        $dup = NfEvent::where('employee_id', $e->id)->whereIn('decision', ['accepted', 'flagged'])->whereBetween('occurred_at', [$at->copy()->subSeconds($win), $at->copy()->addSeconds($win)])->exists();
        if ($dry) return ['decision' => $dup ? 'duplicate' : ($c['flag'] ? 'flagged' : 'accepted'), 'reason' => $dup ? 'duplicate' : $c['reason'], 'seq' => $seq, 'name' => $name, 'mission_id' => $c['mission_id']];
        if ($dup) return self::out($this->record($d, $e, $identifier, $at, 'duplicate', 'duplicate', $x), $seq, $name);
        $r = $this->att->punch($e, $d, $at, $via === 'zk' ? 'zk' : ($via === 'sticker' ? 'phone' : 'bridge'));
        if ($r['kind'] === 'dup') return self::out($this->record($d, $e, $identifier, $at, 'duplicate', 'duplicate', $x), $seq, $name);
        if ($r['kind'] !== 'ok') return self::out($this->record($d, $e, $identifier, $at, 'rejected', 'inactive', $x), $seq);
        $dir = in_array($d->direction, ['in', 'out'], true) && self::cfg()['direction'] === 'auto' ? $d->direction : $r['type'];
        $ev = $this->record($d, $e, $identifier, $at, $c['flag'] ? 'flagged' : 'accepted', $c['reason'], $x + ['mission_id' => $c['mission_id'], 'scan_id' => $r['scan_id'], 'direction' => $dir, 'date' => $c['date']]);
        return self::out($ev, $seq, $name) + ['time' => $at->copy()->setTimezone($c['tz'])->format('H:i')];
    }

    private static function out(NfEvent $ev, ?int $seq, ?array $name = null): array
    {
        $show = in_array($ev->decision, ['accepted', 'flagged', 'duplicate'], true);
        return ['decision' => $ev->decision, 'reason' => $ev->reason, 'seq' => $seq, 'name' => $show ? $name : null, 'direction' => $ev->direction, 'event_id' => $ev->id];
    }

    // ———————————————— التنبيهات (FR-NF-015) ————————————————

    private function alert(NfEvent $ev, Device $d, ?Employee $e = null): void
    {
        $cfg = self::cfg(); $day = now()->toDateString(); $dev = $d->name_ar; $devEn = $d->name_en;
        $once = fn (string $k) => Cache::add('nf-alert:'.Tenant::id().":{$k}:{$day}", 1, 86400);
        switch ($ev->reason) {
            case 'unknown_card':
                $n = NfEvent::where('device_id', $d->id)->where('reason', 'unknown_card')->where('identifier', $ev->identifier)->where('occurred_at', '>=', now()->subDay())->count();
                if ($n >= (int) $cfg['unknown_alert'] && $once("unknown:{$ev->identifier}"))
                    Notify::hr("بطاقة غير معروفة قُرئت {$n} مرات على «{$dev}»", "An unknown card was read {$n} times on «{$devEn}»", 'nfc:unknown');
                break;
            case 'lost_card': case 'suspended':
                $who = $ev->employee_id ? Employee::find($ev->employee_id) : null;
                if ($once("blocked:{$ev->identifier}")) Notify::hr("استُخدمت بطاقة موقوفة أو مفقودة على «{$dev}»".($who ? " — {$who->name}" : ''), "A disabled or lost card was used on «{$devEn}»".($who ? " — {$who->name_en}" : ''), 'nfc:blocked');
                break;
            case 'off_branch':
                $who = $e ?? ($ev->employee_id ? Employee::find($ev->employee_id) : null);
                if ($who && $once("off:{$who->id}")) Notify::hr("بصمة خارج الفرع المصرّح: {$who->name} على «{$dev}»", "Punch outside the authorised branch: {$who->name_en} on «{$devEn}»", 'nfc:off_branch');
                break;
        }
    }

    /** بطاقتان مختلفتان على الجهاز نفسه خلال ثوانٍ، ويتكرر ذلك للزوج نفسه ← «بصمة زميل عن زميل» */
    private function buddy(NfEvent $ev, Device $d): void
    {
        $cfg = self::cfg(); $sec = (int) $cfg['buddy_seconds'];
        if ($sec <= 0) return;
        $near = NfEvent::where('device_id', $d->id)->whereIn('decision', ['accepted', 'flagged'])->where('employee_id', '!=', $ev->employee_id)->whereKeyNot($ev->id)
            ->whereBetween('occurred_at', [$ev->occurred_at->copy()->subSeconds($sec), $ev->occurred_at->copy()->addSeconds($sec)])->value('employee_id');
        if (! $near) return;
        $pair = collect([$ev->employee_id, $near])->sort()->values();
        $n = count(self::buddyPairs(now()->subDays(30), now(), $d->id)[$pair->implode('-')] ?? []);
        if ($n >= (int) $cfg['buddy_repeat'] && Cache::add('nf-buddy:'.Tenant::id().':'.$pair->implode('-').':'.now()->format('Y-W'), 1, 86400 * 7)) {
            $names = Employee::whereIn('id', $pair)->pluck('name')->implode(' و');
            $namesEn = Employee::whereIn('id', $pair)->pluck('name_en')->implode(' & ');
            Notify::hr("بصمتان متلاصقتان تكررتا {$n} مرات: {$names}", "Back-to-back punches repeated {$n} times: {$namesEn}", 'nfc:buddy');
        }
    }

    /** أزواج البصمات المتلاصقة: «a-b» ← [أوقات] */
    public static function buddyPairs(CarbonInterface $from, CarbonInterface $to, ?int $deviceId = null): array
    {
        $sec = max(1, (int) self::cfg()['buddy_seconds']);
        $rows = NfEvent::whereIn('decision', ['accepted', 'flagged'])->whereNotNull('employee_id')->whereBetween('occurred_at', [$from, $to])
            ->when($deviceId, fn ($q) => $q->where('device_id', $deviceId))->orderBy('device_id')->orderBy('occurred_at')->get(['device_id', 'employee_id', 'occurred_at']);
        $out = []; $prev = null;
        foreach ($rows as $r) {
            if ($prev && $prev->device_id === $r->device_id && $prev->employee_id !== $r->employee_id && $prev->occurred_at->diffInSeconds($r->occurred_at, true) <= $sec) {
                $k = collect([$prev->employee_id, $r->employee_id])->sort()->implode('-');
                $out[$k][] = $r->occurred_at->toIso8601String();
            }
            $prev = $r;
        }
        return $out;
    }

    // ———————————————— المراجعة والمراقبة ————————————————

    /** حدث معلَّم «خارج الفرع»: القبول يبقيه، والرفض يحذف حركته ويعيد حساب اليوم */
    public function review(NfEvent $ev, bool $accept, ?string $note, User $u): NfEvent
    {
        if ($ev->review !== 'pending') throw new ApiException(L::t('روجع هذا الحدث من قبل.', 'This event was already reviewed.'), 'reviewed', 409);
        if (! $accept && mb_strlen(trim((string) $note)) < 5) throw ApiException::field('note', L::t('اكتب سبب الرفض.', 'Write the reason.'), 'reason_required');
        DB::transaction(function () use ($ev, $accept, $note, $u) {
            $ev->update(['review' => $accept ? 'accepted' : 'rejected', 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'review_note' => $note, 'decision' => $accept ? 'accepted' : 'rejected']);
            if (! $accept && $ev->scan_id && ($scan = Scan::find($ev->scan_id))) {
                $e = Employee::find($scan->employee_id); $date = substr($scan->getRawOriginal('ts'), 0, 10);
                $scan->delete();
                $this->recompute($e, $date);
            }
        });
        Audit::record('nfc.review', $ev, ['accept' => $accept, 'note' => $note]);
        return $ev;
    }

    private function recompute(Employee $e, string $date): void
    {
        $tz = WorkSchedules::tz($e);
        $day = Scan::where('employee_id', $e->id)->where('ts', '>=', $date.' 00:00:00')->where('ts', '<=', $date.' 23:59:59')->orderBy('ts')->get();
        $d = AttendanceDay::where('employee_id', $e->id)->whereDate('date', $date)->first();
        if (! $d || $d->source === 'manual') return;
        if ($day->isEmpty()) { $d->delete(); return; }
        $in = Carbon::parse($day->first()->getRawOriginal('ts'), $tz); $out = $day->count() > 1 ? Carbon::parse($day->last()->getRawOriginal('ts'), $tz) : null;
        $c = DayCalc::day($date, $in, $out, $e);
        $d->update(['in_at' => $in, 'out_at' => $out, 'status' => $c['late'] ? 'late' : ($c['rest'] ? 'weekend' : 'present')]);
    }

    /** نبضة الجهاز: آخر اتصال، العنوان الداخلي، فرق الساعة، حالة البوابة */
    public static function heartbeat(Device $d, array $v): void
    {
        $drift = isset($v['clock']) ? (int) round(Carbon::parse($v['clock'])->getTimestamp() - now()->getTimestamp()) : $d->clock_drift;
        $was = $d->offline_alerted_at;
        $d->update(['last_seen_at' => now(), 'last_ip' => request()?->ip(), 'lan_ip' => $v['lan_ip'] ?? $d->lan_ip, 'clock_drift' => $drift, 'offline_alerted_at' => null,
            'gateway' => isset($v['version']) || isset($v['buffered']) ? ['version' => $v['version'] ?? null, 'buffered' => (int) ($v['buffered'] ?? 0)] : $d->gateway]);
        if ($was) Notify::hr("عاد الجهاز «{$d->name_ar}» للاتصال", "Device «{$d->name_en}» is back online", 'nfc:devices');
        if ($drift !== null && abs($drift) > (int) self::cfg()['drift_seconds'] && Cache::add('nf-drift:'.$d->id.':'.now()->toDateString(), 1, 86400))
            Notify::hr("ساعة الجهاز «{$d->name_ar}» منحرفة {$drift} ثانية", "Clock of «{$d->name_en}» is off by {$drift} s", 'nfc:devices');
    }

    /** FR-NF-013: جهاز فعّال انقطعت نبضته أكثر من الحد ← تنبيه واحد حتى يعود */
    public static function monitor(): int
    {
        $min = (int) self::cfg()['offline_minutes'];
        $n = 0;
        foreach (Device::where('active', true)->whereNotNull('last_seen_at')->whereNull('offline_alerted_at')->where('last_seen_at', '<', now()->subMinutes($min))->get() as $d) {
            $d->update(['offline_alerted_at' => now()]);
            Notify::hr("الجهاز «{$d->name_ar}» منقطع منذ {$d->last_seen_at->diffForHumans()}", "Device «{$d->name_en}» has been offline since {$d->last_seen_at->toDateTimeString()}", 'nfc:devices');
            $n++;
        }
        return $n;
    }

    // ———————————————— التفعيل (WF-NF-01) ————————————————

    public static function activationCode(Device $d): string
    {
        $code = strtoupper(Str::random(4).'-'.Str::random(4));
        $code = strtr($code, ['0' => '7', 'O' => 'P', 'I' => 'K', '1' => '8', 'L' => 'M']);
        $d->update(['activation_hash' => hash('sha256', str_replace('-', '', $code)), 'activation_expires_at' => now()->addHours((int) self::cfg()['activation_hours']), 'status' => 'pending']);
        Audit::record('device.activation_code', $d);
        return $code;
    }

    /** الجهاز يدخل رمزه ← يتسلم مفتاحاً دائماً وسراً للتوقيع (يظهران مرة واحدة) */
    public static function activate(string $code): array
    {
        $h = hash('sha256', strtoupper(str_replace(['-', ' '], '', $code)));
        $d = Device::allCompanies()->with('company')->where('activation_hash', $h)->first();
        if (! $d || ! $d->activation_expires_at || $d->activation_expires_at->isPast() || $d->company?->status !== 'active')
            throw new ApiException(L::t('رمز التفعيل غير صحيح أو انتهت صلاحيته.', 'The activation code is wrong or expired.'), 'activation_invalid', 422);
        $key = Str::random(48); $secret = Str::random(48);
        $d->update(['key_hash' => hash('sha256', $key), 'key_hint' => substr($key, -4), 'secret' => $secret, 'activation_hash' => null, 'activation_expires_at' => null,
            'status' => 'active', 'active' => true, 'last_seen_at' => now(), 'last_ip' => request()?->ip()]);
        Tenant::run($d->company, fn () => Audit::record('device.activated', $d));
        return ['device_id' => $d->code ?: (string) $d->id, 'key' => $key, 'secret' => $secret, 'name' => ['ar' => $d->name_ar, 'en' => $d->name_en],
            'company' => $d->company->name_ar, 'signature' => $d->require_signature || self::cfg()['signature'] === 'required'];
    }

    // ———————————————— التقارير (FR-NF-020) ————————————————

    public static function report(string $from, string $to): array
    {
        $a = Carbon::parse($from)->startOfDay(); $b = Carbon::parse($to)->endOfDay();
        $base = fn () => NfEvent::whereBetween('occurred_at', [$a, $b]);
        $locs = Location::get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $devs = Device::get(['id', 'name_ar', 'name_en', 'location_id', 'active', 'last_seen_at', 'status', 'clock_drift'])->keyBy('id');
        $byBranch = $base()->selectRaw('location_id, decision, count(*) n')->groupBy('location_id', 'decision')->get()->groupBy('location_id')
            ->map(fn ($g, $k) => ['branch' => $k && isset($locs[$k]) ? ['ar' => $locs[$k]->name_ar, 'en' => $locs[$k]->name_en] : null] + $g->pluck('n', 'decision')->map(fn ($n) => (int) $n)->all())->values();
        $byDevice = $base()->selectRaw('device_id, decision, count(*) n')->groupBy('device_id', 'decision')->get()->groupBy('device_id')
            ->map(fn ($g, $k) => ['device' => isset($devs[$k]) ? ['ar' => $devs[$k]->name_ar, 'en' => $devs[$k]->name_en] : null] + $g->pluck('n', 'decision')->map(fn ($n) => (int) $n)->all())->values();
        $reasons = $base()->where('decision', 'rejected')->selectRaw('reason, count(*) n')->groupBy('reason')->pluck('n', 'reason')->map(fn ($n) => (int) $n);
        $off = $base()->where('reason', 'off_branch')->with(['employee:id,code,name,name_en', 'location:id,name_ar,name_en'])->latest('occurred_at')->limit(100)->get()
            ->map(fn ($e) => ['at' => $e->occurred_at->toIso8601String(), 'employee' => $e->employee ? ['ar' => $e->employee->name, 'en' => $e->employee->name_en, 'code' => $e->employee->code] : null,
                'branch' => $e->location ? ['ar' => $e->location->name_ar, 'en' => $e->location->name_en] : null, 'decision' => $e->decision, 'review' => $e->review]);
        $pairs = collect(self::buddyPairs($a, $b))->filter(fn ($v) => count($v) >= 2)->map(function ($v, $k) {
            $ids = explode('-', $k); $names = Employee::whereIn('id', $ids)->get(['name', 'name_en']);
            return ['ar' => $names->pluck('name')->implode(' / '), 'en' => $names->pluck('name_en')->implode(' / '), 'n' => count($v)];
        })->sortByDesc('n')->values();
        $mins = (int) self::cfg()['offline_minutes'];
        return ['by_branch' => $byBranch, 'by_device' => $byDevice, 'rejected' => $reasons, 'off_branch' => $off, 'buddies' => $pairs,
            'devices' => $devs->values()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en, 'status' => $d->status, 'active' => $d->active, 'drift' => $d->clock_drift,
                'online' => $d->last_seen_at && $d->last_seen_at->gt(now()->subMinutes($mins)), 'last_seen' => $d->last_seen_at?->toIso8601String(),
                'branch' => $d->location_id && isset($locs[$d->location_id]) ? ['ar' => $locs[$d->location_id]->name_ar, 'en' => $locs[$d->location_id]->name_en] : null])];
    }

    public static function eventRow(NfEvent $e): array
    {
        return ['id' => $e->id, 'at' => $e->occurred_at->toIso8601String(), 'received_at' => $e->received_at->toIso8601String(), 'decision' => $e->decision, 'reason' => $e->reason,
            'direction' => $e->direction, 'via' => $e->via, 'review' => $e->review, 'review_note' => $e->review_note, 'identifier' => $e->employee_id ? null : $e->identifier,
            'late_upload' => $e->received_at->diffInMinutes($e->occurred_at, true) > 5,
            'employee' => $e->employee ? ['id' => $e->employee->id, 'ar' => $e->employee->name, 'en' => $e->employee->name_en, 'code' => $e->employee->code] : null,
            'device' => $e->device ? ['ar' => $e->device->name_ar, 'en' => $e->device->name_en] : null,
            'branch' => $e->location ? ['ar' => $e->location->name_ar, 'en' => $e->location->name_en] : null,
            'mission' => $e->mission ? ['id' => $e->mission->id, 'ref' => $e->mission->ref_no] : null];
    }
}
