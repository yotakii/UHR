<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** «شكر زميل» (12b): قيمة من قيم الشركة، رسالة، مستلمون (موظفون) ونقاط اختيارية لكل مستلم — يُنشر في مجتمع الشركة */
class Praise extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['recipients' => 'array']; }
    public function from(): BelongsTo { return $this->belongsTo(User::class, 'from_user_id'); }
    public function post(): BelongsTo { return $this->belongsTo(SpacePost::class, 'space_post_id'); }
}
