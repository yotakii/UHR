<?php

namespace App\Services\Onboarding;

use App\Models\Employee;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

/** F-32 — نص مذكرة الترحيب من بيانات الموظف وطلب توظيفه، بصيغة التذكير/التأنيث (memo في Onboarding.tsx) */
class WelcomeMemo
{
    /** @return array{ar: string, en: string} */
    public static function text(Employee $e): array
    {
        $e->loadMissing(['department', 'location', 'manager', 'candidate']);
        $co = Tenant::company();
        $f = $e->gender === 'F';
        $ed = $e->candidate?->education[0] ?? null; $ex = $e->candidate?->experience[0] ?? null;
        $date = Carbon::parse($e->hire_date);
        $qual = $ed && ! empty($ed['phase']) ? ($f ? 'تحمل' : 'يحمل')." {$ed['phase']}".(! empty($ed['major']) ? " في {$ed['major']}" : '').(! empty($ed['institute']) ? " من {$ed['institute']}" : '') : '';
        $prev = $ex && ! empty($ex['company']) ? ($qual ? '، ' : '')."مع خبرة سابقة لدى {$ex['company']}" : '';
        $about = $qual.$prev ? "\n{$qual}{$prev}." : '';
        $ar = "الزملاء الكرام،\nيسرّ إدارة الموارد البشرية في {$co?->name_ar} أن ترحّب بانضمام {$e->name} إلى فريق {$e->department->name_ar} بوظيفة {$e->title_ar}، اعتباراً من {$date->format('d-m-Y')}.{$about}\n"
            ."مقر العمل: {$e->location->name_ar}. المدير المباشر: ".($e->manager?->name ?? '—').".\n"
            .'نتمنى '.($f ? 'لها' : 'له').' بداية موفقة، ونرجو من الجميع تسهيل اندماج'.($f ? 'ها' : 'ه')." في العمل.\n\nإدارة الموارد البشرية";
        $en = "Dear colleagues,\nHR is pleased to welcome {$e->name_en} to the {$e->department->name_en} team as {$e->title_en}, starting {$date->format('d-m-Y')}.\n"
            ."Work location: {$e->location->name_en}. Direct manager: ".($e->manager?->name_en ?? '—').".\nPlease help make ".($f ? 'her' : 'his')." first weeks smooth.\n\nHuman Resources";
        return ['ar' => $ar, 'en' => $en];
    }
}
