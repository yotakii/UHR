<?php

namespace App\Models;


class Policy extends Model
{
    protected function casts(): array { return ['value' => 'array', 'effective_from' => 'date']; }
}
