<?php

namespace App\Models\Concerns;

use App\Support\Audit;

/** كل كتابة على النموذج تُسجَّل في audit_logs: create / update (الحقول المتغيرة فقط) / delete */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => $m->auditable() && Audit::record(static::auditName().'.created', $m, null, $m->getAttributes()));
        static::updated(function ($m) {
            if (! $m->auditable()) return;
            $after = Audit::clean($m->getChanges());
            if (! $after) return;
            $before = array_intersect_key($m->getOriginal(), $after);
            Audit::record(static::auditName().'.updated', $m, $before, $after);
        });
        static::deleted(fn ($m) => $m->auditable() && Audit::record(static::auditName().'.deleted', $m, $m->getAttributes(), null));
    }

    /** employee, job_opening … */
    public static function auditName(): string { return \Illuminate\Support\Str::snake(class_basename(static::class)); }

    public function auditable(): bool { return ! property_exists($this, 'audit') || $this->audit; }
}
