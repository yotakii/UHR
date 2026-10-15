<?php

namespace App\Services\Recruitment;

use App\Models\Candidate;
use App\Models\User;

/**
 * كشف تكرار الطلب (SRS M02 §2): نفس الرقم الوطني أو البريد.
 * الطلب المكرر يُلحق بالملف القديم — لكن في النظام التشغيلي يجب إثبات ملكية الحساب بكلمة مروره،
 * ولا يُلحق بطلب وصل لمرحلة العرض أو التعيين.
 */
class DuplicateCheck
{
    public function __construct(
        public readonly ?Candidate $candidate,
        public readonly ?User $account,          // حساب الدخول المرتبط بالملف السابق
        public readonly ?User $emailOwner,       // صاحب البريد إن كان حساباً آخر
    ) {}

    public static function find(?string $nationalId, string $email): self
    {
        $email = strtolower(trim($email));
        $nid = trim((string) $nationalId);
        $cand = ($nid !== '' ? Candidate::where('national_id', $nid)->first() : null)
            ?? Candidate::whereRaw('lower(email) = ?', [$email])->first();
        $account = $cand ? User::with('roles')->where('candidate_id', $cand->id)->first() : null;
        $owner = User::with('roles')->where('email', $email)->first();
        return new self($cand, $account, $owner && $owner->id !== $account?->id ? $owner : null);
    }

    public function exists(): bool { return (bool) $this->candidate; }

    /** البريد يخص موظفاً أو حساب إدارة (التقديم الداخلي من بوابة الموظف — M12) */
    public function emailBelongsToStaff(): bool
    {
        return $this->emailOwner && $this->emailOwner->portal() !== 'candidate';
    }

    /** البريد مستخدم لحساب آخر غير صاحب الملف المكرر */
    public function emailTaken(): bool { return (bool) $this->emailOwner; }

    /** طلب في مرحلة لا يجوز الرجوع منها (عرض مُرسل/مقبول/تفاوض، قرار تعيين، تعيين) */
    public function locked(): bool
    {
        $c = $this->candidate;
        if (! $c) return false;
        if ($c->stage === 'hired') return true;
        $c->loadMissing(['offer', 'appointment']);
        return (bool) $c->appointment || in_array($c->offer?->status, ['sent', 'accepted', 'negotiation'], true);
    }
}
