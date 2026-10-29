<?php

namespace App\Services\Policies;

use App\Exceptions\ApiException;
use App\Models\PolicyDocument;
use App\Models\PolicyParagraph;
use App\Models\PolicyRead;
use App\Models\PolicyVersion;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * مكتبة السياسات (D-26، SRS §10.12): HR يرفع سياسة أو إجراء (PDF/DOCX/TXT) فيُستخرج النص فقرات للبحث؛
 * الإصدار المنشور واحد بتاريخه والسابق في الأرشيف؛ يُشعر الموظفون عند النشر والكل يقرأ (ويُسجَّل الاطلاع).
 * القواعد لا تُستخرج آلياً: HR يربط الفقرة بالإعداد الذي يطبّقها (rule_key)، وغير المربوط نص مرجعي فقط.
 */
class PolicyLibrary
{
    public const CATEGORIES = [
        'policy' => ['سياسة', 'Policy'], 'procedure' => ['إجراء', 'Procedure'], 'regulation' => ['نظام / لائحة', 'Regulation'],
        'charter' => ['ميثاق', 'Charter'], 'other' => ['أخرى', 'Other'],
    ];

    /** الإعدادات التي يمكن أن تطبّق فقرة — المفتاح ← الاسم وصفحة الضبط */
    public const RULES = [
        'shift' => ['الوردية وساعات الدوام', 'Shift & working hours', '/hr/settings/policies'],
        'work_days' => ['أيام العمل والعطلة الأسبوعية', 'Work days & weekend', '/hr/settings/policies'],
        'holidays' => ['العطل الرسمية', 'Public holidays', '/hr/settings/policies'],
        'late_deduction' => ['جدول حسم التأخير', 'Lateness deduction table', '/hr/settings/policies'],
        'overtime' => ['العمل الإضافي ونسبه', 'Overtime & rates', '/hr/settings/policies'],
        'terminal' => ['جهاز البصمة والتسجيل من الجوال', 'Attendance terminal & phone check-in', '/hr/settings/policies'],
        'annual_leave' => ['استحقاق الإجازة السنوية', 'Annual leave entitlement', '/hr/settings/policies'],
        'leave_rules' => ['قواعد طلب الإجازات', 'Leave request rules', '/hr/settings/policies'],
        'leave_types' => ['أنواع الإجازات', 'Leave types', '/hr/settings/policies'],
        'payroll' => ['قواعد الكشف الشهري', 'Monthly sheet rules', '/hr/settings/policies'],
        'discipline' => ['قواعد الجزاءات', 'Penalty rules', '/hr/settings/policies'],
        'violations' => ['دليل المخالفات', 'Violations catalogue', '/hr/settings/violations'],
        'exit' => ['قواعد الخروج والإخطار', 'Exit & notice rules', '/hr/settings/policies'],
        'offboarding' => ['أسباب الخروج وبراءة الذمة', 'Exit reasons & clearance', '/hr/settings/exit'],
        'chains' => ['سلاسل الاعتماد', 'Approval chains', '/hr/settings/chains'],
        'recruitment' => ['قواعد التوظيف والمطابقة', 'Recruitment & matching rules', '/hr/settings/recruitment'],
        'performance' => ['التقييم والمكافآت', 'Performance & rewards', '/hr/settings/performance'],
        'documents' => ['الملفات الإضافية للموظفين', 'Employee documents', '/hr/settings/documents'],
        'salary_vault' => ['حماية الرواتب', 'Salary protection', '/hr/settings/security'],
    ];

    public static function manages(User $u): bool { return Perm::can($u, 'policies', 'edit'); }

    public function create(array $d, ?UploadedFile $file, ?string $text, User $by): PolicyDocument
    {
        return DB::transaction(function () use ($d, $file, $text, $by) {
            $doc = PolicyDocument::create(['code' => $d['code'] ?? null, 'title_ar' => $d['title_ar'], 'title_en' => $d['title_en'] ?: $d['title_ar'],
                'category' => $d['category'], 'status' => 'draft', 'created_by' => $by->id]);
            $this->newVersion($doc, $d, $file, $text, $by);
            return $doc;
        });
    }

    public function updateDocument(PolicyDocument $doc, array $d): PolicyDocument
    {
        $doc->update(['code' => $d['code'] ?? null, 'title_ar' => $d['title_ar'], 'title_en' => $d['title_en'] ?: $d['title_ar'], 'category' => $d['category']]);
        return $doc;
    }

