<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * نموذج مفتوح المصدر يعمل على خادمنا (خدمة ollama في Docker) — مجاني، والبيانات لا تخرج من الخادم.
 * العنوان OLLAMA_URL والنموذج OLLAMA_MODEL من البيئة؛ بلا عنوان = الميزة متوقفة بصمت.
 */
class LocalModel implements AiClient
{
    public function url(): ?string { return rtrim((string) config('services.ollama.url'), '/') ?: null; }

    public function model(): string { return (string) (config('services.ollama.model') ?: 'qwen2.5:3b'); }

    /** الخدمة تعمل والنموذج محمّل (يُفحص كل دقيقة) */
    public function available(): bool
    {
        if (! $this->url()) return false;
        return Cache::remember('ai:local:up', 60, function () {
            try {
                $tags = Http::timeout(3)->get($this->url().'/api/tags')->json('models') ?? [];
                return collect($tags)->contains(fn ($m) => str_starts_with((string) ($m['name'] ?? ''), $this->model()));
            } catch (\Throwable) { return false; }
        });
    }

    /**
     * JSON بلا قيد قواعد (grammar): تقييد الإخراج بمخطط بطيء جداً على المعالج مع مفردات النموذج الكبيرة،
     * لذا نطلب JSON بالتعليمات ثم نستخرجه ونتحقق منه. OLLAMA_FORMAT=schema|json يعيد التقييد إن لزم.
     */
    public function json(string $system, string $prompt, array $schema): array
    {
        $mode = (string) config('services.ollama.format', 'none');
        $shape = json_encode(array_map(fn ($p) => ($p['type'] ?? 'string') === 'array' ? ['…'] : (($p['type'] ?? '') === 'integer' ? 0 : '…'), $schema['properties'] ?? []), JSON_UNESCAPED_UNICODE);
        $body = [
            'model' => $this->model(), 'stream' => false,
            'options' => array_filter(['temperature' => 0.2, 'num_ctx' => (int) config('services.ollama.num_ctx', 2048), 'num_predict' => (int) config('services.ollama.num_predict', 240),
                'num_thread' => (int) config('services.ollama.threads', 0) ?: null], fn ($v) => $v !== null),
            'keep_alive' => (string) config('services.ollama.keep_alive', '30m'),
            'messages' => [['role' => 'system', 'content' => $system."\nأجب بكائن JSON واحد فقط بهذا الشكل دون أي نص قبله أو بعده: ".$shape],
                ['role' => 'user', 'content' => $prompt]],
        ];
        if ($mode === 'schema') $body['format'] = $schema; elseif ($mode === 'json') $body['format'] = 'json';
        $r = Http::timeout((int) config('services.ollama.timeout', 420))->post($this->url().'/api/chat', $body);
        if (! $r->successful()) throw new \RuntimeException('local model HTTP '.$r->status());
        Log::info('ollama', ['prompt_tokens' => $r->json('prompt_eval_count'), 'out_tokens' => $r->json('eval_count'),
            'load_s' => round(((int) $r->json('load_duration')) / 1e9, 1), 'total_s' => round(((int) $r->json('total_duration')) / 1e9, 1)]);
        $out = self::extract((string) $r->json('message.content'));
        if (! is_array($out)) throw new \RuntimeException('local model returned invalid JSON');
        return $out;
    }

    /** أول كائن JSON في النص (يتجاوز ```json وأي كلام حوله) */
    public static function extract(string $text): ?array
    {
        $text = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
        $d = json_decode($text, true);
        if (is_array($d)) return $d;
        $a = strpos($text, '{'); $b = strrpos($text, '}');
        if ($a === false || $b === false || $b <= $a) return null;
        $d = json_decode(substr($text, $a, $b - $a + 1), true);
        return is_array($d) ? $d : null;
    }
}
