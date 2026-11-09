<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** جهاز جوال يستقبل الإشعارات (رمز Firebase) — مرتبط بالمستخدم، لا بنطاق الشركة */
class PushDevice extends Model
{
    protected bool $audit = false;
    protected function casts(): array { return ['last_seen_at' => 'datetime', 'last_sent_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
