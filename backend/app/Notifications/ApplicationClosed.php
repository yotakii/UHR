<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** إشعار مهذب بانتهاء مراجعة الطلب (عدم الاختيار) — يُستخدم للرفض التلقائي ومن M03 */
class ApplicationClosed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $jobAr, public string $jobEn, public string $companyAr, public string $companyEn) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim(config('app.frontend_url'), '/').'/jobs';
        return (new MailMessage)->subject("نتيجة طلبك · Your application — {$this->companyEn}")->view('mail.notice', [
            'company' => ['ar' => $this->companyAr, 'en' => $this->companyEn], 'url' => $url,
            'name' => method_exists($notifiable, 'displayName') ? $notifiable->displayName('ar') : '',
            'ar' => ['title' => 'انتهت مراجعة طلبك', 'body' => "شكراً لاهتمامك بوظيفة {$this->jobAr} لدى {$this->companyAr}. لم يتم اختيار طلبك لهذه الوظيفة.", 'hint' => 'يبقى حسابك فعّالاً، ويمكنك التقديم على فرص أخرى تناسبك.', 'action' => 'تصفّح فرص العمل'],
            'en' => ['title' => 'Your application review is complete', 'body' => "Thank you for your interest in {$this->jobEn} at {$this->companyEn}. You were not selected for this role.", 'hint' => 'Your account stays active, and you can apply to other openings that suit you.', 'action' => 'Browse jobs'],
        ]);
    }
}
