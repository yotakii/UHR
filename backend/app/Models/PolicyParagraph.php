<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** فقرة من نص الإصدار — للبحث، ويربطها HR بالإعداد الذي يطبّقها (rule_key) */
class PolicyParagraph extends Model
{
    use Concerns\BelongsToCompany;

    protected bool $audit = false;
    public $timestamps = false;
    public function version(): BelongsTo { return $this->belongsTo(PolicyVersion::class, 'version_id'); }
}
