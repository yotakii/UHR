<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Onboarding extends Model
{
    protected function casts(): array { return ['checklist' => 'array', 'memo_sent_at' => 'datetime', 'f22_signed_at' => 'datetime', 'f20_signed_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function doneCount(): int { return count(array_filter($this->checklist ?? [])); }
}
