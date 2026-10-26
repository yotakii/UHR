<?php

namespace App\Services\Security;

use App\Exceptions\ApiException;
use App\Models\MfaFactor;
use App\Models\Employee;
use App\Models\Passkey;
use App\Models\SalaryGrant;
use App\Models\User;
use App\Notifications\SalaryCode;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * حماية الرواتب بخطوتين (SRS §4.4) على الخادم: نافذة فتح بالجلسة (salary_vault)، ثلاث طرق تحقق — رمز لمرة واحدة يُرسل من الخادم،
 * مفتاح مرور WebAuthn (ES256/RS256 بتحقق المستخدم)، وتطبيق المصادقة TOTP (SHA-1، 30 ثانية، نافذة ±1، الرمز لا يُقبل مرتين) مع رموز طوارئ مجزّأة.
 * المحاولات الخاطئة والإيقاف في الكاش، وكل محاولة في mfa_audit (آخر audit_keep لكل مستخدم). كل القيم من الإعداد salary_vault.
 */
class SalaryVault
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // ——— حالة الفتح ———

    /** الثواني المتبقية بعد آخر تمديد في هذا الطلب (يرسلها الخادم في الهيدر X-Salary-Remaining) */
    public static ?int $window = null;

    private static function isOpen(?User $u): bool
    {
        if (! $u) return false;
        $s = session('salary_vault');
        return is_array($s) && ($s['uid'] ?? null) === $u->id && ($s['until'] ?? 0) > now()->timestamp;
    }

    /** مفتوحة؟ وكل اطلاع على بيانات رواتب يمدّد النافذة (unlock_minutes من الآن) بحد أقصى max_minutes من لحظة التحقق */
    public static function open(?User $u): bool
    {
        if (! self::isOpen($u)) return false;
        $s = session('salary_vault'); $c = Settings::vault();
        $cap = ($s['started'] ?? $s['until']) + 60 * (int) $c['max_minutes'];
        $until = min($cap, now()->timestamp + 60 * (int) $c['unlock_minutes']);
        if ($until > $s['until']) session()->put('salary_vault', ['until' => $until] + $s);
        self::$window = max($until, $s['until']) - now()->timestamp;
        return true;
    }

    public static function remaining(User $u): int
    {
        return self::isOpen($u) ? max(0, (int) session('salary_vault')['until'] - now()->timestamp) : 0;
    }

    // ——— إذن الوصول لرواتب الآخرين (10b) ———

    /** يحتاج المستخدم إذناً معتمداً ليرى رواتب غيره؟ (الأدوار المعفاة = المعتمِد) */
    public static function needsGrant(User $u): bool
    {
        $c = Settings::vault();
        if (! $c['grant_required']) return false;
        $u->loadMissing('roles');
        return ! ($c['grant_exempt_roles'] && $u->hasRole(...$c['grant_exempt_roles']));
    }

    public static function grant(User $u): ?SalaryGrant
    {
        return SalaryGrant::where('user_id', $u->id)->where('status', 'approved')->where('expires_at', '>', now())->latest('expires_at')->first();
    }

    /** نطاق رواتب الآخرين المتاح الآن: null = لا شيء، ['all' => true] أو ['all' => false, 'dept' => id] */
    public static function others(?User $u): ?array
    {
        if (! $u || ! self::open($u)) return null;
        if (! self::needsGrant($u)) return ['all' => true, 'dept' => null];
        $g = self::grant($u);
        return $g ? ['all' => $g->scope === 'all', 'dept' => $g->department_id] : null;
    }

    /** يرى راتب هذا الموظف؟ ملفه هو: التحقق يكفي، وغيره: حسب الإذن */
    public static function sees(User $u, ?Employee $e, ?array $others = null): bool
    {
        if (! $e) return false;
        if ($u->employee_id && $u->employee_id === $e->id) return self::open($u);
        $o = func_num_args() >= 3 ? $others : self::others($u);
        return $o !== null && ($o['all'] || $o['dept'] === $e->department_id);
    }

    /** يرفض تصدير رواتب الآخرين بلا فتح وإذن على كل الشركة */
    public static function requireAll(User $u): void
    {
        self::require($u);
        if (! (self::others($u)['all'] ?? false)) throw new ApiException(L::t('تحتاج إذن اطلاع على رواتب كل الشركة من المدير العام.', 'You need company-wide salary access approved by the general manager.'), 'salary_grant_required', 403);
    }

    /** يرفض الطلب إن كانت الرواتب مقفلة (تصدير الكشف) */
    public static function require(User $u): void
    {
        if (! self::isOpen($u)) throw new ApiException(L::t('بيانات الرواتب مقفلة — تحقق من هويتك أولاً.', 'Salary data is locked — verify your identity first.'), 'salary_locked', 403);
    }

    public function lock(User $u): void
    {
        session()->forget('salary_vault');
    }

    public function state(User $u): array
    {
        $cfg = Settings::vault();
        $f = MfaFactor::where('user_id', $u->id)->first();
        $last = DB::table('mfa_audit')->where('user_id', $u->id)->where('ok', true)->orderByDesc('at')->orderByDesc('id')->first();
        $otp = Cache::get($this->key('otp', $u));
        $grants = SalaryGrant::with('department')->where('user_id', $u->id)->latest('id')->limit(1)->get();
        $g = $grants->first();
        return ['unlocked' => self::isOpen($u), 'remaining' => self::remaining($u), 'lockout' => $this->lockout($u),
            'grant' => ['needed' => self::needsGrant($u), 'hours' => (int) $cfg['grant_hours'],
                'last' => $g ? ['id' => $g->id, 'status' => $g->state(), 'scope' => $g->scope, 'department' => $g->department ? ['ar' => $g->department->name_ar, 'en' => $g->department->name_en] : null,
                    'expires_at' => $g->expires_at?->toIso8601String(), 'created_at' => $g->created_at?->toIso8601String()] : null],
            'enrolled' => (bool) $f?->enrolled_at, 'backup_left' => $f ? count($f->backup_hashes ?? []) : 0,
            'passkeys' => Passkey::where('user_id', $u->id)->orderBy('id')->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->label, 'created_at' => $p->created_at?->toIso8601String(), 'last_used_at' => $p->last_used_at?->toIso8601String()])->values(),
            'last_ok' => $last ? ['at' => \Illuminate\Support\Carbon::parse($last->at)->toIso8601String(), 'method' => $last->method] : null,
            'methods' => $cfg['methods'], 'unlock_minutes' => (int) $cfg['unlock_minutes'], 'max_minutes' => (int) $cfg['max_minutes'], 'otp_minutes' => (int) $cfg['otp_minutes'], 'link_seconds' => (int) $cfg['link_seconds'],
            'otp' => $otp && $otp['exp'] > now()->timestamp ? ['expires_in' => $otp['exp'] - now()->timestamp, 'to' => self::maskEmail($u->email)] : null,
            'destination' => self::maskEmail($u->email)];
    }

    // ——— المحاولات والإيقاف ———

    private function key(string $what, User $u): string { return "vault:{$what}:{$u->id}"; }

    public function lockout(User $u): int
    {
        $f = Cache::get($this->key('fail', $u));
        return $f && ($f['until'] ?? 0) > now()->timestamp ? $f['until'] - now()->timestamp : 0;
    }

    private function guard(User $u): void
    {
        if ($s = $this->lockout($u)) throw new ApiException(L::t("محاولات كثيرة خاطئة. أعد المحاولة بعد {$s} ثانية.", "Too many wrong attempts. Try again in {$s}s."), 'locked', 429, extra: ['retry' => $s]);
    }

    /** محاولة خاطئة: تُسجَّل، وبعد max_attempts إيقاف lockout_seconds؛ يرمي الخطأ المناسب */
    private function fail(User $u, string $method, string $ar, string $en, string $code = 'wrong'): never
    {
        $cfg = Settings::vault();
        $this->log($u, false, $method);
        $f = Cache::get($this->key('fail', $u)) ?? ['n' => 0, 'until' => 0];
        $f['n']++;
        if ($f['n'] >= (int) $cfg['max_attempts']) { $f = ['n' => 0, 'until' => now()->timestamp + (int) $cfg['lockout_seconds']]; }
        Cache::put($this->key('fail', $u), $f, now()->addHours(2));
        if ($f['until'] > now()->timestamp) $this->guard($u);
        $left = (int) $cfg['max_attempts'] - $f['n'];
        throw new ApiException(str_replace('{n}', (string) $left, L::t($ar, $en)), $code, 422, extra: ['left' => $left]);
    }

    private function wrong(User $u, string $method): never
    {
        $this->fail($u, $method, 'رمز غير صحيح. تبقّى {n} محاولات.', 'Wrong code. {n} attempts left.');
    }

    private function unlock(User $u, string $method): array
    {
        $this->log($u, true, $method);
        Cache::forget($this->key('fail', $u));
        session()->put('salary_vault', ['uid' => $u->id, 'started' => now()->timestamp, 'until' => now()->timestamp + 60 * (int) Settings::vault()['unlock_minutes']]);
        return $this->state($u);
    }

    private function log(User $u, bool $ok, string $method): void
    {
        DB::table('mfa_audit')->insert(['user_id' => $u->id, 'ok' => $ok, 'method' => $method, 'ip' => request()?->ip(), 'at' => now()]);
        $keep = (int) Settings::vault()['audit_keep'];
        $edge = DB::table('mfa_audit')->where('user_id', $u->id)->orderByDesc('id')->skip($keep)->value('id');
        if ($edge) DB::table('mfa_audit')->where('user_id', $u->id)->where('id', '<=', $edge)->delete();
    }

    private function method(string $m): void
    {
        if (! (Settings::vault()['methods'][$m] ?? false)) throw new ApiException(L::t('طريقة التحقق هذه غير مفعّلة في شركتك.', 'This verification method is turned off for your company.'), 'method_off', 403);
    }

    // ——— تطبيق المصادقة (TOTP) ———

    public static function step(): int { return intdiv(now()->timestamp, 30); }

    public static function totp(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::b32decode($secret), true);
        $o = ord($h[19]) & 15;
        $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($v % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** الخطوة المطابقة ضمن ±1 وبعد $after، أو null */
    private static function match(string $secret, string $code, int $after = PHP_INT_MIN): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return null;
        $now = self::step();
        foreach ([0, -1, 1] as $d) if ($now + $d > $after && hash_equals(self::totp($secret, $now + $d), $code)) return $now + $d;
        return null;
    }

    public static function b32encode(string $bin): string
    {
        $out = ''; $buf = 0; $bits = 0;
        foreach (str_split($bin) as $c) { $buf = ($buf << 8) | ord($c); $bits += 8; while ($bits >= 5) { $out .= self::B32[($buf >> ($bits - 5)) & 31]; $bits -= 5; } }
        if ($bits > 0) $out .= self::B32[($buf << (5 - $bits)) & 31];
        return $out;
    }

    public static function b32decode(string $s): string
    {
        $s = preg_replace('/[^A-Z2-7]/', '', strtoupper($s));
        $out = ''; $buf = 0; $bits = 0;
        foreach (str_split($s) as $c) { $buf = ($buf << 5) | strpos(self::B32, $c); $bits += 5; if ($bits >= 8) { $out .= chr(($buf >> ($bits - 8)) & 255); $bits -= 8; } }
        return $out;
    }

    public function enrol(User $u): array
    {
        $this->method('app');
        if (MfaFactor::where('user_id', $u->id)->whereNotNull('enrolled_at')->exists()) throw new ApiException(L::t('تطبيق المصادقة مربوط مسبقاً.', 'The authenticator app is already linked.'), 'enrolled', 409);
        $secret = self::b32encode(random_bytes(20));
        session()->put('vault_enrol', ['uid' => $u->id, 'secret' => $secret]);
        return ['secret' => $secret, 'uri' => 'otpauth://totp/UHR:'.rawurlencode($u->email).'?secret='.$secret.'&issuer=UHR'];
    }

    /** أول رمز من التطبيق ← حفظ الربط + رموز الطوارئ (تُعرض مرة واحدة) + فتح الرواتب */
    public function confirm(User $u, string $code): array
    {
        $this->method('app');
        $this->guard($u);
        $p = session('vault_enrol');
        if (! is_array($p) || $p['uid'] !== $u->id) throw new ApiException(L::t('ابدأ الإعداد من جديد.', 'Start the setup again.'), 'enrol_expired', 409);
        $step = self::match($p['secret'], $code);
        if ($step === null) $this->fail($u, 'enrol', 'الرمز غير مطابق. تأكد أن ساعة الجوال والحاسوب مضبوطة تلقائياً ثم أعد المحاولة. تبقّى {n} محاولات.', 'Code doesn’t match. Make sure phone and PC clocks are automatic, then retry. {n} attempts left.');
        $codes = $this->newBackup();
        MfaFactor::updateOrCreate(['user_id' => $u->id], ['totp_secret' => $p['secret'], 'totp_last_step' => $step, 'backup_hashes' => array_map([self::class, 'backupHash'], $codes), 'enrolled_at' => now()]);
        session()->forget('vault_enrol');
        Audit::record('mfa.enrolled', $u);
        return ['backup' => $codes] + $this->unlock($u, 'enrol');
    }

    public function verifyApp(User $u, string $code): array
    {
        $this->method('app');
        $this->guard($u);
        $f = MfaFactor::where('user_id', $u->id)->whereNotNull('enrolled_at')->first();
        if (! $f) throw new ApiException(L::t('اربط تطبيق المصادقة أولاً.', 'Link the authenticator app first.'), 'not_enrolled', 409);
        $step = self::match($f->totp_secret, $code, (int) ($f->totp_last_step ?? PHP_INT_MIN));
        if ($step === null) {
            if (self::match($f->totp_secret, $code) !== null) throw new ApiException(L::t('هذا الرمز استُخدم من قبل. انتظر الرمز التالي في التطبيق.', 'That code was already used. Wait for the next one.'), 'reused', 422);
            $this->wrong($u, 'app');
        }
        $f->update(['totp_last_step' => $step]);
        return $this->unlock($u, 'app');
    }

    // ——— رموز الطوارئ ———

    private function newBackup(): array
    {
        return array_map(function () { $c = substr(self::b32encode(random_bytes(5)), 0, 8); return substr($c, 0, 4).'-'.substr($c, 4); }, range(1, (int) Settings::vault()['backup_count']));
    }

    public static function backupHash(string $code): string
    {
        return hash_hmac('sha256', 'uhr-backup:'.preg_replace('/[^A-Z0-9]/', '', strtoupper($code)), (string) config('app.key'));
    }

    public function verifyBackup(User $u, string $code): array
    {
        $this->guard($u);
        $f = MfaFactor::where('user_id', $u->id)->whereNotNull('enrolled_at')->first();
        $list = $f?->backup_hashes ?? [];
        $i = array_search(self::backupHash($code), $list, true);
        if ($i === false) $this->fail($u, 'backup', 'رمز غير صحيح أو مستخدم سابقاً. تبقّى {n} محاولات.', 'Invalid or already used code. {n} attempts left.');
        array_splice($list, $i, 1);
        $f->update(['backup_hashes' => $list]);
        return $this->unlock($u, 'backup');
    }

    // ——— رمز لمرة واحدة من الخادم (البريد الآن، SMS عند اعتماد مزوّد) ———

    public function sendOtp(User $u): array
    {
        $this->method('phone');
        $this->guard($u);
        $prev = Cache::get($this->key('otp', $u));
        if ($prev && ($prev['sent'] ?? 0) > now()->timestamp - 30) throw new ApiException(L::t('أُرسل رمز قبل لحظات — انتظر قليلاً قبل طلب رمز جديد.', 'A code was just sent — wait a moment before asking for a new one.'), 'otp_wait', 429);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $min = (int) Settings::vault()['otp_minutes'];
        Cache::put($this->key('otp', $u), ['hash' => hash_hmac('sha256', $code, (string) config('app.key')), 'exp' => now()->timestamp + 60 * $min, 'sent' => now()->timestamp], now()->addMinutes($min + 1));
        $co = Tenant::company();
        $u->notify(new SalaryCode($code, $min, $co?->name_ar ?? 'UHR', $co?->name_en ?? 'UHR'));
        return $this->state($u);
    }

    public function verifyOtp(User $u, string $code): array
    {
        $this->method('phone');
        $this->guard($u);
        $otp = Cache::get($this->key('otp', $u));
        if (! $otp || $otp['exp'] <= now()->timestamp) throw new ApiException(L::t('انتهت صلاحية الرمز. اضغط «رمز جديد».', 'Code expired. Tap “New code”.'), 'expired', 422);
        if (! hash_equals($otp['hash'], hash_hmac('sha256', preg_replace('/\D/', '', $code), (string) config('app.key')))) $this->wrong($u, 'phone');
        Cache::forget($this->key('otp', $u));
        return $this->unlock($u, 'phone');
    }

    // ——— موافقة من الموبايل (10b): QR على الحاسوب ← الموبايل المسجّل دخوله بنفس الحساب يوافق ← يُفتح الحاسوب ———

    private function linkKey(string $t): string { return 'vault:link:'.hash('sha256', $t); }

    public function linkStart(User $u): array
    {
        $this->method('link');
        $this->guard($u);
        $t = \Illuminate\Support\Str::random(40);
        $sec = (int) Settings::vault()['link_seconds'];
        $ua = (string) request()?->userAgent();
        $device = trim(implode(' · ', array_filter([
            str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Android') ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (str_contains($ua, 'Mac') ? 'macOS' : (str_contains($ua, 'Linux') ? 'Linux' : null)))),
            str_contains($ua, 'Edg/') ? 'Edge' : (str_contains($ua, 'Chrome/') ? 'Chrome' : (str_contains($ua, 'Firefox/') ? 'Firefox' : (str_contains($ua, 'Safari/') ? 'Safari' : null))),
        ])));
        Cache::put($this->linkKey($t), ['uid' => $u->id, 'sid' => session()->getId(), 'status' => 'pending', 'exp' => now()->timestamp + $sec, 'device' => $device ?: null, 'ip' => request()?->ip(), 'at' => now()->toIso8601String()], now()->addSeconds($sec + 60));
        session()->put('vault_link', $t);
        return ['token' => $t, 'url' => rtrim((string) config('app.frontend_url'), '/').'/approve/'.$t, 'expires_in' => $sec];
    }

    private function link(string $t): ?array
    {
        $l = Cache::get($this->linkKey($t));
        return $l && $l['exp'] > now()->timestamp ? $l : null;
    }

    /** الحاسوب يسأل كل ثانيتين: عند الموافقة تُفتح الرواتب في جلسته هو */
    public function linkStatus(User $u, string $t): array
    {
        $l = $this->link($t);
        if (! $l || $l['uid'] !== $u->id || session('vault_link') !== $t) return ['status' => 'expired'];
        if ($l['status'] === 'pending') return ['status' => 'pending', 'expires_in' => $l['exp'] - now()->timestamp];
        Cache::forget($this->linkKey($t));
        session()->forget('vault_link');
        if ($l['status'] === 'rejected') return ['status' => 'rejected'];
        return ['status' => 'approved', 'state' => $this->unlock($u, 'link')];
    }

    /** الموبايل: تفاصيل الطلب (نفس الحساب فقط، ومن جلسة غير جلسة الحاسوب) */
    public function linkInfo(User $u, string $t): array
    {
        $l = $this->linkFor($u, $t);
        return ['status' => $l['status'], 'device' => $l['device'], 'ip' => $l['ip'], 'at' => $l['at'], 'expires_in' => $l['exp'] - now()->timestamp];
    }

    public function linkDecide(User $u, string $t, bool $approve): array
    {
        $l = $this->linkFor($u, $t);
        if ($l['status'] !== 'pending') throw new ApiException(L::t('تم الرد على هذا الطلب.', 'This request was already answered.'), 'link_answered', 409);
        $l['status'] = $approve ? 'approved' : 'rejected';
        Cache::put($this->linkKey($t), $l, now()->addSeconds(max(5, $l['exp'] - now()->timestamp + 60)));
        if (! $approve) $this->log($u, false, 'link');
        return ['status' => $l['status']];
    }

    private function linkFor(User $u, string $t): array
    {
        $l = $this->link($t);
        if (! $l) throw new ApiException(L::t('انتهت صلاحية الطلب — اطلب رمزاً جديداً من الحاسوب.', 'This request expired — start a new one on the computer.'), 'link_expired', 410);
        if ($l['uid'] !== $u->id) throw new ApiException(L::t('هذا الطلب لحساب آخر — سجّل الدخول بحسابك نفسه على الموبايل.', 'This request belongs to another account — sign in with the same account on your phone.'), 'link_other_user', 403);
        if ($l['sid'] === session()->getId()) throw new ApiException(L::t('افتح الرابط من موبايلك وليس من نفس المتصفح.', 'Open the link on your phone, not in the same browser.'), 'link_same_device', 409);
        return $l;
    }

    // ——— البصمة / Windows Hello (WebAuthn) ———

    public function passkeyOptions(User $u): array
    {
        $this->method('passkey');
        $this->guard($u);
        $challenge = random_bytes(32);
        session()->put('vault_challenge', ['uid' => $u->id, 'c' => self::b64u($challenge), 'exp' => now()->timestamp + 120]);
        $keys = Passkey::where('user_id', $u->id)->pluck('credential_id')->map(fn ($id) => ['type' => 'public-key', 'id' => $id])->values();
        $co = Tenant::company();
        return ['challenge' => self::b64u($challenge), 'rp' => ['name' => $co?->name_en ?? 'UHR'], 'user' => ['id' => self::b64u('uhr-'.$u->id), 'name' => $u->email, 'displayName' => $u->displayName('en')],
            'credentials' => $keys, 'timeout' => 60000];
    }

    /** يتحقق من clientDataJSON (النوع، التحدي، الأصل) ويعيد rpId المتوقع */
    private function clientData(User $u, string $json, string $type): string
    {
        $c = json_decode($json, true);
        $ch = session('vault_challenge');
        session()->forget('vault_challenge');
        $bad = fn () => throw new ApiException(L::t('فشل التحقق من التوقيع.', 'Signature check failed.'), 'bad_signature', 422);
        if (! is_array($c) || ($c['type'] ?? '') !== $type || ! is_array($ch) || $ch['uid'] !== $u->id || $ch['exp'] < now()->timestamp || ! hash_equals($ch['c'], (string) ($c['challenge'] ?? ''))) $bad();
        $host = self::allowedOrigin((string) ($c['origin'] ?? ''));
        if (! $host) $bad();
        return $host;
    }

    /** الأصل المسموح: عنوان الواجهة (FRONTEND_URL) أو نطاق فرعي منه، عبر https أو localhost */
    public static function allowedOrigin(string $origin): ?string
    {
        $o = parse_url($origin); $base = parse_url((string) config('app.frontend_url'));
        $host = strtolower($o['host'] ?? ''); $root = strtolower($base['host'] ?? '');
        if (! $host || ! $root) return null;
        if (($o['scheme'] ?? '') !== 'https' && $host !== 'localhost') return null;
        if (rtrim($origin, '/') === rtrim((string) config('app.frontend_url'), '/')) return $host;
        return $host === $root || str_ends_with($host, '.'.$root) ? $host : null;
    }

    /** authenticatorData: تجزئة rpId، وعلَما الحضور (UP) وتحقق المستخدم (UV) إلزاميان */
    private function authData(string $auth, string $rpId): int
    {
        if (strlen($auth) < 37 || ! hash_equals(hash('sha256', $rpId, true), substr($auth, 0, 32)) || (ord($auth[32]) & 0x05) !== 0x05)
            throw new ApiException(L::t('فشل التحقق من التوقيع.', 'Signature check failed.'), 'bad_signature', 422);
        return unpack('N', substr($auth, 33, 4))[1];
    }

    public function registerPasskey(User $u, array $d): array
    {
        $this->method('passkey');
        $this->guard($u);
        $rp = $this->clientData($u, self::unb64u($d['client_data']), 'webauthn.create');
        $count = $this->authData(self::unb64u($d['authenticator_data']), $rp);
        $pem = self::pem(self::unb64u($d['public_key']));
        $key = openssl_pkey_get_public($pem);
        $type = $key ? openssl_pkey_get_details($key)['type'] ?? null : null;
        if (! $key || ! in_array((int) $d['alg'], [-7, -257], true) || ($d['alg'] == -7 ? $type !== OPENSSL_KEYTYPE_EC : $type !== OPENSSL_KEYTYPE_RSA)) throw new ApiException(L::t('تعذّر استخدام البصمة على هذا الجهاز.', 'Couldn’t use biometrics on this device.'), 'passkey_unsupported', 422);
        if (Passkey::where('credential_id', $d['id'])->exists()) throw new ApiException(L::t('هذا الجهاز مسجّل مسبقاً.', 'This device is already registered.'), 'passkey_exists', 409);
        Passkey::create(['user_id' => $u->id, 'credential_id' => $d['id'], 'public_key' => $pem, 'alg' => (int) $d['alg'], 'label' => mb_substr(trim((string) ($d['label'] ?? '')), 0, 80) ?: null, 'sign_count' => $count, 'last_used_at' => now()]);
        Audit::record('mfa.passkey_added', $u);
        return $this->unlock($u, 'passkey');
    }

    public function verifyPasskey(User $u, array $d): array
    {
        $this->method('passkey');
        $this->guard($u);
        $p = Passkey::where('user_id', $u->id)->where('credential_id', $d['id'])->first();
        if (! $p) throw new ApiException(L::t('هذا الجهاز غير مسجّل — سجّل البصمة من جديد.', 'This device is not registered — register it again.'), 'not_enrolled', 409);
        $json = self::unb64u($d['client_data']); $auth = self::unb64u($d['authenticator_data']);
        try {
            $rp = $this->clientData($u, $json, 'webauthn.get');
            $count = $this->authData($auth, $rp);
        } catch (ApiException) {
            $this->fail($u, 'passkey', 'فشل التحقق من التوقيع. تبقّى {n} محاولات.', 'Signature check failed. {n} attempts left.', 'bad_signature');
        }
        $ok = openssl_verify($auth.hash('sha256', $json, true), self::unb64u($d['signature']), $p->public_key, OPENSSL_ALGO_SHA256) === 1;
        // عدّاد التوقيع يجب أن يزيد (إلا لو كان المصادِق لا يعدّ: صفر دائماً)
        if (! $ok || ($count > 0 && $count <= (int) $p->sign_count)) $this->fail($u, 'passkey', 'فشل التحقق من التوقيع. تبقّى {n} محاولات.', 'Signature check failed. {n} attempts left.', 'bad_signature');
        $p->update(['sign_count' => $count, 'last_used_at' => now()]);
        return $this->unlock($u, 'passkey');
    }

    /** إزالة جهاز مسجّل لا تحتاج فتحاً (لا تمنح وصولاً) — «إزالة البصمة المسجّلة» */
    public function removePasskey(User $u, int $id): array
    {
        Passkey::where('user_id', $u->id)->findOrFail($id)->delete();
        Audit::record('mfa.passkey_removed', $u);
        return $this->state($u);
    }

    /** «غيّرت جوالك؟»: بكلمة مرور الحساب يُلغى ربط التطبيق ورموز الطوارئ (ومفاتيح المرور معها) وتُقفل الرواتب */
    public function reset(User $u, string $password): array
    {
        $this->guard($u);
        if (! Hash::check($password, $u->password)) $this->fail($u, 'reset', 'كلمة المرور غير صحيحة. تبقّى {n} محاولات.', 'Wrong password. {n} attempts left.');
        MfaFactor::where('user_id', $u->id)->delete();
        Passkey::where('user_id', $u->id)->delete();
        $this->lock($u);
        Audit::record('mfa.reset', $u);
        return $this->state($u);
    }

    // ——— أدوات ———

    public static function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

    public static function unb64u(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4)); }

    public static function pem(string $der): string { return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n"; }

    public static function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) return null;
        [$n, $d] = explode('@', $email, 2);
        return mb_substr($n, 0, 1).str_repeat('•', max(2, min(6, mb_strlen($n) - 1))).'@'.$d;
    }

    /** يقنّع قيم المال عند القفل: null بدل القيمة */
    public static function mask(mixed $value, bool $open): mixed { return $open ? $value : null; }
}
