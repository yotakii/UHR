<?php

namespace App\Support;

use App\Models\Policy;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * إعدادات النظام (M16) مع كاش، والسياسات بتاريخ نفاذ.
 * القيم الافتراضية = قيم الديمو (SRS §7) وتُكتب عند التثبيت.
 */
class Settings
{
    public const DEFAULTS = [
        'company' => [
            'name_ar' => 'شركة الفرادة للتطوير والاستثمار', 'name_en' => 'Uniqueness Development & Investment Co.',
            'short_ar' => 'مجموعة الفرادة', 'short_en' => 'Uniqueness Group', 'brand_latin' => 'UNIQUENESS GROUP',
            'domain' => 'alfarada.sy', 'careers_url' => 'https://careers.alfarada.sy', 'form_prefix' => 'Uniqueness-HR-01',
            'hr_dept_ar' => 'إدارة الموارد البشرية', 'hr_dept_en' => 'Human Resources Department',
        ],
        // نصوص الواجهة العامة (M01) — قابلة للتعديل من الإعدادات
        'site' => [
            'hero_title_ar' => 'نظام الموارد البشرية المتكامل', 'hero_title_en' => 'The Complete People Operating System',
            'hero_text_ar' => 'قدّم على وظيفة الآن، وعِش رحلة الموظف كاملة: المقابلة، عرض العمل، التعيين، المباشرة، الحضور… حتى إنهاء الخدمة.',
            'hero_text_en' => 'Apply for a role now and live the full employee journey: interview, offer, appointment, onboarding, attendance… through to exit.',
        ],
        'prefixes' => ['employee' => 'UHR', 'candidate' => 'UNQ', 'decision' => 'QR', 'asset' => 'AST', 'job' => 'REQ'],
        // نطاقات المطابقة (SRS §10.9): ≥ match_threshold طبيعي، review_min…threshold-1 للمراجعة، ≤ reject_max رفض تلقائي إذا auto_reject
        'rules' => ['match_threshold' => 50, 'review_min' => 41, 'reject_max' => 40, 'auto_reject' => true, 'auto_screen' => true, 'sla_alerts' => true, 'whatsapp_nudge' => true,
            // نقاط الإحالة المرحلية (SRS §10.9): الفرز، المقابلة، المباشرة، اجتياز التجربة — ×hard_to_fill_x للوظائف الحرجة
            'referral_stages' => [10, 20, 30, 40], 'hard_to_fill_x' => 2],
        // قالب الطباعة (SRS §10.4): الشعار وحجمه ومحاذاته والهامش
        'print' => ['logo' => true, 'logo_height' => 56, 'align' => 'start', 'margin_mm' => 14],
        'stage_sla' => ['new' => 2, 'screening' => 3, 'interview' => 5, 'technical' => 5, 'offer' => 4],
    ];

    /** إعدادات المنصة (بلا شركة): نصوص بوابة فرص العمل العامة — SRS §10.15 */
    public const PLATFORM_DEFAULTS = [
        'portal' => [
            'brand_latin' => 'UHR · ATMATA GROUP',
            'hero_title_ar' => 'فرص عمل حقيقية في شركات سورية رائدة', 'hero_title_en' => 'Real jobs at leading Syrian companies',
            'hero_text_ar' => 'تصفّح الشواغر المفتوحة الآن، وقدّم طلبك بحساب واحد، وتابع حالته خطوة بخطوة حتى التعيين.',
            'hero_text_en' => 'Browse open positions, apply with one account, and follow every application step by step until you are hired.',
        ],
    ];

    /** سياسات الدوام والإجازات الافتراضية — SRS §7.1–7.2 */
    public const POLICY_DEFAULTS = [
        'work_days' => [0, 1, 2, 3, 4],          // الأحد–الخميس (Carbon: 0 = الأحد)
        'shift' => ['start' => 480, 'end' => 960, 'grace' => 15, 'break' => 60, 'break_after' => 300, 'ot_threshold' => 30, 'night_from' => 1320],
        'overtime_rates' => ['day' => 0.25, 'night' => 0.5, 'rest' => 1.0],
        'annual_leave' => ['first_year' => 14, 'standard' => 21, 'senior' => 30, 'senior_years' => 10, 'senior_age' => 50],
        'sick_leave' => ['full' => 30, 'p80' => 30, 'p75' => 30],
        'probation_days' => 90,
        'notice_days' => 30,
        'payslip' => ['basic' => 0.72, 'housing' => 0.14, 'transport' => 0.08, 'insurance' => 0.07, 'pay_day' => 28],
        'advance_max_ratio' => 0.5,
        'unlock_minutes' => 5,
    ];

    /** إعداد الشركة الحالية (Tenant) — وإلا القيمة الافتراضية */
    public static function get(string $key): mixed
    {
        $c = Tenant::id();
        return Cache::rememberForever("settings:{$c}:{$key}", fn () => Setting::where('company_id', $c)->where('key', $key)->first()?->value ?? self::DEFAULTS[$key] ?? null);
    }

    public static function put(string $key, mixed $value): void
    {
        $c = Tenant::id();
        Setting::updateOrCreate(['company_id' => $c, 'key' => $key], ['value' => $value]);
        Cache::forget("settings:{$c}:{$key}");
    }

    /** إعدادات المنصة (company_id = null) */
    public static function platform(string $key): mixed
    {
        return Cache::rememberForever("settings:platform:{$key}", fn () => Setting::whereNull('company_id')->where('key', $key)->first()?->value ?? self::PLATFORM_DEFAULTS[$key] ?? null);
    }

    public static function putPlatform(string $key, mixed $value): void
    {
        Setting::updateOrCreate(['company_id' => null, 'key' => $key], ['value' => $value]);
        Cache::forget("settings:platform:{$key}");
    }

    public static function company(string $field): ?string { return self::get('company')[$field] ?? null; }
    public static function prefix(string $kind): string { return self::get('prefixes')[$kind] ?? self::DEFAULTS['prefixes'][$kind]; }
    public static function rule(string $key): mixed { return self::get('rules')[$key] ?? self::DEFAULTS['rules'][$key]; }

    /** قيمة السياسة النافذة في تاريخ معيّن (افتراضياً اليوم) */
    public static function policy(string $key, Carbon|string|null $on = null): mixed
    {
        $on = $on ? Carbon::parse($on)->toDateString() : now()->toDateString();
        return Cache::remember('policy:'.Tenant::id().":{$key}:{$on}", 300, fn () => Policy::where('key', $key)->where('effective_from', '<=', $on)
            ->orderByDesc('effective_from')->first()?->value ?? self::POLICY_DEFAULTS[$key] ?? null);
    }

    public static function flush(): void { Cache::flush(); }
}
