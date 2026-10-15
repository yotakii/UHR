<?php

namespace App\Models;


class Location extends Model
{
    protected function casts(): array { return ['active' => 'boolean']; }
}
