<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** رسالة في محادثة «المساعد» — لا تُدقَّق (الإجراءات المنفَّذة تُدقَّق في نماذجها) */
class AssistantMessage extends Model
{
    use Concerns\BelongsToCompany;

    protected bool $audit = false;
    protected function casts(): array { return ['data' => 'array']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
