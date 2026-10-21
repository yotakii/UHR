<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** طلب خدمة (M09، D-16) بالمسار الموحد: شهادة راتب، شهادة عمل، سلفة، تحديث بيانات */
class ServiceRequest extends Model
{
    use Concerns\BelongsToCompany;
    public const KINDS = ['salary_cert' => ['شهادة راتب', 'Salary certificate'], 'experience_cert' => ['شهادة عمل / تعريف', 'Employment certificate'],
        'advance' => ['سلفة على الراتب', 'Salary advance'], 'data_update' => ['تحديث بيانات شخصية', 'Personal data update']];

    protected function casts(): array
    {
        return ['amount' => 'float', 'done_at' => 'datetime', 'issued_at' => 'datetime', 'decided_at' => 'datetime', 'addressee' => 'array', 'data' => 'array', 'notify_consent' => 'boolean'];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function issuer(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function steps(): MorphMany { return $this->morphMany(RequestStep::class, 'subject')->orderBy('level'); }
    public function workflowLabel(string $lang): string { $k = self::KINDS[$this->kind] ?? [$this->kind, $this->kind]; return $lang === 'en' ? $k[1] : $k[0]; }
}
