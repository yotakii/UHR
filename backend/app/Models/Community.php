<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** مجتمع في «الساحة»: الشركة كلها، إدارة، أو اهتمام */
class Community extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['archived_at' => 'datetime']; }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function members(): HasMany { return $this->hasMany(CommunityMember::class); }
}
