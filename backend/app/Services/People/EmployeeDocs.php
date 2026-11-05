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
        if (! in_array($d['type'], [...array_column($cfg['types'], 'key'), ...array_keys(self::SYSTEM_TYPES)], true)) throw ApiException::field('type', L::t('نوع المستند غير معروف.', 'Unknown document type.'), 'doc_type');
        $ext = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, $cfg['extensions'], true)) {
            throw ApiException::field('file', L::t('صيغة الملف غير مسموحة. المسموح: ', 'File type not allowed. Allowed: ').strtoupper(implode('، ', $cfg['extensions'])), 'doc_ext');
        }
        if ($file->getSize() > $cfg['max_mb'] * 1024 * 1024) throw ApiException::field('file', L::t("حجم الملف أكبر من {$cfg['max_mb']} ميغابايت.", "The file is larger than {$cfg['max_mb']} MB."), 'doc_size');
        if (! empty($d['sensitive']) && ! self::canSensitive($by)) throw ApiException::field('sensitive', L::t('لا تملك صلاحية المستندات الحساسة.', 'You cannot manage sensitive documents.'), 'doc_sensitive');

        $path = $file->store('companies/'.Tenant::id().'/employee-docs/'.now()->format('Y/m'), 'local');
        $f = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 255), 'size' => $file->getSize(), 'mime' => $file->getMimeType(), 'uploaded_by' => $by->id]);
        $doc = $this->make($e, $f->id, $d, $by, 'upload');
        [$ar, $en] = self::typeName($doc->type);
        $e->log("رفع مستند: {$ar}".($doc->sensitive ? ' (حساس)' : '')." — {$by->displayName('ar')}", "Document uploaded: {$en}".($doc->sensitive ? ' (sensitive)' : '')." — {$by->displayName('en')}");
        return $doc;
    }

    /** أنواع ثابتة يضيفها النظام نفسه (لا تُحذف من الإعدادات) */
    public const SYSTEM_TYPES = ['onboarding' => ['وثيقة مباشرة', 'Onboarding document'], 'linked' => ['ملف من النظام', 'File from the system']];

    private function make(Employee $e, int $fileId, array $d, User $by, string $source): EmployeeDocument
    {
        $sign = (bool) ($d['needs_signature'] ?? false);
        $doc = EmployeeDocument::create([
            'employee_id' => $e->id, 'type' => $d['type'], 'title' => $d['title'] ?? null, 'file_id' => $fileId, 'expires_at' => $d['expires_at'] ?? null,
            'sensitive' => (bool) ($d['sensitive'] ?? false), 'note' => $d['note'] ?? null, 'uploaded_by' => $by->id, 'source' => $source,
            'onboarding_item' => $d['onboarding_item'] ?? null, 'needs_signature' => $sign, 'sign_due' => $sign ? ($d['sign_due'] ?? null) : null,
            'status' => isset($d['expires_at']) && $d['expires_at'] < now()->toDateString() ? 'expired' : 'active',
        ]);
        if ($sign) {
            [$ar, $en] = self::typeName($doc->type);
            $t = $doc->title ?: $ar; $te = $doc->title ?: $en;
            $due = $doc->sign_due?->toDateString();
            Notify::user($e->user?->id, "مستند بانتظار توقيعك: «{$t}»".($due ? " — قبل {$due}" : ''), "A document awaits your signature: {$te}".($due ? " — by {$due}" : ''), 'docs');
        }
        return $doc;
    }

    /**
     * ملفات موجودة في النظام تخص الموظف ويمكن ربطها بملفه (49): السيرة الذاتية وصورته من طلب التوظيف، مستندات الإجازات، توقيعات المباشرة.
     * @return list<array{id: int, name: string, kind: string, ar: string, en: string}>
     */
    public static function linkable(Employee $e): array
    {
        $out = [];
        $add = function (?int $id, string $kind, string $ar, string $en) use (&$out) { if ($id && ! isset($out[$id])) $out[$id] = ['id' => $id, 'kind' => $kind, 'ar' => $ar, 'en' => $en]; };
        if ($c = $e->candidate_id ? \App\Models\Candidate::find($e->candidate_id) : null) { $add($c->cv_file_id, 'cv', 'السيرة الذاتية (طلب التوظيف)', 'CV (application)'); $add($c->photo_file_id, 'photo', 'الصورة الشخصية (طلب التوظيف)', 'Photo (application)'); }
        foreach (\App\Models\LeaveRequest::where('employee_id', $e->id)->whereNotNull('doc_file_id')->latest('id')->limit(30)->get() as $l)
            $add($l->doc_file_id, 'leave', "مستند إجازة {$l->from?->toDateString()}", "Leave document {$l->from?->toDateString()}");
        if ($ob = $e->onboarding) {
            $add($ob->f22_signature_file_id, 'sign', 'توقيع تعهد F-22', 'F-22 signature'); $add($ob->f20_signature_file_id, 'sign', 'توقيع تعهد F-20', 'F-20 signature');
            $add($ob->conduct_signature_file_id, 'sign', 'توقيع ميثاق السلوك', 'Conduct charter signature');
        }
        $linked = EmployeeDocument::where('employee_id', $e->id)->pluck('file_id')->all();
        $names = StoredFile::whereIn('id', array_keys($out))->pluck('name', 'id');
        return array_values(array_map(fn ($x) => $x + ['name' => $names[$x['id']] ?? '', 'linked' => in_array($x['id'], $linked, true)], $out));
    }

    public function link(Employee $e, int $fileId, array $d, User $by): EmployeeDocument
    {
        if (! collect(self::linkable($e))->firstWhere('id', $fileId)) throw ApiException::field('file_id', L::t('هذا الملف لا يخص الموظف.', 'This file does not belong to the employee.'), 'not_linkable');
        if (EmployeeDocument::where('employee_id', $e->id)->where('file_id', $fileId)->exists()) throw ApiException::field('file_id', L::t('الملف مربوط مسبقاً.', 'The file is already linked.'), 'already_linked');
        $doc = $this->make($e, $fileId, ['type' => $d['type'] ?? 'linked'] + $d, $by, 'linked');
        $e->log("ربط ملف من النظام بملف الموظف: ".($doc->title ?: self::typeName($doc->type)[0])." — {$by->displayName('ar')}", 'System file linked to the employee file — '.$by->displayName('en'));
        return $doc;
    }

    /** توقيع الموظف على مستند يطلب توقيعه (74) */
    public function sign(EmployeeDocument $doc, User $u, ?string $signature): EmployeeDocument
    {
        if ($doc->employee_id !== $u->employee_id) throw new ApiException(L::t('يوقّع صاحب المستند فقط.', 'Only the document owner can sign.'), 'not_owner', 403);
        if (! $doc->needs_signature || $doc->signed_at) throw new ApiException(L::t('لا يحتاج هذا المستند توقيعاً.', 'This document does not need a signature.'), 'not_signable', 409);
        if ($doc->status === 'archived') throw new ApiException(L::t('المستند مؤرشف.', 'The document is archived.'), 'archived', 409);
        $f = \App\Support\Signature::store($signature);
        $doc->update(['signed_at' => now(), 'signature_file_id' => $f->id]);
        [$ar, $en] = self::typeName($doc->type);
        $doc->employee->log('توقيع مستند: '.($doc->title ?: $ar), 'Document signed: '.($doc->title ?: $en), 'ok');
        Notify::hr("وقّع {$doc->employee->name} المستند «".($doc->title ?: $ar).'»', "{$doc->employee->name_en} signed «".($doc->title ?: $en).'»', "emp:{$doc->employee_id}?tab=docs");
        return $doc;
    }

    /** مستندات بانتظار توقيع الموظف — لصفحة «وثائقي للتوقيع» */
    public static function toSign(Employee $e): array
    {
        return EmployeeDocument::with(['file', 'uploader'])->where('employee_id', $e->id)->where('needs_signature', true)->whereNull('signed_at')->where('status', '!=', 'archived')
            ->orderByRaw('sign_due is null')->orderBy('sign_due')->get()->map(fn ($d) => self::row($d))->all();
    }

    public static function biometricsOf(Employee $e): array
    {
        $sig = $e->signature_file_id ? StoredFile::find($e->signature_file_id) : null;
        return ['signature' => \App\Support\Signature::dataUrl($sig), 'fingerprint_id' => $e->fingerprint_file_id, 'at' => $e->biometrics_at?->toIso8601String()];
    }

    /** توقيع الموظف وبصمته عند المباشرة (49): التوقيع من لوحة التوقيع، والبصمة صورة من الماسح */
    public function biometrics(Employee $e, ?string $signature, ?UploadedFile $finger, User $by): Employee
    {
        $upd = [];
        if ($signature) $upd['signature_file_id'] = \App\Support\Signature::store($signature)->id;
        if ($finger) {
            $ext = mb_strtolower($finger->getClientOriginalExtension());
            if (! in_array($ext, ['png', 'jpg', 'jpeg', 'bmp', 'webp'], true)) throw ApiException::field('fingerprint', L::t('البصمة صورة PNG أو JPG.', 'The fingerprint must be a PNG or JPG image.'), 'bad_image');
            $path = $finger->store('companies/'.Tenant::id().'/biometrics', 'local');
            $upd['fingerprint_file_id'] = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => 'fingerprint-'.$e->code.'.'.$ext, 'size' => $finger->getSize(), 'mime' => $finger->getMimeType(), 'uploaded_by' => $by->id])->id;
        }
        if (! $upd) throw ApiException::field('signature', L::t('أضف التوقيع أو البصمة.', 'Add the signature or the fingerprint.'), 'required');
        $e->update($upd + ['biometrics_at' => now()]);
        $e->log('تسجيل '.(isset($upd['signature_file_id']) ? 'توقيع' : '').(count($upd) === 2 ? ' و' : '').(isset($upd['fingerprint_file_id']) ? 'بصمة' : '')." الموظف — {$by->displayName('ar')}", 'Employee signature/fingerprint recorded — '.$by->displayName('en'), 'ok');
        Audit::record('employee.biometrics', $e, null, ['signature' => isset($upd['signature_file_id']), 'fingerprint' => isset($upd['fingerprint_file_id'])]);
        return $e;
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
        // 74: مستند بانتظار توقيع الموظف يقترب موعده (أو تجاوزه) — تنبيه واحد للموظف وللموارد البشرية
        $sign = EmployeeDocument::with('employee.user')->where('needs_signature', true)->whereNull('signed_at')->whereNull('sign_alerted_at')->where('status', '!=', 'archived')
            ->whereNotNull('sign_due')->where('sign_due', '<=', $until)->get();
        foreach ($sign as $d) {
            [$ar, $en] = self::typeName($d->type);
            $t = $d->title ?: $ar; $te = $d->title ?: $en; $date = $d->sign_due->toDateString(); $e = $d->employee;
            $late = $date < $today;
            Notify::user($e->user?->id, $late ? "تجاوز موعد توقيع «{$t}» ({$date}) — وقّعه الآن" : "يقترب موعد توقيع «{$t}» ({$date})", $late ? "Signing «{$te}» was due {$date} — please sign now" : "«{$te}» must be signed by {$date}", 'docs');
            Notify::hr(($late ? 'تأخر توقيع' : 'لم يوقّع بعد')." {$e->name} على «{$t}» — الموعد {$date}", "{$e->name_en} has not signed «{$te}» — due {$date}", "emp:{$e->id}?tab=docs");
            $d->update(['sign_alerted_at' => now()]);
        }
        if ($expired || $soon->count() || $sign->count()) Audit::record('docs.sweep', null, null, ['expired' => $expired, 'alerted' => $soon->count(), 'sign_alerted' => $sign->count()]);
        return ['expired' => $expired, 'alerted' => $soon->count(), 'sign_alerted' => $sign->count()];
    }

    /** @return array{0: string, 1: string} */
    public static function typeName(string $key): array
    {
        $t = collect(Settings::docs()['types'])->firstWhere('key', $key);
        return $t ? [$t['ar'], $t['en']] : (self::SYSTEM_TYPES[$key] ?? [$key, $key]);
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
            'sensitive' => $d->sensitive, 'status' => $state, 'source' => $d->source, 'onboarding_item' => $d->onboarding_item,
            'needs_signature' => $d->needs_signature, 'sign_due' => $d->sign_due?->toDateString(), 'signed_at' => $d->signed_at?->toIso8601String(),
            'sign_overdue' => $d->needs_signature && ! $d->signed_at && $d->sign_due && $d->sign_due->toDateString() < now()->toDateString(), 'signature_file_id' => $d->signature_file_id, 'uploaded_at' => $d->created_at?->toIso8601String(),
            'uploaded_by' => $d->uploader ? ['ar' => $d->uploader->displayName('ar'), 'en' => $d->uploader->displayName('en')] : null];
    }
}
