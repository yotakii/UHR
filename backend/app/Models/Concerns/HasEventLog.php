<?php

namespace App\Models\Concerns;

use App\Models\EventLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** سجل الأحداث (Timeline) للمتقدم والموظف — نفس ev(ar, en, kind, form) في الديمو */
trait HasEventLog
{
    public function logs(): MorphMany
    {
        return $this->morphMany(EventLog::class, 'subject')->orderBy('at')->orderBy('id');
    }

    public function log(string $ar, string $en, string $kind = 'info', ?string $form = null, $at = null): EventLog
    {
        return $this->logs()->create([
            'at' => $at ?? now(), 'text_ar' => $ar, 'text_en' => $en, 'kind' => $kind, 'form' => $form,
            'user_id' => auth()->id(),
        ]);
    }
}
