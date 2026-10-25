<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** إعلان (D-06): draft ← pending (إن تطلّب الاعتماد) ← published ← expired */
class Announcement extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['published_at' => 'datetime', 'archived_at' => 'datetime', 'expires_at' => 'datetime', 'approved_at' => 'datetime', 'notified_at' => 'datetime']; }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function image(): BelongsTo { return $this->belongsTo(StoredFile::class, 'image_file_id'); }
    public function attachment(): BelongsTo { return $this->belongsTo(StoredFile::class, 'attachment_file_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
}
