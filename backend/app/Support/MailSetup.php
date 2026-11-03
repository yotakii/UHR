<?php

namespace App\Support;

use App\Models\Company;
use App\Models\MailLog;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;

/**
 * البريد الصادر (11e):
 * - خادم SMTP من إعداد المنصة `mail` (لوحة المنصة) فوق إعداد البيئة MAIL_* — يُطبَّق عند الإقلاع وقبل كل مهمة طابور
 *   (العامل يعمل طويلاً، فيلتقط التعديل دون إعادة تشغيل).
 * - اسم المرسل وعنوان الرد لكل شركة من «قنوات الإشعارات».
 * - سجل لكل رسالة: sending ← sent، أو failed مع سبب الخطأ.
 */
class MailSetup
{
    private static ?string $applied = null;
    /** سجلات هذه العملية التي لم يؤكد إرسالها بعد */
    private static array $open = [];

    /** إعداد SMTP المحفوظ مع كلمة المرور مفكوكة — null إن لم يُضبط (يبقى إعداد البيئة) */
    public static function smtp(): ?array
    {
        $s = Settings::platformMerged('mail');
        if (trim((string) $s['host']) === '') return null;
        $s['password'] = $s['password'] ? self::decrypt($s['password']) : '';
        return $s;
    }

    public static function decrypt(string $v): string { try { return Crypt::decryptString($v); } catch (\Throwable) { return ''; } }

    /** يطبّق إعداد المنصة على mailer «smtp» ويجعله الافتراضي (إلا في الاختبارات: array يبقى) */
    public static function apply(bool $force = false): void
    {
        try { $s = self::smtp(); } catch (\Throwable) { return; }   // قبل الترحيل
        $sig = $s ? md5(json_encode($s)) : 'env';
        if (! $force && self::$applied === $sig) return;
        if (self::$applied !== null || $s) {
            if ($s) {
                $env = config('mail.mailers.smtp', []);
                config(['mail.mailers.smtp' => array_merge($env, [
                    'transport' => 'smtp', 'scheme' => $s['encryption'] === 'ssl' ? 'smtps' : 'smtp', 'host' => $s['host'], 'port' => (int) $s['port'],
                    'username' => $s['username'] ?: null, 'password' => $s['password'] ?: null, 'timeout' => 20,
                ])]);
                if ($s['from_address']) config(['mail.from.address' => $s['from_address']]);
                if ($s['from_name']) config(['mail.from.name' => $s['from_name']]);
                if (config('mail.default') !== 'array' && ! app()->runningUnitTests()) config(['mail.default' => 'smtp']);
            }
            Mail::purge('smtp');
        }
        self::$applied = $sig;
    }

    public static function forget(): void { self::$applied = null; }

    /** الشركة التي تُرسل باسمها: الشركة الحالية، أو شركة حساب المستلم */
    private static function company(?User $u): ?Company
    {
        if ($c = Tenant::company()) return $c;
        return $u?->company_id ? Company::find($u->company_id) : null;
    }

    public static function sending(MessageSending $e): void
    {
        $msg = $e->message;
        $to = $msg->getTo()[0] ?? null;
        $email = $to?->getAddress() ?? '';
        $user = $email ? User::where('email', strtolower($email))->first() : null;
        $co = self::company($user);
        if ($co) {
            $ch = \App\Models\NotificationChannel::allCompanies()->where('company_id', $co->id)->where('key', 'email')->first();
            $cfg = (array) ($ch?->config ?? []);
            $from = $msg->getFrom()[0] ?? null;
            $name = trim((string) ($cfg['from_name'] ?? '')) ?: $co->name_ar;
            if ($from) $msg->from(new Address($from->getAddress(), $name));
            if (! empty($cfg['reply_to']) && ! $msg->getReplyTo()) $msg->replyTo($cfg['reply_to']);
        }
        $kind = $e->data['__laravel_notification'] ?? null;
        try {
            $log = MailLog::create(['company_id' => $co?->id, 'user_id' => $user?->id, 'to' => mb_substr($email, 0, 190), 'subject' => mb_substr((string) $msg->getSubject(), 0, 300),
                'kind' => $kind ? class_basename($kind) : null, 'status' => 'sending']);
            $msg->getHeaders()->addTextHeader('X-UHR-Mail', (string) $log->id);
            self::$open[$log->id] = true;
        } catch (\Throwable) {}
    }

    public static function sent(MessageSent $e): void
    {
        $id = (int) ($e->message->getHeaders()->get('X-UHR-Mail')?->getBodyAsString() ?? 0);
        if (! $id) return;
        unset(self::$open[$id]);
        MailLog::whereKey($id)->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
    }

    /** خطأ أثناء الإرسال (مهمة طابور أو إرسال مباشر): كل رسالة مفتوحة في هذه العملية تُسجَّل فاشلة */
    public static function failed(\Throwable $ex): void
    {
        if (! self::$open) return;
        MailLog::whereKey(array_keys(self::$open))->where('status', 'sending')->update(['status' => 'failed', 'error' => mb_substr($ex->getMessage(), 0, 1000)]);
        self::$open = [];
    }

    public static function reset(): void { self::$open = []; }
}
