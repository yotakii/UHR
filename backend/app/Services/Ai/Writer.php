<?php

namespace App\Services\Ai;

/** كاتب نص حر بالتدفق (كلمة كلمة) — لملخصات «المساعد». التنفيذ محلي (LocalModel)، والاختبارات تستبدله */
interface Writer
{
    public function available(): bool;

    /** يكتب الرد ويستدعي $onDelta لكل جزء يصل؛ يعيد النص كاملاً */
    public function stream(string $system, string $prompt, callable $onDelta, array $options = []): string;
}
