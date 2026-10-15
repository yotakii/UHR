<?php

namespace App\Notifications;

use App\Models\MessageTemplate;
use App\Support\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** تأكيد استلام طلب التوظيف برقم المتابعة — نص الرسالة من قوالب M16 (application_received) */
class ApplicationReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $ref, public string $jobAr, public string $jobEn) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $tpl = MessageTemplate::where('key', 'application_received')->where('channel', 'email')->first();
        $fill = fn (?string $s, string $job, string $fallback) => strtr($s ?: $fallback, [':job' => $job, ':ref' => $this->ref]);
        $company = ['ar' => Settings::company('short_ar'), 'en' => Settings::company('short_en')];
        $url = rtrim(config('app.frontend_url'), '/').'/#/';
        return (new MailMessage)
            ->subject("استلمنا طلبك · Application received — {$this->ref}")
            ->view('mail.notice', [
                'company' => $company, 'url' => $url,
                'ar' => ['title' => 'استلمنا طلبك', 'body' => $fill($tpl?->body_ar, $this->jobAr, 'استلمنا طلبك على وظيفة :job. رقم المتابعة: :ref'), 'hint' => 'تابع حالة طلبك من حسابك ببريدك وكلمة المرور التي أنشأتها.', 'action' => 'متابعة حالة الطلب'],
                'en' => ['title' => 'We received your application', 'body' => $fill($tpl?->body_en, $this->jobEn, 'We received your application for :job. Reference: :ref'), 'hint' => 'Follow your application by signing in with your email and the password you created.', 'action' => 'Track my application'],
                'ref' => $this->ref,
                'name' => method_exists($notifiable, 'displayName') ? $notifiable->displayName('ar') : '',
            ]);
    }
}
