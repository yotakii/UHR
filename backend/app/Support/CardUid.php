<?php

namespace App\Support;

/**
 * رقم بطاقة NFC (analyse/findByCard في الديمو): القارئ يرسل الرقم ست عشرياً (04:A1:…) أو عشرياً (UHR-NFC-Bridge يكتب الأرقام)،
 * وبعض القرّاء يعكسون ترتيب البايتات. التخزين بصيغة HEX مع «:»، والمطابقة على كل الصيغ.
 */
class CardUid
{
    /** نص بلا فواصل وبأحرف كبيرة */
    public static function norm(string $raw): string
    {
        return strtoupper(preg_replace('/[^0-9a-zA-Z]/', '', $raw));
    }

    /** أرقام فقط ← عشري (كما في الديمو)؛ وإلا ست عشري. null إن لم يكن رقماً صالحاً */
    public static function hex(string $raw): ?string
    {
        $n = self::norm($raw);
        if ($n === '') return null;
        if (ctype_digit($n)) $n = self::decToHex($n);
        if (! ctype_xdigit($n)) return null;
        if (strlen($n) % 2) $n = '0'.$n;
        return $n;
    }

    /** صيغة التخزين: 04:A1:B2:C3:D4 (4–10 بايت) */
    public static function format(string $raw): ?string
    {
        $h = self::hex($raw);
        return $h && strlen($h) >= 8 && strlen($h) <= 20 ? implode(':', str_split($h, 2)) : null;
    }

    /** كل الصيغ المحتملة (بلا فواصل) للمطابقة مع nfc_uid المخزّن */
    public static function keys(string $raw): array
    {
        $n = self::norm($raw);
        $h = self::hex($raw);
        $out = [$n];
        if ($h) { $out[] = $h; $out[] = implode('', array_reverse(str_split($h, 2))); }
        if (ctype_xdigit($n) && strlen($n) % 2 === 0) $out[] = implode('', array_reverse(str_split($n, 2)));
        return array_values(array_unique(array_filter($out)));
    }

    /** تقييد استعلام الموظفين بمن تطابق بطاقته أي صيغة للرقم */
    public static function match(\Illuminate\Database\Eloquent\Builder $q, string $raw): \Illuminate\Database\Eloquent\Builder
    {
        $keys = self::keys($raw);
        return $q->whereNotNull('nfc_uid')->whereIn(\Illuminate\Support\Facades\DB::raw("upper(replace(nfc_uid, ':', ''))"), $keys ?: ['-']);
    }

    /** تحويل عشري طويل إلى ست عشري بلا bcmath/gmp */
    private static function decToHex(string $dec): string
    {
        $dec = ltrim($dec, '0');
        if ($dec === '') return '0';
        $hex = '';
        while ($dec !== '' && $dec !== '0') {
            $rem = 0; $q = '';
            foreach (str_split($dec) as $d) { $cur = $rem * 10 + (int) $d; $q .= intdiv($cur, 16); $rem = $cur % 16; }
            $hex = dechex($rem).$hex;
            $dec = ltrim($q, '0');
        }
        return strtoupper($hex);
    }
}
