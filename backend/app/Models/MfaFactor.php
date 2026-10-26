<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** عامل التحقق بخطوتين للرواتب: سر TOTP مشفّر، آخر خطوة مقبولة، رموز الطوارئ مجزّأة (التدقيق يدوي في SalaryVault) */
class MfaFactor extends Model
{
    protected bool $audit = false;
    protected $hidden = ['totp_secret', 'backup_hashes'];
    protected function casts(): array { return ['totp_secret' => 'encrypted', 'backup_hashes' => 'array', 'enrolled_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
