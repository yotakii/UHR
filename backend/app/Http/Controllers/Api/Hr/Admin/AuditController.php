<?php

namespace App\Http\Controllers\Api\Hr\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\AuditArchive;
use App\Support\Audit;
use App\Support\AuditLabels;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * M16 «سجل التدقيق» (SRS §6 M16، §8): بحث وتصفية بالمستخدم والعملية والتاريخ، قبل/بعد، تصدير Excel.
 * الواجهة تعرض آخر visible_days يوماً؛ الأقدم «أرشيف» لمن يملك صلاحية الأرشفة (تصفح + ملفات شهرية). لا حذف.
 */
class AuditController extends Controller
{
    private const PER = 50;

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        [$q, $f] = $this->filtered($request);
        $total = (clone $q)->count();
        $page = max(1, (int) ($f['page'] ?? 1));
        $rows = $q->with('user:id,name,name_en,email')->orderByDesc('at')->orderByDesc('id')->forPage($page, self::PER)->get();
        $cid = Tenant::id();
        $prefixes = AuditLog::where('company_id', $cid)->selectRaw("distinct split_part(action, '.', 1) as p")->pluck('p')->sort()->values();
        $actors = User::where(fn ($w) => $w->where('company_id', $cid)->orWhereIn('id', AuditLog::where('company_id', $cid)->whereNotNull('user_id')->select('user_id')->distinct()))
            ->orderBy('name')->get(['id', 'name', 'name_en', 'email']);
        return response()->json([
            'data' => $rows->map(fn (AuditLog $r) => $this->row($r))->values(),
            'total' => $total, 'page' => $page, 'per' => self::PER,
            'settings' => Settings::merged('audit'), 'defaults' => Settings::DEFAULTS['audit'],
            'since' => $this->since()->toIso8601String(),
            'groups' => $prefixes->map(fn ($p) => ['key' => $p, 'ar' => AuditLabels::prefix($p)[0], 'en' => AuditLabels::prefix($p)[1]])->values(),
            'users' => $actors->map(fn ($x) => ['id' => $x->id, 'ar' => $x->name, 'en' => $x->name_en ?: $x->name, 'email' => $x->email])->values(),
            'can' => ['export' => Perm::can($u, 'audit', 'export'), 'archive' => Perm::can($u, 'audit', 'archive'), 'settings' => Perm::can($u, 'settings', 'edit')],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        [$q, $f] = $this->filtered($request);
        $rows = $q->with('user:id,name,email')->orderByDesc('at')->orderByDesc('id')->limit(20000)->get();
        $path = tempnam(sys_get_temp_dir(), 'aud').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName('Audit')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
        $w->addRow(Row::fromValues(['الوقت', 'المستخدم', 'البريد', 'العملية', 'الرمز', 'العنصر', 'رقم العنصر', 'قبل', 'بعد', 'العنوان'], (new Style())->setFontBold()));
        foreach ($rows as $r) {
            $s = AuditLabels::subject($r->subject_type);
            $w->addRow(Row::fromValues([$r->at->timezone(config('app.timezone'))->format('Y-m-d H:i:s'), $r->user?->name ?? 'النظام', $r->user?->email ?? '', AuditLabels::action($r->action)[0], $r->action,
                $s[0] ?? '', (string) ($r->subject_id ?? ''), $r->before ? json_encode($r->before, JSON_UNESCAPED_UNICODE) : '', $r->after ? json_encode($r->after, JSON_UNESCAPED_UNICODE) : '', (string) $r->ip]));
        }
        $w->close();
        Audit::record('audit.exported', null, null, ['filters' => array_diff_key($f, ['page' => 1]), 'count' => $rows->count()]);
        return response()->download($path, 'UHR-audit-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function archives(AuditArchive $a): JsonResponse
    {
        return response()->json(['data' => $a->files()]);
    }

    public function archiveFile(AuditArchive $a, string $month): BinaryFileResponse
    {
        $p = $a->path($month);
        abort_unless($p, 404);
        Audit::record('audit.archive_downloaded', null, null, ['month' => $month]);
        return response()->download($p, "UHR-audit-{$month}.jsonl.gz", ['Content-Type' => 'application/gzip']);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $v = $request->validate(['visible_days' => ['required', 'integer', 'between:7,365']], [], ['visible_days' => L::t('أيام العرض', 'Days shown')]);
        $before = Settings::merged('audit');
        Settings::put('audit', ['visible_days' => (int) $v['visible_days']]);
        Audit::record('settings.audit', null, $before, Settings::merged('audit'));
        return response()->json(['data' => Settings::merged('audit')]);
    }

    private function since(): \Illuminate\Support\Carbon { return now()->subDays((int) Settings::merged('audit')['visible_days'])->startOfDay(); }

    /** @return array{0: Builder, 1: array} */
    private function filtered(Request $request): array
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'user' => ['nullable', 'integer'], 'group' => ['nullable', 'string', 'max:40'], 'action' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'archive' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1'],
            'subject' => ['nullable', 'string', 'max:60'], 'subject_id' => ['nullable', 'integer'],
        ]);
        $q = AuditLog::query()->where('company_id', Tenant::id());
        $archive = ! empty($f['archive']) && Perm::can($request->user(), 'audit', 'archive');
        if (! $archive) $q->where('at', '>=', $this->since());
        if (! empty($f['from'])) $q->where('at', '>=', \Illuminate\Support\Carbon::parse($f['from'])->startOfDay());
        if (! empty($f['to'])) $q->where('at', '<=', \Illuminate\Support\Carbon::parse($f['to'])->endOfDay());
        if (! empty($f['user'])) $q->where('user_id', $f['user']);
        if (! empty($f['group'])) $q->where(fn ($w) => $w->where('action', $f['group'])->orWhere('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $f['group']).'.%'));
        if (! empty($f['action'])) $q->where('action', $f['action']);
        if (! empty($f['subject'])) {
            $q->where('subject_type', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], \Illuminate\Support\Str::studly($f['subject'])));
            if (! empty($f['subject_id'])) $q->where('subject_id', $f['subject_id']);
        }
        if ($s = trim($f['q'] ?? '')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
            $q->where(fn ($w) => $w->where('action', 'ilike', $like)->orWhere('ip', 'ilike', $like)->orWhereRaw('cast("before" as text) ilike ?', [$like])->orWhereRaw('cast("after" as text) ilike ?', [$like])
                ->orWhereIn('user_id', User::where('name', 'ilike', $like)->orWhere('name_en', 'ilike', $like)->orWhere('email', 'ilike', $like)->select('id')));
        }
        return [$q, $f];
    }

    private function row(AuditLog $r): array
    {
        $a = AuditLabels::action($r->action); $s = AuditLabels::subject($r->subject_type);
        return ['id' => $r->id, 'at' => $r->at->toIso8601String(), 'action' => $r->action, 'label' => ['ar' => $a[0], 'en' => $a[1]],
            'subject' => $s ? ['ar' => $s[0], 'en' => $s[1], 'id' => $r->subject_id] : null,
            'user' => $r->user ? ['id' => $r->user->id, 'ar' => $r->user->name, 'en' => $r->user->name_en ?: $r->user->name, 'email' => $r->user->email] : null,
            'before' => $r->before, 'after' => $r->after, 'ip' => $r->ip, 'agent' => $r->user_agent];
    }
}
