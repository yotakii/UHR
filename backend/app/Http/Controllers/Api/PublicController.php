<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\JobOpening;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

/** الواجهة العامة (M01): بيانات الشركة ونصوص الصفحة والوظائف المنشورة — بلا تسجيل دخول */
class PublicController extends Controller
{
    public function site(): JsonResponse
    {
        $c = Settings::get('company');
        $s = Settings::get('site');
        return response()->json([
            'company' => ['name' => ['ar' => $c['name_ar'], 'en' => $c['name_en']], 'short' => ['ar' => $c['short_ar'], 'en' => $c['short_en']], 'brand_latin' => $c['brand_latin']],
            'hero' => ['title' => ['ar' => $s['hero_title_ar'], 'en' => $s['hero_title_en']], 'text' => ['ar' => $s['hero_text_ar'], 'en' => $s['hero_text_en']]],
        ]);
    }

    public function jobs(): JsonResponse
    {
        $jobs = JobOpening::with(['department', 'location'])->where('status', 'open')
            ->orderByDesc('posted_at')->orderBy('id')->get();
        return response()->json(['data' => $jobs->map(fn ($j) => self::present($j))]);
    }

    public function job(string $code): JsonResponse
    {
        $j = JobOpening::with(['department', 'location'])->where('status', 'open')->where('code', $code)->first();
        abort_unless($j, 404, \App\Support\L::t('هذه الوظيفة غير متاحة للتقديم حالياً.', 'This position is not open for applications.'));
        return response()->json(['data' => self::present($j, true)]);
    }

    public const TYPES = [
        'full_time' => ['ar' => 'دوام كامل', 'en' => 'Full-time'], 'part_time' => ['ar' => 'دوام جزئي', 'en' => 'Part-time'],
        'shifts' => ['ar' => 'ورديات', 'en' => 'Shifts'], 'contract' => ['ar' => 'عقد مؤقت', 'en' => 'Contract'],
    ];

    /** شكل الوظيفة للواجهة — لا يكشف سلّم الرواتب الداخلي */
    public static function present(JobOpening $j, bool $full = false): array
    {
        $out = [
            'code' => $j->code,
            'title' => ['ar' => $j->title_ar, 'en' => $j->title_en],
            'summary' => ['ar' => $j->summary_ar, 'en' => $j->summary_en],
            'dept' => ['code' => $j->department->code, 'ar' => $j->department->name_ar, 'en' => $j->department->name_en],
            'city' => ['ar' => $j->location->name_ar, 'en' => $j->location->name_en],
            'type' => self::TYPES[$j->employment_type] ?? ['ar' => $j->employment_type, 'en' => $j->employment_type],
            'posted_at' => $j->posted_at?->toDateString(),
        ];
        if ($full) $out += ['requirements' => $j->requirements, 'openings' => $j->openings, 'min_years' => $j->min_years, 'degree' => $j->degree];
        return $out;
    }
}
