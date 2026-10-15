<?php

namespace App\Models;


class NotificationChannel extends Model
{
    use Concerns\BelongsToCompany;
    protected $hidden = ['config'];
    protected function casts(): array { return ['enabled' => 'boolean', 'config' => 'encrypted:array']; }
}
