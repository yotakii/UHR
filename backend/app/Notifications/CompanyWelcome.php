<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** حساب المدير الأول لشركة جديدة على المنصة — كلمة مرور مؤقتة تُغيَّر عند أول دخول */
class CompanyWelcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $companyAr, public string $companyEn, public string $password) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/';
        return (new MailMessage)->subject("حسابك على UHR · Your UHR account — {$this->companyEn}")->view('mail.notice', [
            'company' => ['ar' => $this->companyAr, 'en' => $this->companyEn], 'url' => $url, 'name' => '', 'ref' => $this->password,
            'ar' => ['title' => 'تم تفعيل شركتك على UHR', 'body' => "أُنشئ حساب مدير النظام لشركة {$this->companyAr}. البريد: {$notifiable->email} — كلمة المرور المؤقتة أدناه.", 'hint' => 'ستُطلب منك كلمة مرور جديدة عند أول دخول.', 'action' => 'الدخول إلى النظام'],
            'en' => ['title' => 'Your company is live on UHR', 'body' => "An administrator account was created for {$this->companyEn}. Email: {$notifiable->email} — temporary password above.", 'hint' => 'You will be asked to choose a new password at first sign-in.', 'action' => 'Sign in'],
        ]);
    }
}
