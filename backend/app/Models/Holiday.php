<?php

namespace App\Models;


class Holiday extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['date' => 'date']; }
}
