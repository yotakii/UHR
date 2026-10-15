<?php

namespace App\Services\Recruitment;

use App\Models\Candidate;
use App\Models\JobOpening;
use App\Models\User;

/**
 * التقديم على شاغر (SRS M02 §2، §10.15):
 * - الحساب على مستوى المنصة: بريد واحد = متقدم واحد يقدّم لأي شركة.
 * - الطلب السابق يُبحث عنه داخل شركة الشاغر فقط (نفس الرقم الوطني أو البريد أو نفس الحساب) ويُلحق به.
 * - إثبات ملكية الحساب الموجود بكلمة مروره (أو بالدخول المسبق)، ولا إلحاق بطلب وصل للعرض أو التعيين.
 */
class DuplicateCheck
{
    public function __construct(
        public readonly JobOpening $job,
        public readonly ?Candidate $candidate,   // الطلب السابق في هذه الشركة
        public readonly ?User $account,          // حساب الدخول لهذا البريد (أو المسجّل حالياً)
        public readonly bool $signedIn,          // المتقدم مسجّل الدخول — لا حاجة لكلمة المرور
    ) {}

    public static function find(JobOpening $job, ?string $nationalId, string $email, ?User $user = null): self
    {
        $signedIn = $user && $user->portal() === 'candidate';
        $email = strtolower(trim($email));
        $account = $signedIn ? $user : User::with(['roles', 'employee'])->where('email', $email)->first();
        $nid = trim((string) $nationalId);
        $q = fn () => Candidate::allCompanies()->where('company_id', $job->company_id);
        $cand = ($account ? $q()->where('user_id', $account->id)->latest('id')->first() : null)
            ?? ($nid !== '' ? $q()->where('national_id', $nid)->first() : null)
            ?? $q()->whereRaw('lower(email) = ?', [$signedIn ? strtolower($account->email) : $email])->first();
        return new self($job, $cand, $account, $signedIn);
    }

    public function exists(): bool { return (bool) $this->candidate; }

    /** البريد لحساب موظف/إدارة/منصة — ليس حساب متقدم */
    public function staff(): bool { return $this->account && $this->account->portal() !== 'candidate'; }

    /** موظف في نفس الشركة صاحبة الشاغر ← التقديم الداخلي (M12) */
    public function staffOfThisCompany(): bool { return $this->staff() && $this->account->company_id === $this->job->company_id; }

    /** الطلب السابق (بنفس الرقم الوطني) يخص حساباً آخر */
    public function ownedByAnother(): bool
    {
        return $this->candidate?->user_id && $this->candidate->user_id !== $this->account?->id;
    }

    /** حساب موجود ولم يسجّل صاحبه الدخول ← كلمة مرور الحساب مطلوبة */
    public function needsPassword(): bool { return $this->account && ! $this->signedIn && ! $this->staff(); }

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
