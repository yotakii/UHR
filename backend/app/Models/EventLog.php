<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;

class EventLog extends Model
{
    protected bool $audit = false;
    public $timestamps = false;
    protected function casts(): array { return ['at' => 'datetime']; }
    public function subject(): MorphTo { return $this->morphTo(); }
}
