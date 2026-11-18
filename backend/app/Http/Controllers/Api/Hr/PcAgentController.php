<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentCommand;
use App\Models\Employee;
use App\Models\PcAgent;
use App\Services\Attendance\PcAgents;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** وكلاء أجهزة الموظفين UHR-Agent (D-15): القائمة والحالة، الربط والحزمة، أمر تجريبي، والإعدادات — وواجهة الوكيل نفسه */
class PcAgentController extends Controller
{
    private function agent(Request $r, int $id): PcAgent
    {
        $a = PcAgent::with('employee:id,code,name,name_en')->findOrFail($id);
        if (! Directory::scoped($r->user(), 'attendance')->whereKey($a->employee_id)->exists()) abort(404);
        return $a;
    }

    public function index(Request $r): JsonResponse
    {
        $ids = Directory::scoped($r->user(), 'attendance')->pluck('id');
        $rows = PcAgent::with('employee:id,code,name,name_en')->whereIn('employee_id', $ids)
            ->when($r->query('employee'), fn ($q, $v) => $q->where('employee_id', (int) $v))->orderByDesc('id')->get();
        return response()->json(['data' => $rows->map(fn ($a) => PcAgents::row($a)), 'settings' => PcAgents::cfg(), 'defaults' => Settings::DEFAULTS['pc_agent'],
            'can' => ['edit' => Perm::can($r->user(), 'attendance', 'edit'), 'settings' => Perm::can($r->user(), 'settings', 'edit')]]);
    }

    public function store(Request $r): JsonResponse
    {
        $v = $r->validate(['employee_id' => ['required', 'integer'], 'name' => ['required', 'string', 'min:2', 'max:120']], [], ['name' => L::t('اسم الجهاز', 'Computer name')]);
        $e = Directory::scoped($r->user(), 'attendance')->findOrFail($v['employee_id']);
        return response()->json(['data' => PcAgents::row(PcAgents::create($e, $v['name'], $r->user())->load('employee:id,code,name,name_en'))], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $a = $this->agent($r, $id);
        $v = $r->validate(['name' => ['required', 'string', 'min:2', 'max:120'], 'active' => ['required', 'boolean']]);
        $a->update($v);
        if (! $v['active']) AgentCommand::where('pc_agent_id', $a->id)->whereIn('status', ['queued', 'sent'])->update(['status' => 'cancelled', 'error' => 'disabled', 'done_at' => now()]);
        return response()->json(['data' => PcAgents::row($a->fresh('employee'))]);
    }

    public function commands(Request $r, int $id): JsonResponse
    {
        $a = $this->agent($r, $id);
        return response()->json(['data' => AgentCommand::where('pc_agent_id', $a->id)->latest('id')->limit(50)->get()->map(fn ($c) => ['id' => $c->id, 'action' => $c->action, 'reason' => $c->reason,
            'source' => $c->source, 'status' => $c->status, 'error' => $c->error, 'at' => $c->created_at->toIso8601String(), 'sent_at' => $c->sent_at?->toIso8601String(), 'done_at' => $c->done_at?->toIso8601String()])]);
    }

    public function test(Request $r, int $id): JsonResponse
    {
        $a = $this->agent($r, $id);
        PcAgents::test($a, (string) $r->input('action', 'lock'), $r->user());
        return response()->json(['data' => PcAgents::row($a->fresh('employee'))]);
    }

    /** الحزمة الجاهزة: zip فيه الوكيل وأدوات التثبيت وملف الإعداد بعنوان النظام ومفتاح جديد (يقطع أي نسخة سابقة) */
    public function package(Request $r, int $id): BinaryFileResponse
    {
        $a = $this->agent($r, $id);
        $key = PcAgents::rotateKey($a);
        Audit::record('agent.package', $a);
        $origin = $r->headers->get('origin') ?: (($ref = $r->headers->get('referer')) ? preg_replace('#^(https?://[^/]+).*$#', '$1', $ref) : null) ?: config('app.frontend_url');
        $src = resource_path('pc-agent');
        $path = tempnam(sys_get_temp_dir(), 'agt').'.zip';
        $zip = new \ZipArchive(); $zip->open($path, \ZipArchive::CREATE);
        foreach (scandir($src) as $f) if (is_file("{$src}/{$f}")) $zip->addFromString("UHR-Agent/{$f}", preg_replace("/\r?\n/", "\r\n", file_get_contents("{$src}/{$f}")));
        $zip->addFromString('UHR-Agent/uhr-agent.ini', implode("\r\n", ['; UHR-Agent — '.$a->name.' — '.($a->employee?->name_en ?: $a->employee?->name).' ('.now()->toDateString().')',
            'URL='.rtrim($origin, '/'), 'KEY='.$key, '']));
        $zip->close();
        return response()->download($path, 'UHR-Agent.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    public function saveSettings(Request $r): JsonResponse
    {
        $v = $r->validate(['enabled' => ['required', 'boolean'], 'action' => ['required', 'in:'.implode(',', PcAgents::ACTIONS)], 'warn_seconds' => ['required', 'integer', 'between:0,600'],
            'postpone_minutes' => ['required', 'integer', 'between:0,60'], 'sources' => ['present', 'array'], 'sources.*' => ['in:'.implode(',', PcAgents::SOURCES)],
            'fresh_minutes' => ['required', 'integer', 'between:1,120'], 'queue_hours' => ['required', 'integer', 'between:1,72'], 'offline_minutes' => ['required', 'integer', 'between:1,60'],
            'poll_seconds' => ['required', 'integer', 'between:5,120'], 'notify_employee' => ['required', 'boolean']]);
        $old = PcAgents::cfg();
        Settings::put('pc_agent', $v);
        Audit::record('settings.pc_agent', null, ['before' => $old, 'after' => $v]);
        return $this->index($r);
    }

    // ———————————————— واجهة الوكيل (X-Agent-Key) ————————————————

    public function poll(Request $r): JsonResponse
    {
        $v = $r->validate(['host' => ['nullable', 'string', 'max:120'], 'user' => ['nullable', 'string', 'max:120'], 'version' => ['nullable', 'string', 'max:20']]);
        return response()->json(PcAgents::poll($r->attributes->get('agent'), $v, $r->ip()));
    }

    public function report(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['status' => ['required', 'in:done,failed,postponed'], 'error' => ['nullable', 'string', 'max:500']]);
        $c = PcAgents::report($r->attributes->get('agent'), $id, $v['status'], $v['error'] ?? null);
        return response()->json(['ok' => true, 'status' => $c->status]);
    }
}
