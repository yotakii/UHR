<?php

namespace App\Notifications;

use App\Models\MessageTemplate;
use App\Support\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * رسائل مسار التوظيف للمتقدم (دعوة المقابلة، صدور عرض العمل، التعيين…) بالقالب من M16 إن وُجد (:job :ref)،
 * وإلا بالنص الافتراضي. الرابط لحساب المتقدم.
 */
class CandidateMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public ?int $companyId;

    /** $text = ['ar' => [title, body, hint, action], 'en' => [...]] */
    public function __construct(public string $template, public array $text, public string $jobAr, public string $jobEn, public string $ref, public array $company, public string $path = '/my')
    {
        $this->companyId = Tenant::id();
    }

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $tpl = Tenant::run($this->companyId, fn () => MessageTemplate::where('key', $this->template)->where('channel', 'email')->first());
        $fill = fn (?string $s, string $job, string $fallback) => strtr($s ?: $fallback, [':job' => $job, ':ref' => $this->ref]);
        [$ta, $ba, $ha, $aa] = $this->text['ar']; [$te, $be, $he, $ae] = $this->text['en'];
        return (new MailMessage)->subject("{$ta} · {$te} — {$this->company['en']}")->view('mail.notice', [
            'company' => $this->company, 'url' => rtrim(config('app.frontend_url'), '/').$this->path,
            'name' => method_exists($notifiable, 'displayName') ? $notifiable->displayName('ar') : '',
            'ar' => ['title' => $ta, 'body' => $fill($tpl?->body_ar, $this->jobAr, $ba), 'hint' => $ha, 'action' => $aa],
            'en' => ['title' => $te, 'body' => $fill($tpl?->body_en, $this->jobEn, $be), 'hint' => $he, 'action' => $ae],
        ]);
    }
}
