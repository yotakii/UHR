<?php

namespace App\Http\Controllers\Api\Hr\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CodebookVersion;
use App\Models\CodeChangeRequest;
use App\Models\CodeItem;
use App\Models\CodeList;
use App\Models\CodeMapping;
use App\Models\User;
use App\Services\Codes\CodeCatalog;
use App\Services\Codes\Codebook;
use App\Services\Codes\CodeGen;
use App\Services\Codes\CodeImport;
use App\Services\Codes\Codes;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** «دليل الترميز» (SRS الأنظمة الأساسية، الجزء A — الدفعة 18) */
class CodesController extends Controller
{
    /** واجهة القراءة الموحدة لكل الموديولات: الرموز الفعالة لقائمة (FR-CS-016) */
    public function lookup(Request $request, string $key): JsonResponse
    {
        $parent = $request->query('parent');
        return response()->json(['data' => array_map(fn ($r) => ['id' => $r['id'], 'code' => $r['code'], 'ar' => $r['ar'], 'en' => $r['en'], 'parent_id' => $r['parent_id'], 'attrs' => $r['attrs'], 'live' => $r['live']],
            Codes::items($key, ! $request->boolean('all'), $parent !== null && $parent !== '' ? (int) $parent : null, $request->query('q')))]);
    }

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $lists = CodeList::where('active', true)->orWhere('system', false)->orderByRaw('system')->orderBy('sort')->get();
        $out = [];
        foreach ($lists as $l) {
            $rows = Codes::rows($l);
            $counts = $l->system ? [] : Codes::usageCounts($l);
            $out[] = $this->listRow($l) + ['total' => count($rows), 'live' => collect($rows)->where('live', true)->count(),
                'used' => $l->system ? 0 : collect($rows)->filter(fn ($r) => ! empty($counts[$r['id']]) || $r['locked'])->count()];
        }
        $cfg = Codes::config();
        return response()->json(['lists' => $out, 'golive' => Codebook::golive(), 'version' => Codebook::current()?->only(['id', 'version', 'summary', 'approved_at']),
            'direct' => Codebook::directAllowed(), 'pending' => CodeChangeRequest::where('status', 'pending')->count(),
            'settings' => $cfg + ['templates' => CodeGen::templates()], 'defaults' => Settings::DEFAULTS['codes'] + ['templates' => array_map(fn ($t) => ['template' => $t['template'], 'start' => $t['start']], CodeGen::TEMPLATES)],
            'tokens' => CodeGen::TOKENS, 'roles' => Perm::ROLES, 'sectors' => array_map(fn ($k, $s) => ['key' => $k, 'ar' => $s[0], 'en' => $s[1], 'count' => count($s[2])], array_keys(CodeCatalog::SECTORS), CodeCatalog::SECTORS),
            'can' => ['create' => Perm::can($u, 'codes', 'create'), 'edit' => Perm::can($u, 'codes', 'edit'), 'decide' => Perm::can($u, 'codes', 'decide'), 'export' => Perm::can($u, 'codes', 'export'),
                'print' => Perm::can($u, 'codes', 'print'), 'approve' => Codebook::canApproveBook($u), 'settings' => Perm::can($u, 'settings', 'edit')]]);
    }

    private function listRow(CodeList $l): array
    {
        return ['key' => $l->key, 'ar' => $l->name_ar, 'en' => $l->name_en, 'group' => $l->group, 'owner_role' => $l->owner_role, 'pattern' => $l->pattern, 'example' => $l->example,
            'hierarchical' => $l->hierarchical, 'parent_key' => $l->parent_key, 'source' => $l->source, 'system' => $l->system, 'required' => $l->required, 'active' => $l->active,
            'attrs_schema' => $l->attrs_schema ?? [], 'custom' => ! $l->system && ! array_key_exists($l->key, CodeCatalog::lists())];
    }

    public function show(string $key): JsonResponse
    {
        $l = Codes::list($key);
        $counts = $l->system ? [] : Codes::usageCounts($l);
        $rows = array_map(fn ($r) => $r + ['usage' => $counts[$r['id']] ?? 0, 'locked' => $r['locked'] || ! empty($counts[$r['id']])], Codes::rows($l));
        $parents = $l->parent_key ? Codes::items($l->parent_key, false) : ($l->hierarchical ? $rows : []);
        return response()->json(['list' => $this->listRow($l), 'rows' => $rows, 'next' => $l->system ? null : Codes::suggest($l),
            'parents' => array_map(fn ($p) => ['id' => $p['id'], 'code' => $p['code'], 'ar' => $p['ar'], 'en' => $p['en'], 'live' => $p['live']], $parents),
            'mappings' => CodeMapping::where('list_key', $key)->latest()->limit(50)->get(['old_code', 'new_code', 'operation', 'effective_on', 'moved'])]);
    }

    public function store(Request $request, string $key): JsonResponse
    {
        Codebook::requireDirect();
        $i = Codes::store(Codes::list($key), $request->all());
        return response()->json(['id' => $i->id, 'code' => $i->code], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        Codebook::requireDirect();
        $i = CodeItem::whereNotNull('company_id')->findOrFail($id);
        Codes::store($i->list, $request->all(), $i);
        return response()->json(['ok' => true]);
    }

    public function status(Request $request, string $key, int $id): JsonResponse
    {
        Codebook::requireDirect();
        $d = $request->validate(['active' => ['required', 'boolean'], 'until' => ['nullable', 'date']]);
        Codes::setStatus(Codes::list($key), $id, (bool) $d['active'], $d['until'] ?? null);
        return response()->json(['ok' => true]);
    }

    public function impact(string $key, int $id): JsonResponse
    {
        $l = Codes::list($key);
        return response()->json(['usage' => Codes::usage($l, $id), 'locked' => Codes::isLocked($l, $id)]);
    }

    /** تعديل تعريف القائمة: النمط، المالك، الإلزامية للتأسيس، الهرمية (لا يمس الرموز الموجودة) */
    public function saveList(Request $request, ?string $key = null): JsonResponse
    {
        $l = $key ? Codes::list($key) : null;
        if ($l?->system) throw new ApiException(L::t('القوائم القياسية مقفلة.', 'Standard lists are locked.'), 'system_list', 409);
        $d = $request->validate([
            'key' => [$l ? 'prohibited' : 'required', 'string', 'regex:/^[a-z][a-z0-9_]{2,39}$/'],
            'ar' => ['required', 'string', 'max:120'], 'en' => ['required', 'string', 'max:120'],
            'owner_role' => ['nullable', Rule::in(Perm::ROLES)], 'pattern' => ['nullable', 'string', 'max:120'], 'example' => ['nullable', 'string', 'max:40'],
            'required' => ['boolean'], 'hierarchical' => ['boolean'], 'active' => ['boolean'], 'group' => ['nullable', Rule::in(['org', 'people', 'pay', 'ops', 'docs'])],
        ], [], ['key' => L::t('مفتاح القائمة', 'List key'), 'ar' => L::t('الاسم بالعربية', 'Arabic name'), 'en' => L::t('الاسم بالإنكليزية', 'English name')]);
        if (($d['pattern'] ?? '') !== '' && @preg_match('/'.$d['pattern'].'/', '') === false) throw ApiException::field('pattern', L::t('النمط غير صالح.', 'Invalid pattern.'), 'invalid');
        if (! empty($d['pattern']) && ! empty($d['example']) && ! preg_match('/'.$d['pattern'].'/', $d['example'])) throw ApiException::field('example', L::t('المثال لا يطابق النمط.', 'The example does not match the pattern.'), 'invalid');
        if (! $l && CodeList::where('key', $d['key'])->exists()) throw ApiException::field('key', L::t('المفتاح مستخدم.', 'Key already used.'), 'invalid');
        if ($l?->source && ($d['active'] ?? true) === false) throw ApiException::field('active', L::t('لا يمكن إيقاف هذه القائمة.', 'This list cannot be switched off.'), 'invalid');
        $vals = ['name_ar' => trim($d['ar']), 'name_en' => trim($d['en']), 'owner_role' => $d['owner_role'] ?? null, 'pattern' => ($d['pattern'] ?? '') ?: null, 'example' => ($d['example'] ?? '') ?: null,
            'required' => (bool) ($d['required'] ?? false), 'hierarchical' => $l?->source ? false : (bool) ($d['hierarchical'] ?? false), 'active' => (bool) ($d['active'] ?? true)];
        $before = $l?->only(array_keys($vals));
        if ($l) $l->update($vals); else $l = CodeList::create($vals + ['key' => $d['key'], 'group' => $d['group'] ?? 'org', 'sort' => CodeList::max('sort') + 1]);
        Audit::record('codes.list_saved', $l, $before, $vals);
        Codes::bump();
        return response()->json($this->listRow($l->fresh()));
    }

    public function export(string $key): BinaryFileResponse
    {
        $l = Codes::list($key);
        Audit::record('codes.exported', null, null, ['list' => $key]);
        return response()->download(CodeImport::file($l, false), "UHR-codes-{$key}.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function template(string $key): BinaryFileResponse
    {
        return response()->download(CodeImport::file(Codes::list($key), true), "UHR-codes-{$key}-template.xlsx", ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function preview(Request $request, string $key): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $rows = CodeImport::preview(Codes::list($key), $request->file('file'));
        return response()->json(['rows' => $rows, 'ok' => count(array_filter($rows, fn ($r) => ! $r['errors'])), 'errors' => count(array_filter($rows, fn ($r) => $r['errors']))]);
    }

    public function import(Request $request, string $key): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $l = Codes::list($key);
        $owner = in_array($l->owner_role ?: 'admin', $request->user()->roles->pluck('key')->all(), true) && Perm::can($request->user(), 'codes', 'decide');
        if (! Codebook::directAllowed() && ! $owner) Codebook::requireDirect();
        $n = CodeImport::commit($l, $request->file('file'));
        if (Codebook::approved()) Codebook::snapshotVersion($request->user(), "استيراد {$n} رمزاً إلى {$l->name_ar}", "Imported {$n} code(s) into {$l->name_en}");
        Audit::record('codes.imported', null, null, ['list' => $key, 'count' => $n]);
        return response()->json(['imported' => $n], 201);
    }

    /** قالب قطاع جاهز (مسميات وعائلات) — في مرحلة التأسيس */
    public function sector(Request $request): JsonResponse
    {
        $d = $request->validate(['sector' => ['required', Rule::in(array_keys(CodeCatalog::SECTORS))]]);
        Codebook::requireDirect();
        $n = CodeCatalog::loadSector($d['sector']);
        Codes::bump();
        Audit::record('codes.sector_loaded', null, null, ['sector' => $d['sector'], 'added' => $n]);
        return response()->json(['added' => $n]);
    }

    // ——— طلبات التغيير ———

    public function requests(Request $request): JsonResponse
    {
        $u = $request->user();
        $q = CodeChangeRequest::with(['requester', 'decider'])->latest('id');
        $status = $request->query('status', 'open');
        if ($status === 'open') $q->whereIn('status', ['pending', 'approved']); elseif ($status !== 'all') $q->where('status', $status);
        return response()->json(['data' => $q->limit(200)->get()->map(fn ($r) => Codebook::row($r, $u))]);
    }

    public function submit(Request $request): JsonResponse
    {
        $d = $request->validate(['list' => ['required', 'string'], 'type' => ['required', Rule::in(Codebook::TYPES)], 'item_id' => ['nullable', 'integer'], 'payload' => ['nullable', 'array'],
            'reason' => ['required', 'string', 'max:2000'], 'effective_on' => ['nullable', 'date']]);
        $r = Codebook::request($request->user(), $d['list'], $d);
        return response()->json(Codebook::row($r->load(['requester', 'decider']), $request->user()), 201);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $r = Codebook::decide($request->user(), CodeChangeRequest::findOrFail($id), (bool) $d['approve'], $d['note'] ?? null);
        return response()->json(Codebook::row($r->load(['requester', 'decider']), $request->user()));
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        Codebook::cancel($request->user(), CodeChangeRequest::findOrFail($id));
        return response()->json(['ok' => true]);
    }

    public function simulate(Request $request): JsonResponse
    {
        $d = $request->validate(['list' => ['required', 'string'], 'item_id' => ['required', 'integer'], 'target_id' => ['required', 'integer']]);
        return response()->json(Codebook::simulate(Codes::list($d['list']), [(int) $d['item_id']], (int) $d['target_id']));
    }

    // ——— الاعتماد والإصدارات والطباعة ———

    public function approve(Request $request): JsonResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $v = Codebook::approve($request->user(), trim((string) ($d['note'] ?? '')));
        return response()->json(['version' => $v->version]);
    }

    public function versions(): JsonResponse
    {
        return response()->json(['data' => CodebookVersion::with('approver')->orderByDesc('major')->orderByDesc('minor')->get()->map(fn ($v) => ['id' => $v->id, 'version' => $v->version, 'summary' => $v->summary,
            'approved_at' => $v->approved_at->toIso8601String(), 'by' => $v->approver ? ['ar' => $v->approver->name, 'en' => $v->approver->name_en ?: $v->approver->name] : null,
            'major' => $v->minor === 0])]);
    }

    public function compare(Request $request): JsonResponse
    {
        $d = $request->validate(['a' => ['required', 'integer'], 'b' => ['required', 'integer']]);
        $a = CodebookVersion::findOrFail($d['a']); $b = CodebookVersion::findOrFail($d['b']);
        if ($a->major * 1000 + $a->minor > $b->major * 1000 + $b->minor) [$a, $b] = [$b, $a];
        return response()->json(['a' => $a->version, 'b' => $b->version, 'lists' => Codebook::compare($a, $b)]);
    }

    /** بيانات طباعة الدليل كاملاً (FR-CS-015) — اللقطة الحالية أو إصدار محدد */
    public function book(Request $request): JsonResponse
    {
        $v = $request->query('version') ? CodebookVersion::findOrFail((int) $request->query('version')) : Codebook::current();
        $lists = CodeList::where('system', false)->where('active', true)->orderBy('sort')->get();
        return response()->json(['version' => $v?->version, 'approved_at' => $v?->approved_at?->toIso8601String(), 'draft' => ! $request->query('version'),
            'lists' => $lists->map(fn ($l) => $this->listRow($l) + ['items' => $request->query('version') ? ($v->snapshot[$l->key]['items'] ?? [])
                : array_map(fn ($r) => ['code' => $r['code'], 'ar' => $r['ar'], 'en' => $r['en'], 'status' => $r['status'], 'parent' => $r['parent_id'] ? CodeItem::whereKey($r['parent_id'])->value('code') : null],
                    array_values(array_filter(Codes::rows($l), fn ($r) => ! $r['shared'])))])->values(),
            'templates' => CodeGen::templates()]);
    }

    public function printed(Request $request): JsonResponse
    {
        Audit::record('codes.printed', null, null, ['version' => Codebook::current()?->version]);
        return response()->json(['ok' => true]);
    }

    public function reports(): JsonResponse { return response()->json(Codes::reports()); }

    public function generate(Request $request, string $tpl): JsonResponse
    {
        $d = $request->validate(['template' => ['nullable', 'string', 'max:60'], 'start' => ['nullable', 'integer', 'min:0']]);
        if (! empty($d['template']) && ($e = CodeGen::check($d['template']))) throw ApiException::field('template', $e, 'invalid');
        return response()->json(['code' => CodeGen::preview($tpl, $request->only(['br', 'dept', 'job', 'pol', 'type']), $d['template'] ?? null, $d['start'] ?? (empty($d['template']) ? null : 1))]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'gate' => ['required', 'boolean'], 'change_requests' => ['required', 'boolean'], 'self_approve' => ['required', 'boolean'], 'admin_approves' => ['required', 'boolean'],
            'approver_roles' => ['required', 'array', 'min:1'], 'approver_roles.*' => [Rule::in(Perm::ROLES)], 'restructure_role' => ['required', Rule::in(Perm::ROLES)],
            'unused_months' => ['required', 'integer', 'between:1,60'], 'company_code' => ['nullable', 'string', 'regex:/^[A-Z]{2,5}$/'],
            'templates' => ['nullable', 'array'], 'templates.*.template' => ['required', 'string', 'max:60'], 'templates.*.start' => ['required', 'integer', 'between:0,99999999'],
        ], [], ['company_code' => L::t('رمز الشركة', 'Company code')]);
        $tpls = [];
        foreach ($d['templates'] ?? [] as $k => $t) {
            if (! isset(CodeGen::TEMPLATES[$k])) continue;
            if ($e = CodeGen::check($t['template'])) throw ApiException::field("templates.{$k}.template", $e, 'invalid');
            $tpls[$k] = ['template' => $t['template'], 'start' => (int) $t['start']];
        }
        $before = Codes::config();
        $new = ['gate' => (bool) $d['gate'], 'change_requests' => (bool) $d['change_requests'], 'self_approve' => (bool) $d['self_approve'], 'admin_approves' => (bool) $d['admin_approves'],
            'approver_roles' => array_values(array_unique($d['approver_roles'])), 'restructure_role' => $d['restructure_role'], 'unused_months' => (int) $d['unused_months'],
            'company_code' => $d['company_code'] ?? '', 'templates' => $tpls];
        Settings::put('codes', $new);
        Audit::record('settings.codes', null, $before, $new);
        return response()->json(['ok' => true]);
    }
}
