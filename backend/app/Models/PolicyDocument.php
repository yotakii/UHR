<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** وثيقة في مكتبة السياسات (D-26): سياسة/إجراء/نظام/ميثاق، لها إصدارات، والمعتمد منها current */
class PolicyDocument extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['archived_at' => 'datetime']; }
    public function versions(): HasMany { return $this->hasMany(PolicyVersion::class, 'document_id')->orderByDesc('number'); }
    public function current(): BelongsTo { return $this->belongsTo(PolicyVersion::class, 'current_version_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
