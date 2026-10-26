<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** حساب أنشأه مدير النظام أو أعاد تعيين كلمة مروره (M16) — كلمة مرور مؤقتة تُغيَّر عند أول دخول */
class AccountNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $companyAr, public string $companyEn, public string $password) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $new = $this->kind === 'created';
        return (new MailMessage)->subject(($new ? 'حسابك في النظام · Your account' : 'كلمة مرور مؤقتة · Temporary password')." — {$this->companyEn}")->view('mail.notice', [
            'company' => ['ar' => $this->companyAr, 'en' => $this->companyEn], 'url' => rtrim(config('app.frontend_url'), '/').'/login', 'name' => '', 'ref' => $this->password,
            'ar' => ['title' => $new ? "أُنشئ حسابك في {$this->companyAr}" : 'أُعيد تعيين كلمة مرورك', 'body' => "البريد: {$notifiable->email} — وكلمة المرور المؤقتة أعلاه.", 'hint' => 'ستُطلب منك كلمة مرور جديدة عند أول دخول. إن لم تطلب ذلك فراجع مدير النظام.', 'action' => 'الدخول'],
            'en' => ['title' => $new ? "Your {$this->companyEn} account is ready" : 'Your password was reset', 'body' => "Email: {$notifiable->email} — temporary password above.", 'hint' => 'You will choose a new password at first sign-in. If you did not expect this, contact your administrator.', 'action' => 'Sign in'],
        ]);
    }
}
