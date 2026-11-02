<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل البريد الصادر (11e): رسالة لكل مستلم — بلا محتوى الرسالة */
class MailLog extends Model
{
    use MassPrunable;

    protected bool $audit = false;
    protected function casts(): array { return ['sent_at' => 'datetime']; }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(max(7, (int) Settings::platformMerged('mail')['log_days'])));
    }

    public function row(): array
    {
        return ['id' => $this->id, 'to' => $this->to, 'subject' => $this->subject, 'kind' => $this->kind, 'status' => $this->status, 'error' => $this->error,
            'company_id' => $this->company_id, 'at' => $this->created_at?->toIso8601String(), 'sent_at' => $this->sent_at?->toIso8601String()];
    }
}
