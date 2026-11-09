<?php

namespace App\Services\Push;

use App\Models\PushDevice;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * إشعارات تطبيق الجوال عبر Firebase Cloud Messaging (HTTP v1) دون مكتبات:
 * مفتاح حساب الخدمة (JSON) يُلصق مرة واحدة في لوحة المنصة ويُحفظ مشفّراً → رمز OAuth (JWT RS256) مخزّن 50 دقيقة → إرسال لكل جهاز.
 * الرمز غير الصالح (التطبيق حُذف) يُحذف من الأجهزة تلقائياً.
 */
class Fcm
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** حساب الخدمة المفكوك أو null */
    public static function account(): ?array
    {
        $s = Settings::platformMerged('push');
        if (! $s['enabled'] || ! $s['credentials']) return null;
        try { $j = json_decode(Crypt::decryptString($s['credentials']), true); } catch (\Throwable) { return null; }
        return self::valid($j) ? $j : null;
    }

    public static function valid(mixed $j): bool
    {
        return is_array($j) && ($j['type'] ?? '') === 'service_account' && ! empty($j['project_id']) && ! empty($j['client_email']) && ! empty($j['private_key']);
    }

    public static function enabled(): bool { return self::account() !== null; }

    public static function accessToken(array $sa): string
    {
        return Cache::remember('fcm:token:'.sha1($sa['client_email']), 3000, function () use ($sa) {
            $b64 = fn (string $x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
            $now = time(); $uri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $head = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $body = $b64(json_encode(['iss' => $sa['client_email'], 'scope' => self::SCOPE, 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600]));
            if (! openssl_sign("$head.$body", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new \RuntimeException('fcm_key');
            $r = Http::asForm()->timeout(15)->post($uri, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$head.$body.".$b64($sig)]);
            if (! $r->ok() || ! $r->json('access_token')) throw new \RuntimeException('fcm_auth: '.$r->status().' '.mb_substr($r->body(), 0, 200));
            return $r->json('access_token');
        });
    }

    /** @return 'ok'|'gone'|'error' */
    public static function send(array $sa, string $token, string $title, string $body, array $data = []): string
    {
        $r = Http::withToken(self::accessToken($sa))->timeout(15)->post("https://fcm.googleapis.com/v1/projects/{$sa['project_id']}/messages:send", ['message' => [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => mb_substr($body, 0, 240)],
            'data' => array_map('strval', $data),
            'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'uhr', 'sound' => 'default']],
        ]]);
        if ($r->ok()) return 'ok';
        $status = $r->json('error.status');
        if ($r->status() === 404 || $status === 'UNREGISTERED' || ($status === 'INVALID_ARGUMENT' && str_contains((string) $r->body(), 'registration token'))) return 'gone';
        Log::warning('fcm.send', ['status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
        return 'error';
    }

    /** إرسال لكل أجهزة المستخدم؛ يعيد عدد الإرسالات الناجحة */
    public static function toUser(int $userId, string $title, string $body, array $data = []): int
    {
        $sa = self::account();
        if (! $sa) return 0;
        $ok = 0;
        foreach (PushDevice::where('user_id', $userId)->get() as $d) {
            $res = self::send($sa, $d->token, $title, $body, $data);
            if ($res === 'gone') $d->delete();
            elseif ($res === 'ok') { $ok++; $d->forceFill(['last_sent_at' => now()])->save(); }
        }
        return $ok;
    }
}
