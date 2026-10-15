<?php

namespace App\Http\Resources;

use App\Models\JobOpening;
use App\Services\Recruitment\MatchScore;

/** شكل الشاغر للواجهة العامة — لا يكشف سلّم الرواتب الداخلي */
class PublicJob
{
    public static function card(JobOpening $j): array
    {
        return [
            'code' => $j->code,
            'company' => $j->company->card(),
            'title' => ['ar' => $j->title_ar, 'en' => $j->title_en],
            'summary' => ['ar' => $j->summary_ar, 'en' => $j->summary_en],
            'dept' => ['code' => $j->department->code, 'ar' => $j->department->name_ar, 'en' => $j->department->name_en],
            'city' => ['code' => $j->location->code, 'ar' => $j->location->name_ar, 'en' => $j->location->name_en],
            'type' => JobOpening::TYPES[$j->employment_type] ?? ['ar' => $j->employment_type, 'en' => $j->employment_type],
            'type_code' => $j->employment_type,
            'min_years' => $j->min_years,
            'hard_to_fill' => $j->hard_to_fill,
            'posted_at' => $j->posted_at?->toDateString(),
            'closes_at' => $j->closes_at?->toDateString(),
        ];
    }

    public static function full(JobOpening $j): array
    {
        return self::card($j) + [
            'requirements' => $j->requirements, 'openings' => $j->openings, 'degree' => $j->degree,
            'degree_label' => MatchScore::DEGREES[$j->degree] ?? null, 'requires_photo' => $j->requires_photo,
            'company_about' => ['ar' => $j->company->about_ar, 'en' => $j->company->about_en],
        ];
    }

    public const WITH = ['company', 'department', 'location'];
}
