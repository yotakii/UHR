<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends Model
{
    protected function casts(): array { return ['basic' => 'float', 'housing' => 'float', 'transport' => 'float', 'fuel' => 'float', 'start_date' => 'date', 'issued_at' => 'datetime', 'responded_at' => 'datetime']; }
    public function candidate(): BelongsTo { return $this->belongsTo(Candidate::class); }
    public function signature(): BelongsTo { return $this->belongsTo(StoredFile::class, 'signature_file_id'); }
    /** إجمالي الراتب الشهري الشامل */
    public function total(): float { return $this->basic + $this->housing + $this->transport + $this->fuel; }
}
