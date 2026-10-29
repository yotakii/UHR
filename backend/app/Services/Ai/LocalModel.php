<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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

    public function json(string $system, string $prompt, array $schema): array
    {
        $r = Http::timeout((int) config('services.ollama.timeout', 300))->post($this->url().'/api/chat', [
            'model' => $this->model(), 'stream' => false, 'format' => $schema,
            'options' => ['temperature' => 0.2, 'num_ctx' => 4096],
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
        ]);
        if (! $r->successful()) throw new \RuntimeException('local model HTTP '.$r->status());
        $out = json_decode((string) $r->json('message.content'), true);
        if (! is_array($out)) throw new \RuntimeException('local model returned invalid JSON');
        return $out;
    }
}
