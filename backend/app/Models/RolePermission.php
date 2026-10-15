<?php

namespace App\Models;

/** خلية في مصفوفة الصلاحيات (دور × وحدة) لشركة — SRS §10.2 */
class RolePermission extends Model
{
    use Concerns\BelongsToCompany;
    protected bool $audit = false;   // التغيير يُسجَّل مجمّعاً كحدث permissions.updated
    protected function casts(): array { return ['actions' => 'array']; }
}
