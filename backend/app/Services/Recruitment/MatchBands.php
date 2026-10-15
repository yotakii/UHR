<?php

namespace App\Services\Recruitment;

use App\Support\Settings;

/** نطاقات المطابقة من قواعد الأتمتة (SRS §10.9): طبيعي ≥ الحد، رفض ≤ حد الرفض (إذا مفعّل)، وما بينهما للمراجعة */
class MatchBands
{
    public const NORMAL = 'normal';
    public const REVIEW = 'review';
    public const REJECT = 'reject';

    public static function of(int $score): string
    {
        if ($score >= (int) Settings::rule('match_threshold')) return self::NORMAL;
        if (Settings::rule('auto_reject') && $score <= (int) Settings::rule('reject_max')) return self::REJECT;
        return self::REVIEW;
    }
}
