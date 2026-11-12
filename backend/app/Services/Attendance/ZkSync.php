<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Support\Audit;
use App\Support\CardUid;
use App\Support\L;
use App\Support\Realtime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * بصّامات ZKTeco (iClock وغيرها) عبر جسر على حاسوب في شبكة الشركة (المهمة 17):
 *  - الجسر يقرأ إعداد الجهاز وقائمة الموظفين من config، ويرسل الحركات الجديدة إلى punches.
 *  - رقم الموظف على البصّامة device_pin (الافتراضي أرقام رقمه الوظيفي UHR-1007 ← 1007).
 *  - الحركة: مرة واحدة لكل (جهاز، رقم، وقت)؛ رقم بلا موظف ← «غير مربوط» حتى تربطه الموارد البشرية فتُطبَّق حركاته.
 */
class ZkSync
{
    public const DEFAULTS = ['ip' => '', 'port' => 4370, 'comm_key' => '', 'machine_no' => 1, 'card_order' => 'normal', 'force' => 0];
    public const MAX_BATCH = 2000;

    public function __construct(private Attendance $att) {}

    public static function cfg(Device $d): array { return array_merge(self::DEFAULTS, (array) ($d->zk ?? [])); }

    /** أرقام الرقم الوظيفي (UHR-1007 ← 1007) */
    public static function defaultPin(Employee $e): ?string
    {
        $n = ltrim(preg_replace('/\D/', '', (string) $e->code), '0');
        return $n !== '' ? $n : null;
    }

    /** يعطي رقماً على البصّامة لكل موظف حالي بلا رقم (إن لم يكن مأخوذاً) */
    public static function ensurePins(): void
    {
        $taken = Employee::whereNotNull('device_pin')->pluck('device_pin')->flip();
        foreach (Employee::whereNull('device_pin')->where('status', '!=', 'terminated')->get(['id', 'code', 'device_pin']) as $e) {
            $pin = self::defaultPin($e);
            if ($pin && ! isset($taken[$pin])) { Employee::whereKey($e->id)->update(['device_pin' => $pin]); $taken[$pin] = true; }
        }
    }

    /** بطاقة NFC بالصيغة العشرية التي تخزنها البصّامة (أول 4 بايت، بالترتيب أو معكوسة حسب إعداد الجهاز) */
    public static function cardDec(?string $uid, string $order = 'normal'): string
    {
        if (! $uid) return '';
        $h = CardUid::hex($uid);
        if (! $h) return '';
        $bytes = array_slice(str_split($h, 2), 0, 4);
        if ($order === 'reversed') $bytes = array_reverse($bytes);
        return (string) hexdec(implode('', $bytes));
    }

    /** الموظفون كما يُرسلون للبصّامة: الرقم، الاسم اللاتيني (شاشة الجهاز لا تعرض العربية غالباً)، البطاقة */
    public static function users(Device $d): Collection
    {
        self::ensurePins();
        $order = self::cfg($d)['card_order'];
        return Employee::whereNotNull('device_pin')->where('status', '!=', 'terminated')->orderBy('code')->get(['id', 'code', 'name', 'name_en', 'device_pin', 'nfc_uid'])
            ->map(fn (Employee $e) => ['pin' => $e->device_pin, 'name' => mb_substr(self::latin($e->name_en ?: $e->code), 0, 24), 'card' => self::cardDec($e->nfc_uid, $order)])->values();
    }

    private static function latin(string $s): string
    {
        $s = preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
        return trim($s) !== '' ? trim($s) : 'Employee';
    }

    /** ما يقرأه الجسر في كل دورة */
    public static function config(Device $d): array
    {
        $c = self::cfg($d);
        $users = self::users($d);
        $last = DevicePunch::where('device_id', $d->id)->max('punched_at');
        $tz = WorkSchedules::tzOf($d->location_id);
        return [
            'zk' => ['ip' => $c['ip'], 'port' => (int) $c['port'], 'comm_key' => (string) $c['comm_key'], 'machine_no' => (int) $c['machine_no']],
            'poll' => 20,
            // يقرأ الجسر من البصّامة ما بعد هذا الوقت (بتوقيت الجهاز) مع هامش ساعة لما تأخر
            'since' => $last ? Carbon::parse($last)->setTimezone($tz)->subHour()->format('Y-m-d H:i:s') : null,
            'users_hash' => md5(json_encode($users).'|'.$c['force']),
            'users' => $users,
        ];
    }

