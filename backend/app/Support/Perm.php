<?php

namespace App\Support;

use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * مصفوفة الصلاحيات (SRS §10.2): دور × وحدة × إجراءات × نطاق، لكل شركة.
 * القيمة الافتراضية هنا، وتعديلات الشركة في role_permissions (لوحة الصلاحيات — M16). صلاحية المستخدم = اتحاد أدواره.
 * الخادم يتحقق في كل endpoint (middleware perm:module,action)، والواجهة تخفي ما لا يُسمح به فقط.
 */
class Perm
{
    /** الإجراءات بترتيب رموزها في المواصفات: ع ض ت ق ص ط ح */
    public const ACTIONS = [
        'view' => ['ع', 'عرض', 'View'], 'create' => ['ض', 'إضافة', 'Create'], 'edit' => ['ت', 'تعديل', 'Edit'],
        'decide' => ['ق', 'اعتماد/رفض', 'Approve/Reject'], 'export' => ['ص', 'تصدير', 'Export'], 'print' => ['ط', 'طباعة', 'Print'], 'archive' => ['ح', 'أرشفة', 'Archive'],
    ];
    private const LETTERS = ['v' => 'view', 'c' => 'create', 'e' => 'edit', 'd' => 'decide', 'x' => 'export', 'p' => 'print', 'a' => 'archive'];

    public const SCOPES = ['own' => ['خاصته', 'Own'], 'team' => ['فريقه', 'Team'], 'all' => ['الكل', 'All']];
    private const RANK = ['own' => 1, 'team' => 2, 'all' => 3];

    /** الوحدات بترتيب جدول المواصفات */
    public const MODULES = [
        'attendance' => ['الدوام (دخول/خروج)', 'Attendance (in/out)'],
        'leaves' => ['طلبات الإجازة', 'Leave requests'],
        'excuses' => ['تبرير غياب/تأخير', 'Absence/lateness justification'],
        'payroll' => ['الرواتب والسلف', 'Payroll & advances'],
        'salary_cert' => ['شهادة الراتب', 'Salary certificate'],
        'jobs' => ['الشواغر', 'Job openings'],
        'candidates' => ['المتقدمون وقاعدة السير', 'Applicants & CV database'],
        'internal' => ['الترشيح الداخلي', 'Internal nominations'],
        'referrals' => ['الإحالة ونقاطها', 'Referrals & points'],
        'announcements' => ['لوحة الإعلانات', 'Announcements'],
        'employees' => ['ملف الموظف والملفات الإضافية', 'Employee file & documents'],
        'assets' => ['العهد', 'Custody'],
        'policies' => ['السياسات', 'Policies'],
        'org' => ['الهيكل الوظيفي', 'Org structure'],
        'discipline' => ['المخالفات والجزاءات', 'Violations & penalties'],
        'performance' => ['التقييم والمكافآت', 'Performance & rewards'],
        'succession' => ['خطط التعاقب والتطوير', 'Succession & development'],
        'settings' => ['إعدادات الشركة والنماذج', 'Company settings & forms'],
        'users' => ['المستخدمون والصلاحيات', 'Users & permissions'],
        'audit' => ['سجل التدقيق', 'Audit log'],
    ];

    /** الأدوار التي تظهر في اللوحة (المتقدم ومدير المنصة خارج الشركة) */
    public const ROLES = ['employee', 'manager', 'hr_director', 'hr_officer', 'recruiter', 'finance', 'executive', 'ceo', 'admin'];

