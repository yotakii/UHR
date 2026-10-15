<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\ApprovalChain;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AttendanceDay;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\JobOpening;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\Notice;
use App\Models\Offer;
use App\Models\Redemption;
use App\Models\Referral;
use App\Models\Resignation;
use App\Models\Reward;
use App\Models\Role;
use App\Models\ServiceRequest;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\Tenant;
use App\Models\Company;
use App\Services\Platform\CompanyDefaults;
use App\Services\Platform\CompanyProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * بيانات الاختبار = بيانات ديمو المعرض حرفياً (data/seed.ts)، بنفس المولّد العشوائي الحتمي rng(20261006)
 * وبنفس ترتيب السحب، فتطابق الأرقام والتواريخ ما يظهر في الديمو. للتطوير والتجريب فقط — لا تُحمَّل في الإنتاج.
 *   php artisan db:seed --class=TestDataSeeder
 * كلمة مرور كل الحسابات: 123456 — المدير: admin@alfarada.sy · HR: hr@alfarada.sy · موظف: samer.halabi@alfarada.sy · متقدمة: leen.ali@mail.sy
 */
class TestDataSeeder extends Seeder
{
    private int $seed = 20261006;
    private Carbon $now;
    private string $today;

    /** mulberry32 — مطابق لـ rng() في الديمو (حساب 32-bit) */
    private function r(): float
    {
        $i32 = fn (int $x): int => (($x & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000;
        $u32 = fn (int $x): int => $x & 0xFFFFFFFF;
        // Math.imul: ضرب 32-bit بتقسيم 16-bit حتى لا يفيض عدد PHP (64-bit)
        $imul = function (int $a, int $b) use ($i32, $u32): int {
            $a = $u32($a); $b = $u32($b);
            $lo = ($a & 0xFFFF) * $b;
            $hi = ((($a >> 16) * ($b & 0xFFFF)) & 0xFFFF) << 16;
            return $i32(($lo + $hi) & 0xFFFFFFFF);
        };
        $this->seed = $i32($this->seed + 0x6D2B79F5);
        $s = $this->seed;
        $t = $imul($s ^ ($u32($s) >> 15), 1 | $s);
        $t = $i32($t + $imul($t ^ ($u32($t) >> 7), 61 | $t)) ^ $t;
        return $u32($t ^ ($u32($t) >> 14)) / 4294967296;
    }

    private function f(float $x): int { return (int) floor($x); }
    private function addDays(string $iso, int $n): string { return Carbon::parse($iso.' 12:00:00')->addDays($n)->toDateString(); }
    private function isWeekend(string $iso): bool { $g = Carbon::parse($iso.' 12:00:00')->dayOfWeek; return $g === 5 || $g === 6; }
    /** ago(y, m) في الديمو: الآن − سنوات − أشهر، ثم تاريخ UTC (toISOString) */
    private function ago(int $y, int $m): string { return $this->now->copy()->subYears($y)->subMonths($m)->utc()->toDateString(); }

    public function run(): void
    {
        if (app()->isProduction()) throw new \RuntimeException('TestDataSeeder is not allowed in production.');
        $this->now = now();
        $this->today = $this->now->toDateString();

        Audit::mute(fn () => DB::transaction(function () {
            (new DefaultsSeeder)->run();
            [$atmata, $alfarada] = $this->companies();
            $this->atmata($atmata);
            Tenant::run($alfarada, fn () => $this->alfarada());
        }));
    }

    /**
     * شركتان على المنصة (SRS §10.15): Atmata مالكة المنصة وتنشر شواغرها مباشرة، والفرادة أول عميل وشواغرها تمر بمراجعة المنصة.
     * + مدير المنصة platform@atmata.sy
     */
    private function companies(): array
    {
        $prov = new CompanyProvisioner;
        $make = function (array $attrs, string $logo) use ($prov) {
            $c = Company::where('slug', $attrs['slug'])->first();
            if (! $c) { $c = Company::create($attrs); CompanyDefaults::seed($c); } else $c->update($attrs);
            $prov->setLogo($c, resource_path("logos/{$logo}"));
            return $c;
        };
        $atmata = $make(['slug' => 'atmata', 'name_ar' => 'مجموعة أتمتة', 'name_en' => 'Atmata Group', 'short_ar' => 'أتمتة', 'short_en' => 'Atmata', 'brand_latin' => 'ATMATA GROUP',
            'about_ar' => 'شركة سورية للحلول التقنية وأتمتة الأعمال، مالكة منصة UHR.', 'about_en' => 'Syrian technology and business-automation company, owner of the UHR platform.',
            'website' => 'https://atmata.sy', 'status' => 'active', 'portal_enabled' => true, 'portal_review' => false, 'is_platform' => true], 'atmata.png');
        $alfarada = $make(['slug' => 'alfarada', 'name_ar' => 'شركة الفرادة للتطوير والاستثمار', 'name_en' => 'Uniqueness Development & Investment Co.', 'short_ar' => 'مجموعة الفرادة', 'short_en' => 'Uniqueness Group',
            'brand_latin' => 'UNIQUENESS GROUP', 'about_ar' => 'مجموعة سورية للتطوير والاستثمار في دمشق وحلب وحمص.', 'about_en' => 'Syrian development and investment group in Damascus, Aleppo and Homs.',
            'website' => 'https://alfarada.sy', 'status' => 'active', 'portal_enabled' => true, 'portal_review' => true, 'is_platform' => false], 'alfarada.png');

        $platform = User::updateOrCreate(['email' => 'platform@atmata.sy'], ['name' => 'مدير المنصة', 'name_en' => 'Platform admin', 'company_id' => null, 'password' => Hash::make('123456'), 'must_change_password' => false, 'active' => true]);
        $platform->roles()->sync(Role::where('key', 'platform_admin')->pluck('id'));
        return [$atmata, $alfarada];
    }

    /** Atmata: إدارتان، مسؤولة موارد بشرية ومسؤول توظيف، وثلاث شواغر منشورة على البوابة */
    private function atmata(Company $atmata): void
    {
        Tenant::run($atmata, function () use ($atmata) {
            $loc = Location::pluck('id', 'code');
            $it = Department::updateOrCreate(['code' => 'eng'], ['name_ar' => 'الهندسة والتطوير', 'name_en' => 'Engineering', 'color' => 'brand', 'sort' => 1]);
            $ops = Department::updateOrCreate(['code' => 'delivery'], ['name_ar' => 'تنفيذ المشاريع', 'name_en' => 'Project Delivery', 'color' => 'brass', 'sort' => 2]);
            $J = [
                ['ATM-2026-001', 'مهندس Laravel', 'Laravel Engineer', $it, 'dam', 'full_time', 3, 2, 900, 1400, 'بناء أنظمة الشركات (ERP وHR) بـ Laravel وPostgreSQL ضمن فريق منتجات Atmata.', 'Build business systems (ERP and HR) with Laravel and PostgreSQL in Atmata’s product team.',
                    [['خبرة 3 سنوات في PHP وLaravel', '3 years of PHP and Laravel'], ['قواعد بيانات علائقية وتصميم API', 'Relational databases and API design'], ['العمل بـ Git واختبارات آلية', 'Git and automated testing']], true],
                ['ATM-2026-002', 'مطور واجهات React', 'React Front-end Developer', $it, 'dam', 'full_time', 2, 2, 800, 1200, 'تطوير واجهات ثنائية اللغة سريعة ومتجاوبة لمنتجات الشركة.', 'Build fast, responsive, bilingual interfaces for our products.',
                    [['React وTypeScript', 'React and TypeScript'], ['خبرة بالواجهات العربية RTL', 'Arabic RTL interfaces'], ['حس تصميمي ودقة', 'Design sense and attention to detail']], false],
                ['ATM-2026-003', 'مدير مشاريع تقنية', 'Technical Project Manager', $ops, 'alp', 'full_time', 5, 2, 1100, 1600, 'إدارة تنفيذ أنظمة Atmata لدى العملاء من التحليل حتى التشغيل.', 'Lead Atmata system rollouts at client sites from analysis to go-live.',
                    [['خبرة 5 سنوات بإدارة المشاريع', '5 years managing projects'], ['شهادة PMP أو ما يعادلها ميزة', 'PMP or equivalent is a plus'], ['استعداد للسفر بين المحافظات', 'Willing to travel between governorates']], false],
            ];
            foreach ($J as $i => $j) JobOpening::updateOrCreate(['code' => $j[0]], [
                'title_ar' => $j[1], 'title_en' => $j[2], 'department_id' => $j[3]->id, 'location_id' => $loc[$j[4]], 'employment_type' => $j[5], 'openings' => 1,
                'min_years' => $j[6], 'degree' => $j[7], 'salary_min' => $j[8], 'salary_max' => $j[9], 'currency' => 'USD', 'summary_ar' => $j[10], 'summary_en' => $j[11],
                'requirements' => array_map(fn ($r) => ['ar' => $r[0], 'en' => $r[1]], $j[12]), 'status' => 'open', 'posted_at' => $this->addDays($this->today, -(1 + $i * 2)),
                'visibility' => 'public', 'portal_status' => 'approved', 'hard_to_fill' => $j[13],
            ]);
            $role = Role::pluck('id', 'key');
            foreach ([['hr@atmata.sy', 'سلمى الحكيم', 'Salma Al-Hakim', ['admin', 'hr_director']], ['jobs@atmata.sy', 'كريم داود', 'Karim Daoud', ['recruiter']]] as [$email, $ar, $en, $roles]) {
                $u = User::updateOrCreate(['email' => $email], ['name' => $ar, 'name_en' => $en, 'company_id' => $atmata->id, 'password' => Hash::make('123456'), 'must_change_password' => false, 'active' => true]);
                $u->roles()->sync(collect($roles)->map(fn ($k) => $role[$k])->all());
            }
        });
    }

    /** الفرادة = بيانات ديمو المعرض حرفياً */
    private function alfarada(): void
    {
            $loc = Location::pluck('id', 'code');
            $depts = $this->departments();
            [$employees, $emp] = $this->employees($loc, $depts);
            $jobs = $this->jobs($loc, $depts);
            $cands = $this->candidates($jobs);
            $this->assets($employees);
            $this->attendance($employees);
            $this->requests($emp);
            $this->engagement($emp, $jobs, $cands);
            $this->users($employees, $cands);
            Counters::atLeast('employee', 1000 + count($employees));
            Counters::atLeast('asset', Asset::count());
            Counters::atLeast('cand-app-'.now()->year, 48210 + 9 * 37);
    }

    private function departments(): array
    {
        $list = [['exec', 'الإدارة التنفيذية', 'Executive Office', 'brand'], ['hr', 'الموارد البشرية', 'Human Resources', 'info'], ['fin', 'الإدارة المالية', 'Finance', 'brass'],
            ['it', 'تقنية المعلومات', 'Information Technology', 'brand'], ['sales', 'المبيعات', 'Sales', 'danger'], ['ops', 'العمليات والمشاريع', 'Operations & Projects', 'brass'],
            ['cs', 'خدمة العملاء', 'Customer Service', 'info'], ['admin', 'الشؤون الإدارية', 'Administration', 'ink-3']];
        $out = [];
        foreach ($list as $i => [$code, $ar, $en, $color]) $out[$code] = Department::updateOrCreate(['code' => $code], ['name_ar' => $ar, 'name_en' => $en, 'color' => $color, 'sort' => $i, 'headcount' => 0]);
        return $out;
    }

    private function employees($loc, array $depts): array
    {
        // name, nameEn, gender, titleAr, titleEn, dept, city, salary USD, hire "y.m"
        $E = [
            ['هاني السمان', 'Hani Al-Samman', 'M', 'الرئيس التنفيذي', 'Chief Executive Officer', 'exec', 'dam', 4800, '9.2'],
            ['مازن الدروبي', 'Mazen Al-Droubi', 'M', 'المدير التنفيذي', 'Executive Director', 'exec', 'dam', 3600, '7.5'],
            ['رشا الخطيب', 'Rasha Al-Khatib', 'F', 'مديرة الموارد البشرية', 'HR Director', 'hr', 'dam', 2400, '5.1'],
            ['باسل الأتاسي', 'Basel Al-Atassi', 'M', 'المدير المالي', 'Finance Director', 'fin', 'dam', 2600, '6.3'],
            ['نور الحسن', 'Nour Al-Hassan', 'F', 'أخصائية توظيف', 'Recruitment Specialist', 'hr', 'dam', 850, '2.4'],
            ['ريم قباني', 'Reem Kabbani', 'F', 'أخصائية رواتب وشؤون موظفين', 'Payroll & Personnel Officer', 'hr', 'dam', 780, '3.7'],
            ['سامر الحلبي', 'Samer Al-Halabi', 'M', 'مطور برمجيات أول', 'Senior Software Developer', 'it', 'dam', 1450, '3.1'],
            ['علاء شيخاني', 'Alaa Sheikhani', 'M', 'مدير تقنية المعلومات', 'IT Manager', 'it', 'dam', 2100, '6.8'],
            ['لمى العظمة', 'Lama Al-Azmeh', 'F', 'مهندسة شبكات', 'Network Engineer', 'it', 'dam', 1050, '2.2'],
            ['يزن المصري', 'Yazan Al-Masri', 'M', 'مطور واجهات', 'Front-end Developer', 'it', 'dam', 900, '1.4'],
            ['كنان سليمان', 'Kinan Suleiman', 'M', 'فني دعم تقني', 'IT Support Technician', 'it', 'hom', 520, '0.9'],
            ['دانة الشامي', 'Dana Al-Shami', 'F', 'محاسبة أولى', 'Senior Accountant', 'fin', 'dam', 1100, '4.6'],
            ['عمر النابلسي', 'Omar Al-Nabulsi', 'M', 'محاسب', 'Accountant', 'fin', 'alp', 680, '1.8'],
            ['فراس الجندي', 'Firas Al-Jundi', 'M', 'مدير المبيعات', 'Sales Manager', 'sales', 'dam', 2000, '5.5'],
            ['هبة الزعبي', 'Heba Al-Zoubi', 'F', 'مسؤولة مبيعات', 'Sales Executive', 'sales', 'dam', 700, '1.2'],
            ['جود الأيوبي', 'Joud Al-Ayoubi', 'F', 'مسؤولة مبيعات', 'Sales Executive', 'sales', 'alp', 650, '0.7'],
            ['محمد الحمصي', 'Mohammad Al-Homsi', 'M', 'مسؤول حسابات كبرى', 'Key Account Manager', 'sales', 'hom', 1150, '3.3'],
            ['أنس داوود', 'Anas Dawoud', 'M', 'مندوب مبيعات', 'Sales Representative', 'sales', 'lat', 480, '0.5'],
            ['طارق الحكيم', 'Tarek Al-Hakim', 'M', 'مدير العمليات والمشاريع', 'Operations Manager', 'ops', 'dam', 2300, '6.1'],
            ['سلاف حداد', 'Sulaf Haddad', 'F', 'مديرة مشاريع', 'Project Manager', 'ops', 'dam', 1500, '3.9'],
            ['خالد العمر', 'Khaled Al-Omar', 'M', 'مهندس تنفيذ', 'Site Engineer', 'ops', 'ham', 900, '2.6'],
            ['رامي عيسى', 'Rami Issa', 'M', 'مهندس تنفيذ', 'Site Engineer', 'ops', 'tar', 880, '1.9'],
            ['إياد الصباغ', 'Iyad Al-Sabbagh', 'M', 'فني تركيب', 'Installation Technician', 'ops', 'dam', 520, '4.2'],
            ['وسيم مرعي', "Wassim Mar'i", 'M', 'فني تركيب', 'Installation Technician', 'ops', 'hom', 500, '1.1'],
            ['ميس الكردي', 'Mais Al-Kurdi', 'F', 'مشرفة خدمة العملاء', 'Customer Service Supervisor', 'cs', 'dam', 900, '3.4'],
            ['تالا خوري', 'Tala Khoury', 'F', 'موظفة خدمة عملاء', 'Customer Service Agent', 'cs', 'dam', 450, '0.8'],
            ['بشرى العلي', 'Bushra Al-Ali', 'F', 'موظفة خدمة عملاء', 'Customer Service Agent', 'cs', 'lat', 430, '1.6'],
            ['حسام البيطار', 'Hussam Al-Bitar', 'M', 'موظف خدمة عملاء', 'Customer Service Agent', 'cs', 'alp', 430, '2.1'],
            ['غيث المحمد', 'Ghaith Al-Mohammad', 'M', 'مدير الشؤون الإدارية', 'Administration Manager', 'admin', 'dam', 1250, '7.0'],
            ['سوسن درويش', 'Sawsan Darwish', 'F', 'سكرتيرة تنفيذية', 'Executive Secretary', 'admin', 'dam', 620, '2.9'],
            ['أيهم يوسف', 'Ayham Youssef', 'M', 'سائق', 'Driver', 'admin', 'dam', 380, '5.0'],
            ['زياد قاسم', 'Ziad Kassem', 'M', 'مسؤول مستودع', 'Warehouse Officer', 'admin', 'dam', 520, '3.0'],
        ];
        $emailOf = fn (string $en) => implode('.', array_filter(array_map(fn ($w) => preg_replace('/[^a-z]/', '', preg_replace('/^al-/', '', $w)), explode(' ', strtolower($en))))).'@alfarada.sy';
        $rows = []; $byCode = [];
        foreach ($E as $i => $e) {
            [$y, $m] = array_map('intval', explode('.', $e[8]));
            $phone = '+963 9'.(3 + ($i % 6)).$this->f($this->r() * 9).' '.(100 + $this->f($this->r() * 899)).' '.(100 + $this->f($this->r() * 899));
            $hire = $this->ago($y, $m);
            $dobY = 26 + $this->f($this->r() * 26); $dobM = $this->f($this->r() * 11); $dob = $this->ago($dobY, $dobM);
            $nid = '0'.$this->f(1e9 + $this->r() * 8.9e9);
            $nfc = '04:'.strtoupper(dechex(0x10 + $i)).':A7:'.str_pad(strtoupper(dechex($this->f($this->r() * 255))), 2, '0', STR_PAD_LEFT).':6C';
            $leaveUsed = $this->f($this->r() * 12);
            $sickUsed = $this->r() < 0.25 ? $this->f($this->r() * 4) : 0;
            $code = 'UHR-'.(1001 + $i);
            $emp = Employee::updateOrCreate(['code' => $code], [
                'name' => $e[0], 'name_en' => $e[1], 'gender' => $e[2], 'title_ar' => $e[3], 'title_en' => $e[4],
                'department_id' => $depts[$e[5]]->id, 'location_id' => $loc[$e[6]], 'email' => $emailOf($e[1]), 'phone' => $phone,
                'hire_date' => $hire, 'dob' => $dob, 'national_id' => $nid, 'salary' => $e[7], 'currency' => 'USD',
                'status' => ($y === 0 && $m < 3) ? 'probation' : 'active', 'nfc_uid' => $nfc,
                'leave_used' => $leaveUsed, 'sick_used' => $sickUsed,
            ]);
            if (! $emp->logs()->exists()) $emp->log('مباشرة العمل', 'Started work', 'ok', null, $hire.' 09:00:00');
            $rows[] = $emp; $byCode['e'.(1001 + $i)] = $emp;
        }
        // المدراء: رئيس الإدارة؛ رؤساء الإدارات يتبعون المدير التنفيذي (e1002)، والرئيس التنفيذي هو الجذر
        $headOf = ['exec' => 'e1001', 'hr' => 'e1003', 'fin' => 'e1004', 'it' => 'e1008', 'sales' => 'e1014', 'ops' => 'e1019', 'cs' => 'e1025', 'admin' => 'e1029'];
        foreach ($rows as $i => $emp) {
            $key = 'e'.(1001 + $i); $dept = $E[$i][5]; $h = $headOf[$dept];
            $mgr = $h === $key ? ($key === 'e1001' ? null : $byCode['e1002']->id) : $byCode[$h]->id;
            $emp->update(['manager_id' => $mgr]);
        }
        foreach ($headOf as $d => $k) $depts[$d]->update(['head_id' => $byCode[$k]->id, 'headcount' => collect($rows)->where('department_id', $depts[$d]->id)->count() + 2]);
        $byCode['e1028']->update(['status' => 'resigning']);
        return [$rows, $byCode];
    }

    private function jobs($loc, array $depts): array
    {
        $J = [
            ['j1', 'REQ-2026-031', 'مطور برمجيات أول', 'Senior Software Developer', 'it', 'dam', 'full_time', 2, 5, 2, 1200, 1700, 'تطوير وصيانة أنظمة الشركة الداخلية والعمل مع فريق المشاريع على حلول العملاء.', 'Build and maintain internal systems and deliver client solutions with the projects team.',
                [['إجازة في هندسة المعلوماتية أو ما يعادلها', 'Degree in IT engineering or equivalent'], ['خبرة 5 سنوات في تطوير تطبيقات الويب', '5 years building web applications'], ['خبرة بقواعد البيانات العلائقية', 'Relational database experience']]],
            ['j2', 'REQ-2026-034', 'محاسب', 'Accountant', 'fin', 'dam', 'full_time', 1, 2, 2, 600, 850, 'تسجيل القيود اليومية، مطابقة الحسابات المصرفية، وإعداد التقارير الشهرية.', 'Daily entries, bank reconciliations and monthly reporting.',
                [['إجازة في المحاسبة', 'Accounting degree'], ['إتقان برامج المحاسبة', 'Accounting software'], ['خبرة سنتين على الأقل', 'At least 2 years experience']]],
            ['j3', 'REQ-2026-036', 'مسؤول مبيعات', 'Sales Executive', 'sales', 'alp', 'full_time', 2, 1, 2, 500, 800, 'بناء علاقات مع عملاء الشركات في حلب ومتابعة العروض حتى التعاقد.', 'Build corporate client relationships in Aleppo and follow quotes to contract.',
                [['خبرة في مبيعات الشركات', 'B2B sales experience'], ['امتلاك رخصة قيادة', 'Driving licence'], ['مهارات تفاوض وعرض', 'Negotiation & presentation skills']]],
            ['j4', 'REQ-2026-038', 'موظف خدمة عملاء', 'Customer Service Agent', 'cs', 'dam', 'shifts', 3, 0, 1, 380, 500, 'الرد على اتصالات العملاء ومتابعة الشكاوى ضمن نظام ورديات.', 'Handle customer calls and follow up complaints on a shift basis.',
                [['مهارات تواصل ممتازة', 'Excellent communication'], ['استعداد للعمل بنظام الورديات', 'Shift availability'], ['استخدام الحاسب', 'Computer literacy']]],
            ['j5', 'REQ-2026-040', 'مهندس تنفيذ مشاريع', 'Project Site Engineer', 'ops', 'hom', 'full_time', 1, 3, 2, 850, 1100, 'الإشراف على تنفيذ مشاريع التيار الخفيف والبنية التحتية في المنطقة الوسطى.', 'Supervise low-current and infrastructure projects in the central region.',
                [['هندسة كهربائية أو اتصالات', 'Electrical or telecom engineering'], ['خبرة 3 سنوات بالتنفيذ', '3 years on-site'], ['استعداد للتنقل بين المحافظات', 'Willing to travel between governorates']]],
            ['j6', 'REQ-2026-041', 'أخصائي موارد بشرية', 'HR Generalist', 'hr', 'dam', 'full_time', 1, 3, 2, 700, 950, 'متابعة شؤون الموظفين والحضور والإجازات وتطبيق السياسات الداخلية.', 'Run personnel affairs, attendance, leave and internal policies.',
                [['خبرة 3 سنوات في الموارد البشرية', '3 years HR experience'], ['معرفة بقانون العمل السوري', 'Knowledge of Syrian labour law'], ['إتقان Excel', 'Strong Excel']]],
        ];
        $out = [];
        foreach ($J as $i => $j) {
            $out[$j[0]] = JobOpening::updateOrCreate(['code' => $j[1]], [
                'title_ar' => $j[2], 'title_en' => $j[3], 'department_id' => $depts[$j[4]]->id, 'location_id' => $loc[$j[5]], 'employment_type' => $j[6],
                'openings' => $j[7], 'min_years' => $j[8], 'degree' => $j[9], 'salary_min' => $j[10], 'salary_max' => $j[11], 'currency' => 'USD',
                'summary_ar' => $j[12], 'summary_en' => $j[13], 'requirements' => array_map(fn ($r) => ['ar' => $r[0], 'en' => $r[1]], $j[14]),
                'status' => 'open', 'posted_at' => $this->addDays($this->today, -(4 + $i * 3)),
                // على البوابة العامة؛ آخر وظيفة بانتظار مراجعة المنصة (لتجربة المراجعة) وتظهر على صفحة الشركة فقط
                'visibility' => 'public', 'portal_status' => $j[0] === 'j6' ? 'pending' : 'approved',
            ]);
        }
        Counters::atLeast('job-2026', 41);
        return $out;
    }

    private function candidates(array $jobs): array
    {
        $EN = ['Ahmad Al-Mansour', 'Raghad Al-Sayed', 'Mohannad Al-Khatib', 'Shahd Al-Abdullah', 'Qusay Al-Hamad', 'Nada Ismail', 'Maher Al-Shaar', 'Jana Al-Saleh', 'Leen Al-Ali', 'Bilal Hamdan'];
        // name, gender, jobId, stage, daysInStage, yearsExp, degree, salary, english, city
        $C = [
            ['أحمد المنصور', 'M', 'j1', 'new', 1, 6, 2, 1500, 'good', 'دمشق'], ['رغد السيد', 'F', 'j2', 'new', 0, 3, 2, 720, 'fair', 'دمشق'],
            ['مهند الخطيب', 'M', 'j3', 'new', 3, 2, 2, 650, 'good', 'حلب'], ['شهد العبدالله', 'F', 'j4', 'screening', 2, 1, 1, 420, 'fair', 'دمشق'],
            ['قصي الحمد', 'M', 'j1', 'screening', 4, 8, 3, 1900, 'excellent', 'دمشق'], ['ندى إسماعيل', 'F', 'j6', 'interview', 2, 4, 2, 880, 'good', 'دمشق'],
            ['ماهر الشعار', 'M', 'j5', 'interview', 6, 5, 2, 1050, 'fair', 'حمص'], ['جنى الصالح', 'F', 'j3', 'technical', 1, 3, 2, 700, 'excellent', 'حلب'],
            ['لين العلي', 'F', 'j2', 'offer', 1, 4, 2, 800, 'good', 'دمشق'], ['بلال حمدان', 'M', 'j4', 'rejected', 5, 0, 0, 600, 'weak', 'دمشق'],
        ];
        $cvs = ['Ahmad_Mansour', 'Raghad_Sayed', 'Mohannad_Khatib', 'Shahd_Abdullah', 'Qusay_Hamad', 'Nada_Ismail', 'Maher_Shaar', 'Jana_Saleh', 'Leen_Ali', 'Bilal_Hamdan'];
        $phases = ['ثانوية', 'معهد متوسط', 'إجازة جامعية', 'ماجستير'];
        $out = [];
        foreach ($C as $i => $c) {
            $job = $jobs[$c[2]]; $dept = $job->department->code;
            $created = $this->addDays($this->today, -($c[4] + 3 + $this->f($this->r() * 6)));
            $marital = $this->r() < 0.5 ? 'single' : 'married';
            $dob = $this->ago(23 + $this->f($this->r() * 14), 3);
            $nid = '0'.$this->f(1e9 + $this->r() * 8.9e9);
            $linkedin = $this->r() < 0.6 ? 'linkedin.com/in/c'.($i + 1) : '';
            $shifts = $this->r() < 0.6; $travel = $this->r() < 0.5;
            $cvSize = 180000 + $this->f($this->r() * 300000);
            $cand = Candidate::updateOrCreate(['ref' => 'UNQ-2026-APP-'.(48210 + $i * 37)], [
                'job_opening_id' => $job->id, 'stage' => $c[3], 'stage_at' => $this->addDays($this->today, -$c[4]),
                'name' => $c[0], 'name_en' => $EN[$i], 'gender' => $c[1], 'marital' => $marital, 'nationality' => 'سورية', 'dob' => $dob, 'pob' => $c[9],
                'national_id' => $nid, 'blood' => ['A+', 'O+', 'B+', 'AB+'][$i % 4], 'email' => $c[3] === 'offer' ? 'leen.ali@mail.sy' : 'cand'.($i + 1).'@mail.sy',
                'mobile' => '+963 9'.(4 + ($i % 5)).$i.' 2'.(10 + $i).(30 + $i).' '.(400 + $i * 11), 'address' => $c[9], 'linkedin' => $linkedin,
                'education' => [['phase' => $phases[$c[6]] ?? 'إجازة جامعية', 'institute' => $c[9] === 'حلب' ? 'جامعة حلب' : ($c[9] === 'حمص' ? 'جامعة البعث' : 'جامعة دمشق'),
                    'major' => match ($dept) { 'fin' => 'محاسبة', 'it' => 'هندسة معلوماتية', 'ops' => 'هندسة كهربائية', 'hr' => 'إدارة أعمال', default => 'اقتصاد' }, 'year' => (string) (2026 - $c[5] - 1), 'grade' => ['جيد', 'جيد جداً', 'امتياز'][$i % 3]]],
                'experience' => $c[5] ? [['company' => ['شركة الشام للتقنية', 'مجموعة قاسيون', 'بنك سورية الدولي', 'شركة البيان للتجارة', 'مؤسسة الفرات'][$i % 5], 'position' => $job->title_ar, 'from' => (string) (2026 - $c[5]), 'to' => '2026', 'tasks' => '', 'reason' => 'فرصة أفضل']] : [],
                'refs' => [['name' => 'م. سليم حداد', 'job' => 'مدير سابق', 'phone' => '+963 944 120 330']],
                'english' => $c[8], 'arabic' => 'native', 'shifts' => $shifts, 'travel' => $travel, 'public_sector' => false, 'conviction' => false,
                'salary' => $c[7], 'availability' => $this->addDays($this->today, 30), 'source' => ['website', 'linkedin', 'referral', 'website'][$i % 4],
                'years_exp' => $c[5], 'degree_level' => $c[6], 'reject_reason' => $c[3] === 'rejected' ? 'لا تتوفر الخبرة المطلوبة' : null,
                'created_at' => $created.' 10:20:00',
            ]);
            $cand->logs()->delete();
            $cand->log('استلام طلب التوظيف عبر البوابة', 'Application received via portal', 'info', 'F-28', $created.' 10:20:00');
            // ملف السيرة: الاسم والحجم فقط (لا محتوى في بيانات الاختبار)
            $file = StoredFile::updateOrCreate(['path' => 'cv/test/'.$cvs[$i].'_CV.pdf'], ['disk' => 'local', 'name' => $cvs[$i].'_CV.pdf', 'size' => $cvSize, 'mime' => 'application/pdf']);
            $cand->update(['cv_file_id' => $file->id]);
            $cand->update(['match_score' => \App\Services\Recruitment\MatchScore::total($cand, $job)]);

            if (in_array($c[3], ['interview', 'technical', 'offer'], true)) $cand->log('اجتياز الفرز الأولي', 'Passed screening', 'ok', null, $this->addDays($this->today, -$c[4] - 2).' 11:00:00');
            if (in_array($c[3], ['technical', 'offer'], true)) {
                $ratings = [];
                foreach (['readiness', 'physical', 'education', 'experience', 'knowledge', 'english', 'comm', 'negotiation', 'computer', 'flex', 'learning', 'overtime', 'professional', 'behavior', 'body', 'confidence', 'maturity', 'responsibility', 'problem', 'goals', 'risk', 'planning', 'influence', 'team'] as $k) $ratings[$k] = 3 + (int) round($this->r() * 2);
                $pct = (int) round(array_sum($ratings) / 120 * 100);
                Interview::updateOrCreate(['candidate_id' => $cand->id], ['ratings' => $ratings, 'notes' => 'مرشحة متمكنة، تواصل جيد وخبرة مناسبة.', 'recommendation' => 'recommended', 'interviewer' => 'نور الحسن', 'date' => $this->addDays($this->today, -$c[4] - 1), 'pct' => $pct]);
                $cand->log('تقييم المقابلة الأولية: موصى به', 'HR interview: recommended', 'ok', 'F-25', $this->addDays($this->today, -$c[4] - 1).' 12:30:00');
            }
            if ($c[3] === 'offer') {
                Offer::updateOrCreate(['candidate_id' => $cand->id], ['title' => $job->title_ar, 'contract' => 'fixed', 'currency' => 'USD', 'basic' => 650, 'housing' => 80, 'transport' => 40, 'fuel' => 30, 'leave_days' => 14, 'start_date' => $this->addDays($this->today, 10), 'issued_at' => $this->addDays($this->today, -1).' 13:00:00', 'status' => 'sent']);
                $cand->log('إرسال عرض العمل للمرشحة', 'Offer sent', 'info', 'F-29', $this->addDays($this->today, -1).' 13:00:00');
            }
            if ($c[3] === 'rejected') $cand->log('اعتذار: لا تتوفر الخبرة المطلوبة', 'Declined: insufficient experience', 'danger', null, $this->addDays($this->today, -$c[4]).' 09:00:00');
            $out['c'.($i + 1)] = $cand;
        }
        return $out;
    }

    private function assets(array $employees): void
    {
        $T = [['حاسب محمول Dell Latitude 5440', 'Dell Latitude 5440 laptop', 'computers', 950], ['حاسب مكتبي HP ProDesk', 'HP ProDesk desktop', 'computers', 650],
            ['هاتف Samsung Galaxy A55', 'Samsung Galaxy A55 phone', 'phones', 380], ['هاتف IP Yeastar', 'Yeastar IP phone', 'phones', 120], ['بطاقة NFC للحضور', 'NFC attendance card', 'cards', 5],
            ['سيارة خدمة Kia Rio', 'Kia Rio service car', 'vehicles', 14000], ['شاشة Dell 24 بوصة', 'Dell 24" monitor', 'peripherals', 160]];
        $cat = AssetCategory::pluck('id', 'code');
        $ai = 1;
        $make = function (int $t, ?Employee $e) use ($T, $cat, &$ai) {
            $a = $T[$t]; $serial = 'SN'.$this->f(1e7 + $this->r() * 8.9e7);
            Asset::updateOrCreate(['tag' => 'AST-'.str_pad((string) $ai, 4, '0', STR_PAD_LEFT)], ['name_ar' => $a[0], 'name_en' => $a[1], 'asset_category_id' => $cat[$a[2]], 'serial' => $serial, 'value' => $a[3],
                'employee_id' => $e?->id, 'status' => $e ? 'assigned' : 'available', 'assigned_at' => $e?->hire_date, 'signed_at' => $e?->hire_date]);
            $ai++;
        };
        foreach ($employees as $i => $e) {
            $code = $e->department->code; $give = [4];
            if (in_array($code, ['it', 'fin', 'hr', 'exec'], true)) $give[] = 0;
            if (in_array($code, ['sales', 'ops'], true)) $give[] = 2;
            if ($code === 'cs') array_push($give, 3, 1);
            if ($i === 13 || $i === 18) $give[] = 5;
            foreach ($give as $t) $make($t, $e);
        }
        foreach ([0, 0, 2, 4, 4, 4, 6, 1] as $t) $make($t, null);
    }

    private function attendance(array $employees): void
    {
        AttendanceDay::whereIn('employee_id', collect($employees)->pluck('id'))->delete();
        $nowMin = $this->now->hour * 60 + $this->now->minute;
        $rows = [];
        for ($k = 14; $k >= 0; $k--) {
            $date = $this->addDays($this->today, -$k);
            if ($this->isWeekend($date)) continue;
            foreach ($employees as $i => $e) {
                if ($e->code === 'UHR-1007' && $k === 0) continue;   // سامر: بلا حضور اليوم
                $x = $this->r();
                if ($x < 0.04) { $rows[] = [$e->id, $date, null, null, 'absent']; continue; }
                if ($x < 0.08) { $rows[] = [$e->id, $date, null, null, 'leave']; continue; }
                $lateBias = $i % 7 === 3 ? 0.45 : 0.12;
                $inM = $this->r() < $lateBias ? 8 * 60 + 16 + $this->f($this->r() * 40) : 7 * 60 + 35 + $this->f($this->r() * 38);
                $outM = $this->r() < 0.18 ? 16 * 60 + 35 + $this->f($this->r() * 120) : 16 * 60 + $this->f($this->r() * 20);
                $t = fn (int $m) => sprintf('%s %02d:%02d:00', $date, intdiv($m, 60), $m % 60);
                if ($k === 0) { if ($inM > $nowMin) continue; $rows[] = [$e->id, $date, $t($inM), $outM <= $nowMin ? $t($outM) : null, 'present']; }
                else $rows[] = [$e->id, $date, $t($inM), $t($outM), 'present'];
            }
        }
        $now = now();
        foreach (array_chunk($rows, 200) as $chunk) AttendanceDay::insert(array_map(fn ($r) => ['company_id' => Tenant::id(), 'employee_id' => $r[0], 'date' => $r[1], 'in_at' => $r[2], 'out_at' => $r[3], 'status' => $r[4], 'justified' => false, 'created_at' => $now, 'updated_at' => $now], $chunk));
    }

    private function requests(array $emp): void
    {
        $mgr = ['role' => ['ar' => 'المدير المباشر', 'en' => 'Direct manager']]; $hr = ['role' => ['ar' => 'الموارد البشرية', 'en' => 'Human Resources']];
        $d = fn (int $n) => $this->addDays($this->today, $n);
        LeaveRequest::query()->delete();
        $L = [
            ['e1015', 'annual', $d(5), $d(9), null, null, null, 'سفر عائلي', $d(-1).' 10:00:00', 'pending_manager', [$mgr, $hr]],
            ['e1021', 'mission', $d(1), $d(3), null, null, null, 'استلام موقع مشروع حماة', $d(-1).' 15:20:00', 'pending_hr', [$mgr + ['by' => 'طارق الحكيم', 'at' => $d(0).'T08:40:00', 'decision' => 'approved'], $hr]],
            ['e1026', 'justify', $d(-1), $d(-1), null, 34, $d(-1), 'ازدحام على طريق المزة', $d(-1).' 09:10:00', 'pending_manager', [$mgr, $hr]],
            ['e1013', 'sick', $d(-6), $d(-5), null, null, null, 'تقرير طبي مرفق', $d(-6).' 07:30:00', 'approved', [$mgr + ['by' => 'باسل الأتاسي', 'at' => $d(-6).'T09:00:00', 'decision' => 'approved'], $hr + ['by' => 'رشا الخطيب', 'at' => $d(-6).'T10:00:00', 'decision' => 'approved']]],
            ['e1010', 'hourly', $this->today, $this->today, 2, null, null, 'مراجعة دائرة رسمية', $this->today.' 08:05:00', 'pending_manager', [$mgr, $hr]],
        ];
        foreach ($L as $l) LeaveRequest::create(['employee_id' => $emp[$l[0]]->id, 'type' => $l[1], 'from' => $l[2], 'to' => $l[3], 'hours' => $l[4], 'minutes' => $l[5], 'ref_date' => $l[6], 'reason' => $l[7], 'created_at' => $l[8], 'status' => $l[9], 'chain' => $l[10]]);

        Resignation::query()->delete();
        Resignation::create(['employee_id' => $emp['e1028']->id, 'created_at' => $d(-3).' 11:00:00', 'last_day' => $d(27), 'reason_key' => 'travel', 'status' => 'pending_hr',
            'text' => 'أتقدم بطلب قبول استقالتي بسبب السفر خارج القطر، مع التزامي بإتمام فترة الإخطار وتسليم المهام.',
            'clearance' => ['custody' => false, 'it' => false, 'finance' => false, 'hr' => false], 'chain' => [$mgr + ['by' => 'ميس الكردي', 'at' => $d(-2).'T09:30:00'], $hr]]);

        ServiceRequest::query()->delete();
        ServiceRequest::create(['employee_id' => $emp['e1012']->id, 'kind' => 'salary_cert', 'note' => 'لتقديمها إلى المصرف', 'status' => 'pending', 'created_at' => $d(-1).' 10:10:00']);
    }

    private function engagement(array $emp, array $jobs, array $cands): void
    {
        $d = fn (int $n) => $this->addDays($this->today, $n);
        foreach (['e1007' => [2650, 4200], 'e1003' => [1900, 3100], 'e1017' => [1250, 1800], 'e1014' => [800, 1500], 'e1025' => [600, 900], 'e1009' => [350, 500]] as $k => [$p, $l]) $emp[$k]->update(['points' => $p, 'lifetime_points' => $l]);
        Referral::query()->delete();
        $R = [['e1007', 'j1', 'UHR1007-J1', $d(-9).' 10:00:00', 23, 'c1', 'applied'], ['e1017', 'j3', 'UHR1017-J3', $d(-8).' 12:00:00', 14, 'c3', 'applied'], ['e1003', 'j6', 'UHR1003-J6', $d(-6).' 09:30:00', 9, 'c6', 'applied'],
            ['e1025', 'j4', 'UHR1025-J4', $d(-4).' 15:10:00', 31, null, 'shared'], ['e1007', 'j5', 'UHR1007-J5', $d(-2).' 11:45:00', 6, null, 'shared']];
        foreach ($R as $r) Referral::create(['employee_id' => $emp[$r[0]]->id, 'job_opening_id' => $jobs[$r[1]]->id, 'code' => $r[2], 'created_at' => $r[3], 'clicks' => $r[4], 'candidate_id' => $r[5] ? $cands[$r[5]]->id : null, 'status' => $r[6]]);
        $cands['c1']->update(['referrer_id' => $emp['e1007']->id]); $cands['c3']->update(['referrer_id' => $emp['e1017']->id]); $cands['c6']->update(['referrer_id' => $emp['e1003']->id]);

        Redemption::query()->delete(); Reward::query()->delete();
        $W = [['يوم إجازة إضافي مدفوع', 'Extra paid day off', 1500, 10, 'leave'], ['قسيمة مطعم لشخصين', 'Dinner voucher for two', 400, 25, 'food'], ['اشتراك نادي رياضي لشهر', 'One-month gym membership', 900, 8, 'gym'],
            ['دورة تدريبية معتمدة', 'Accredited training course', 2000, 5, 'course'], ['موقف سيارة مخصص لشهر', 'Reserved parking for a month', 700, 4, 'parking'], ['سماعات لاسلكية', 'Wireless headphones', 1200, 6, 'gift']];
        $rewards = [];
        foreach ($W as $w) $rewards[] = Reward::create(['title_ar' => $w[0], 'title_en' => $w[1], 'cost' => $w[2], 'stock' => $w[3], 'kind' => $w[4]]);
        Redemption::create(['employee_id' => $emp['e1017']->id, 'reward_id' => $rewards[2]->id, 'cost' => 900, 'status' => 'pending', 'created_at' => $d(-1).' 14:20:00']);

        Announcement::query()->delete();
        foreach ([[$d(-1).' 09:00:00', 'تُصرف رواتب الشهر الحالي يوم 28 عبر الحسابات المصرفية.', 'This month’s salaries are paid on the 28th by bank transfer.', 'payroll'],
            [$d(-3).' 12:00:00', 'دورة السلامة المهنية الإلزامية الأحد القادم في قاعة الاجتماعات.', 'Mandatory safety training next Sunday in the meeting room.', 'training'],
            [$d(-6).' 08:30:00', 'مرحباً بزملائنا الجدد في إدارة المبيعات — حلب.', 'Welcome to our new colleagues in Sales — Aleppo.', 'people']] as $a) Announcement::create(['published_at' => $a[0], 'text_ar' => $a[1], 'text_en' => $a[2], 'tag' => $a[3]]);

        Notice::query()->delete();
        Notice::create(['audience' => 'hr', 'at' => $d(-1).' 15:20:00', 'text_ar' => 'طلب مهمة رسمية من خالد العمر بانتظار اعتماد الموارد البشرية', 'text_en' => 'Mission request from Khaled Al-Omar awaits HR approval', 'link' => 'leaves']);
        Notice::create(['audience' => 'hr', 'at' => $d(-1).' 13:05:00', 'text_ar' => 'أُرسل عرض العمل إلى لين العلي', 'text_en' => 'Offer sent to Leen Al-Ali', 'link' => 'recruitment']);
    }

    private function users(array $employees, array $cands): void
    {
        $role = Role::pluck('id', 'key');
        $pw = Hash::make('123456');
        $mk = function (string $email, array $attrs, array $roles) use ($pw, $role) {
            $u = User::updateOrCreate(['email' => $email], $attrs + ['password' => $pw, 'must_change_password' => false, 'active' => true]);
            $u->roles()->sync(collect($roles)->map(fn ($k) => $role[$k])->all());
            return $u;
        };
        $company = Tenant::id();
        $mk('admin@alfarada.sy', ['name' => 'مدير النظام', 'name_en' => 'System admin', 'company_id' => $company], ['admin']);
        $special = ['UHR-1001' => ['ceo'], 'UHR-1002' => ['executive'], 'UHR-1003' => ['hr_director'], 'UHR-1004' => ['finance'], 'UHR-1005' => ['hr_officer'], 'UHR-1006' => ['hr_officer']];
        $heads = Department::pluck('head_id')->filter()->all();
        $byCode = [];
        foreach ($employees as $e) {
            $roles = array_merge(['employee'], $special[$e->code] ?? [], in_array($e->id, $heads, true) ? ['manager'] : []);
            // حساب رشا الخطيب يبقى hr@alfarada.sy كما في الديمو
            $byCode[$e->code] = $mk($e->code === 'UHR-1003' ? 'hr@alfarada.sy' : $e->email, ['employee_id' => $e->id, 'name' => $e->name, 'name_en' => $e->name_en, 'company_id' => $company], $roles);
        }
        // المتقدمون: حسابات على مستوى المنصة (بلا شركة)، وطلبهم داخل الفرادة
        foreach ($cands as $c) $c->update(['user_id' => $mk($c->email, ['name' => $c->name, 'name_en' => $c->name_en, 'company_id' => null], ['candidate'])->id, 'consent_at' => $c->created_at, 'keep_cv' => true]);

        // سلسلة قرار التعيين: أصحاب المستويات
        $chain = ApprovalChain::where('type', 'appointment')->first();
        $levels = $chain->levels;
        foreach (['UHR-1003', 'UHR-1004', 'UHR-1002', 'UHR-1001'] as $i => $code) $levels[$i]['user_id'] = $byCode[$code]->id;
        $chain->update(['levels' => $levels]);
    }
}
