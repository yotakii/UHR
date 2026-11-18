<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** وكيل جهاز الموظف UHR-Agent (D-15) */
class PcAgent extends Model
{
    use Concerns\BelongsToCompany;
    protected $hidden = ['key_hash'];
    protected function casts(): array { return ['active' => 'boolean', 'last_seen_at' => 'datetime', 'offline_alerted_at' => 'datetime']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function commands(): HasMany { return $this->hasMany(AgentCommand::class); }
}