    /**
     * المصفوفة الافتراضية — منقولة من جدول SRS §10.2 (عمود HR لمدير ومسؤول الموارد البشرية، «مدير النظام» = admin).
     * الحروف: v عرض، c إضافة، e تعديل، d اعتماد، x تصدير، p طباعة، a أرشفة. ثم النطاق.
     * إضافات عملية فوق الجدول (موثّقة في CLAUDE.md): HR يعدّل ويعتمد في المتقدمين ويطبع في وحداته، والأدوار التنفيذية تعرض فقط.
     */
    private const DEFAULTS = [
        'employee' => ['attendance' => 'v:own', 'leaves' => 'cvp:own', 'excuses' => 'cv:own', 'payroll' => 'v:own', 'salary_cert' => 'cvp:own', 'internal' => 'cv:own',
            'referrals' => 'v:own', 'announcements' => 'v', 'employees' => 'vp:own', 'assets' => 'v:own', 'policies' => 'v', 'org' => 'v', 'discipline' => 'v:own',
            'performance' => 'v:own', 'succession' => 'v:own'],
        'manager' => ['attendance' => 'vp:team', 'leaves' => 'vdp:team', 'excuses' => 'vd:team', 'jobs' => 'v:team', 'candidates' => 'v:team', 'internal' => 'vd:team',
            'referrals' => 'v', 'announcements' => 'v', 'employees' => 'vp:team', 'assets' => 'v:team', 'policies' => 'v', 'org' => 'v', 'discipline' => 'cv:team',
            'performance' => 'vc:team', 'succession' => 'vce:team'],
        'hr_director' => ['attendance' => 'vexp', 'leaves' => 'vdxp', 'excuses' => 'vd', 'payroll' => 'vedxp', 'salary_cert' => 'vdp', 'jobs' => 'vce', 'candidates' => 'vcedxp',
            'internal' => 'v', 'referrals' => 've', 'announcements' => 'vcead', 'employees' => 'vcexp', 'assets' => 'vcex', 'policies' => 'vcea', 'org' => 'vexp',
            'discipline' => 'vcedxp', 'performance' => 'vcedx', 'succession' => 'vce', 'settings' => 've', 'audit' => 'v'],
        'hr_officer' => ['attendance' => 'vexp', 'leaves' => 'vdxp', 'excuses' => 'vd', 'payroll' => 'vedxp', 'salary_cert' => 'vdp', 'jobs' => 'vce', 'candidates' => 'vcedxp',
            'internal' => 'v', 'referrals' => 've', 'announcements' => 'vcea', 'employees' => 'vcexp', 'assets' => 'vcex', 'policies' => 'vcea', 'org' => 'vexp',
            'discipline' => 'vcedxp', 'performance' => 'vcedx', 'succession' => 'vce'],
        'recruiter' => ['jobs' => 'vcea', 'candidates' => 'vcedxp', 'referrals' => 'v'],
        'finance' => ['attendance' => 'vx', 'payroll' => 'vedxp', 'salary_cert' => 'vdp', 'employees' => 'v', 'assets' => 'v'],
        'executive' => ['attendance' => 'v', 'leaves' => 'v', 'payroll' => 'v', 'jobs' => 'v', 'candidates' => 'v', 'employees' => 'v', 'org' => 'vp', 'performance' => 'v', 'discipline' => 'v'],
        'ceo' => ['attendance' => 'v', 'leaves' => 'v', 'payroll' => 'v', 'jobs' => 'v', 'candidates' => 'v', 'employees' => 'v', 'org' => 'vp', 'performance' => 'v', 'discipline' => 'v'],
        'admin' => ['attendance' => 've', 'leaves' => 'v', 'excuses' => 'v', 'payroll' => 'v', 'salary_cert' => 'v', 'jobs' => 'v', 'candidates' => 'v', 'internal' => 'v',
            'referrals' => 've', 'announcements' => 'vcea', 'employees' => 'v', 'assets' => 'v', 'policies' => 'vcea', 'org' => 'vexp', 'discipline' => 'v',
            'performance' => 'v', 'succession' => 'v', 'settings' => 've', 'users' => 'vcea', 'audit' => 'vxa'],
    ];

    /** خلية افتراضية: ['actions' => [...], 'scope' => ...] */
    public static function default(string $role, string $module): array
    {
        $raw = self::DEFAULTS[$role][$module] ?? '';
        [$letters, $scope] = array_pad(explode(':', $raw), 2, 'all');
        return ['actions' => self::order(array_map(fn ($l) => self::LETTERS[$l], $letters === '' ? [] : str_split($letters))), 'scope' => $raw === '' ? 'all' : $scope];
    }

    /** مصفوفة الشركة الحالية كاملة: role => module => cell */
    public static function matrix(?int $companyId = null): array
    {
        $companyId ??= Tenant::id();
        return Cache::rememberForever("perm:{$companyId}", function () use ($companyId) {
            $rows = $companyId ? RolePermission::allCompanies()->where('company_id', $companyId)->get()->keyBy(fn ($r) => "{$r->role}.{$r->module}") : collect();
            $m = [];
            foreach (self::ROLES as $role) foreach (array_keys(self::MODULES) as $module) {
                $r = $rows->get("{$role}.{$module}");
                $m[$role][$module] = $r ? ['actions' => self::order($r->actions), 'scope' => $r->scope] : self::default($role, $module);
            }
            return $m;
        });
    }

    public static function forget(?int $companyId = null): void { Cache::forget('perm:'.($companyId ?? Tenant::id())); }

    /** صلاحيات المستخدم: module => ['actions' => [...], 'scope' => أوسع نطاق بين أدواره] — للواجهة والتحقق */
    public static function for(User $user): array
    {
        if (! $user->company_id) return [];
        $user->loadMissing('roles');
        $matrix = self::matrix($user->company_id);
        $out = [];
        foreach ($user->roles->pluck('key') as $role) foreach ($matrix[$role] ?? [] as $module => $cell) {
            if (! $cell['actions']) continue;
            $cur = $out[$module] ?? ['actions' => [], 'scope' => 'own'];
            $out[$module] = ['actions' => self::order([...$cur['actions'], ...$cell['actions']]), 'scope' => self::RANK[$cell['scope']] > self::RANK[$cur['scope']] ? $cell['scope'] : $cur['scope']];
        }
        return $out;
    }

    public static function can(?User $user, string $module, string $action = 'view'): bool
    {
        return $user && in_array($action, self::for($user)[$module]['actions'] ?? [], true);
    }

    public static function scope(User $user, string $module): ?string
    {
        return self::for($user)[$module]['scope'] ?? null;
    }

    /** إجراءات بلا تكرار وبترتيب ACTIONS */
    public static function order(array $actions): array
    {
        return array_values(array_intersect(array_keys(self::ACTIONS), $actions));
    }
}
