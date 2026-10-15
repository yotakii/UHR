<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    public function assets(): HasMany { return $this->hasMany(Asset::class); }
}
