<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** شركة على المنصة: Atmata (المالكة) والشركات العميلة — SRS §10.3، §10.15 */
class Company extends Model
{
    protected function casts(): array { return ['portal_enabled' => 'boolean', 'portal_review' => 'boolean', 'is_platform' => 'boolean']; }

    public function jobs(): HasMany { return $this->hasMany(JobOpening::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }

    public function active(): bool { return $this->status === 'active'; }

    /** شكل الشركة للواجهة العامة */
    public function card(): array
    {
        return [
            'slug' => $this->slug, 'name' => ['ar' => $this->name_ar, 'en' => $this->name_en],
            'short' => ['ar' => $this->short_ar ?: $this->name_ar, 'en' => $this->short_en ?: $this->name_en],
            'brand_latin' => $this->brand_latin, 'logo' => $this->logo_path ? '/api/public/companies/'.$this->slug.'/logo?v='.$this->updated_at?->timestamp : null,
        ];
    }
}
