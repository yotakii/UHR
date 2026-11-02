<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MailLog;
use App\Support\Audit;
use App\Support\L;
use App\Support\MailSetup;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * البريد الصادر (11e): إعداد SMTP في لوحة المنصة + رسالة تجريبية، وسجل الرسائل (المنصة: كل الشركات · الشركة: رسائلها فقط).
 */
class MailLogController extends Controller
{
    public function settings(): JsonResponse
    {
        $s = Settings::platformMerged('mail');
        return response()->json(['data' => [
            'host' => $s['host'], 'port' => (int) $s['port'], 'encryption' => $s['encryption'], 'username' => $s['username'], 'password_set' => $s['password'] !== '',
            'from_address' => $s['from_address'], 'from_name' => $s['from_name'], 'log_days' => (int) $s['log_days'],
            'source' => trim($s['host']) !== '' ? 'settings' : 'server',
            'server' => ['host' => config('mail.mailers.smtp.host'), 'from' => config('mail.from.address')],
            'stats' => ['sent' => MailLog::where('status', 'sent')->where('created_at', '>=', now()->subDay())->count(),
                'failed' => MailLog::where('status', 'failed')->where('created_at', '>=', now()->subDay())->count()],
        ]]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'host' => ['nullable', 'string', 'max:190'], 'port' => ['required', 'integer', 'between:1,65535'], 'encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['nullable', 'string', 'max:190'], 'password' => ['nullable', 'string', 'max:300'], 'clear_password' => ['nullable', 'boolean'],
            'from_address' => ['required_with:host', 'nullable', 'email', 'max:190'], 'from_name' => ['required', 'string', 'max:80'], 'log_days' => ['required', 'integer', 'between:7,730'],
        ], [], ['host' => L::t('الخادم', 'Server'), 'from_address' => L::t('عنوان المرسل', 'From address'), 'from_name' => L::t('اسم المرسل', 'From name')]);
        $old = Settings::platformMerged('mail');
        $password = $old['password'];
        if (! empty($d['clear_password'])) $password = '';
        elseif (($d['password'] ?? '') !== '') $password = Crypt::encryptString($d['password']);
        $new = ['host' => trim((string) ($d['host'] ?? '')), 'port' => (int) $d['port'], 'encryption' => $d['encryption'], 'username' => trim((string) ($d['username'] ?? '')),
            'password' => $password, 'from_address' => strtolower(trim((string) ($d['from_address'] ?? ''))), 'from_name' => trim($d['from_name']), 'log_days' => (int) $d['log_days']];
        Settings::putPlatform('mail', $new);
        MailSetup::apply(true);
        $mask = fn ($s) => ['password' => $s['password'] !== '' ? '••••' : ''] + $s;
        Audit::record('platform.mail', null, $mask($old), $mask($new));
        return $this->settings();
    }

    /** رسالة تجريبية تُرسل الآن (بلا طابور) — يظهر الخطأ كما هو إن فشلت */
    public function test(Request $request): JsonResponse
    {
        $to = $request->validate(['to' => ['required', 'email']])['to'];
        MailSetup::apply(true);
        try {
            Mail::html('<div dir="rtl" style="font-family:Tahoma,Arial">هذه رسالة تجريبية من نظام الموارد البشرية — البريد الصادر يعمل.<br><span dir="ltr">This is a test message — outgoing mail works.</span></div>',
                fn ($m) => $m->to($to)->subject('رسالة تجريبية · Test message'));
        } catch (\Throwable $e) {
            MailSetup::failed($e);
            Audit::record('platform.mail_test', null, null, ['to' => $to, 'ok' => false]);
            return response()->json(['message' => L::t('تعذّر الإرسال: ', 'Sending failed: ').mb_substr($e->getMessage(), 0, 400), 'code' => 'mail_failed'], 422);
        }
        Audit::record('platform.mail_test', null, null, ['to' => $to, 'ok' => true]);
        return response()->json(['message' => L::t('أُرسلت الرسالة. تحقق من صندوق الوارد (وصندوق الرسائل غير المرغوبة).', 'Sent. Check the inbox (and the spam folder).')]);
    }

    public function platform(Request $request): JsonResponse
    {
        $rows = $this->query($request)->with('company:id,name_ar,name_en')->paginate(50);
        return response()->json(['data' => collect($rows->items())->map(fn (MailLog $m) => $m->row() + ['company' => $m->company ? ['ar' => $m->company->name_ar, 'en' => $m->company->name_en] : null]),
            'meta' => ['page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function company(Request $request): JsonResponse
    {
        $rows = $this->query($request)->where('company_id', Tenant::id())->paginate(50);
        return response()->json(['data' => collect($rows->items())->map(fn (MailLog $m) => $m->row()),
            'meta' => ['page' => $rows->currentPage(), 'pages' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    private function query(Request $request)
    {
        $q = trim((string) $request->query('q', '')); $status = $request->query('status');
        return MailLog::query()->when(in_array($status, ['sending', 'sent', 'failed'], true), fn ($w) => $w->where('status', $status))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('to', 'ilike', "%$q%")->orWhere('subject', 'ilike', "%$q%")))
            ->when($request->query('company'), fn ($w, $c) => $w->where('company_id', (int) $c))
            ->orderByDesc('id');
    }
}
