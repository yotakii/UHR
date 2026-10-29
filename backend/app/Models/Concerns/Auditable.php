<?php

namespace App\Models\Concerns;

use App\Support\Audit;

/** كل كتابة على النموذج تُسجَّل في audit_logs: create / update (الحقول المتغيرة فقط) / delete */
trait Auditable
{
    public static function bootAuditable(): void
    {
        // كل كتابة (حتى غير المدقّقة) تُعلم الواجهات المفتوحة عبر البث اللحظي
        $touch = fn ($m) => \App\Support\Realtime::touch($m->getTable(), $m->getAttributes()['company_id'] ?? null);
        static::created($touch); static::deleted($touch);
        // تعديل حقول الضجيج فقط (آخر دخول، محاولات فاشلة…) لا يُبث
        static::updated(function ($m) use ($touch) { if (array_diff(array_keys($m->getChanges()), [...Audit::NOISE, 'remember_token', 'locked_until'])) $touch($m); });
        // لا تُرجع false من المستمع: Laravel يوقف بقية مستمعي الحدث عندها
        static::created(function ($m) { if ($m->auditable()) Audit::record(static::auditName().'.created', $m, null, $m->getAttributes()); });
        static::updated(function ($m) {
            if (! $m->auditable()) return;
            $after = Audit::clean($m->getChanges());
            if (! $after) return;
            $before = array_intersect_key($m->getOriginal(), $after);
            Audit::record(static::auditName().'.updated', $m, $before, $after);
        });
        static::deleted(function ($m) { if ($m->auditable()) Audit::record(static::auditName().'.deleted', $m, $m->getAttributes(), null); });
    }

    /** employee, job_opening … */
    public static function auditName(): string { return \Illuminate\Support\Str::snake(class_basename(static::class)); }

    public function auditable(): bool { return ! property_exists($this, 'audit') || $this->audit; }
}