    /** إصدار جديد (مسودة) — مسودة واحدة لكل وثيقة */
    public function newVersion(PolicyDocument $doc, array $d, ?UploadedFile $file, ?string $text, User $by): PolicyVersion
    {
        if ($doc->status === 'archived') throw new ApiException(L::t('الوثيقة مؤرشفة — استعدها أولاً.', 'The document is archived — restore it first.'), 'policy_archived', 409);
        if (PolicyVersion::where('document_id', $doc->id)->where('status', 'draft')->exists())
            throw new ApiException(L::t('يوجد إصدار مسودة لهذه الوثيقة — انشره أو احذفه أولاً.', 'This document already has a draft version — publish or delete it first.'), 'draft_exists', 409);
        $v = PolicyVersion::create(['document_id' => $doc->id, 'number' => (int) PolicyVersion::where('document_id', $doc->id)->max('number') + 1,
            'issue' => $d['issue'] ?? null, 'effective_from' => $d['effective_from'] ?? null, 'note' => $d['note'] ?? null, 'status' => 'draft', 'created_by' => $by->id]);
        $this->setContent($v, $file, $text, $by);
        return $v;
    }

    /** تعديل مسودة: البيانات، واستبدال الملف أو النص (يعيد بناء الفقرات) */
    public function updateVersion(PolicyVersion $v, array $d, ?UploadedFile $file, ?string $text, User $by): PolicyVersion
    {
        if ($v->status !== 'draft') throw new ApiException(L::t('لا يُعدَّل إصدار منشور — أنشئ إصداراً جديداً.', 'A published version cannot be edited — create a new version.'), 'version_locked', 409);
        $v->update(['issue' => $d['issue'] ?? null, 'effective_from' => $d['effective_from'] ?? null, 'note' => $d['note'] ?? null]);
        if ($file || $text !== null) $this->setContent($v, $file, $text, $by);
        return $v;
    }

