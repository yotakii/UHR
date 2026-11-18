<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** صلاحية فرع إضافي (FR-NF-007): مصرّح دائم، مؤقت بتاريخين، أو ندب */
class BranchAccess extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'branch_access';
    protected function casts(): array { return ['from' => 'date', 'to' => 'date']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
}