    /**
     * الحركات الواردة: [{pin, time "Y-m-d H:i:s" بتوقيت الجهاز, verify, state}] — تُحفظ مرة واحدة وتُطبَّق على الحضور.
     * @return array{received:int, new:int, applied:int, dup:int, unmatched:int}
     */
    public function ingest(Device $d, array $rows): array
    {
        $tz = WorkSchedules::tzOf($d->location_id);
        $out = ['received' => count($rows), 'new' => 0, 'applied' => 0, 'dup' => 0, 'unmatched' => 0];
        $byPin = Employee::whereNotNull('device_pin')->get()->keyBy('device_pin');
        usort($rows, fn ($a, $b) => strcmp((string) ($a['time'] ?? ''), (string) ($b['time'] ?? '')));
        foreach ($rows as $r) {
            $pin = trim((string) ($r['pin'] ?? ''));
            try { $at = Carbon::createFromFormat('Y-m-d H:i:s', (string) ($r['time'] ?? ''), $tz); } catch (\Throwable) { continue; }
            if ($pin === '' || ! $at || $at->gt(now()->addDay())) continue;
            $p = DevicePunch::firstOrCreate(['device_id' => $d->id, 'pin' => $pin, 'punched_at' => $at->copy()->setTimezone(config('app.timezone'))],
                ['verify' => isset($r['verify']) ? (int) $r['verify'] : null, 'state' => isset($r['state']) ? (int) $r['state'] : null, 'status' => 'unmatched']);
            if (! $p->wasRecentlyCreated) continue;
            $out['new']++;
            $e = $byPin[$pin] ?? null;
            $res = $this->apply($p, $e, $d, $at);
            $out[$res === 'ok' ? 'applied' : ($res === 'unmatched' ? 'unmatched' : 'dup')]++;
        }
        if ($out['new']) Realtime::touch('device_punches', $d->company_id);
        return $out;
    }

    private function apply(DevicePunch $p, ?Employee $e, Device $d, Carbon $at): string
    {
        if (! $e) { $p->update(['status' => 'unmatched']); return 'unmatched'; }
        $r = $this->att->punch($e, $d, $at, 'zk');
        $p->update(['employee_id' => $e->id, 'status' => $r['kind'], 'scan_id' => $r['scan_id'] ?? null]);
        return $r['kind'];
    }

    /** ربط رقم على البصّامة بموظف، ثم تطبيق حركاته المعلّقة */
    public function map(string $pin, Employee $e, $by): int
    {
        $pin = trim($pin);
        if (! preg_match('/^\d{1,9}$/', $pin)) throw ApiException::field('pin', L::t('رقم البصّامة أرقام فقط (حتى 9 خانات).', 'The device number must be digits (up to 9).'), 'invalid_pin');
        if (Employee::where('device_pin', $pin)->whereKeyNot($e->id)->exists()) throw ApiException::field('pin', L::t('هذا الرقم مربوط بموظف آخر.', 'This number belongs to another employee.'), 'pin_taken');
        $old = $e->device_pin;
        $e->update(['device_pin' => $pin]);
        $e->log("ربط رقم البصّامة {$pin}", "Fingerprint device number {$pin} linked", 'info');
        Audit::record('employee.device_pin', $e, ['device_pin' => $old], ['device_pin' => $pin]);
        $n = 0;
        foreach (DevicePunch::with('device')->where('pin', $pin)->where('status', 'unmatched')->orderBy('punched_at')->get() as $p) {
            $tz = WorkSchedules::tzOf($p->device->location_id);
            if ($this->apply($p, $e, $p->device, Carbon::parse($p->getRawOriginal('punched_at'), config('app.timezone'))->setTimezone($tz)) === 'ok') $n++;
        }
        return $n;
    }

    /** للوحة الأجهزة: آخر الحركات + الأرقام غير المربوطة */
    public static function overview(Device $d): array
    {
        $tz = config('app.timezone');
        return [
            'recent' => DevicePunch::with('employee')->where('device_id', $d->id)->latest('punched_at')->limit(15)->get()->map(fn (DevicePunch $p) => [
                'pin' => $p->pin, 'at' => $p->punched_at->toIso8601String(), 'status' => $p->status, 'verify' => $p->verify,
                'employee' => $p->employee ? ['id' => $p->employee->id, 'code' => $p->employee->code, 'name' => ['ar' => $p->employee->name, 'en' => $p->employee->name_en]] : null]),
            'unmatched' => DevicePunch::where('device_id', $d->id)->where('status', 'unmatched')->selectRaw('pin, count(*) as n, max(punched_at) as last')->groupBy('pin')->orderByDesc('last')->limit(50)->get()
                ->map(fn ($r) => ['pin' => $r->pin, 'count' => (int) $r->n, 'last' => Carbon::parse($r->last, $tz)->toIso8601String()]),
            'today' => DevicePunch::where('device_id', $d->id)->where('punched_at', '>=', now()->startOfDay())->count(),
        ];
    }
}
