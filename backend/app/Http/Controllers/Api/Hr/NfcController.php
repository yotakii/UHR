<?php

namespace App\Http\Controllers\Api\Hr;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\BranchAccess;
use App\Models\Credential;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Location;
use App\Models\NfEvent;
use App\Services\Attendance\NfcGate;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * بوابة الحضور متعددة الفروع (الدفعة 21): سجل الأحداث الخام ومراجعة «خارج الفرع»، المحاكاة، التقارير،
 * اعتمادات كل موظف (بطاقات، بصمة…) وصلاحيات فروعه، ورموز تفعيل الأجهزة وملصقات الفروع، والإعدادات.
 */
class NfcController extends Controller
{
    public function events(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'decision' => ['nullable', 'in:accepted,flagged,rejected,duplicate,pending'],
            'reason' => ['nullable', 'string', 'max:20'], 'branch' => ['nullable', 'integer'], 'device' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:60'], 'page' => ['nullable', 'integer', 'min:1']]);
        $from = Carbon::parse($f['from'] ?? now()->subDays(6))->startOfDay(); $to = Carbon::parse($f['to'] ?? now())->endOfDay();
        $scope = $this->scopeIds($request);
        $q = NfEvent::with(['employee:id,code,name,name_en', 'device:id,name_ar,name_en', 'location:id,name_ar,name_en', 'mission:id,ref_no'])->whereBetween('occurred_at', [$from, $to])
            ->when($scope !== null, fn ($w) => $w->where(fn ($x) => $x->whereIn('employee_id', $scope)->orWhereNull('employee_id')))
            ->when(($f['decision'] ?? null) === 'pending', fn ($w) => $w->where('review', 'pending'), fn ($w) => $w->when($f['decision'] ?? null, fn ($x, $v) => $x->where('decision', $v)))
            ->when($f['reason'] ?? null, fn ($w, $v) => $w->where('reason', $v))->when($f['branch'] ?? null, fn ($w, $v) => $w->where('location_id', $v))->when($f['device'] ?? null, fn ($w, $v) => $w->where('device_id', $v))
            ->when($f['q'] ?? null, fn ($w, $v) => $w->where(fn ($x) => $x->where('identifier', 'ilike', "%{$v}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$v}%")->orWhere('name_en', 'ilike', "%{$v}%")->orWhere('code', 'ilike', "%{$v}%"))));
        $counts = (clone $q)->reorder()->selectRaw('decision, count(*) n')->groupBy('decision')->pluck('n', 'decision')->map(fn ($n) => (int) $n);
        $page = (int) ($f['page'] ?? 1);
        $total = (clone $q)->count();
        $rows = $q->latest('occurred_at')->latest('id')->skip(($page - 1) * 50)->take(50)->get();
        return response()->json(['data' => $rows->map(fn ($e) => NfcGate::eventRow($e)), 'total' => $total, 'page' => $page, 'counts' => $counts,
            'pending' => NfEvent::where('review', 'pending')->count(),
            'branches' => Location::where('active', true)->orderBy('id')->get(['id', 'name_ar', 'name_en'])->map(fn ($l) => ['id' => $l->id, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'devices' => Device::orderBy('id')->get(['id', 'name_ar', 'name_en', 'kind'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en, 'kind' => $d->kind]),
            'can' => ['review' => Perm::can($request->user(), 'attendance', 'edit'), 'settings' => Perm::can($request->user(), 'settings', 'edit')]]);
    }

    public function review(Request $request, int $id, NfcGate $gate): JsonResponse
    {
        $v = $request->validate(['accept' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $ev = NfEvent::with(['employee', 'device', 'location', 'mission'])->findOrFail($id);
        $this->inScope($request, $ev->employee_id);
        $gate->review($ev, $v['accept'], $v['note'] ?? null, $request->user());
        return response()->json(['data' => NfcGate::eventRow($ev->fresh(['employee', 'device', 'location', 'mission']))]);
    }

    /** محاكاة القرار (C.4): أي جهاز + أي بطاقة، دون حفظ */
    public function simulate(Request $request, NfcGate $gate): JsonResponse
    {
        $v = $request->validate(['device_id' => ['required', 'integer'], 'card' => ['required', 'string', 'max:60'], 'at' => ['nullable', 'date']]);
        $d = Device::findOrFail($v['device_id']);
        $r = $gate->event($d, $v['card'], Carbon::parse($v['at'] ?? now()), null, $d->kind === 'zk' ? 'zk' : 'nfc', true);
        $r['branch'] = $d->location_id ? (fn ($l) => ['ar' => $l->name_ar, 'en' => $l->name_en])(Location::find($d->location_id)) : null;
        if (! empty($r['mission_id'])) $r['mission'] = \App\Models\Mission::whereKey($r['mission_id'])->value('ref_no');
        return response()->json(['data' => $r]);
    }

    public function report(Request $request): JsonResponse
    {
        $v = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        return response()->json(['data' => NfcGate::report($v['from'] ?? now()->subDays(29)->toDateString(), $v['to'] ?? now()->toDateString())]);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => NfcGate::cfg(), 'defaults' => Settings::DEFAULTS['nfc'], 'can' => ['edit' => Perm::can($request->user(), 'settings', 'edit')]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $v = $request->validate(['off_branch' => ['required', 'in:reject,flag'], 'mission_access' => ['required', 'boolean'], 'duplicate_seconds' => ['required', 'integer', 'between:5,3600'],
            'direction' => ['required', 'in:auto,sequence'], 'signature' => ['required', 'in:optional,required'], 'activation_hours' => ['required', 'integer', 'between:1,720'],
            'offline_minutes' => ['required', 'integer', 'between:2,1440'], 'drift_seconds' => ['required', 'integer', 'between:10,3600'], 'unknown_alert' => ['required', 'integer', 'between:1,100'],
            'buddy_seconds' => ['required', 'integer', 'between:0,60'], 'buddy_repeat' => ['required', 'integer', 'between:1,100'], 'auto_disable' => ['required', 'boolean'],
            'sticker' => ['required', 'boolean'], 'sticker_geo' => ['required', 'boolean'], 'card_security' => ['required', 'in:uid,memory,secure']]);
        $old = NfcGate::cfg();
        Settings::put('nfc', $v);
        Audit::record('settings.nfc', null, ['before' => $old, 'after' => $v]);
        return $this->settings($request);
    }

    // ———————————————— اعتمادات الموظف وفروعه ————————————————

    public function access(Request $request, int $id): JsonResponse
    {
        $e = Directory::scoped($request->user())->with('location')->findOrFail($id);
        $today = now()->setTimezone(\App\Services\Attendance\WorkSchedules::tz($e))->toDateString();
        $missions = \App\Models\Mission::where('employee_id', $e->id)->whereIn('status', ['approved', 'assigned', 'in_progress', 'review'])->whereNotNull('travel')
            ->whereRaw("travel->>'return_on' >= ?", [$today])->orderByRaw("travel->>'depart_on'")->limit(10)->get(['id', 'ref_no', 'title', 'travel'])
            ->map(fn ($m) => ['id' => $m->id, 'ref' => $m->ref_no, 'title' => $m->title, 'from' => $m->travel['depart_on'] ?? null, 'to' => $m->travel['return_on'] ?? null,
                'branches' => Location::where('governorate', $m->travel['governorate'] ?? '-')->get(['name_ar', 'name_en'])->map(fn ($l) => ['ar' => $l->name_ar, 'en' => $l->name_en])]);
        return response()->json([
            'home' => $e->location ? ['id' => $e->location->id, 'ar' => $e->location->name_ar, 'en' => $e->location->name_en] : null,
            'credentials' => Credential::where('employee_id', $e->id)->latest('id')->get()->map(fn ($c) => self::cred($c)),
            'branches' => BranchAccess::with('location')->where('employee_id', $e->id)->orderByDesc('id')->get()->map(fn ($a) => ['id' => $a->id, 'kind' => $a->kind,
                'branch' => $a->location ? ['id' => $a->location->id, 'ar' => $a->location->name_ar, 'en' => $a->location->name_en] : null, 'from' => $a->from?->toDateString(), 'to' => $a->to?->toDateString(),
                'note' => $a->note, 'live' => (! $a->from || $a->from->toDateString() <= $today) && (! $a->to || $a->to->toDateString() >= $today)]),
            'missions' => $missions,
            'locations' => Location::where('active', true)->orderBy('id')->get(['id', 'name_ar', 'name_en'])->map(fn ($l) => ['id' => $l->id, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'can' => ['edit' => Perm::can($request->user(), 'employees', 'edit')],
        ]);
    }

    public function addCredential(Request $request, int $id): JsonResponse
    {
        $e = Directory::scoped($request->user())->findOrFail($id);
        $v = $request->validate(['type' => ['required', 'in:nfc,fingerprint,face,phone'], 'identifier' => ['required', 'string', 'max:60'], 'label' => ['nullable', 'string', 'max:120'],
            'valid_from' => ['nullable', 'date'], 'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from']]);
        $c = NfcGate::issue($e, $v['type'], $v['identifier'], $request->user(), $v);
        $e->log("إصدار اعتماد حضور ({$c->type}): {$c->identifier}", "Attendance credential issued ({$c->type}): {$c->identifier}", 'ok');
        return response()->json(['data' => self::cred($c)], 201);
    }

    public function credentialStatus(Request $request, int $id): JsonResponse
    {
        $c = Credential::findOrFail($id);
        Directory::scoped($request->user())->findOrFail($c->employee_id);
        $v = $request->validate(['status' => ['required', 'in:active,lost,suspended,revoked'], 'reason' => ['nullable', 'string', 'max:255']]);
        if ($v['status'] !== 'active' && mb_strlen(trim((string) ($v['reason'] ?? ''))) < 3) throw ApiException::field('reason', L::t('اكتب السبب.', 'Write the reason.'), 'reason_required');
        return response()->json(['data' => self::cred(NfcGate::setStatus($c, $v['status'], $v['reason'] ?? null, $request->user()))]);
    }

    public function addBranch(Request $request, int $id): JsonResponse
    {
        $e = Directory::scoped($request->user())->findOrFail($id);
        $v = $request->validate(['location_id' => ['required', 'integer', Rule::exists('locations', 'id')], 'kind' => ['required', 'in:permanent,temporary,secondment'],
            'from' => ['nullable', 'required_unless:kind,permanent', 'date'], 'to' => ['nullable', 'required_unless:kind,permanent', 'date', 'after_or_equal:from'], 'note' => ['nullable', 'string', 'max:255']],
            ['from.required_unless' => L::t('حدد تاريخ البداية.', 'Set the start date.'), 'to.required_unless' => L::t('حدد تاريخ النهاية.', 'Set the end date.')]);
        Location::findOrFail($v['location_id']);
        if ((int) $e->location_id === (int) $v['location_id']) throw ApiException::field('location_id', L::t('هذا هو الفرع الأساسي للموظف.', 'This is the employee\'s home branch.'), 'home_branch');
        $a = BranchAccess::create($v + ['employee_id' => $e->id, 'created_by' => $request->user()->id, 'from' => $v['kind'] === 'permanent' ? null : $v['from'], 'to' => $v['kind'] === 'permanent' ? null : $v['to']]);
        $name = Location::find($v['location_id']);
        $e->log("صلاحية فرع إضافي: {$name->name_ar}", "Extra branch access: {$name->name_en}", 'info');
        return response()->json(['id' => $a->id], 201);
    }

    public function removeBranch(Request $request, int $id): JsonResponse
    {
        $a = BranchAccess::findOrFail($id);
        $e = Directory::scoped($request->user())->findOrFail($a->employee_id);
        $a->delete();
        $e->log('إلغاء صلاحية فرع إضافي', 'Extra branch access removed', 'warn');
        return response()->json(['ok' => true]);
    }

    // ———————————————— الأجهزة والملصقات ————————————————

    public function activation(int $id): JsonResponse
    {
        $d = Device::findOrFail($id);
        $code = NfcGate::activationCode($d);
        return response()->json(['code' => $code, 'expires_at' => $d->activation_expires_at->toIso8601String(), 'device_id' => $d->code]);
    }

    /** ملصق الفرع (FR-NF-017): رمز ثابت للفرع يُطبع QR أو يُكتب على ملصق NFC — تجديده يبطل الملصقات القديمة */
    public function sticker(Request $request, int $id): JsonResponse
    {
        $l = Location::whereNotNull('company_id')->findOrFail($id);
        if ($request->isMethod('post') || ! $l->scan_token) { $l->update(['scan_token' => Str::random(32)]); Audit::record('branch.sticker', $l); }
        $origin = $request->headers->get('origin') ?: rtrim((string) config('app.frontend_url'), '/');
        return response()->json(['url' => rtrim($origin, '/').'/checkin/'.$l->scan_token, 'branch' => ['ar' => $l->name_ar, 'en' => $l->name_en, 'code' => $l->code],
            'geo' => (bool) NfcGate::cfg()['sticker_geo'] && $l->radius > 0, 'enabled' => (bool) NfcGate::cfg()['sticker']]);
    }

    public static function cred(Credential $c): array
    {
        return ['id' => $c->id, 'type' => $c->type, 'identifier' => $c->identifier, 'label' => $c->label, 'status' => $c->status, 'reason' => $c->reason,
            'valid_from' => $c->valid_from?->toDateString(), 'valid_to' => $c->valid_to?->toDateString(), 'created_at' => $c->created_at?->toIso8601String(), 'revoked_at' => $c->revoked_at?->toIso8601String()];
    }

    /** null = كل الموظفين؛ وإلا معرّفات من في نطاق الحضور */
    private function scopeIds(Request $request): ?array
    {
        return Perm::scope($request->user(), 'attendance') === 'all' ? null : Directory::scoped($request->user(), 'attendance')->pluck('id')->all();
    }

    private function inScope(Request $request, ?int $employeeId): void
    {
        $ids = $this->scopeIds($request);
        if ($ids !== null && $employeeId && ! in_array($employeeId, $ids, true)) abort(404);
    }
}
