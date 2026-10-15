<?php

namespace App\Models;

/** إعداد مفتاح/قيمة لكل شركة؛ company_id = null لإعدادات المنصة (Settings::platform) */
class Setting extends Model
{
    protected function casts(): array { return ['value' => 'array']; }
}
