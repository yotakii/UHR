<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** أمر لوكيل جهاز الموظف: قفل / تسجيل خروج / إيقاف تشغيل — يبقى في الطابور حتى يتصل الجهاز */
class AgentCommand extends Model
{
    use Concerns\BelongsToCompany;
    protected bool $audit = false;   // سجل الأوامر نفسه هو الأثر؛ لا ضجيج في سجل التدقيق
    protected function casts(): array { return ['sent_at' => 'datetime', 'done_at' => 'datetime', 'expires_at' => 'datetime']; }
    public function agent(): BelongsTo { return $this->belongsTo(PcAgent::class, 'pc_agent_id'); }
}
