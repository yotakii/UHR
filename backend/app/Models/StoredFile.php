<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoredFile extends Model
{
    protected bool $audit = false;
    protected $table = 'files';

    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
