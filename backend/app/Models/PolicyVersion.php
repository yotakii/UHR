<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** إصدار وثيقة: الملف الأصلي والنص المستخرج (فقرات)، وتاريخ النفاذ؛ المنشور واحد لكل وثيقة والسابق في الأرشيف */
class PolicyVersion extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['effective_from' => 'date:Y-m-d', 'published_at' => 'datetime', 'archived_at' => 'datetime', 'text_missing' => 'boolean']; }
    public function document(): BelongsTo { return $this->belongsTo(PolicyDocument::class, 'document_id'); }
    public function file(): BelongsTo { return $this->belongsTo(StoredFile::class, 'file_id'); }
    public function paragraphs(): HasMany { return $this->hasMany(PolicyParagraph::class, 'version_id')->orderBy('n'); }
    public function reads(): HasMany { return $this->hasMany(PolicyRead::class, 'version_id'); }
    public function publisher(): BelongsTo { return $this->belongsTo(User::class, 'published_by'); }
}
