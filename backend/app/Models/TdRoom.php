<?php

namespace App\Models;

/** قاعة تدريب (FR-TD-075): السعة والتجهيزات، ويُفحص تعارض حجزها بين التنفيذات */
class TdRoom extends Model
{
    use Concerns\BelongsToCompany;
    protected $table = 'td_rooms';
    protected function casts(): array { return ['equipment' => 'array', 'active' => 'boolean']; }
}
