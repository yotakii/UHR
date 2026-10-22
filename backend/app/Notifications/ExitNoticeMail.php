<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** إشعار جهة خارجية بانتهاء خدمة موظف (D-17) — يُرسل فقط بموافقة الموظف الموثقة عند طلب شهادة الراتب */
class ExitNoticeMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $companyAr, public string $companyEn, public string $nameAr, public string $nameEn, public string $lastDay, public string $to) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("إشعار بانتهاء خدمة · End of service notice — {$this->companyEn}")->view('mail.notice', [
            'company' => ['ar' => $this->companyAr, 'en' => $this->companyEn], 'url' => null, 'name' => $this->to, 'ref' => $this->lastDay,
            'ar' => ['title' => 'إشعار بانتهاء خدمة', 'body' => "نعلمكم بأن خدمة {$this->nameAr} لدى {$this->companyAr} انتهت بتاريخ {$this->lastDay}، وذلك بناءً على موافقته المسبقة عند طلب شهادة الراتب الموجهة إليكم.", 'hint' => 'هذه رسالة آلية من نظام الموارد البشرية.', 'action' => null],
            'en' => ['title' => 'End of service notice', 'body' => "We inform you that {$this->nameEn}'s service with {$this->companyEn} ended on {$this->lastDay}, as they consented when requesting the salary certificate addressed to you.", 'hint' => 'This is an automated message from the HR system.', 'action' => null],
        ]);
    }
}
