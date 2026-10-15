<?php

namespace App\Models;


class Holiday extends Model
{
    protected function casts(): array { return ['date' => 'date']; }
}
