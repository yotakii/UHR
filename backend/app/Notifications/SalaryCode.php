<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** رمز التحقق لفتح بيانات الرواتب (SRS §4.4) — يُرسل فوراً (بلا طابور) لأن صلاحيته دقائق */
class SalaryCode extends Notification
{
    public function __construct(public string $code, public int $minutes, public string $companyAr, public string $companyEn) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("رمز التحقق {$this->code} · Verification code — {$this->companyEn}")->view('mail.notice', [
            'company' => ['ar' => $this->companyAr, 'en' => $this->companyEn], 'url' => null, 'name' => '', 'ref' => $this->code,
            'ar' => ['title' => 'رمز عرض بيانات الرواتب', 'body' => "استخدم هذا الرمز لعرض بيانات الرواتب، وهو صالح لمدة {$this->minutes} دقيقة ولمرة واحدة فقط.", 'hint' => 'إن لم تطلب هذا الرمز فتجاهل الرسالة وغيّر كلمة مرورك.', 'action' => ''],
            'en' => ['title' => 'Code to view salary data', 'body' => "Use this code to view salary data. It is valid for {$this->minutes} minutes and works once.", 'hint' => 'If you did not ask for this code, ignore this email and change your password.', 'action' => ''],
        ]);
    }
}
