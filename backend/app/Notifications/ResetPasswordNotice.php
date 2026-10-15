<?php

namespace App\Notifications;

use App\Support\Settings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** رابط إعادة تعيين كلمة المرور — بريد ثنائي اللغة يفتح شاشة #/reset في الواجهة */
class ResetPasswordNotice extends Notification
{
    public function __construct(public string $token) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function url(object $notifiable): string
    {
        return rtrim(config('app.frontend_url'), '/').'/#/reset?'.http_build_query(['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $company = ['ar' => Settings::company('short_ar'), 'en' => Settings::company('short_en')];
        return (new MailMessage)
            ->subject("إعادة تعيين كلمة المرور · Reset your password — {$company['en']}")
            ->view('mail.reset', [
                'url' => $this->url($notifiable),
                'minutes' => config('auth.passwords.users.expire', 60),
                'company' => $company,
                'name' => method_exists($notifiable, 'displayName') ? $notifiable->displayName('ar') : '',
            ]);
    }
}
