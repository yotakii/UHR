<?php

namespace App\Services\Files;

use App\Models\Asset;
use App\Models\Candidate;
use App\Models\EmployeeDocument;
use App\Models\Offer;
use App\Models\Onboarding;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\People\Directory;
use App\Services\People\EmployeeDocs;
use App\Support\Perm;

/**
 * مستودع الملفات (SRS §10.3): لكل ملف صاحب (متقدم/موظف) ووحدة. يصل إليه صاحبه، أو مستخدم من نفس الشركة
 * يملك «عرض» وحدته. لا روابط عامة: التنزيل عبر الخادم فقط ويُسجَّل.
 */
class FileAccess
{
    /** @return array{module: string, owner: ?int, label: string, employee_id?: int, sensitive?: bool}|null */
    public static function owner(StoredFile $f): ?array
    {
        if ($c = Candidate::allCompanies()->where('cv_file_id', $f->id)->orWhere('photo_file_id', $f->id)->first()) {
            return ['module' => 'candidates', 'owner' => $c->user_id, 'label' => ($c->cv_file_id === $f->id ? 'cv:' : 'photo:').$c->ref];
        }
        if ($o = Offer::with('candidate')->where('signature_file_id', $f->id)->first()) return ['module' => 'candidates', 'owner' => $o->candidate?->user_id, 'label' => 'offer-signature:'.$o->candidate?->ref];
        if ($a = Asset::with('employee.user')->where('signature_file_id', $f->id)->first()) return ['module' => 'assets', 'owner' => $a->employee?->user?->id, 'label' => 'asset-signature:'.$a->tag];
        if ($b = Onboarding::with('employee.user')->where('f22_signature_file_id', $f->id)->orWhere('f20_signature_file_id', $f->id)->first()) {
            return ['module' => 'employees', 'owner' => $b->employee?->user?->id, 'label' => 'onboarding-signature:'.$b->employee?->code];
        }
        if ($l = \App\Models\LeaveRequest::with('employee.user')->where('doc_file_id', $f->id)->first()) {
            return ['module' => 'leaves', 'owner' => $l->employee?->user?->id, 'label' => 'leave-doc:'.$l->employee?->code, 'employee_id' => $l->employee_id];
        }
        if ($d = EmployeeDocument::with('employee.user')->where('file_id', $f->id)->first()) {
            return ['module' => 'employees', 'owner' => $d->employee?->user?->id, 'label' => 'employee-doc:'.$d->employee?->code, 'employee_id' => $d->employee_id, 'sensitive' => $d->sensitive];
        }
        if ($n = \App\Models\Announcement::where('image_file_id', $f->id)->orWhere('attachment_file_id', $f->id)->first()) {
            return ['module' => 'announcements', 'owner' => null, 'label' => 'announcement:'.$n->id, 'department_id' => $n->audience === 'department' ? $n->department_id : null];
        }
        return null;
    }

    public static function allowed(User $user, StoredFile $f, ?array $owner): bool
    {
        if (! $owner) return false;
        if ($owner['owner'] && $owner['owner'] === $user->id) return true;
        if (! $user->company_id || $user->company_id !== $f->company_id || ! Perm::can($user, $owner['module'])) return false;
        // مستندات الموظف: ضمن نطاق رؤية المستخدم (خاصته/فريقه/الكل)، والحساس لأدواره فقط
        // إعلان لإدارة: لموظفيها، ولمن يدير الإعلانات
        if (! empty($owner['department_id']) && ! Perm::can($user, 'announcements', 'create') && \App\Models\Employee::whereKey($user->employee_id ?? 0)->value('department_id') !== $owner['department_id']) return false;
        if (isset($owner['employee_id']) && ! Directory::visible($user, $owner['employee_id'], $owner['module'])) return false;
        return empty($owner['sensitive']) || EmployeeDocs::canSensitive($user);
    }
}
