<?php

namespace App\Models;


class Policy extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['value' => 'array', 'effective_from' => 'date']; }
}
