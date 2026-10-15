<?php

namespace App\Services\Files;

use App\Models\Asset;
use App\Models\Candidate;
use App\Models\Offer;
use App\Models\Onboarding;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Perm;

/**
 * مستودع الملفات (SRS §10.3): لكل ملف صاحب (متقدم/موظف) ووحدة. يصل إليه صاحبه، أو مستخدم من نفس الشركة
 * يملك «عرض» وحدته. لا روابط عامة: التنزيل عبر الخادم فقط ويُسجَّل.
 */
class FileAccess
{
    /** @return array{module: string, owner: ?int, label: string}|null */
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
        return null;
    }

    public static function allowed(User $user, StoredFile $f, ?array $owner): bool
    {
        if (! $owner) return false;
        if ($owner['owner'] && $owner['owner'] === $user->id) return true;
        return $user->company_id && $user->company_id === $f->company_id && Perm::can($user, $owner['module']);
    }
}