    private function setContent(PolicyVersion $v, ?UploadedFile $file, ?string $text, User $by): void
    {
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, TextExtractor::EXTENSIONS, true)) throw ApiException::field('file', L::t('الصيغ المسموحة: PDF وDOCX وTXT.', 'Allowed formats: PDF, DOCX and TXT.'), 'policy_ext');
            $path = $file->store('companies/'.Tenant::id().'/policies/'.now()->format('Y/m'), 'local');
            $f = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 255), 'size' => $file->getSize(), 'mime' => $file->getMimeType(), 'uploaded_by' => $by->id]);
            $v->file_id = $f->id;
            if ($text === null) $text = app(TextExtractor::class)->extract($file->getRealPath(), $ext);
        }
        $v->text = $text !== null ? trim($text) : $v->text;
        $v->text_missing = trim((string) $v->text) === '';
        $v->save();
        $this->rebuild($v);
    }

    /** يعيد بناء الفقرات من النص، ويُبقي ربط الإعدادات للفقرات التي لم يتغير نصها */
    private function rebuild(PolicyVersion $v): void
    {
        $old = PolicyParagraph::where('version_id', $v->id)->whereNotNull('rule_key')->get()->keyBy('norm');
        PolicyParagraph::where('version_id', $v->id)->delete();
        $rows = [];
        foreach (TextExtractor::paragraphs((string) $v->text) as $i => $p) {
            $norm = TextExtractor::norm($p); $keep = $old->get($norm);
            $rows[] = ['company_id' => $v->company_id, 'version_id' => $v->id, 'n' => $i + 1, 'text' => $p, 'norm' => $norm, 'rule_key' => $keep?->rule_key, 'rule_note' => $keep?->rule_note];
        }
        foreach (array_chunk($rows, 500) as $chunk) PolicyParagraph::insert($chunk);
    }

    /** نسخ الربط من الإصدار السابق إلى إصدار جديد (للفقرات بنفس النص) */
    private function carryLinks(PolicyVersion $from, PolicyVersion $to): void
    {
        $links = PolicyParagraph::where('version_id', $from->id)->whereNotNull('rule_key')->get(['norm', 'rule_key', 'rule_note']);
        foreach ($links as $l) PolicyParagraph::where('version_id', $to->id)->where('norm', $l->norm)->whereNull('rule_key')->update(['rule_key' => $l->rule_key, 'rule_note' => $l->rule_note]);
    }

    public function link(PolicyParagraph $p, ?string $key, ?string $note): PolicyParagraph
    {
        $before = ['rule_key' => $p->rule_key, 'rule_note' => $p->rule_note];
        $p->update(['rule_key' => $key, 'rule_note' => $key ? $note : null]);
        Audit::record('policy.linked', $p->version, $before + ['n' => $p->n], ['rule_key' => $key, 'rule_note' => $p->rule_note, 'n' => $p->n]);
        return $p;
    }

    /** نشر المسودة: السابق ← الأرشيف، والموظفون يُشعرون */
    public function publish(PolicyVersion $v, User $by, bool $notify = true): PolicyVersion
    {
        if ($v->status !== 'draft') throw new ApiException(L::t('هذا الإصدار منشور مسبقاً.', 'This version is already published.'), 'version_locked', 409);
        if ($v->text_missing && ! $v->file_id) throw new ApiException(L::t('أضف ملف الوثيقة أو نصها قبل النشر.', 'Add the document file or its text before publishing.'), 'policy_empty', 422);
        $doc = $v->document;
        if ($doc->status === 'archived') throw new ApiException(L::t('الوثيقة مؤرشفة — استعدها أولاً.', 'The document is archived — restore it first.'), 'policy_archived', 409);
        DB::transaction(function () use ($v, $doc, $by) {
            $prev = $doc->current_version_id ? PolicyVersion::find($doc->current_version_id) : null;
            if ($prev && $prev->status === 'published') { $prev->update(['status' => 'archived', 'archived_at' => now()]); $this->carryLinks($prev, $v); }
            $v->update(['status' => 'published', 'published_at' => now(), 'published_by' => $by->id, 'effective_from' => $v->effective_from ?? now()->toDateString()]);
            $doc->update(['status' => 'published', 'current_version_id' => $v->id]);
        });
        Audit::record('policy.published', $v, null, ['document' => $doc->code ?: $doc->title_ar, 'number' => $v->number, 'issue' => $v->issue]);
        if (! $notify) return $v->refresh();
        $users = User::where('company_id', Tenant::id())->where('active', true)->whereNotNull('employee_id')->pluck('id');
        $verb = $v->number > 1 ? ['إصدار جديد من', 'New version of'] : ['سياسة جديدة:', 'New policy:'];
        foreach ($users as $id) Notify::user($id, "{$verb[0]} «{$doc->title_ar}» — يرجى الاطلاع", "{$verb[1]} «{$doc->title_en}» — please read it", "policy:{$doc->id}");
        return $v->refresh();
    }

    /** حذف مسودة لم تُنشر (وحذف الوثيقة إن لم يبق لها إصدار) */
    public function deleteDraft(PolicyVersion $v): ?PolicyDocument
    {
        if ($v->status !== 'draft') throw new ApiException(L::t('لا يُحذف إصدار منشور أو مؤرشف.', 'A published or archived version cannot be deleted.'), 'version_locked', 409);
        $doc = $v->document;
        $v->delete();
        if (! PolicyVersion::where('document_id', $doc->id)->exists()) { $doc->delete(); return null; }
        return $doc;
    }

    public function archive(PolicyDocument $doc, bool $restore): PolicyDocument
    {
        if ($restore) $doc->update(['status' => $doc->current_version_id ? 'published' : 'draft', 'archived_at' => null]);
        else $doc->update(['status' => 'archived', 'archived_at' => now()]);
        return $doc;
    }

    /** يُسجّل اطلاع المستخدم على الإصدار المنشور (مرة واحدة) */
    public function markRead(PolicyVersion $v, User $u): void
    {
        if ($v->status !== 'published') return;
        PolicyRead::firstOrCreate(['version_id' => $v->id, 'user_id' => $u->id], ['read_at' => now()]);
    }

    /** البحث بكلمة يرجع الفقرة: كل الكلمات مطلوبة، في الإصدارات المنشورة (والمؤرشفة لمن يدير المكتبة إن طُلب) */
    public function search(User $u, string $q, bool $archived = false): array
    {
        $words = array_values(array_filter(explode(' ', TextExtractor::norm(trim($q))), fn ($w) => mb_strlen($w) >= 2));
        if (! $words) return [];
        $manage = self::manages($u);
        $rows = PolicyParagraph::query()->join('policy_versions as v', 'v.id', '=', 'policy_paragraphs.version_id')->join('policy_documents as d', 'd.id', '=', 'v.document_id')
            ->whereIn('v.status', $archived && $manage ? ['published', 'archived'] : ['published'])
            ->when(! $manage, fn ($w) => $w->where('d.status', 'published'))
            ->where(function ($w) use ($words) { foreach ($words as $x) $w->where('policy_paragraphs.norm', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $x).'%'); })
            ->orderByRaw("case v.status when 'published' then 0 else 1 end")->orderBy('d.id')->orderBy('policy_paragraphs.n')->limit(60)
            ->get(['policy_paragraphs.id', 'policy_paragraphs.n', 'policy_paragraphs.text', 'policy_paragraphs.rule_key', 'v.id as version_id', 'v.number', 'v.status as version_status',
                'd.id as document_id', 'd.code', 'd.title_ar', 'd.title_en']);
        return $rows->map(fn ($r) => ['id' => $r->id, 'n' => $r->n, 'text' => $r->text, 'rule' => self::rule($r->rule_key), 'version_id' => $r->version_id, 'number' => $r->number,
            'version_status' => $r->version_status, 'document' => ['id' => $r->document_id, 'code' => $r->code, 'ar' => $r->title_ar, 'en' => $r->title_en]])->all();
    }

    public static function rule(?string $key): ?array
    {
        if (! $key || ! isset(self::RULES[$key])) return null;
        [$ar, $en, $path] = self::RULES[$key];
        return ['key' => $key, 'ar' => $ar, 'en' => $en, 'path' => $path];
    }

    /**
     * وثائق الشركة الحالية (reference/company ← resources/company-policies) — تُبذر منشورة، مع ربط فقرات القواعد المضبوطة في الإعدادات.
     * links: [نص يبحث عنه في الفقرة ← rule_key]
     */
    public const COMPANY_DOCS = [
        ['file' => 'staff-regulations.txt', 'name' => '01-نظام العاملين.txt', 'code' => 'REG-01', 'title_ar' => 'نظام العاملين', 'title_en' => 'Staff regulations', 'category' => 'regulation', 'issue' => '01',
            'links' => []],
        ['file' => 'HR.01-01.docx', 'name' => 'HR.01-01- سياسات وإجراءات الدوام والإجازات.docx', 'code' => 'HR.01-01', 'title_ar' => 'سياسات وإجراءات الدوام والإجازات', 'title_en' => 'Attendance & leave policy and procedures', 'category' => 'policy', 'issue' => '01',
            'links' => ['تبدأ ساعات العمل النظامية' => 'shift', 'في حال تأخر الموظف عن العمل' => 'late_deduction', 'يعفى الموظف من التأخير' => 'late_deduction',
                'تجاوز عمله مدة 48 ساعة' => 'overtime', 'أجر الساعة العادية مضافا إليه 25' => 'overtime', 'وفق النموذج HR-01-F-04' => 'overtime',
                'الإجازات الإدارية: يحق للموظف' => 'annual_leave', 'قبل 48 ساعة من تاريخ طلب الإجازة' => 'leave_rules', 'إجازات ساعية بعد فترة التجربة' => 'leave_rules',
                'الحق بالحصول على إجازة مرضية' => 'leave_types']],
        ['file' => 'HR.01-03.docx', 'name' => 'HR.01-03- الاستقالة.docx', 'code' => 'HR.01-03', 'title_ar' => 'إجراء الاستقالة وإنهاء الخدمة', 'title_en' => 'Resignation & end of service procedure', 'category' => 'procedure', 'issue' => '01',
            'links' => ['تعريف الاستقالة' => 'exit']],
        ['file' => 'violations.docx', 'name' => '02-جدول المخالفات.docx', 'code' => 'HR-VIOL', 'title_ar' => 'جدول المخالفات والجزاءات', 'title_en' => 'Violations & penalties table', 'category' => 'regulation', 'issue' => '01',
            'links' => ['التأخر عن مواعيد العمل لمدة تصل إلى 15' => 'violations']],
        ['file' => 'conduct.docx', 'name' => 'ميثاق السلوك المهني.docx', 'code' => 'COC', 'title_ar' => 'ميثاق الأخلاق والسلوك المهني', 'title_en' => 'Code of ethics & professional conduct', 'category' => 'charter', 'issue' => '01',
            'links' => []],
    ];

    /** يبذر وثائق الشركة الحالية (يتخطى الموجود بنفس الرمز) — يُرجع عدد المضاف */
    public function seedCompanyDocs(User $by, bool $notify = false): int
    {
        $n = 0;
        foreach (self::COMPANY_DOCS as $d) {
            if (PolicyDocument::where('code', $d['code'])->exists()) continue;
            $path = resource_path('company-policies/'.$d['file']);
            if (! is_file($path)) continue;
            $file = new UploadedFile($path, $d['name'], null, null, true);
            $doc = $this->create($d + ['effective_from' => \App\Support\Forms::ISSUED_ON], $file, null, $by);
            $v = $doc->versions()->first();
            foreach ($d['links'] as $needle => $key) {
                $p = PolicyParagraph::where('version_id', $v->id)->where('norm', 'like', '%'.TextExtractor::norm($needle).'%')->orderBy('n')->first();
                $p?->update(['rule_key' => $key]);
            }
            $this->publish($v, $by, $notify);
            $n++;
        }
        return $n;
    }
}
