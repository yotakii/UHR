<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سعر صرف مؤرخ للشركة: 1 from = rate to (يُستخدم آخر سعر بتاريخ الصرف أو قبله، أو مقلوب الزوج المعاكس) */
class ExchangeRate extends Model
{
    use Concerns\BelongsToCompany;

    protected function casts(): array { return ['date' => 'date', 'rate' => 'float']; }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public static function lookup(string $from, string $to, string $date): ?array
    {
        if ($from === $to) return ['rate' => 1.0, 'date' => $date];
        $direct = self::where('from', $from)->where('to', $to)->where('date', '<=', $date)->orderByDesc('date')->first();
        $inverse = self::where('from', $to)->where('to', $from)->where('date', '<=', $date)->orderByDesc('date')->first();
        $pick = $direct && (! $inverse || $direct->date >= $inverse->date) ? ['rate' => $direct->rate, 'date' => $direct->date->toDateString()]
            : ($inverse && $inverse->rate > 0 ? ['rate' => 1 / $inverse->rate, 'date' => $inverse->date->toDateString()] : null);
        return $pick;
    }
}
