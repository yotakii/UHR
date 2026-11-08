<?php

namespace App\Services\Assistant;

use App\Services\Policies\TextExtractor;

/** تطبيع نصوص «المساعد»: بلا تشكيل وبهمزات موحدة (TextExtractor::norm)، أرقام لاتينية، علامات الترقيم مسافات، وكلمات مفتاحية بجذور خفيفة */
class Text
{
    /** كلمات شائعة لا تفيد البحث (بعد التطبيع) */
    public const STOP = ['من', 'في', 'علي', 'الي', 'عن', 'ما', 'ماذا', 'ماهي', 'ماهو', 'كيف', 'هل', 'كم', 'متي', 'اين', 'وين', 'لماذا', 'ليش', 'هو', 'هي', 'انا', 'نحن', 'انت', 'اريد', 'بدي', 'ابي',
        'شو', 'ايش', 'يعني', 'ان', 'او', 'مع', 'هذا', 'هذه', 'ذلك', 'تلك', 'التي', 'الذي', 'عند', 'لي', 'لدي', 'عندي', 'كان', 'يكون', 'تكون', 'اذا', 'لو', 'ثم', 'قد', 'لا', 'لم', 'لن', 'كل', 'بعض', 'اي',
        'يمكن', 'يمكنني', 'ممكن', 'بقدر', 'فيني', 'هناك', 'هنا', 'الان', 'لكم', 'لنا', 'معي', 'بها', 'فيها', 'منها', 'عليها', 'له', 'لها', 'به', 'فيه', 'عليه', 'منه', 'حول', 'بين', 'حتي', 'لكن', 'بس',
        'the', 'a', 'an', 'is', 'are', 'how', 'what', 'when', 'where', 'why', 'can', 'could', 'i', 'my', 'me', 'to', 'of', 'for', 'in', 'on', 'do', 'does', 'it', 'and', 'or', 'with', 'about', 'please', 'there'];

    public static function norm(string $s): string
    {
        $s = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '،' => ' ', '؟' => ' ', '؛' => ' ']);
        $s = TextExtractor::norm($s);
        $s = preg_replace('/[^\p{L}\p{N}\/\-:\s]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** جذر خفيف: يحذف «ال» وحروف العطف والجر الملتصقة، واللواحق الشائعة، دون أن يقل عن 3 أحرف */
    public static function stem(string $w): string
    {
        foreach (['وال', 'بال', 'كال', 'فال', 'لل', 'ال'] as $p) if (mb_strlen($w) - mb_strlen($p) >= 3 && str_starts_with($w, $p)) { $w = mb_substr($w, mb_strlen($p)); break; }
        if (mb_strlen($w) >= 5 && in_array(mb_substr($w, 0, 1), ['و', 'ف', 'ب', 'ل'], true)) $w = mb_substr($w, 1);
        foreach (['يات', 'ات', 'ين', 'ون', 'ها', 'هم', 'كم', 'نا', 'يه', 'ه', 'ي'] as $s) if (mb_strlen($w) - mb_strlen($s) >= 3 && str_ends_with($w, $s)) { $w = mb_substr($w, 0, -mb_strlen($s)); break; }
        if (preg_match('/^[a-z]+$/', $w) && strlen($w) > 4) $w = preg_replace('/(ing|ed|es|s)$/', '', $w);
        return $w;
    }

    /** @return string[] كلمات البحث (جذور مميزة، بلا الكلمات الشائعة) */
    public static function terms(string $s): array
    {
        $out = [];
        foreach (explode(' ', self::norm($s)) as $w) {
            if (mb_strlen($w) < 2 || in_array($w, self::STOP, true) || preg_match('/^\d+$/', $w)) continue;
            $st = self::stem($w);
            if (mb_strlen($st) >= 2 && ! in_array($st, self::STOP, true)) $out[$st] = true;
        }
        return array_keys($out);
    }

    /** يحتوي النص المطبَّع على أي من العبارات (مطبَّعة سلفاً) ككلمة أو بداية كلمة */
    public static function has(string $norm, array $phrases): bool
    {
        foreach ($phrases as $p) if (preg_match('/(^|\s|[وبلف])'.preg_quote($p, '/').'/u', $norm)) return true;
        return false;
    }
}
