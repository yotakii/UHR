<?php

namespace App\Models;

class ExpertRating extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['rehire' => 'boolean', 'score' => 'float']; }
}
