<?php

namespace App\Services\Onboarding;

use App\Models\Employee;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

/** F-32 — نص مذكرة الترحيب من بيانات الموظف وطلب توظيفه، بصيغة التذكير/التأنيث (memo في Onboarding.tsx) */
class WelcomeMemo
{
    /** المتغيرات المسموحة في نص الشركة */
    public const VARS = ['name', 'title', 'department', 'date', 'location', 'manager', 'company', 'about'];

    /** @return array{ar: string, en: string} */
    public static function text(Employee $e): array
    {
        $def = self::standard($e);
        $cfg = \App\Support\Settings::merged('welcome_memo');
        if (trim($cfg['ar']) === '' && trim($cfg['en']) === '') return $def;
        $e->loadMissing(['department', 'location', 'manager']);
        $co = Tenant::company();
        $ed = $e->candidate?->education[0] ?? null;
        $vars = fn (string $l) => [':name' => $l === 'ar' ? $e->name : $e->name_en, ':title' => $l === 'ar' ? $e->title_ar : $e->title_en,
            ':department' => $l === 'ar' ? $e->department->name_ar : $e->department->name_en, ':date' => Carbon::parse($e->hire_date)->format('d-m-Y'),
            ':location' => $l === 'ar' ? $e->location->name_ar : $e->location->name_en, ':manager' => ($l === 'ar' ? $e->manager?->name : $e->manager?->name_en) ?? '—',
            ':company' => $l === 'ar' ? ($co?->name_ar ?? '') : ($co?->name_en ?? ''), ':about' => $ed && ! empty($ed['phase']) ? trim($ed['phase'].(! empty($ed['major']) ? ' — '.$ed['major'] : '')) : ''];
        $fill = fn (string $t, string $l) => strtr($t, $vars($l));
        return ['ar' => trim($cfg['ar']) !== '' ? $fill($cfg['ar'], 'ar') : $def['ar'], 'en' => trim($cfg['en']) !== '' ? $fill($cfg['en'], 'en') : $def['en']];
    }

    /** @return array{ar: string, en: string} */
    public static function standard(Employee $e): array
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
