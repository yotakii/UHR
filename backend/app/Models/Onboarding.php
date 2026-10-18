<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Onboarding extends Model
{
    protected function casts(): array
    {
        return ['checklist' => 'array', 'memo_sent_at' => 'datetime', 'f22_signed_at' => 'datetime', 'f20_signed_at' => 'datetime', 'conduct_signed_at' => 'datetime',
            'probation_started_at' => 'date', 'confirmed_at' => 'datetime'];
    }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function f22Signature(): BelongsTo { return $this->belongsTo(StoredFile::class, 'f22_signature_file_id'); }
    public function f20Signature(): BelongsTo { return $this->belongsTo(StoredFile::class, 'f20_signature_file_id'); }
    public function conductSignature(): BelongsTo { return $this->belongsTo(StoredFile::class, 'conduct_signature_file_id'); }
    public function doneCount(): int { return count(array_filter($this->checklist ?? [])); }
}
