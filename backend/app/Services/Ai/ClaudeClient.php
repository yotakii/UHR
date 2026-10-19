<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Exceptions\ApiException;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Support\Facades\Crypt;

/**
 * Claude API عبر حزمة Anthropic الرسمية لـ PHP. المفتاح من إعدادات الشركة (M16) مشفّراً، وإلا ANTHROPIC_API_KEY في بيئة الخادم.
 * مخرجات مقيّدة بمخطط JSON (structured outputs)، ومع بديل الخادم عند الرفض (server-side fallback «default»).
 */
class ClaudeClient implements AiClient
{
    public const MODEL = 'claude-opus-5-5';

    public function configured(): bool { return self::key() !== ''; }

    /** المفتاح المحفوظ للشركة الحالية (مفكوك التشفير) أو مفتاح البيئة */
    public static function key(): string
    {
        $enc = Settings::get('ai')['api_key'] ?? null;
        if ($enc) { try { return Crypt::decryptString($enc); } catch (\Throwable) { return ''; } }
        return (string) (env('ANTHROPIC_API_KEY') ?: '');
    }

    public function json(string $system, string $prompt, array $schema): array
    {
        if (! $this->configured()) throw new ApiException(L::t('الاختبار الأولي بالذكاء الاصطناعي غير مفعّل — أضف مفتاح Claude API من إعدادات التوظيف، أو اكتب الأسئلة يدوياً.', 'AI preliminary tests are not configured — add a Claude API key in the recruitment settings, or write the questions manually.'), 'ai_not_configured', 422);
        $client = new Client(apiKey: self::key());
        try {
            $msg = $client->beta->messages->create(
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $prompt]],
                model: Settings::get('ai')['model'] ?? self::MODEL,
                system: $system,
                outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException) {
            throw new ApiException(L::t('مفتاح Claude API غير صالح — راجع إعدادات التوظيف.', 'The Claude API key is invalid — check the recruitment settings.'), 'ai_auth', 422);
        } catch (RateLimitException) {
            throw new ApiException(L::t('خدمة الذكاء الاصطناعي مشغولة الآن — حاول بعد دقيقة.', 'The AI service is busy — try again in a minute.'), 'ai_busy', 503);
        } catch (APIStatusException|APIConnectionException $e) {
            report($e);
            throw new ApiException(L::t('تعذّر الاتصال بخدمة الذكاء الاصطناعي — حاول لاحقاً أو اكتب الأسئلة يدوياً.', 'Could not reach the AI service — try later or write the questions manually.'), 'ai_unavailable', 503);
        }
        if ($msg->stopReason === 'refusal' || $msg->stopReason === 'max_tokens') {
            throw new ApiException(L::t('لم تُكمل خدمة الذكاء الاصطناعي الطلب — عدّل متطلبات الشاغر أو اكتب الأسئلة يدوياً.', 'The AI service did not complete the request — adjust the job requirements or write the questions manually.'), 'ai_incomplete', 422);
        }
        foreach ($msg->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);
                if (is_array($data)) return $data;
            }
        }
        throw new ApiException(L::t('ردّ غير متوقع من خدمة الذكاء الاصطناعي.', 'Unexpected response from the AI service.'), 'ai_bad_response', 502);
    }
}
