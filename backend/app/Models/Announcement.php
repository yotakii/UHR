<?php

namespace App\Models;


class Announcement extends Model
{
    use Concerns\BelongsToCompany;
    protected function casts(): array { return ['published_at' => 'datetime', 'archived_at' => 'datetime']; }
}
