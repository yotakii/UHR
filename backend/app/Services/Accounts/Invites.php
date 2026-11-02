<?php

namespace App\Services\Accounts;

use App\Exceptions\ApiException;
use App\Models\Company;
use App\Models\User;
use App\Notifications\AccountInvite;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * روابط الحساب (11e): بدل كلمة مرور مؤقتة تُرسل بالبريد، يصل رابط لمرة واحدة يختار به صاحب الحساب كلمة مروره.
 * الرابط لا يُحفظ (sha256 فقط)، وصلاحيته من إعداد الشركة accounts.invite_hours. رابط جديد يُبطل السابق.
 */
class Invites
{
    /** كلمة مرور عشوائية لا يعرفها أحد — للحساب الذي ينتظر التفعيل */
    public static function unusable(): string { return Str::random(48); }

    public static function hours(?int $companyId): int
    {
        if (! $companyId) return Settings::DEFAULTS['accounts']['invite_hours'];
        return (int) Tenant::run($companyId, fn () => Settings::merged('accounts')['invite_hours']);
    }

    public static function url(string $token): string { return rtrim((string) config('app.frontend_url'), '/').'/activate/'.$token; }

    /** ينشئ رابطاً ويرسله بالبريد (بعد إتمام المعاملة) — يعيد الرابط ليُنسخ عند الحاجة */
    public function issue(User $u, string $kind = 'invite', ?string $code = null, bool $mail = true): string
    {
        $token = Str::random(48);
        $hours = self::hours($u->company_id);
        $u->forceFill(['activated_at' => $u->activated_at ?? ($u->isActivated() ? ($u->last_login_at ?? $u->created_at) : null), 'invite_hash' => hash('sha256', $token), 'invite_kind' => $kind === 'reset' || $kind === 'successor' ? 'reset' : 'invite',
            'invite_expires_at' => now()->addHours($hours), 'invited_at' => now()])->save();
        $url = self::url($token);
        if ($mail) {
            $co = $u->company_id ? Company::find($u->company_id) : null;
            $u->notify((new AccountInvite($kind, $url, $hours, $co?->name_ar ?? 'UHR', $co?->name_en ?? 'UHR', $code))->afterCommit());
        }
        Audit::record($kind === 'reset' ? 'user.reset_link' : 'user.invited', $u, null, ['kind' => $kind, 'hours' => $hours, 'mail' => $mail]);
        return $url;
    }

    /** الحساب صاحب الرابط إن كان صالحاً */
    public function find(string $token): ?User
    {
        if (strlen($token) < 20) return null;
        return User::where('invite_hash', hash('sha256', $token))->first();
    }

    public static function state(User $u): ?string
    {
        if (! $u->invite_hash) return null;
        return $u->invite_expires_at && $u->invite_expires_at->isPast() ? 'expired' : 'pending';
    }

    /** يختار كلمة المرور بالرابط: يفعّل الحساب ويُبطل الرابط */
    public function accept(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password) {
            $u = User::where('invite_hash', hash('sha256', $token))->lockForUpdate()->first();
            if (! $u || self::state($u) !== 'pending') throw new ApiException(L::t('الرابط غير صالح أو انتهت صلاحيته. اطلب رابطاً جديداً من مدير النظام، أو من «نسيت كلمة المرور؟».', 'This link is invalid or has expired. Ask your administrator for a new one, or use "Forgot password?".'), 'invite_invalid', 410);
            if ($u->blocked()) throw new ApiException(L::t('هذا الحساب موقوف. راجع الموارد البشرية.', 'This account is disabled. Please contact HR.'), 'blocked', 403);
            $first = ! $u->activated_at;
            $u->forceFill(['password' => $password, 'must_change_password' => false, 'failed_logins' => 0, 'locked_until' => null,
                'invite_hash' => null, 'invite_kind' => null, 'invite_expires_at' => null, 'activated_at' => $u->activated_at ?? now(), 'last_login_at' => now()])->save();
            Audit::record($first ? 'auth.activated' : 'auth.password_reset', $u);
            return $u;
        });
    }
}
