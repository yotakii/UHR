<?php

namespace App\Events;

use App\Models\Notice;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** إشعار جرس جديد: لصاحبه على قناته، أو لموظفي الموارد البشرية على قناة الشركة — الواجهة تحدّث الجرس وتعرض النص */
class NoticeCreated implements ShouldBroadcastNow
{
    public function __construct(public Notice $notice) {}

    public function broadcastOn(): array
    {
        $n = $this->notice;
        return [new PrivateChannel($n->audience === 'user' && $n->user_id ? "user.{$n->user_id}" : "company.{$n->company_id}")];
    }

    public function broadcastAs(): string { return 'uhr.notice'; }

    public function broadcastWith(): array
    {
        $n = $this->notice;
        return ['id' => $n->id, 'audience' => $n->audience, 'ar' => $n->text_ar, 'en' => $n->text_en, 'link' => $n->link];
    }
}
