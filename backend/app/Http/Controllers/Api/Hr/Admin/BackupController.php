<?php

namespace App\Http\Controllers\Api\Hr\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\Admin\Backups;
use App\Services\Security\SalaryVault;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * النسخ الاحتياطي — للشركة (M16 /hr/settings/backups) وللمنصة (/platform/backups).
 * الشركة: نسخة بياناتها (zip) عند الطلب + حالة آخر نسخة كاملة للخادم. التنزيل يحوي الرواتب ← تحقق الهوية وإذن رواتب الشركة كلها.
 * المنصة: نسخة الخادم الكاملة (pg_dump) يدوياً أو آلياً يومياً، والإعدادات.
 */
class BackupController extends Controller
{
    public function __construct(private Backups $backups) {}

    public function index(Request $request): JsonResponse
    {
        $rows = Backup::with('creator')->where('company_id', Tenant::id())->orderByDesc('id')->limit(30)->get();
        $server = Backup::whereNull('company_id')->whereIn('status', ['ok', 'failed', 'running'])->orderByDesc('id')->first();
        $lastOk = Backup::whereNull('company_id')->where('status', 'ok')->orderByDesc('id')->first();
        return response()->json(['data' => $rows->map(fn ($b) => Backups::row($b))->values(),
            'server' => ['last' => $server ? Backups::row($server) : null, 'last_ok_at' => $lastOk?->finished_at?->toIso8601String(), 'schedule' => Settings::platformMerged('backup')],
            'settings' => Settings::merged('backup'), 'defaults' => Settings::DEFAULTS['backup'], 'can' => ['edit' => Perm::can($request->user(), 'settings', 'edit')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $b = $this->backups->company(Tenant::company(), $request->user());
        return response()->json(['data' => Backups::row($b->load('creator'))], $b->status === 'ok' ? 201 : 500);
    }

    public function download(Request $request, int $id): BinaryFileResponse
    {
        $b = Backup::where('company_id', Tenant::id())->findOrFail($id);
        SalaryVault::requireAll($request->user());
        $path = $this->backups->file($b);
        Audit::record('backup.downloaded', $b, null, ['kind' => 'company']);
        return response()->download($path, basename($path), ['Content-Type' => 'application/zip']);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $v = $request->validate(['keep' => ['required', 'integer', 'between:1,50'], 'include_files' => ['required', 'boolean']], [], ['keep' => L::t('عدد النسخ المحفوظة', 'Copies kept')]);
        $before = Settings::merged('backup');
        Settings::put('backup', ['keep' => (int) $v['keep'], 'include_files' => (bool) $v['include_files']]);
        Audit::record('backup.settings', null, $before, Settings::merged('backup'));
        return $this->index($request);
    }

    // ——— المنصة ———
    public function platform(): JsonResponse
    {
        $rows = Backup::with('creator')->whereNull('company_id')->orderByDesc('id')->limit(40)->get();
        return response()->json(['data' => $rows->map(fn ($b) => Backups::row($b))->values(), 'settings' => Settings::platformMerged('backup'), 'defaults' => Settings::PLATFORM_DEFAULTS['backup']]);
    }

    public function platformRun(Request $request): JsonResponse
    {
        $b = $this->backups->full($request->user());
        return response()->json(['data' => Backups::row($b->load('creator'))], $b->status === 'ok' ? 201 : 500);
    }

    public function platformDownload(Request $request, int $id): BinaryFileResponse
    {
        $b = Backup::whereNull('company_id')->findOrFail($id);
        SalaryVault::require($request->user());
        $path = $this->backups->file($b);
        Audit::record('backup.downloaded', $b, null, ['kind' => 'full']);
        return response()->download($path, basename($path), ['Content-Type' => 'application/octet-stream']);
    }

    public function platformSettings(Request $request): JsonResponse
    {
        $v = $request->validate(['enabled' => ['required', 'boolean'], 'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'], 'keep' => ['required', 'integer', 'between:1,90']],
            [], ['time' => L::t('وقت النسخ', 'Backup time'), 'keep' => L::t('عدد النسخ المحفوظة', 'Copies kept')]);
        $before = Settings::platformMerged('backup');
        Settings::putPlatform('backup', ['enabled' => (bool) $v['enabled'], 'time' => $v['time'], 'keep' => (int) $v['keep']]);
        Audit::record('backup.settings', null, $before, Settings::platformMerged('backup'));
        return $this->platform();
    }
}
