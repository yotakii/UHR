<?php

namespace App\Services\Policies;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * استخراج نص الوثيقة للبحث (D-26): DOCX من word/document.xml (فقرة لكل w:p)، PDF عبر pdftotext (poppler-utils)، TXT كما هو.
 * إن تعذّر الاستخراج يُرجع null ويضيف HR النص يدوياً.
 */
class TextExtractor
{
    public const EXTENSIONS = ['pdf', 'docx', 'txt'];

    public function extract(string $path, string $ext): ?string
    {
        $text = match (strtolower($ext)) {
            'docx' => $this->docx($path),
            'pdf' => $this->pdf($path),
            'txt' => @file_get_contents($path) ?: null,
            default => null,
        };
        if ($text === null) return null;
        $text = trim(preg_replace("/\r\n?/", "\n", $text));
        return $text === '' ? null : $text;
    }

    private function docx(string $path): ?string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return null;
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! $xml) return null;
        $out = [];
        preg_match_all('#<w:p[ >].*?</w:p>#s', $xml, $ps);
        foreach ($ps[0] as $p) {
            $p = preg_replace(['#<w:tab/>#', '#<w:br[^>]*/>#'], ["\t", "\n"], $p);
            preg_match_all('#<w:t(?: [^>]*)?>(.*?)</w:t>#s', $p, $ts);
            $line = trim(html_entity_decode(implode('', $ts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($line !== '') $out[] = $line;
        }
        return implode("\n\n", $out);
    }

    private function pdf(string $path): ?string
    {
        $bin = (new ExecutableFinder())->find('pdftotext');
        if (! $bin) return null;
        $p = new Process([$bin, '-enc', 'UTF-8', '-nopgbrk', $path, '-']);
        $p->setTimeout(60);
        $p->run();
        return $p->isSuccessful() ? $p->getOutput() : null;
    }

    /** فقرات: سطر فارغ يفصل الفقرات؛ نص بلا أسطر فارغة ← كل سطر فقرة. الحد 3000 فقرة × 4000 حرف */
    public static function paragraphs(string $text): array
    {
        $text = trim(preg_replace("/\r\n?/", "\n", $text));
        if ($text === '') return [];
        $parts = str_contains($text, "\n\n") ? preg_split("/\n\s*\n/u", $text) : explode("\n", $text);
        $out = [];
        foreach ($parts as $p) {
            $p = trim(preg_replace('/[ \t]+/u', ' ', $p));
            if ($p === '') continue;
            foreach (mb_str_split($p, 4000) as $chunk) $out[] = $chunk;
            if (count($out) >= 3000) break;
        }
        return $out;
    }

    /** تطبيع للبحث: بلا تشكيل ولا تطويل، أ/إ/آ/ٱ ← ا، ى ← ي، ة ← ه، ؤ ← و، ئ ← ي، وحروف صغيرة */
    public static function norm(string $s): string
    {
        $s = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $s);
        $s = strtr($s, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي']);
        return mb_strtolower(preg_replace('/\s+/u', ' ', $s));
    }
}
