<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Notice extends Model
{
    use Concerns\BelongsToCompany;
    protected bool $audit = false;
    public $timestamps = false;
    protected static function booted(): void
    {
        static::created(function (Notice $n) {
            \App\Support\Realtime::send(new \App\Events\NoticeCreated($n));
            // تطبيق الجوال: الإشعار الشخصي يصل إلى أجهزة صاحبه (إن وُجدت وكانت الإشعارات مفعّلة في المنصة)
            if ($n->audience === 'user' && $n->user_id && PushDevice::where('user_id', $n->user_id)->exists() && \App\Services\Push\Fcm::enabled()) {
                \App\Jobs\SendPush::dispatch($n->user_id, $n->text_ar, $n->text_en, $n->link)->afterCommit();
            }
        });
    }

    protected function casts(): array { return ['at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reads(): BelongsToMany { return $this->belongsToMany(User::class, 'notice_reads')->withPivot('read_at'); }
}
