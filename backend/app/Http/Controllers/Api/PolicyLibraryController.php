<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\PolicyDocument;
use App\Models\PolicyParagraph;
use App\Models\PolicyRead;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\Policies\PolicyLibrary;
use App\Services\Policies\TextExtractor;
use App\Support\L;
use App\Support\Perm;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** مكتبة السياسات (D-26) — للبوابتين: الكل يقرأ المنشور ويبحث فيه، ومن يملك تعديل «السياسات» يرفع وينشر ويؤرشف ويربط الفقرات بالإعدادات */
class PolicyLibraryController extends Controller
{
    public function __construct(private PolicyLibrary $lib) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user(); $manage = PolicyLibrary::manages($u);
        $docs = PolicyDocument::with(['current', 'versions:id,document_id,number,status'])->when(! $manage, fn ($q) => $q->where('status', 'published'))
            ->orderByRaw("case status when 'published' then 0 when 'draft' then 1 else 2 end")->orderBy('code')->orderBy('id')->get();
        $read = PolicyRead::where('user_id', $u->id)->pluck('version_id')->flip();
        $staff = $manage ? $this->staffCount() : 0;
        $reads = $manage ? PolicyRead::selectRaw('version_id, count(*) as n')->groupBy('version_id')->pluck('n', 'version_id') : collect();
        return response()->json(['data' => $docs->map(fn (PolicyDocument $d) => $this->docRow($d) + [
            'read' => $d->current_version_id ? $read->has($d->current_version_id) : false,
            'reads' => $manage && $d->current_version_id ? ['n' => (int) ($reads[$d->current_version_id] ?? 0), 'of' => $staff] : null,
            'has_draft' => $d->versions->contains('status', 'draft'),
        ])->values(), 'categories' => $this->categories(), 'rules' => $this->rules(), 'can' => $this->can($u)]);
    }

    public function search(Request $request): JsonResponse
    {
        $v = $request->validate(['q' => ['required', 'string', 'min:2', 'max:120'], 'archived' => ['nullable', 'boolean']]);
        return response()->json(['data' => $this->lib->search($request->user(), $v['q'], (bool) ($v['archived'] ?? false))]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $u = $request->user(); $manage = PolicyLibrary::manages($u);
        $d = PolicyDocument::with(['current', 'versions.file', 'versions.publisher'])->findOrFail($id);
        if (! $manage && $d->status !== 'published') abort(404);
        $versions = $manage ? $d->versions : $d->versions->where('status', '!=', 'draft')->values();
        $vid = (int) $request->query('version', 0);
        $v = $versions->firstWhere('id', $vid) ?? $versions->firstWhere('id', $d->current_version_id) ?? $versions->first();
        if ($v) $this->lib->markRead($v, $u);
        $paras = $v ? PolicyParagraph::where('version_id', $v->id)->orderBy('n')->get(['id', 'n', 'text', 'rule_key', 'rule_note']) : collect();
        return response()->json(['data' => $this->docRow($d) + [
            'versions' => $versions->map(fn (PolicyVersion $x) => $this->versionRow($x))->values(),
            'version' => $v ? $this->versionRow($v) + ['text' => $manage && $v->status === 'draft' ? $v->text : null,
                'paragraphs' => $paras->map(fn ($p) => ['id' => $p->id, 'n' => $p->n, 'text' => $p->text, 'rule' => PolicyLibrary::rule($p->rule_key), 'rule_note' => $p->rule_note])->values(),
                'reads' => $manage ? ['n' => PolicyRead::where('version_id', $v->id)->count(), 'of' => $this->staffCount()] : null,
                'read_at' => PolicyRead::where('version_id', $v->id)->where('user_id', $u->id)->value('read_at')?->toIso8601String()] : null,
        ], 'categories' => $this->categories(), 'rules' => $this->rules(), 'can' => $this->can($u)]);
    }

    /** من اطّلع على الإصدار ومن لم يطّلع (موظفو الشركة الحاليون) */
    public function reads(int $id): JsonResponse
    {
        $v = PolicyVersion::findOrFail($id);
        $reads = PolicyRead::where('version_id', $v->id)->pluck('read_at', 'user_id');
        $users = User::with('employee:id,code,department_id')->where('company_id', Tenant::id())->where('active', true)->whereNotNull('employee_id')->orderBy('name')->get(['id', 'name', 'name_en', 'employee_id']);
        return response()->json(['data' => $users->map(fn ($x) => ['id' => $x->id, 'ar' => $x->name, 'en' => $x->name_en ?: $x->name, 'code' => $x->employee?->code,
            'read_at' => isset($reads[$x->id]) ? \Illuminate\Support\Carbon::parse($reads[$x->id])->toIso8601String() : null])->sortBy(fn ($r) => $r['read_at'] ? 1 : 0)->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validDoc($request) + $this->validVersion($request);
        $doc = $this->lib->create($d, $request->file('file'), $this->text($request), $request->user());
        return $this->show($request, $doc->id);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $doc = PolicyDocument::findOrFail($id);
        $this->lib->updateDocument($doc, $this->validDoc($request, $doc));
        return $this->show($request, $doc->id);
    }

    public function addVersion(Request $request, int $id): JsonResponse
    {
        $doc = PolicyDocument::findOrFail($id);
        $v = $this->lib->newVersion($doc, $this->validVersion($request), $request->file('file'), $this->text($request), $request->user());
        $request->query->set('version', $v->id);
        return $this->show($request, $doc->id);
    }

    public function updateVersion(Request $request, int $id): JsonResponse
    {
        $v = PolicyVersion::findOrFail($id);
        $this->lib->updateVersion($v, $this->validVersion($request), $request->file('file'), $this->text($request), $request->user());
        $request->query->set('version', $v->id);
        return $this->show($request, $v->document_id);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $v = PolicyVersion::with('document')->findOrFail($id);
        $this->lib->publish($v, $request->user());
        $request->query->set('version', $v->id);
        return $this->show($request, $v->document_id);
    }

    public function destroyVersion(Request $request, int $id): JsonResponse
    {
        $doc = $this->lib->deleteDraft(PolicyVersion::with('document')->findOrFail($id));
        return $doc ? $this->show($request, $doc->id) : response()->json(['data' => null]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $this->lib->archive(PolicyDocument::findOrFail($id), $request->boolean('restore'));
        return $this->show($request, $id);
    }

    public function link(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['rule_key' => ['nullable', Rule::in(array_keys(PolicyLibrary::RULES))], 'rule_note' => ['nullable', 'string', 'max:300']]);
        $p = PolicyParagraph::with('version')->findOrFail($id);
        $this->lib->link($p, $v['rule_key'] ?? null, $v['rule_note'] ?? null);
        return response()->json(['data' => ['id' => $p->id, 'n' => $p->n, 'text' => $p->text, 'rule' => PolicyLibrary::rule($p->rule_key), 'rule_note' => $p->rule_note]]);
    }

    private function docRow(PolicyDocument $d): array
    {
        $c = $d->current;
        return ['id' => $d->id, 'code' => $d->code, 'ar' => $d->title_ar, 'en' => $d->title_en, 'category' => $d->category, 'status' => $d->status,
            'current' => $c ? ['id' => $c->id, 'number' => $c->number, 'issue' => $c->issue, 'effective_from' => $c->effective_from?->toDateString(), 'published_at' => $c->published_at?->toIso8601String()] : null,
            'archived_at' => $d->archived_at?->toIso8601String()];
    }

    private function versionRow(PolicyVersion $v): array
    {
        return ['id' => $v->id, 'number' => $v->number, 'issue' => $v->issue, 'status' => $v->status, 'effective_from' => $v->effective_from?->toDateString(), 'note' => $v->note,
            'file' => $v->file_id && $v->relationLoaded('file') && $v->file ? ['id' => $v->file->id, 'name' => $v->file->name, 'size' => $v->file->size] : ($v->file_id ? ['id' => $v->file_id, 'name' => null, 'size' => null] : null),
            'text_missing' => $v->text_missing, 'published_at' => $v->published_at?->toIso8601String(), 'archived_at' => $v->archived_at?->toIso8601String(),
            'publisher' => $v->relationLoaded('publisher') && $v->publisher ? ['ar' => $v->publisher->name, 'en' => $v->publisher->name_en ?: $v->publisher->name] : null];
    }

    private function validDoc(Request $request, ?PolicyDocument $doc = null): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:40', Rule::unique('policy_documents', 'code')->where('company_id', Tenant::id())->ignore($doc?->id)],
            'title_ar' => ['required', 'string', 'max:200'], 'title_en' => ['nullable', 'string', 'max:200'], 'category' => ['required', Rule::in(array_keys(PolicyLibrary::CATEGORIES))],
        ], [], ['code' => L::t('الرمز', 'Code'), 'title_ar' => L::t('العنوان بالعربية', 'Arabic title'), 'title_en' => L::t('العنوان بالإنكليزية', 'English title')]);
    }

    private function validVersion(Request $request): array
    {
        $v = $request->validate([
            'issue' => ['nullable', 'string', 'max:20'], 'effective_from' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:1000'],
            'file' => ['nullable', 'file', 'max:20480'], 'text' => ['nullable', 'string', 'max:500000'],
        ], [], ['issue' => L::t('رقم الإصدار', 'Issue'), 'effective_from' => L::t('تاريخ النفاذ', 'Effective date'), 'file' => L::t('الملف', 'File'), 'text' => L::t('النص', 'Text')]);
        if ($f = $request->file('file')) {
            if (! in_array(strtolower($f->getClientOriginalExtension()), TextExtractor::EXTENSIONS, true)) throw ApiException::field('file', L::t('الصيغ المسموحة: PDF وDOCX وTXT.', 'Allowed formats: PDF, DOCX and TXT.'), 'policy_ext');
        }
        return array_intersect_key($v, array_flip(['issue', 'effective_from', 'note']));
    }

    private function text(Request $request): ?string { return $request->has('text') && $request->input('text') !== null ? (string) $request->input('text') : null; }

    private function staffCount(): int { return User::where('company_id', Tenant::id())->where('active', true)->whereNotNull('employee_id')->count(); }

    private function categories(): array { return collect(PolicyLibrary::CATEGORIES)->map(fn ($c, $k) => ['key' => $k, 'ar' => $c[0], 'en' => $c[1]])->values()->all(); }

    private function rules(): array { return collect(PolicyLibrary::RULES)->keys()->map(fn ($k) => PolicyLibrary::rule($k))->all(); }

    private function can(User $u): array
    {
        return ['create' => Perm::can($u, 'policies', 'create'), 'edit' => Perm::can($u, 'policies', 'edit'), 'archive' => Perm::can($u, 'policies', 'archive')];
    }
}
