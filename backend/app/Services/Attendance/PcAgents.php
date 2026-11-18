<?php

namespace App\Services\Attendance;

use App\Exceptions\ApiException;
use App\Models\AgentCommand;
use App\Models\Employee;
use App\Models\PcAgent;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * وكيل جهاز الموظف UHR-Agent (D-15، SRS §10.10):
 * عند تسجيل الخروج (بالبطاقة افتراضياً) يوضع أمر قفل/تسجيل خروج لكل جهاز مربوط بالموظف.
 * الوكيل يسأل الخادم كل بضع ثوانٍ؛ الجهاز المطفأ يجد الأمر في الطابور عند أول اتصال ما لم تنتهِ صلاحيته
 * أو يسجّل الموظف دخولاً جديداً (فيُلغى الأمر حتى لا يُقفل جهازه صباح اليوم التالي).
 */
class PcAgents
{
    public const ACTIONS = ['lock', 'logoff', 'shutdown'];
    public const SOURCES = ['card', 'fingerprint', 'phone'];

    public static function cfg(): array { return Settings::merged('pc_agent'); }

    /** حركة حضور مسجّلة: خروج ← أوامر للأجهزة، دخول ← إلغاء ما بقي في الطابور */
    public static function afterTap(Employee $e, string $type, string $source, ?CarbonInterface $at = null): int
    {
        $c = self::cfg();
        if (! $c['enabled']) return 0;
        if ($type === 'in') {
            AgentCommand::where('employee_id', $e->id)->whereIn('status', ['queued', 'sent'])->where('reason', 'checkout')
                ->update(['status' => 'cancelled', 'error' => 'checked_in', 'done_at' => now()]);
            return 0;
        }
        if ($type !== 'out' || ! in_array($source, $c['sources'], true)) return 0;
        // حركة قديمة وصلت متأخرة من بصّامة/بوابة كانت منقطعة: لا تُقفل جهازاً يعمل عليه الموظف الآن
        if ($at && $at->diffInMinutes(now(), true) > (int) $c['fresh_minutes']) return 0;
        $agents = PcAgent::where('employee_id', $e->id)->where('active', true)->get();
        foreach ($agents as $a) self::queue($a, 'checkout', $c['action'], $source);
        if ($agents->isNotEmpty() && $c['notify_employee'] && ($uid = User::where('employee_id', $e->id)->value('id'))) {
            Notify::user($uid, 'تم تسجيل خروجك — سيُقفل جهازك خلال '.$c['warn_seconds'].' ثانية، فاحفظ عملك.', 'You have checked out — your computer will lock in '.$c['warn_seconds'].' seconds; please save your work.', 'attendance');
        }
        return $agents->count();
    }

    public static function queue(PcAgent $a, string $reason, ?string $action = null, ?string $source = null, ?User $by = null): AgentCommand
    {
        $c = self::cfg();
        // أمر واحد قائم لكل جهاز: الأحدث يحل محل ما في الطابور
        AgentCommand::where('pc_agent_id', $a->id)->where('status', 'queued')->update(['status' => 'cancelled', 'error' => 'replaced', 'done_at' => now()]);
        return AgentCommand::create(['pc_agent_id' => $a->id, 'employee_id' => $a->employee_id, 'action' => $action ?? $c['action'], 'reason' => $reason, 'source' => $source,
            'warn_seconds' => (int) $c['warn_seconds'], 'postpone_minutes' => (int) $c['postpone_minutes'], 'status' => 'queued',
            'expires_at' => now()->addHours((int) $c['queue_hours']), 'created_by' => $by?->id]);
    }

    // ———————————————— إدارة الوكلاء (الموارد البشرية) ————————————————

    public static function create(Employee $e, string $name, User $by): PcAgent
    {
        $a = PcAgent::create(['employee_id' => $e->id, 'name' => trim($name), 'active' => true, 'created_by' => $by->id]);
        $e->log("رُبط جهاز «{$a->name}» بوكيل UHR-Agent", "Computer “{$a->name}” linked with UHR-Agent", 'info');
        return $a;
    }

    /** مفتاح جديد (يقطع أي نسخة سابقة) — يُعاد مرة واحدة ليُكتب في ملف إعداد الحزمة */
    public static function rotateKey(PcAgent $a): string
    {
        $key = Str::random(48);
        $a->update(['key_hash' => hash('sha256', $key), 'key_hint' => substr($key, -4), 'active' => true]);
        return $key;
    }

    public static function test(PcAgent $a, string $action, User $by): AgentCommand
    {
        if (! in_array($action, self::ACTIONS, true)) throw ApiException::field('action', L::t('اختر الأمر.', 'Choose the command.'), 'invalid');
        $cmd = self::queue($a, 'test', $action, null, $by);
        Audit::record('agent.test', $a, null, ['action' => $action]);
        return $cmd;
    }

    // ———————————————— واجهة الوكيل ————————————————

