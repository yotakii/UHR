<?php

namespace App\Models;


class ApprovalChain extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['levels' => 'array']; }
}
