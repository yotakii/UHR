<?php

namespace App\Services\Ai;

/**
 * الذكاء الاصطناعي في UHR = الاختبار الأولي فقط (SRS §10.9، D-34). يُستدعى من الخادم فقط،
 * ولا يُرسل إليه أي بيان شخصي للمتقدم — متطلبات الشاغر وإجابات الاختبار فقط.
 * واجهة صغيرة حتى تستبدلها الاختبارات بنسخة وهمية.
 */
interface AiClient
{
    /** هل مفتاح الشركة مضبوط؟ */
    public function configured(): bool;

    /** طلب واحد بمخرجات JSON مقيّدة بالمخطط — يعيد المصفوفة المفككة */
    public function json(string $system, string $prompt, array $schema): array;
}
