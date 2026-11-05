<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\JobOpening;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;

/**
 * معاينة الروابط عند مشاركتها (ملاحظة الاختبار 33): برامج المراسلة والشبكات (واتساب، فيسبوك، تلغرام، لينكدإن…) لا تشغّل
 * الواجهة، فيوجّهها Nginx إلى هنا لتقرأ وسوم og:* لنفس الرابط — العنوان، الوصف، وصورة عريضة 1200×630 فيها شعار الشركة.
 */
class SharePreview
{
    public const W = 1200;
    public const H = 630;

    /** وسوم الصفحة حسب المسار: /jobs/{slug}/{code}، /c/{slug}، /apply/{slug}/{code}، وغيرها = البوابة */
    public function meta(string $path): array
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $p = Settings::platform('portal');
        $portal = ['title' => 'UHR — '.($p['hero_title_ar'] ?? 'فرص العمل'), 'description' => $p['hero_text_ar'] ?? '', 'image' => "{$base}/api/public/og-image.png", 'url' => $base.$path];
        $seg = array_values(array_filter(explode('/', parse_url($path, PHP_URL_PATH) ?: '/'), 'strlen'));
        if (in_array($seg[0] ?? '', ['jobs', 'apply'], true) && count($seg) >= 3) {
            $job = JobOpening::allCompanies()->with(['company', 'location'])->whereHas('company', fn ($q) => $q->where('slug', $seg[1])->where('status', 'active'))
                ->where('code', rawurldecode($seg[2]))->whereIn('visibility', ['company', 'public'])->first();
            if ($job) {
                $c = $job->company;
                return ['title' => "{$job->title_ar} — {$c->name_ar}", 'url' => "{$base}/jobs/{$c->slug}/".rawurlencode($job->code),
                    'description' => trim(mb_substr(preg_replace('/\s+/u', ' ', (string) $job->summary_ar), 0, 220).' · '.$job->location?->name_ar.' · '.(JobOpening::TYPES[$job->employment_type]['ar'] ?? '')),
                    'image' => "{$base}/api/public/og-image/{$c->slug}.png?v=".($c->updated_at?->timestamp ?? 0)];
            }
        }
        if (($seg[0] ?? '') === 'c' && isset($seg[1]) && ($c = Company::where('slug', $seg[1])->where('status', 'active')->first())) {
            return ['title' => "فرص العمل في {$c->name_ar}", 'url' => "{$base}/c/{$c->slug}", 'description' => mb_substr((string) ($c->about_ar ?: $portal['description']), 0, 220),
                'image' => "{$base}/api/public/og-image/{$c->slug}.png?v=".($c->updated_at?->timestamp ?? 0)];
        }
        return $portal;
    }

    public function html(string $path): string
    {
        $m = $this->meta($path);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>'.$e($m['title']).'</title>'
            .'<meta name="description" content="'.$e($m['description']).'">'
            .'<meta property="og:type" content="website"><meta property="og:site_name" content="UHR">'
            .'<meta property="og:title" content="'.$e($m['title']).'"><meta property="og:description" content="'.$e($m['description']).'">'
            .'<meta property="og:url" content="'.$e($m['url']).'"><meta property="og:image" content="'.$e($m['image']).'">'
            .'<meta property="og:image:width" content="'.self::W.'"><meta property="og:image:height" content="'.self::H.'"><meta property="og:locale" content="ar_SY">'
            .'<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="'.$e($m['title']).'"><meta name="twitter:image" content="'.$e($m['image']).'">'
            .'<link rel="canonical" href="'.$e($m['url']).'"></head><body><h1>'.$e($m['title']).'</h1><p>'.$e($m['description']).'</p><a href="'.$e($m['url']).'">'.$e($m['url']).'</a></body></html>';
    }

    /** صورة المعاينة العريضة: خلفية بلون العلامة وبطاقة بيضاء في وسطها الشعار (مخزّنة حتى يتغير الشعار) */
    public function image(?Company $c): string
    {
        $src = $c?->logo_path && Storage::disk('public')->exists($c->logo_path) ? Storage::disk('public')->path($c->logo_path) : resource_path('og/uhr-logo.png');
        $key = 'og/'.($c ? $c->id : 'portal').'-'.substr(sha1($src.'|'.@filemtime($src).'|'.($c?->updated_at?->timestamp ?? 0)), 0, 12).'.png';
        $disk = Storage::disk('local');
        if ($disk->exists($key)) return $disk->path($key);

        $im = imagecreatetruecolor(self::W, self::H);
        imagealphablending($im, true);
        // خلفية متدرجة بلون العلامة (تركوازي داكن)
        for ($y = 0; $y < self::H; $y++) {
            $t = $y / self::H;
            imagefilledrectangle($im, 0, $y, self::W, $y, imagecolorallocate($im, (int) (14 + 10 * $t), (int) (60 + 50 * $t), (int) (72 + 40 * $t)));
        }
        $white = imagecolorallocate($im, 255, 255, 255);
        [$cx, $cy, $cw, $ch, $r] = [150, 115, 900, 400, 36];
        imagefilledrectangle($im, $cx + $r, $cy, $cx + $cw - $r, $cy + $ch, $white);
        imagefilledrectangle($im, $cx, $cy + $r, $cx + $cw, $cy + $ch - $r, $white);
        foreach ([[$cx + $r, $cy + $r], [$cx + $cw - $r, $cy + $r], [$cx + $r, $cy + $ch - $r], [$cx + $cw - $r, $cy + $ch - $r]] as [$x, $y]) imagefilledellipse($im, $x, $y, $r * 2, $r * 2, $white);
        $logo = @imagecreatefromstring((string) file_get_contents($src));
        if ($logo) {
            $lw = imagesx($logo); $lh = imagesy($logo);
            $s = min(($cw - 160) / $lw, ($ch - 120) / $lh);
            $nw = (int) round($lw * $s); $nh = (int) round($lh * $s);
            imagecopyresampled($im, $logo, (int) ($cx + ($cw - $nw) / 2), (int) ($cy + ($ch - $nh) / 2), 0, 0, $nw, $nh, $lw, $lh);
            imagedestroy($logo);
        }
        // شريط سفلي بلون العلامة
        imagefilledrectangle($im, 0, self::H - 14, self::W, self::H, imagecolorallocate($im, 32, 178, 160));
        ob_start(); imagepng($im, null, 6); $png = (string) ob_get_clean(); imagedestroy($im);
        $disk->put($key, $png);
        return $disk->path($key);
    }
}
