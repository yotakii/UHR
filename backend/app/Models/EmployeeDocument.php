<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array
    {
        return ['expires_at' => 'date:Y-m-d', 'sensitive' => 'boolean', 'alerted_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function file(): BelongsTo { return $this->belongsTo(StoredFile::class, 'file_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
