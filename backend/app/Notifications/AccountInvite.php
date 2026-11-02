<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * رابط تفعيل الحساب أو تعيين كلمة مرور جديدة (11e) — لا تُرسل كلمات مرور بالبريد أبداً.
 * kind: invite (حساب جديد) · employee (حساب بوابة الموظف) · company (مدير شركة جديدة) · reset (أعاد المدير تعيينها) · successor (نُقلت إليه صلاحيات)
 */
class AccountInvite extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $kind, public string $url, public int $hours, public string $companyAr, public string $companyEn, public ?string $code = null) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $reset = $this->kind === 'reset';
        $valid = $this->hours >= 48 && $this->hours % 24 === 0 ? ['ar' => ($this->hours / 24).' أيام', 'en' => ($this->hours / 24).' days'] : ['ar' => "{$this->hours} ساعة", 'en' => "{$this->hours} hours"];
        [$ta, $te, $ba, $be] = match ($this->kind) {
            'employee' => ["أهلاً بك في {$this->companyAr}", "Welcome to {$this->companyEn}",
                'أُنشئ حسابك في بوابة الموظف'.($this->code ? " برقمك الوظيفي {$this->code}" : '').'. اضغط الزر لتختار كلمة المرور وتدخل إلى البوابة، ثم تجد وثائقك للتوقيع.',
                'Your employee portal account is ready'.($this->code ? " (employee ID {$this->code})" : '').'. Use the button to choose your password and sign in.'],
            'company' => ["تم تفعيل {$this->companyAr} على النظام", "{$this->companyEn} is live",
                'أُنشئ حساب مدير النظام لشركتكم. اضغط الزر لتختار كلمة المرور وتبدأ إعداد الشركة.', 'An administrator account was created for your company. Use the button to choose your password and start the setup.'],
            'reset' => ['تعيين كلمة مرور جديدة', 'Set a new password',
                'طلب مدير النظام تعيين كلمة مرور جديدة لحسابك. اضغط الزر لتختارها. تبقى كلمة مرورك الحالية صالحة حتى تستخدم الرابط.', 'Your administrator asked you to set a new password. Use the button to choose it. Your current password keeps working until you use the link.'],
            'successor' => ['أُضيفت صلاحيات إلى حسابك', 'Permissions were added to your account',
                'نُقلت إلى حسابك صلاحيات جديدة. اضغط الزر لتعيين كلمة مرور جديدة قبل استخدامها.', 'New permissions were moved to your account. Use the button to set a new password before using them.'],
            default => ["أُنشئ حسابك في {$this->companyAr}", "Your {$this->companyEn} account is ready",
                'اضغط الزر لتختار كلمة المرور وتفعّل حسابك.', 'Use the button to choose your password and activate your account.'],
        };
        return (new MailMessage)->subject("{$ta} · {$te}")->view('mail.notice', [
            'company' => ['ar' => $this->companyAr, 'en' => $this->companyEn], 'url' => $this->url, 'name' => '',
            'ar' => ['title' => $ta, 'body' => $ba." بريد الدخول: {$notifiable->email}", 'hint' => "الرابط صالح لمدة {$valid['ar']} ولمرة واحدة. إن لم تكن تتوقع هذه الرسالة فتجاهلها.", 'action' => $reset ? 'تعيين كلمة المرور' : 'تفعيل الحساب'],
            'en' => ['title' => $te, 'body' => $be." Sign-in email: {$notifiable->email}", 'hint' => "The link works once and expires in {$valid['en']}. If you were not expecting this, ignore it.", 'action' => $reset ? 'Set password' : 'Activate account'],
        ]);
    }
}
