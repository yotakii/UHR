<?php

namespace App\Models;


class Reward extends Model
{
    protected function casts(): array { return ['active' => 'boolean']; }
}
