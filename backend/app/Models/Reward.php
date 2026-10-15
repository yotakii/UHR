<?php

namespace App\Models;


class Reward extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['active' => 'boolean']; }
}
