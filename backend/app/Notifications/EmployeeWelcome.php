<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** حساب موظف جديد (معالج إضافة موظف أو الاستيراد) — كلمة مرور مؤقتة يغيّرها عند أول دخول */
class EmployeeWelcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $companyAr, public string $companyEn, public string $code, public string $password) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("حسابك في بوابة الموظف · Your employee account — {$this->companyEn}")->view('mail.notice', [
            'company' => ['ar' => $this->companyAr, 'en' => $this->companyEn], 'url' => rtrim(config('app.frontend_url'), '/').'/login', 'name' => '', 'ref' => $this->password,
            'ar' => ['title' => "أهلاً بك في {$this->companyAr}", 'body' => "أُنشئ حسابك في بوابة الموظف. رقمك الوظيفي {$this->code}، والبريد {$notifiable->email}، وكلمة المرور المؤقتة أعلاه.", 'hint' => 'ستُطلب منك كلمة مرور جديدة عند أول دخول، ثم تجد وثائقك للتوقيع.', 'action' => 'الدخول إلى البوابة'],
            'en' => ['title' => "Welcome to {$this->companyEn}", 'body' => "Your employee portal account is ready. Employee ID {$this->code}, email {$notifiable->email}, temporary password above.", 'hint' => 'You will choose a new password at first sign-in, then find your documents to sign.', 'action' => 'Sign in'],
        ]);
    }
}
