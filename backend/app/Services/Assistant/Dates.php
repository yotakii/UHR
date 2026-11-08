<?php

namespace App\Services\Assistant;

use Illuminate\Support\Carbon;

/**
 * قراءة التواريخ والمدد من كلام الموظف (عربي فصيح/شامي/إنكليزي) بقواعد ثابتة — أدق وأسرع من النموذج:
 * اليوم، غداً/بكرا، بعد غد، أمس، أيام الأسبوع (القادم/الماضي)، 12/11، 2026-11-12، «12 تشرين الثاني»، «12 نوفمبر»،
 * «من … إلى …»، «لمدة يومين/3 أيام»، «ساعتين/3 ساعات». يعيد التواريخ بترتيب ورودها.
 */
class Dates
{
    private const MONTHS = [
        'كانون الثاني' => 1, 'كانون الاول' => 12, 'تشرين الاول' => 10, 'تشرين الثاني' => 11, 'شباط' => 2, 'اذار' => 3, 'نيسان' => 4, 'ايار' => 5, 'حزيران' => 6, 'تموز' => 7, 'ايلول' => 9, 'اب' => 8,
        'يناير' => 1, 'فبراير' => 2, 'مارس' => 3, 'ابريل' => 4, 'مايو' => 5, 'يونيو' => 6, 'يوليو' => 7, 'اغسطس' => 8, 'سبتمبر' => 9, 'اكتوبر' => 10, 'نوفمبر' => 11, 'ديسمبر' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];
    /** Carbon: 0 = الأحد */
    private const DAYS = ['الاحد' => 0, 'الاثنين' => 1, 'الاتنين' => 1, 'الثلاثاء' => 2, 'التلات' => 2, 'التلاتا' => 2, 'الاربعاء' => 3, 'الاربعا' => 3, 'الخميس' => 4, 'الجمعه' => 5, 'السبت' => 6,
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
    private const NUM = ['واحد' => 1, 'واحده' => 1, 'اثنين' => 2, 'اثنان' => 2, 'تنين' => 2, 'ثلاث' => 3, 'ثلاثه' => 3, 'تلات' => 3, 'تلاته' => 3, 'اربع' => 4, 'اربعه' => 4, 'خمس' => 5, 'خمسه' => 5,
        'ست' => 6, 'سته' => 6, 'سبع' => 7, 'سبعه' => 7, 'ثمان' => 8, 'ثماني' => 8, 'ثمانيه' => 8, 'تمن' => 8, 'تسع' => 9, 'تسعه' => 9, 'عشر' => 10, 'عشره' => 10,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];

    /** @return array{dates: string[], days: ?int, hours: ?int} */
    public static function parse(string $text, ?Carbon $today = null): array
    {
        $t = Text::norm($text);
        $today = ($today ?? now())->copy()->startOfDay();
        $found = [];   // [pos, date]
        $add = function (int $pos, Carbon $d) use (&$found) { $found[] = [$pos, $d->toDateString()]; };

        // 2026-11-12
        preg_match_all('/\b(20\d\d)-(\d{1,2})-(\d{1,2})\b/u', $t, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => $x) if (checkdate((int) $m[2][$i][0], (int) $m[3][$i][0], (int) $m[1][$i][0])) $add($x[1], Carbon::create((int) $m[1][$i][0], (int) $m[2][$i][0], (int) $m[3][$i][0]));
        $t2 = preg_replace('/\b20\d\d-\d{1,2}-\d{1,2}\b/u', ' ', $t);
        // 12/11 أو 12/11/2026 (يوم/شهر)
        preg_match_all('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/u', $t2, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => $x) {
            $d = (int) $m[1][$i][0]; $mo = (int) $m[2][$i][0]; $y = isset($m[3][$i]) && $m[3][$i][0] !== '' ? (int) $m[3][$i][0] : null;
            if ($y !== null && $y < 100) $y += 2000;
            if ($date = self::make($d, $mo, $y, $today)) $add($x[1], $date);
        }
        // 12 تشرين الثاني [2026] / 12 نوفمبر / november 12
        $names = implode('|', array_map(fn ($k) => preg_quote($k, '/'), array_keys(self::MONTHS)));
        preg_match_all('/\b(\d{1,2})\s+(?:من\s+)?(?:شهر\s+)?('.$names.')(?:\s+(20\d\d))?(?![\p{L}])/u', $t, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => $x) if ($date = self::make((int) $m[1][$i][0], self::MONTHS[$m[2][$i][0]], isset($m[3][$i]) && $m[3][$i][0] !== '' ? (int) $m[3][$i][0] : null, $today)) $add($x[1], $date);
        preg_match_all('/\b('.$names.')\s+(\d{1,2})(?:\s*,?\s*(20\d\d))?\b/u', $t, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => $x) if (preg_match('/^[a-z]+$/', $m[1][$i][0]) && ($date = self::make((int) $m[2][$i][0], self::MONTHS[$m[1][$i][0]], isset($m[3][$i]) && $m[3][$i][0] !== '' ? (int) $m[3][$i][0] : null, $today))) $add($x[1], $date);

        // كلمات نسبية
        foreach (['بعد بكرا' => 2, 'بعد بكره' => 2, 'بعد غد' => 2, 'بعد الغد' => 2, 'day after tomorrow' => 2] as $w => $n) if (($p = mb_strpos($t, $w)) !== false) { $add($p, $today->copy()->addDays($n)); $t = str_replace($w, str_repeat(' ', mb_strlen($w)), $t); }
        foreach (['اليوم' => 0, 'today' => 0, 'غدا' => 1, 'بكرا' => 1, 'بكره' => 1, 'tomorrow' => 1, 'امس' => -1, 'مبارح' => -1, 'البارحه' => -1, 'yesterday' => -1] as $w => $n) {
            if (preg_match('/(^|\s)[وب]?'.$w.'(\s|$)/u', $t, $mm, PREG_OFFSET_CAPTURE)) $add($mm[0][1], $today->copy()->addDays($n));
        }
        // أيام الأسبوع
        $dn = implode('|', array_keys(self::DAYS));
        preg_match_all('/(?:(next|last)\s+)?(?:يوم\s+)?\b('.$dn.')\b(?:\s+(القادم|الجاي|الجايه|المقبل|الماضي|اللي فات))?/u', $t, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as $i => $x) {
            $dow = self::DAYS[$m[2][$i][0]];
            $q = ($m[1][$i][0] ?? '') ?: ($m[3][$i][0] ?? '');
            $past = in_array($q, ['last', 'الماضي', 'اللي فات'], true);
            $strict = in_array($q, ['next', 'القادم', 'الجاي', 'الجايه', 'المقبل'], true);
            $d = $today->copy();
            if ($past) { do { $d->subDay(); } while ($d->dayOfWeek !== $dow); }
            else { if ($strict || $d->dayOfWeek !== $dow) { do { $d->addDay(); } while ($d->dayOfWeek !== $dow); } }
            $add($x[1], $d);
        }

        usort($found, fn ($a, $b) => $a[0] <=> $b[0]);
        $dates = array_values(array_unique(array_column($found, 1)));

        $num = '(\d{1,3}|'.implode('|', array_keys(self::NUM)).')';
        $days = null; $hours = null;
        if (preg_match('/\b'.$num.'\s+(?:ايام|يوم|days?)\b/u', $t, $mm)) $days = self::n($mm[1]);
        elseif (preg_match('/(^|\s)(يومين|يومان)(\s|$)/u', $t)) $days = 2;
        elseif (preg_match('/(لمده|مدة|for)\s+(يوم|day)\b/u', $t)) $days = 1;
        if (preg_match('/\b'.$num.'\s+(?:ساعات|ساعه|hours?)\b/u', $t, $mm)) $hours = self::n($mm[1]);
        elseif (preg_match('/(^|\s)(ساعتين|ساعتان)(\s|$)/u', $t)) $hours = 2;
        elseif (preg_match('/(^|\s)(ساعه|hour)(\s|$)/u', $t)) $hours = 1;
        return ['dates' => $dates, 'days' => $days, 'hours' => $hours];
    }

    private static function n(string $s): int { return ctype_digit($s) ? (int) $s : (self::NUM[$s] ?? 0); }

    /** تاريخ بلا سنة: هذه السنة، أو القادمة إن مضى عليه أكثر من شهرين */
    private static function make(int $d, int $m, ?int $y, Carbon $today): ?Carbon
    {
        $y ??= $today->year;
        if (! checkdate($m, $d, $y)) return null;
        $c = Carbon::create($y, $m, $d)->startOfDay();
        if (func_num_args() && $c->lt($today->copy()->subMonths(2)) && $y === $today->year) $c->addYear();
        return $c;
    }
}
