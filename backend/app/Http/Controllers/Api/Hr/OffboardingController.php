<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExitCase;
use App\Models\Employee;
use App\Models\ExitNotice;
use App\Models\Resignation;
use App\Models\User;
use App\Services\Exit\ExitFlow;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** M11 — الاستقالات وإنهاء الخدمة (HR.01-03): القائمة، التفاصيل، المقابلة، الإعفاء، الرجوع، F-05، F-07، مقابلة الخروج، الإغلاق، البديل، الإشعارات، التقرير */
class OffboardingController extends Controller
{
    public function __construct(private ExitFlow $flow) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', 'in:open,closed,all'], 'q' => ['nullable', 'string', 'max:100']]);
        $u = $request->user();
        $st = $f['status'] ?? 'open';
        $base = Resignation::whereIn('employee_id', Directory::scoped($u)->select('id'));
        $rows = (clone $base)->with(ExitCase::WITH)
            ->when($st === 'open', fn ($q) => $q->whereIn('status', Resignation::OPEN))->when($st === 'closed', fn ($q) => $q->whereNotIn('status', Resignation::OPEN))
            ->when($f['q'] ?? null, fn ($q, $s) => $q->where(fn ($x) => $x->where('ref_no', 'ilike', "%{$s}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%")->orWhere('name_en', 'ilike', "%{$s}%")->orWhere('code', 'ilike', "%{$s}%"))))
            ->orderByRaw("case when status in ('pending_manager','pending_hr','pending_exec') then 0 when status = 'clearance' then 1 else 2 end")->latest('id')->limit(300)->get();
        $all = Perm::scope($u, 'employees') === 'all';
        return response()->json(['data' => $rows->map(fn ($r) => ExitCase::card($r, $u)),
            'counts' => ['pending' => (clone $base)->whereIn('status', Resignation::PENDING)->count(), 'clearance' => (clone $base)->where('status', 'clearance')->count(),
                'followups' => $all ? (clone $base)->where('status', 'closed')->get(['followups'])->filter(fn ($r) => ! ($r->followups['labor']['done'] ?? true) || ! ($r->followups['insurance']['done'] ?? true))->count() : 0],
            'lists' => ExitFlow::lists(), 'policy' => Settings::policy('exit'), 'reject_min' => (int) Settings::policy('leave_rules')['reject_min'],
            'employees' => Directory::scoped($u)->current()->where('status', '!=', 'resigning')->orderBy('code')->get(['id', 'code', 'name', 'name_en'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en]),
            'users' => Perm::can($u, 'users', 'edit') ? User::with('employee')->where('active', true)->whereNotNull('employee_id')->get()->filter(fn ($x) => $x->employee && ! in_array($x->employee->status, ['terminated', 'resigning'], true))
                ->map(fn ($x) => ['id' => $x->id, 'ar' => $x->displayName('ar'), 'en' => $x->displayName('en'), 'code' => $x->employee->code])->sortBy('code')->values() : [],
            'can' => ['terminate' => $all && Perm::can($u, 'employees', 'edit'), 'settings' => Perm::can($u, 'settings', 'edit'), 'report' => $all]]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->out($request, $this->find($request, $id));
    }

    public function terminate(Request $request): JsonResponse
    {
        $e = Directory::scoped($request->user())->current()->findOrFail((int) $request->input('employee_id'));
        $r = $this->flow->terminate($request->user()->loadMissing('roles'), $e, $request->all());
        return $this->out($request, $r, 201);
    }

    public function interview(Request $request, int $id): JsonResponse { return $this->out($request, $this->flow->interview($this->find($request, $id), $request->user(), $request->all())); }
    public function waive(Request $request, int $id): JsonResponse { return $this->out($request, $this->flow->waive($this->find($request, $id), $request->user(), $request->all())); }

    public function withdrawal(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['accept' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        return $this->out($request, $this->flow->decideWithdrawal($this->find($request, $id), $request->user(), (bool) $d['accept'], $d['note'] ?? null));
    }

    public function handover(Request $request, int $id): JsonResponse { return $this->out($request, $this->flow->saveHandover($this->find($request, $id), $request->user(), $request->all())); }

    public function signHandover(Request $request, int $id): JsonResponse
    {
        $as = $request->validate(['as' => ['required', 'in:manager,hr']])['as'];
        return $this->out($request, $this->flow->signHandover($this->find($request, $id), $request->user()->loadMissing('roles'), $as));
    }

    public function markItem(Request $request, int $id, string $section, string $item): JsonResponse
    {
        return $this->out($request, $this->flow->markItem($this->find($request, $id), $request->user(), $section, $item, $request->all()));
    }

    public function signSection(Request $request, int $id, string $section): JsonResponse
    {
        return $this->out($request, $this->flow->signSection($this->find($request, $id), $request->user(), $section));
    }

    public function exitInterview(Request $request, int $id): JsonResponse { return $this->out($request, $this->flow->saveExit($this->find($request, $id), $request->user(), $request->all())); }
    public function close(Request $request, int $id): JsonResponse { return $this->out($request, $this->flow->close($this->find($request, $id), $request->user())); }
    public function certificate(Request $request, int $id): JsonResponse { return $this->out($request, $this->flow->serviceCertificate($this->find($request, $id), $request->user())); }

    public function successor(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['user_id' => ['required', 'integer'], 'reset' => ['nullable', 'boolean']]);
        $res = $this->flow->successor($this->find($request, $id), $request->user(), (int) $d['user_id'], (bool) ($d['reset'] ?? false));
        return response()->json(['data' => ExitCase::full($this->find($request, $id), $request->user()), 'password' => $res['password'], 'roles' => $res['roles']]);
    }

    public function followup(Request $request, int $id, string $key): JsonResponse
    {
        $done = (bool) $request->validate(['done' => ['required', 'boolean']])['done'];
        return $this->out($request, $this->flow->followup($this->find($request, $id), $request->user(), $key, $done));
    }

    public function sendNotice(Request $request, int $id, int $noticeId): JsonResponse
    {
        $r = $this->find($request, $id);
        $this->flow->sendNotice(ExitNotice::where('resignation_id', $r->id)->findOrFail($noticeId), $request->user());
        return $this->out($request, $r);
    }

    public function report(Request $request): JsonResponse
    {
        if (Perm::scope($request->user(), 'employees') !== 'all') abort(403);
        $m = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $from = $m.'-01';
        return response()->json(['data' => ExitFlow::report($from, \Illuminate\Support\Carbon::parse($from)->endOfMonth()->toDateString()), 'month' => $m]);
    }

    // ── إعدادات القوائم (M16) ──

    public function settings(): JsonResponse
    {
        return response()->json(['data' => ExitFlow::lists(), 'defaults' => Settings::DEFAULTS['offboarding']]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $bi = fn (string $k) => ["{$k}" => ['required', 'array', 'min:1', 'max:30'], "{$k}.*.key" => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,30}$/'], "{$k}.*.ar" => ['required', 'string', 'max:300'], "{$k}.*.en" => ['required', 'string', 'max:300']];
        $d = $request->validate($bi('reasons') + $bi('causes') + $bi('dismissal_reasons') + $bi('questions') + ['sections' => ['required', 'array', 'min:1', 'max:12'],
            'sections.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,30}$/'], 'sections.*.ar' => ['required', 'string', 'max:200'], 'sections.*.en' => ['required', 'string', 'max:200'],
            'sections.*.role' => ['required', 'in:'.implode(',', Perm::ROLES)],
            'sections.*.items' => ['required', 'array', 'min:1', 'max:15'], 'sections.*.items.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,30}$/'],
            'sections.*.items.*.ar' => ['required', 'string', 'max:300'], 'sections.*.items.*.en' => ['required', 'string', 'max:300'], 'sections.*.items.*.auto' => ['nullable', 'in:handover,assets,leave'],
            'sections.*.items.*.amount' => ['nullable', 'boolean']]);
        foreach (['reasons', 'causes', 'dismissal_reasons', 'questions', 'sections'] as $k) {
            if (count(array_unique(array_column($d[$k], 'key'))) !== count($d[$k])) throw ApiException::field($k, L::t('المفاتيح مكررة.', 'Duplicate keys.'), 'duplicate_key');
        }
        // «فصل» في أنواع الإنهاء و«أخرى» في الأسباب أساسيان (لهما حقول خاصة في النموذج)
        foreach (['causes' => 'dismissal', 'reasons' => 'other', 'dismissal_reasons' => 'other'] as $list => $must) {
            if (! in_array($must, array_column($d[$list], 'key'), true)) throw ApiException::field($list, L::t("البند «{$must}» أساسي ولا يُحذف.", "The «{$must}» item is required."), 'required_item');
        }
        // طلب مفتوح يستخدم سبباً لا يُحذف سببه
        $used = Resignation::whereIn('status', Resignation::OPEN)->get(['reasons', 'reason_key'])->flatMap(fn ($r) => [...($r->reasons ?? []), $r->reason_key])->unique();
        $keys = [...array_column($d['reasons'], 'key'), ...array_column($d['causes'], 'key'), ...array_column($d['dismissal_reasons'], 'key')];
        if ($missing = $used->diff($keys)->filter()->values()->all()) throw ApiException::field('reasons', L::t('سبب مستخدم في طلب مفتوح لا يُحذف: ', 'A reason used by an open request cannot be removed: ').implode(', ', $missing), 'in_use');
        $sections = array_map(fn ($s) => ['key' => $s['key'], 'ar' => $s['ar'], 'en' => $s['en'], 'role' => $s['role'],
            'items' => array_map(fn ($i) => array_filter(['key' => $i['key'], 'ar' => $i['ar'], 'en' => $i['en'], 'auto' => $i['auto'] ?? null, 'amount' => ! empty($i['amount']) ?: null], fn ($v) => $v !== null), $s['items'])], $d['sections']);
        $before = Settings::offboarding();
        $new = ['reasons' => $d['reasons'], 'causes' => $d['causes'], 'dismissal_reasons' => $d['dismissal_reasons'], 'questions' => $d['questions'], 'sections' => $sections];
        foreach (['reasons', 'causes', 'dismissal_reasons', 'questions'] as $k) $new[$k] = array_map(fn ($x) => ['key' => $x['key'], 'ar' => trim($x['ar']), 'en' => trim($x['en'])], $new[$k]);
        Settings::put('offboarding', $new);
        Audit::record('settings.offboarding', null, $before, $new);
        return $this->settings();
    }

    private function find(Request $request, int $id): Resignation
    {
        return Resignation::with(ExitCase::WITH)->whereIn('employee_id', Directory::scoped($request->user())->select('id'))->findOrFail($id);
    }

    private function out(Request $request, Resignation $r, int $status = 200): JsonResponse
    {
        return response()->json(['data' => ExitCase::full($r->fresh(ExitCase::WITH), $request->user()->loadMissing('roles'))], $status);
    }
}
