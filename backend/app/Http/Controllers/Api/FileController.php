<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StoredFile;
use App\Services\Files\FileAccess;
use App\Support\Audit;
use App\Support\L;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** تنزيل ملف من المستودع: صلاحية الوحدة أو ملكية الملف، وكل وصول يُسجَّل (من، أي ملف، متى — file.view للعرض المضمّن، file.download للتنزيل) */
class FileController extends Controller
{
    public function show(Request $request, int $id): StreamedResponse
    {
        $f = StoredFile::allCompanies()->findOrFail($id);
        $owner = Tenant::run($f->company_id, fn () => FileAccess::owner($f));
        abort_unless(FileAccess::allowed($request->user(), $f, $owner), 403, L::t('لا تملك صلاحية الوصول لهذا الملف.', 'You are not allowed to access this file.'));
        abort_unless(Storage::disk($f->disk)->exists($f->path), 404, L::t('الملف غير موجود.', 'File not found.'));
        $inline = $request->boolean('inline') && preg_match('#^(image/|application/pdf)#', (string) $f->mime);
        Tenant::run($f->company_id, fn () => Audit::record($inline ? 'file.view' : 'file.download', $f, null, ['name' => $f->name, 'of' => $owner['label']]));
        return Storage::disk($f->disk)->response($f->path, $f->name, ['Content-Type' => $f->mime ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store'], $inline ? 'inline' : 'attachment');
    }
}
