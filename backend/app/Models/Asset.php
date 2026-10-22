<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use Concerns\BelongsToCompany;
    public const STATUSES = ['available', 'pending_ack', 'assigned', 'maintenance', 'retired'];
    public const CONDITIONS = ['good', 'fair', 'damaged'];

    protected function casts(): array { return ['value' => 'float', 'assigned_at' => 'datetime', 'signed_at' => 'datetime', 'returned_at' => 'datetime', 'purchased_at' => 'date:Y-m-d']; }
    public function category(): BelongsTo { return $this->belongsTo(AssetCategory::class, 'asset_category_id'); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function movements(): HasMany { return $this->hasMany(AssetMovement::class)->orderByDesc('at')->orderByDesc('id'); }
    public function signature(): BelongsTo { return $this->belongsTo(StoredFile::class, 'signature_file_id'); }
}
