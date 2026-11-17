<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إصدار معتمد من دليل الترميز مع لقطة كاملة للقوائم (للمقارنة بين إصدارين) */
class CodebookVersion extends Model
{
    use BelongsToCompany;

    protected bool $audit = false;

    protected function casts(): array { return ['snapshot' => 'array', 'approved_at' => 'datetime']; }

    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
