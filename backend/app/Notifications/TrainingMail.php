<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** نسخة بريدية من إشعارات التدريب (ترشيح، تعهد للتوقيع، موافقة مدير، متابعة أثر، رمز تحقق) — ثنائية اللغة */
class TrainingMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param array{title:string,body:string,action:string} $ar */
    public function __construct(public array $ar, public array $en, public ?string $url, public array $company, public ?string $ref = null) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->ar['title'].' · '.$this->en['title'])->view('mail.notice', [
            'company' => $this->company, 'url' => $this->url, 'ref' => $this->ref,
            'name' => method_exists($notifiable, 'displayName') ? $notifiable->displayName('ar') : '',
            'ar' => $this->ar + ['hint' => ''], 'en' => $this->en + ['hint' => ''],
        ]);
    }
}
