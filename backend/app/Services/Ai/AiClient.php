<?php

namespace App\Services\Ai;

/** نموذج لغوي يُرجع JSON مطابقاً لمخطط — التنفيذ الافتراضي محلي على الخادم (LocalModel)، والاختبارات تستبدله */
interface AiClient
{
    public function available(): bool;

    /** @return array<string, mixed> */
    public function json(string $system, string $prompt, array $schema): array;

    public function model(): string;
}
