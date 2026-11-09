<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Push\Fcm;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** إشعار شخصي → تطبيق الجوال (بلغة صاحب الحساب) */
class SendPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(public int $userId, public string $ar, public string $en, public ?string $link = null) {}

    public function handle(): void
    {
        $u = User::with('company')->find($this->userId);
        if (! $u || ! $u->active) return;
        $en = ($u->locale ?? 'ar') === 'en';
        $title = $u->company ? ($en ? ($u->company->short_en ?: $u->company->name_en) : ($u->company->short_ar ?: $u->company->name_ar)) : 'UHR';
        Fcm::toUser($u->id, $title, $en ? $this->en : $this->ar, ['link' => (string) $this->link]);
    }
}
