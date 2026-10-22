<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حركة أصل (سجل العهدة): من استلم ومتى، ومن استرجع وبأي حالة — لا يُعدَّل ولا يُحذف */
class AssetMovement extends Model
{
    use Concerns\BelongsToCompany;

    public $timestamps = false;
    protected bool $audit = false;

    protected function casts(): array { return ['at' => 'datetime']; }
    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
