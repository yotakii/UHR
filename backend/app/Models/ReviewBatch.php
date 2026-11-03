<?php

namespace App\Models;

/** دفعة قراءة سير طلبها مستخدم من «فرز السير» */
class ReviewBatch extends Model
{
    use Concerns\BelongsToCompany;

    protected bool $audit = false;
    protected function casts(): array { return ['candidate_ids' => 'array', 'counts' => 'array', 'filters' => 'array', 'force' => 'boolean']; }
}
