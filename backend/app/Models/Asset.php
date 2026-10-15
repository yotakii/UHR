<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['value' => 'float', 'assigned_at' => 'datetime', 'signed_at' => 'datetime']; }
    public function category(): BelongsTo { return $this->belongsTo(AssetCategory::class, 'asset_category_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function signature(): BelongsTo { return $this->belongsTo(StoredFile::class, 'signature_file_id'); }
}
