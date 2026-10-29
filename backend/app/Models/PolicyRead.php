<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل الاطلاع على إصدار سياسة (مرة واحدة لكل مستخدم) */
class PolicyRead extends Model
{
    use Concerns\BelongsToCompany;

    protected bool $audit = false;
    public $timestamps = false;
    protected function casts(): array { return ['read_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
