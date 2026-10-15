<?php

namespace Tests\Unit;

use App\Models\Candidate;
use App\Models\JobOpening;
use App\Services\Recruitment\MatchScore;
use Tests\TestCase;

/** المطابقة على الخادم = matchScore في الديمو (القيم المتوقعة مولّدة من rules.ts الأصلي) */
class MatchScoreTest extends TestCase
{
    private const JOBS = [[3, 2, 700, 1100], [0, 1, 400, 600], [5, 3, 1200, 2000]];
    // years, degree, salary, english, cv, education, experience, refs, linkedin
    private const CANDS = [[6, 2, 1500, 'good', 1, 1, 1, 1, 1], [3, 2, 720, 'fair', 1, 1, 0, 1, 0], [0, 0, 600, 'weak', 0, 1, 0, 0, 0], [8, 3, 1900, 'excellent', 1, 1, 1, 1, 1],
        [1, 1, 420, 'fair', 1, 1, 1, 1, 0], [4, 4, 3000, 'good', 0, 0, 0, 0, 1], [2, 1, 950, 'excellent', 1, 1, 1, 0, 1]];
    private const EXPECTED = [[0, 0, 72], [0, 1, 74], [0, 2, 26], [0, 3, 80], [0, 4, 50], [0, 5, 62], [0, 6, 60], [1, 0, 76], [1, 1, 69], [1, 2, 50], [1, 3, 80], [1, 4, 79], [1, 5, 68], [1, 6, 71],
        [2, 0, 75], [2, 1, 53], [2, 2, 26], [2, 3, 91], [2, 4, 40], [2, 5, 52], [2, 6, 53]];

    public function test_matches_the_demo_rules_exactly(): void
    {
        foreach (self::EXPECTED as [$ji, $ci, $total]) {
            [$minYears, $degree, $min, $max] = self::JOBS[$ji];
            $job = new JobOpening(['min_years' => $minYears, 'degree' => $degree, 'salary_min' => $min, 'salary_max' => $max]);
            $c = self::CANDS[$ci];
            $cand = new Candidate([
                'years_exp' => $c[0], 'degree_level' => $c[1], 'salary' => $c[2], 'english' => $c[3], 'cv_file_id' => $c[4] ?: null,
                'education' => array_fill(0, $c[5], []), 'experience' => array_fill(0, $c[6], []), 'refs' => array_fill(0, $c[7], []), 'linkedin' => $c[8] ? 'in/x' : '',
            ]);
            $this->assertSame($total, MatchScore::total($cand, $job), "job {$ji} × candidate {$ci}");
        }
    }

    public function test_parts_carry_weights_and_notes(): void
    {
        $job = new JobOpening(['min_years' => 3, 'degree' => 2, 'salary_min' => 700, 'salary_max' => 1100]);
        $cand = new Candidate(['years_exp' => 4, 'degree_level' => 2, 'salary' => 1300, 'english' => 'good', 'education' => [], 'experience' => [], 'refs' => [], 'linkedin' => '']);
        $r = MatchScore::compute($cand, $job);
        $this->assertSame([30, 25, 20, 15, 10], array_column($r['parts'], 'weight'));
        $this->assertSame('4 من 3 مطلوبة', $r['parts'][0]['note_ar']);
        $this->assertSame('Above band', $r['parts'][2]['note_en']);
    }
}
