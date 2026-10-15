<?php

namespace App\Models;


class Competency extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['active' => 'boolean']; }
}