    /** نبضة الوكيل: يحدّث حالته ويستلم الأوامر الصالحة (تُعلَّم «أُرسل») */
    public static function poll(PcAgent $a, array $info, ?string $ip): array
    {
        $back = $a->last_seen_at && $a->last_seen_at->lt(now()->subMinutes((int) self::cfg()['offline_minutes']));
        $a->update(['last_seen_at' => now(), 'last_ip' => $ip, 'offline_alerted_at' => null,
            'host' => mb_substr((string) ($info['host'] ?? $a->host), 0, 120) ?: null, 'os_user' => mb_substr((string) ($info['user'] ?? $a->os_user), 0, 120) ?: null,
            'version' => mb_substr((string) ($info['version'] ?? $a->version), 0, 20) ?: null]);
        self::expire($a->id);
        $cmds = AgentCommand::where('pc_agent_id', $a->id)->whereIn('status', ['queued', 'sent'])->orderBy('id')->get();
        foreach ($cmds as $c) if ($c->status === 'queued') $c->update(['status' => 'sent', 'sent_at' => now()]);
        return ['commands' => $cmds->map(fn ($c) => ['id' => $c->id, 'action' => $c->action, 'warn' => $c->warn_seconds, 'postpone' => $c->postpone_minutes,
            'reason' => $c->reason, 'queued_at' => $c->created_at->toIso8601String()])->values(),
            'poll' => (int) self::cfg()['poll_seconds'], 'back' => $back, 'employee' => $a->employee_id];
    }

    /** نتيجة التنفيذ من الوكيل: نُفّذ / فشل (+ تنبيه للموارد البشرية) / أجّله الموظف */
    public static function report(PcAgent $a, int $id, string $status, ?string $error): AgentCommand
    {
        $c = AgentCommand::where('pc_agent_id', $a->id)->findOrFail($id);
        if (! in_array($status, ['done', 'failed', 'postponed'], true)) throw ApiException::field('status', 'invalid', 'invalid');
        // الوكيل يبلّغ «نُفّذ» قبل تسجيل الخروج/الإيقاف (فعمليته تنتهي معهما)، فإن رفض ويندوز يصحّح بـ«فشل» خلال دقائق
        $correct = $c->status === 'done' && $status === 'failed' && $c->done_at?->gte(now()->subMinutes(5));
        if (! $correct && in_array($c->status, ['cancelled', 'expired', 'done', 'failed'], true)) return $c;
        if ($status === 'postponed') { $c->update(['error' => 'postponed']); return $c; }
        $c->update(['status' => $status, 'error' => $error ? mb_substr($error, 0, 255) : null, 'done_at' => now()]);
        if ($status === 'failed') {
            $name = Employee::whereKey($a->employee_id)->value('name');
            Notify::hr("تعذّر تنفيذ أمر الخروج على جهاز «{$a->name}» للموظف {$name}: ".($error ?: '—'), "The check-out command failed on “{$a->name}” ({$name}): ".($error ?: '—'), 'pc-agents');
        }
        return $c;
    }

    // ———————————————— المتابعة الدورية ————————————————

    public static function expire(?int $agentId = null): int
    {
        return AgentCommand::whereIn('status', ['queued', 'sent'])->where('expires_at', '<', now())->when($agentId, fn ($q, $v) => $q->where('pc_agent_id', $v))
            ->update(['status' => 'expired', 'done_at' => now()]);
    }

    /** أمر بقي في الطابور لجهاز غير متصل أكثر من مهلة الاتصال ← تنبيه واحد للموارد البشرية */
    public static function sweep(): int
    {
        $n = self::expire();
        $mins = (int) self::cfg()['offline_minutes'];
        foreach (PcAgent::with('employee:id,name,name_en')->where('active', true)->whereNull('offline_alerted_at')
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subMinutes($mins)))
            ->whereHas('commands', fn ($q) => $q->where('status', 'queued')->where('created_at', '<', now()->subMinutes($mins)))->get() as $a) {
            Notify::hr("جهاز «{$a->name}» للموظف {$a->employee?->name} غير متصل — أمر الخروج بالانتظار حتى يتصل.", "“{$a->name}” ({$a->employee?->name_en}) is offline — the check-out command is waiting.", 'pc-agents');
            $a->update(['offline_alerted_at' => now()]); $n++;
        }
        return $n;
    }

    public static function row(PcAgent $a): array
    {
        $last = $a->relationLoaded('commands') ? $a->commands->sortByDesc('id')->first() : AgentCommand::where('pc_agent_id', $a->id)->latest('id')->first();
        $online = $a->last_seen_at && $a->last_seen_at->gte(now()->subMinutes((int) self::cfg()['offline_minutes']));
        return ['id' => $a->id, 'name' => $a->name, 'active' => $a->active, 'online' => $online, 'paired' => (bool) $a->key_hint, 'key_hint' => $a->key_hint,
            'host' => $a->host, 'os_user' => $a->os_user, 'version' => $a->version, 'last_ip' => $a->last_ip, 'last_seen_at' => $a->last_seen_at?->toIso8601String(),
            'employee' => $a->relationLoaded('employee') && $a->employee ? ['id' => $a->employee->id, 'code' => $a->employee->code, 'ar' => $a->employee->name, 'en' => $a->employee->name_en] : null,
            'last' => $last ? ['id' => $last->id, 'action' => $last->action, 'reason' => $last->reason, 'status' => $last->status, 'error' => $last->error,
                'at' => $last->created_at->toIso8601String(), 'done_at' => $last->done_at?->toIso8601String()] : null,
            'queued' => AgentCommand::where('pc_agent_id', $a->id)->whereIn('status', ['queued', 'sent'])->count()];
    }
}
