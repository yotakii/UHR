<?php

namespace App\Services\People;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * الملفات الإضافية للموظف (SRS §10.11، D-25): رفع بنوع وتاريخ انتهاء وحساسية، الصيغ والحجم من الإعدادات،
 * تنبيه قبل الانتهاء، أرشفة بلا حذف، وسجل من رفع/شاهد/نزّل (من audit_logs).
 */
class EmployeeDocs
{
    /** من يرى المستند «الحساس»: أدوار docs.sensitive_roles (والموظف صاحبه) */
    public static function canSensitive(User $u): bool
    {
        $u->loadMissing('roles');
        return $u->roles->pluck('key')->intersect(Settings::docs()['sensitive_roles'])->isNotEmpty();
    }

    /** مستندات الموظف التي يحق للمستخدم رؤيتها */
    public static function visible(Employee $e, User $u): Collection
    {
        $all = $e->documents()->with(['file', 'uploader'])->orderByRaw("case status when 'archived' then 1 else 0 end")->orderByDesc('id')->get();
        $own = $u->employee_id === $e->id;
        return $own || self::canSensitive($u) ? $all : $all->where('sensitive', false)->values();
    }

    public function upload(Employee $e, UploadedFile $file, array $d, User $by): EmployeeDocument
    {
        $cfg = Settings::docs();
        if (! in_array($d['type'], array_column($cfg['types'], 'key'), true)) throw ApiException::field('type', L::t('نوع المستند غير معروف.', 'Unknown document type.'), 'doc_type');
        $ext = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $cfg['extensions'], true)) {
            throw ApiException::field('file', L::t('صيغة الملف غير مسموحة. المسموح: ', 'File type not allowed. Allowed: ').strtoupper(implode('، ', $cfg['extensions'])), 'doc_ext');
        }
        if ($file->getSize() > $cfg['max_mb'] * 1024 * 1024) throw ApiException::field('file', L::t("حجم الملف أكبر من {$cfg['max_mb']} ميغابايت.", "The file is larger than {$cfg['max_mb']} MB."), 'doc_size');
        if (! empty($d['sensitive']) && ! self::canSensitive($by)) throw ApiException::field('sensitive', L::t('لا تملك صلاحية المستندات الحساسة.', 'You cannot manage sensitive documents.'), 'doc_sensitive');

        $path = $file->store('companies/'.Tenant::id().'/employee-docs/'.now()->format('Y/m'), 'local');
        $f = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 255), 'size' => $file->getSize(), 'mime' => $file->getMimeType(), 'uploaded_by' => $by->id]);
        $doc = EmployeeDocument::create([
            'employee_id' => $e->id, 'type' => $d['type'], 'title' => $d['title'] ?? null, 'file_id' => $f->id, 'expires_at' => $d['expires_at'] ?? null,
            'sensitive' => (bool) ($d['sensitive'] ?? false), 'note' => $d['note'] ?? null, 'uploaded_by' => $by->id,
            'status' => isset($d['expires_at']) && $d['expires_at'] < now()->toDateString() ? 'expired' : 'active',
        ]);
        [$ar, $en] = self::typeName($doc->type);
        $e->log("رفع مستند: {$ar}".($doc->sensitive ? ' (حساس)' : '')." — {$by->displayName('ar')}", "Document uploaded: {$en}".($doc->sensitive ? ' (sensitive)' : '')." — {$by->displayName('en')}");
        return $doc;
    }

    public function archive(EmployeeDocument $doc, User $by, bool $restore = false): EmployeeDocument
    {
        if ($doc->sensitive && ! self::canSensitive($by)) throw new ApiException(L::t('لا تملك صلاحية المستندات الحساسة.', 'You cannot manage sensitive documents.'), 'doc_sensitive', 403);
        $doc->update($restore
            ? ['status' => $doc->expires_at && $doc->expires_at->toDateString() < now()->toDateString() ? 'expired' : 'active', 'archived_at' => null, 'archived_by' => null]
            : ['status' => 'archived', 'archived_at' => now(), 'archived_by' => $by->id]);
        [$ar, $en] = self::typeName($doc->type);
        $doc->employee->log(($restore ? 'استعادة مستند من الأرشيف: ' : 'أرشفة مستند: ').$ar, ($restore ? 'Document restored: ' : 'Document archived: ').$en);
        return $doc;
    }

    /** سجل المستند: الرفع، المشاهدة، التنزيل، الأرشفة — الأحدث أولاً */
    public static function history(EmployeeDocument $doc): array
    {
        return AuditLog::with('user')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', $doc->getMorphClass())->where('subject_id', $doc->id))
                ->orWhere(fn ($w) => $w->where('subject_type', (new StoredFile)->getMorphClass())->where('subject_id', $doc->file_id)->whereIn('action', ['file.download', 'file.view'])))
            ->orderByDesc('at')->orderByDesc('id')->limit(200)->get()
            ->map(fn (AuditLog $a) => ['action' => match (true) {
                $a->action === 'employee_document.created' => 'upload', $a->action === 'file.view' => 'view', $a->action === 'file.download' => 'download',
                ($a->after['status'] ?? null) === 'archived' => 'archive', isset($a->after['status']) && ($a->before['status'] ?? null) === 'archived' => 'restore', default => 'update',
            }, 'by' => $a->user ? ['ar' => $a->user->displayName('ar'), 'en' => $a->user->displayName('en')] : null, 'at' => $a->at->toIso8601String()])->all();
    }

    /**
     * يومياً: المنتهي ← «منتهي»، وما ينتهي خلال alert_days ← تنبيه للموارد البشرية وللموظف (مرة واحدة).
     * @return array{expired: int, alerted: int}
     */
    public function sweep(): array
    {
        $today = now()->toDateString();
        $expired = EmployeeDocument::where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', $today)->update(['status' => 'expired']);
        $until = now()->addDays((int) Settings::docs()['alert_days'])->toDateString();
        $soon = EmployeeDocument::with('employee.user')->where('status', 'active')->whereNull('alerted_at')->whereNotNull('expires_at')->whereBetween('expires_at', [$today, $until])->get();
        foreach ($soon as $d) {
            [$ar, $en] = self::typeName($d->type);
            $date = $d->expires_at->toDateString(); $e = $d->employee;
            Notify::hr("ينتهي مستند «{$ar}» للموظف {$e->name} بتاريخ {$date}", "{$e->name_en}'s {$en} expires on {$date}", "emp:{$e->id}?tab=docs");
            Notify::user($e->user?->id, "ينتهي مستندك «{$ar}» بتاريخ {$date} — سلّم نسخة محدّثة للموارد البشرية", "Your {$en} expires on {$date} — please hand an updated copy to HR");
            $d->update(['alerted_at' => now()]);
        }
        if ($expired || $soon->count()) Audit::record('docs.sweep', null, null, ['expired' => $expired, 'alerted' => $soon->count()]);
        return ['expired' => $expired, 'alerted' => $soon->count()];
    }

    /** @return array{0: string, 1: string} */
    public static function typeName(string $key): array
    {
        $t = collect(Settings::docs()['types'])->firstWhere('key', $key);
        return $t ? [$t['ar'], $t['en']] : [$key, $key];
    }

    public static function row(EmployeeDocument $d): array
    {
        $alert = (int) Settings::docs()['alert_days'];
        $exp = $d->expires_at?->toDateString();
        $state = $d->status === 'active' && $exp && $exp < now()->toDateString() ? 'expired' : $d->status;
        [$ar, $en] = self::typeName($d->type);
        return ['id' => $d->id, 'type' => $d->type, 'type_name' => ['ar' => $ar, 'en' => $en], 'title' => $d->title, 'note' => $d->note,
            'file' => ['id' => $d->file_id, 'name' => $d->file?->name, 'size' => $d->file?->size, 'mime' => $d->file?->mime],
            'expires_at' => $exp, 'expiring' => $state === 'active' && $exp && $exp <= now()->addDays($alert)->toDateString(),
            'sensitive' => $d->sensitive, 'status' => $state, 'uploaded_at' => $d->created_at?->toIso8601String(),
            'uploaded_by' => $d->uploader ? ['ar' => $d->uploader->displayName('ar'), 'en' => $d->uploader->displayName('en')] : null];
    }
}
