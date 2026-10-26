<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** مفتاح مرور WebAuthn (البصمة / Windows Hello) — المفتاح العام PEM، والتدقيق يدوي في SalaryVault */
class Passkey extends Model
{
    protected bool $audit = false;
    protected $hidden = ['public_key'];
    protected function casts(): array { return ['last_used_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
