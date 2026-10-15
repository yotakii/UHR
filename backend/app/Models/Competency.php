<?php

namespace App\Models;


class Competency extends Model
{
    protected function casts(): array { return ['active' => 'boolean']; }
}
