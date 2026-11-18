<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** تعليق على مهمة — بلا company_id: يُصل إليه عبر المهمة المقيّدة بالشركة */
class MissionComment extends Model
{
    protected bool $audit = false;
    protected function casts(): array { return ['files' => 'array', 'mentions' => 'array']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
