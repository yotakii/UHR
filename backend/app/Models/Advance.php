<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سلفة معتمدة على الراتب: تُخصم من كشف شهرها عند إغلاقه */
class Advance extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['amount' => 'float']; }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function request(): BelongsTo { return $this->belongsTo(ServiceRequest::class, 'service_request_id'); }
}
