<?php

namespace App\Http\Controllers\Api\Hr\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Models\NotificationChannel;
use App\Support\Audit;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * M16 «قنوات الإشعار»: البريد / الرسائل القصيرة / واتساب (تفعيل + إعدادات المزوّد مشفّرة، لا تُعاد للواجهة إلا كـ«محفوظ»)،
 * وقوالب الرسائل باللغتين لكل قناة. المتغيرات (:job، :ref، :code) محفوظة — لا يُقبل متغير غير معروف للقالب.
 */
class ChannelController extends Controller
{
    /** حقول إعداد كل قناة (secret = لا تُعرض بعد الحفظ) */
    public const FIELDS = [
        'email' => ['from_name' => false, 'reply_to' => false],
        'sms' => ['provider' => false, 'sender' => false, 'api_key' => true],
        'whatsapp' => ['provider' => false, 'phone_id' => false, 'token' => true],
    ];
    public const KEYS = ['application_received' => ['استلام طلب التوظيف', 'Application received'], 'interview_invite' => ['دعوة للمقابلة', 'Interview invitation'],
        'offer_sent' => ['صدور عرض العمل', 'Job offer issued'], 'test_invite' => ['دعوة للاختبار المبدئي', 'Preliminary test invitation'], 'otp' => ['رمز التحقق', 'Verification code']];
    public const TOKENS = [':job', ':ref', ':code', ':link', ':name'];

    public function index(): JsonResponse
    {
        $ch = NotificationChannel::all()->keyBy('key');
        return response()->json([
            'channels' => collect(self::FIELDS)->map(fn ($fields, $k) => ['key' => $k, 'enabled' => (bool) $ch->get($k)?->enabled,
                'config' => collect($fields)->map(fn ($secret, $f) => $secret ? ['set' => ! empty($ch->get($k)?->config[$f] ?? null), 'secret' => true] : ['value' => $ch->get($k)?->config[$f] ?? '', 'secret' => false])])->values(),
            'templates' => MessageTemplate::orderBy('key')->orderBy('channel')->get()->map(fn ($t) => ['id' => $t->id, 'key' => $t->key, 'channel' => $t->channel,
                'label' => ['ar' => self::KEYS[$t->key][0] ?? $t->key, 'en' => self::KEYS[$t->key][1] ?? $t->key], 'ar' => $t->body_ar, 'en' => $t->body_en])->values(),
            'tokens' => self::TOKENS,
        ]);
    }

    public function updateChannel(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(self::FIELDS[$key]), 404);
        $rules = ['enabled' => ['required', 'boolean'], 'config' => ['nullable', 'array']];
        foreach (self::FIELDS[$key] as $f => $secret) $rules["config.$f"] = ['nullable', 'string', 'max:500', ...($f === 'reply_to' ? ['email'] : [])];
        $d = $request->validate($rules);
        if ($key === 'email' && ! $d['enabled']) throw ValidationException::withMessages(['enabled' => [L::t('البريد هو القناة الأساسية ولا يُعطَّل.', 'Email is the primary channel and cannot be turned off.')]]);
        $c = NotificationChannel::firstOrNew(['key' => $key]);
        $config = $c->config ?? [];
        foreach (self::FIELDS[$key] as $f => $secret) {
            $v = trim((string) ($d['config'][$f] ?? ''));
            if ($secret && $v === '') continue;           // سرّ فارغ = إبقاء المحفوظ
            $config[$f] = $v;
        }
        $was = (bool) $c->enabled;
        $c->fill(['enabled' => (bool) $d['enabled'], 'config' => $config])->save();
        Audit::record('settings.channel', $c, ['enabled' => $was], ['enabled' => $c->enabled, 'fields' => array_keys(array_filter($config))]);
        return $this->index();
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        $t = MessageTemplate::findOrFail($id);
        $d = $request->validate(['ar' => ['required', 'string', 'max:1000'], 'en' => ['required', 'string', 'max:1000']]);
        foreach (['ar', 'en'] as $l) {
            preg_match_all('/:[a-z_]+/', $d[$l], $m);
            $bad = array_diff($m[0], self::TOKENS);
            if ($bad) throw ValidationException::withMessages([$l => [L::t('متغير غير معروف: ', 'Unknown placeholder: ').implode('، ', $bad)]]);
        }
        $before = ['ar' => $t->body_ar, 'en' => $t->body_en];
        $t->update(['body_ar' => trim($d['ar']), 'body_en' => trim($d['en'])]);
        Audit::record('settings.template', $t, $before, ['ar' => $t->body_ar, 'en' => $t->body_en]);
        return $this->index();
    }
}
