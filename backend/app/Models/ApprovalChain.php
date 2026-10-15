<?php

namespace App\Models;


class ApprovalChain extends Model
{
    protected function casts(): array { return ['levels' => 'array']; }
}
