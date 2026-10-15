<?php

namespace Tests\Unit;

use App\Services\Recruitment\F25;
use PHPUnit\Framework\TestCase;

/** F-25 على الخادم = f25Score في الديمو (القيم المتوقعة مولّدة من rules.ts الأصلي) */
class F25Test extends TestCase
{
    public function test_matches_the_demo_rules_exactly(): void
    {
        $keys = F25::keys();
        $this->assertCount(24, $keys);
        $sets = [
            array_fill_keys($keys, 3),
            array_combine($keys, array_map(fn ($i) => $i % 5 + 1, array_keys($keys))),
            array_combine($keys, array_map(fn ($i) => $i < 12 ? 5 : 2, array_keys($keys))),
            array_fill_keys(array_slice($keys, 0, 18), 4),
        ];
        $expected = [[[4.5, 4.5, 4.5, 4.5], 18, 60], [[4, 4.25, 4.5, 4.75], 17.5, 58], [[7.5, 7.5, 3, 3], 21, 70], [[6, 6, 6, 0], 18, 60]];
        foreach ($sets as $i => $r) {
            $s = F25::score($r);
            $this->assertEqualsWithDelta($expected[$i][0], $s['cats'], 1e-9);
            $this->assertEqualsWithDelta($expected[$i][1], $s['total'], 1e-9);
            $this->assertSame($expected[$i][2], $s['pct']);
        }
        $this->assertSame('Very good — acceptable', F25::label(70)['en']);
        $this->assertSame('غير كافٍ', F25::label(49)['ar']);
    }
}
