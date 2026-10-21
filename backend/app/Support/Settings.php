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
            'referral_stages' => [10, 20, 30, 40], 'hard_to_fill_x' => 2,
            // قاعدة السير (D-02): مدة الاحتفاظ بالسيرة قبل أرشفتها تلقائياً
            'cv_retention_months' => 24,
            // المباشرة F-19: عدد البنود المنجزة الذي ينقل الموظف إلى فترة التجربة
            'probation_at' => 14],
        // معايرة المطابقة (لكل شركة، والأوزان قابلة للتجاوز لكل شاغر) — القيم الافتراضية = الديمو، كلها نسب مئوية
        'match' => [
            'weights' => ['exp' => 30, 'deg' => 25, 'sal' => 20, 'eng' => 15, 'file' => 10],                // مجموعها 100، و0 يلغي المعيار
            'exp' => ['meets' => 70, 'bonus_years' => 3, 'below_max' => 60],                                 // يستوفي الحد ← meets٪ ثم يرتفع خطياً حتى 100٪ بعد bonus_years سنوات إضافية؛ دون الحد ← حتى below_max٪ بالتناسب
            'deg' => ['above' => 100, 'equal' => 85, 'one_below' => 40, 'lower' => 10],
            'sal' => ['to_mid' => 100, 'to_max' => 75, 'over' => 50, 'over_penalty' => 2],                    // حتى منتصف النطاق، حتى أعلاه، فوقه يبدأ من over٪ وينقص over_penalty نقطة لكل 1٪ زيادة
            'eng' => ['excellent' => 100, 'good' => 70, 'fair' => 40, 'weak' => 10],
            'file' => ['cv' => true, 'education' => true, 'experience' => true, 'refs' => true, 'linkedin' => true], // ما يُحسب في اكتمال الملف
        ],
        // الاختبار الأولي (D-34): مفتاح Claude API مشفّراً، والنموذج
        'ai' => ['api_key' => null, 'model' => 'claude-opus-5-5'],
        // قالب الطباعة (SRS §10.4): الشعار وحجمه ومحاذاته والهامش
        'print' => ['logo' => true, 'logo_height' => 56, 'align' => 'start', 'margin_mm' => 14],
        // الملفات الإضافية للموظف (SRS §10.11، D-25): الأنواع، الصيغ والحجم، أيام التنبيه قبل الانتهاء، والأدوار التي ترى «الحساس»
        'docs' => [
            'types' => [
                ['key' => 'id', 'ar' => 'هوية شخصية', 'en' => 'ID card'], ['key' => 'passport', 'ar' => 'جواز سفر', 'en' => 'Passport'],
                ['key' => 'degree', 'ar' => 'شهادة علمية', 'en' => 'Degree certificate'], ['key' => 'contract', 'ar' => 'عقد عمل', 'en' => 'Employment contract'],
                ['key' => 'medical', 'ar' => 'تقرير طبي', 'en' => 'Medical report'], ['key' => 'experience', 'ar' => 'شهادة خبرة', 'en' => 'Experience certificate'],
                ['key' => 'license', 'ar' => 'رخصة / إجازة مزاولة', 'en' => 'License / permit'], ['key' => 'other', 'ar' => 'أخرى', 'en' => 'Other'],
            ],
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'docx'], 'max_mb' => 10, 'alert_days' => 30, 'sensitive_roles' => ['hr_director', 'hr_officer'],
        ],
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

    /**
     * سياسات الدوام والإجازات الافتراضية لأي شركة جديدة = سياسة الشركة HR.01-01 (SRS §10.6): السبت–الخميس 09:00–17:00،
     * جدول حسم التأخير، الإضافي بعد 48 ساعة أسبوعياً بحد ساعتين يومياً. بيانات الاختبار (TestDataSeeder) تكتب قيم الديمو فوقها.
     * المفتاح «key@{department_id}» يتجاوز السياسة لإدارة بعينها (shift، work_days).
     */
    public const POLICY_DEFAULTS = [
        'work_days' => [6, 0, 1, 2, 3, 4],       // السبت–الخميس (Carbon: 0 = الأحد، 6 = السبت)
        // الدقائق من منتصف الليل: البداية، النهاية، سماح، استراحة تُطرح بعد break_after دقيقة عمل، عتبة الإضافي اليومي، بداية الليل
        'shift' => ['start' => 540, 'end' => 1020, 'grace' => 0, 'break' => 60, 'break_after' => 300, 'ot_threshold' => 30, 'night_from' => 1140],
        // نسب الإضافي فوق الأجر: نهاري، ليلي، يوم الراحة (مضاعف)، العطلة الرسمية (ثلاثة أضعاف)
        'overtime_rates' => ['day' => 0.25, 'night' => 0.5, 'rest' => 1.0, 'holiday' => 2.0],
        // حسم التأخير الصباحي: شرائح [من، إلى، معامل]؛ حتى exempt_upto دقيقة يُعفى exempt_per_month مرة بمبرر؛ أكثر من absent_after = غياب
        'late_deduction' => ['tiers' => [['from' => 1, 'to' => 15, 'factor' => 1], ['from' => 16, 'to' => 30, 'factor' => 1], ['from' => 31, 'to' => 60, 'factor' => 1.5], ['from' => 61, 'to' => 120, 'factor' => 2]],
            'exempt_upto' => 15, 'exempt_per_month' => 3, 'absent_after' => 120],
        // الإضافي بتكليف مسبق (F-04): بعد weekly_hours ساعة أسبوعياً، وبحد daily_max دقيقة باليوم
        'overtime' => ['weekly_hours' => 48, 'daily_max' => 120, 'requires_assignment' => true, 'max_continuous_hours' => 10],
        // جهاز الحضور: تجاهل التمرير المكرر خلال cooldown ثانية، واليوم بلا خروج «ناقص» بعد incomplete_after دقيقة من نهاية الوردية
        'terminal' => ['cooldown' => 10, 'incomplete_after' => 120],
        // الاستحقاق السنوي (HR.01-01): first_year حتى standard_years سنوات خدمة (السنة الأولى بالنسبة إن prorate_first_year)،
        // standard بعدها، senior بعد senior_years أو بعمر senior_age
        'annual_leave' => ['first_year' => 14, 'standard' => 21, 'senior' => 30, 'standard_years' => 5, 'senior_years' => 10, 'senior_age' => 50, 'prorate_first_year' => true],
        // قواعد طلبات الإجازة (HR.01-01-F-03، SRS §10.6): مهلة التقديم حسب المدة [حتى max_days يوماً ← days_before قبل البدء]،
        // المستوى الثالث بعد exec_after_days، البديل، التعارض، الطارئة، الساعية، الرفض، التذكير، مهلة التبرير
        'leave_rules' => [
            'notice' => [['max_days' => 1, 'days_before' => 2], ['max_days' => 4, 'days_before' => 3], ['max_days' => 7, 'days_before' => 7], ['max_days' => 365, 'days_before' => 14]],
            'hourly_notice_days' => 1, 'exec_after_days' => 7, 'substitute_required' => true, 'conflict_note_required' => true,
            'emergency_per_year' => 3, 'emergency_report_hours' => 12, 'hourly_max_per_day' => 5, 'hourly_day_hours' => 8,
            'unpaid_max_per_year' => 30, 'reject_min' => 10, 'reminder_hours' => 24, 'justify_hours' => 48, 'probation_paid' => false,
        ],
        // أنواع الإجازات (HR.01-01 + نظام العاملين): paid نسبة الأجر، balance من أي رصيد، max_days للطلب، notice تطبيق مهلة التقديم،
        // doc المستند (none|optional|required)، after_months شرط خدمة، confirmed بعد التجربة، once مرة واحدة، calendar أيام تقويمية، exec مستوى ثالث دائماً
        'leave_types' => [
            ['key' => 'annual', 'ar' => 'إجازة إدارية (سنوية)', 'en' => 'Annual leave', 'paid' => 100, 'balance' => 'annual', 'unit' => 'day', 'max_days' => null, 'notice' => true, 'doc' => 'none', 'after_months' => 0, 'confirmed' => false, 'once' => false, 'calendar' => false, 'exec' => false],
            ['key' => 'emergency', 'ar' => 'إجازة طارئة', 'en' => 'Emergency leave', 'paid' => 100, 'balance' => 'annual', 'unit' => 'day', 'max_days' => 3, 'notice' => false, 'doc' => 'optional', 'after_months' => 0, 'confirmed' => false, 'once' => false, 'calendar' => false, 'exec' => false],
            ['key' => 'hourly', 'ar' => 'إجازة ساعية', 'en' => 'Hourly leave', 'paid' => 100, 'balance' => 'annual', 'unit' => 'hour', 'max_days' => null, 'notice' => true, 'doc' => 'none', 'after_months' => 0, 'confirmed' => true, 'once' => false, 'calendar' => false, 'exec' => false],
            ['key' => 'sick', 'ar' => 'إجازة مرضية', 'en' => 'Sick leave', 'paid' => 70, 'balance' => 'sick', 'unit' => 'day', 'max_days' => null, 'notice' => false, 'doc' => 'required', 'after_months' => 0, 'confirmed' => true, 'once' => false, 'calendar' => true, 'exec' => false],
            ['key' => 'injury', 'ar' => 'إصابة عمل', 'en' => 'Work injury', 'paid' => 100, 'balance' => null, 'unit' => 'day', 'max_days' => null, 'notice' => false, 'doc' => 'required', 'after_months' => 0, 'confirmed' => false, 'once' => false, 'calendar' => true, 'exec' => false],
            ['key' => 'marriage', 'ar' => 'إجازة زواج', 'en' => 'Marriage leave', 'paid' => 100, 'balance' => null, 'unit' => 'day', 'max_days' => 7, 'notice' => true, 'doc' => 'required', 'after_months' => 6, 'confirmed' => false, 'once' => true, 'calendar' => true, 'exec' => false],
            ['key' => 'maternity', 'ar' => 'إجازة أمومة', 'en' => 'Maternity leave', 'paid' => 100, 'balance' => null, 'unit' => 'day', 'max_days' => 120, 'notice' => false, 'doc' => 'required', 'after_months' => 6, 'confirmed' => false, 'once' => false, 'calendar' => true, 'exec' => false],
            ['key' => 'paternity', 'ar' => 'إجازة أبوة', 'en' => 'Paternity leave', 'paid' => 100, 'balance' => null, 'unit' => 'day', 'max_days' => 3, 'notice' => false, 'doc' => 'required', 'after_months' => 0, 'confirmed' => true, 'once' => false, 'calendar' => true, 'exec' => false],
            ['key' => 'hajj', 'ar' => 'إجازة حج / عمرة', 'en' => 'Hajj / Umrah leave', 'paid' => 100, 'balance' => null, 'unit' => 'day', 'max_days' => 30, 'notice' => true, 'doc' => 'required', 'after_months' => 60, 'confirmed' => false, 'once' => true, 'calendar' => true, 'exec' => false],
            ['key' => 'death', 'ar' => 'إجازة وفاة', 'en' => 'Bereavement leave', 'paid' => 100, 'balance' => null, 'unit' => 'day', 'max_days' => 5, 'notice' => false, 'doc' => 'required', 'after_months' => 0, 'confirmed' => false, 'once' => false, 'calendar' => true, 'exec' => false],
            ['key' => 'unpaid', 'ar' => 'إجازة غير مدفوعة', 'en' => 'Unpaid leave', 'paid' => 0, 'balance' => 'unpaid', 'unit' => 'day', 'max_days' => 30, 'notice' => true, 'doc' => 'none', 'after_months' => 0, 'confirmed' => false, 'once' => false, 'calendar' => false, 'exec' => true],
            ['key' => 'mission', 'ar' => 'مهمة عمل', 'en' => 'Work mission', 'paid' => 100, 'balance' => null, 'unit' => 'day', 'max_days' => null, 'notice' => false, 'doc' => 'none', 'after_months' => 0, 'confirmed' => false, 'once' => false, 'calendar' => false, 'exec' => false],
        ],
        // الكشف الشهري والسلفة (D-01، SRS §10.10): الأجر اليومي = الراتب ÷ month_days، والساعة ÷ day_hours؛ الغياب غير المبرر يُحسم absent_days يوماً،
        // والمبرر justified_absent_days؛ التأمينات insurance_rate من insurance_base من الراتب؛ السلفة حتى advance_earned_ratio من المستحق حتى تاريخه
        // وبحد advance_max_ratio من الراتب، وadvance_per_month سلفة بالشهر؛ start_date = بدء احتساب الدوام في النظام (ما قبله لا يُعدّ غياباً)
        'payroll' => ['start_date' => null, 'month_days' => 30, 'day_hours' => 8, 'absent_days' => 1, 'justified_absent_days' => 0, 'insurance_base' => 0.72, 'insurance_rate' => 0.07,
            'advance_earned_ratio' => 0.5, 'advance_max_ratio' => 0.5, 'advance_per_month' => 1],
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
    /** معايرة المطابقة للشركة: القيم المحفوظة فوق الافتراضية (مفتاحاً بمفتاح، حتى لا تنكسر بإضافة إعداد جديد) */
    public static function match(): array
    {
        $saved = self::get('match') ?? [];
        $out = [];
        foreach (self::DEFAULTS['match'] as $k => $def) $out[$k] = array_merge($def, array_intersect_key($saved[$k] ?? [], $def));   // بترتيب الافتراضي (jsonb لا يحفظ الترتيب)
        return $out;
    }

    /** إعدادات الملفات الإضافية: المحفوظ فوق الافتراضي مفتاحاً بمفتاح */
    public static function docs(): array
    {
        return array_merge(self::DEFAULTS['docs'], array_intersect_key(self::get('docs') ?? [], self::DEFAULTS['docs']));
    }

    public static function rule(string $key): mixed { return self::get('rules')[$key] ?? self::DEFAULTS['rules'][$key]; }

    /** قيمة السياسة النافذة في تاريخ معيّن (افتراضياً اليوم) */
    /** قيم مؤقتة للمعاينة قبل الحفظ (withPolicies) — لا تُكتب */
    private static array $overrides = [];

    public static function withPolicies(array $values, callable $fn): mixed
    {
        $prev = self::$overrides;
        self::$overrides = $values + self::$overrides;
        try { return $fn(); } finally { self::$overrides = $prev; }
    }

    public static function policy(string $key, Carbon|string|null $on = null): mixed
    {
        if (array_key_exists($key, self::$overrides)) return self::$overrides[$key];
        $on = $on ? Carbon::parse($on)->toDateString() : now()->toDateString();
        $def = self::POLICY_DEFAULTS[$key] ?? null;
        if (microtime(true) - self::$memoAt > 5) { self::$memo = []; self::$memoAt = microtime(true); }   // العامل والمجدول يعيشان طويلاً
        $memo = Tenant::id().":{$key}:{$on}";
        if (array_key_exists($memo, self::$memo)) return self::$memo[$memo];
        $v = Cache::remember('policy:'.Tenant::id().':'.self::policyVersion().":{$key}:{$on}", 300, fn () => Policy::where('key', $key)->where('effective_from', '<=', $on)
            ->orderByDesc('effective_from')->first()?->value ?? $def);
        // نسخة قديمة محفوظة قبل إضافة مفتاح جديد للسياسة: المفتاح الناقص من الافتراضي (بترتيبه — jsonb لا يحفظ الترتيب)
        return self::$memo[$memo] = is_array($def) && ! array_is_list($def) && is_array($v) ? array_merge($def, array_intersect_key($v, $def)) : $v;
    }

    /** كاش داخل الطلب الواحد (حساب أسبوعين لكل الموظفين يقرأ السياسة آلاف المرات) */
    private static array $memo = [];
    private static float $memoAt = 0;

    /** سياسة دوام لإدارة: «key@{dept}» إن وُجدت لها نسخة نافذة، وإلا سياسة الشركة */
    public static function schedule(string $key, Carbon|string|null $on = null, ?int $deptId = null): mixed
    {
        return ($deptId ? self::policy("{$key}@{$deptId}", $on) : null) ?? self::policy($key, $on);
    }

    public static function flush(): void { Cache::flush(); self::$memo = []; }

    /** إبطال كاش السياسات للشركة الحالية فقط (بعد حفظ نسخة) */
    public static function bumpPolicies(): void { Cache::forever('policy-ver:'.Tenant::id(), self::policyVersion() + 1); self::$memo = []; }
    private static function policyVersion(): int { return (int) Cache::get('policy-ver:'.Tenant::id(), 0); }
}
