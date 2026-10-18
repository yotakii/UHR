<?php

namespace App\Support;

use App\Exceptions\ApiException;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** التوقيع الإلكتروني: صورة PNG (data URL من لوح التوقيع) تُحفظ ملفاً خاصاً، وتُعاد مضمّنة فقط — لا رابط عام */
class Signature
{
    public const MAX_BYTES = 300_000;

    /** يرفض التوقيع الفارغ أو غير الصالح تحت الحقل signature */
    public static function store(?string $dataUrl, string $ar = 'التوقيع مطلوب.', string $en = 'Your signature is required.'): StoredFile
    {
        $bad = fn () => ApiException::field('signature', L::t($ar, $en), 'signature_required');
        if (! $dataUrl || ! preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) throw $bad();
        $png = base64_decode($m[1], true);
        if ($png === false || strlen($png) < 100 || strlen($png) > self::MAX_BYTES || ! str_starts_with($png, "\x89PNG")) throw $bad();
        $path = 'signatures/'.now()->format('Y/m').'/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $png);
        return StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => 'signature.png', 'size' => strlen($png), 'mime' => 'image/png', 'uploaded_by' => auth()->id()]);
    }

    public static function dataUrl(?StoredFile $f): ?string
    {
        if (! $f || ! Storage::disk($f->disk)->exists($f->path)) return null;
        return 'data:'.$f->mime.';base64,'.base64_encode(Storage::disk($f->disk)->get($f->path));
    }
}
