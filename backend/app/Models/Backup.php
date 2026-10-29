<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** نسخة احتياطية: كاملة للخادم (company_id = null، pg_dump) أو لبيانات شركة واحدة (zip) — يُدقَّق إنشاؤها وتنزيلها يدوياً */
class Backup extends Model
{
    protected bool $audit = false;
    protected function casts(): array { return ['meta' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime']; }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
