<?php

namespace App\Services\Codes;

use App\Models\CodeItem;
use App\Models\CodeList;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Support\Str;

/**
 * تعريف القوائم المرجعية وقيمها الافتراضية (SRS الأنظمة الأساسية A.3) + قوالب القطاعات (FR-CS-008).
 * كل ما هنا قيم افتراضية فقط: النمط، المالك، الإلزامية والخصائص قابلة للتعديل من شاشة الدليل.
 */
class CodeCatalog
{
    /** القوائم النظامية المقفلة والمشتركة بين كل الشركات (POL-CS-10) */
    public const SYSTEM = [
        'currencies' => ['العملات (ISO 4217)', 'Currencies (ISO 4217)', '^[A-Z]{3}$', 'SYP'],
        'countries' => ['الدول (ISO 3166)', 'Countries (ISO 3166)', '^[A-Z]{2}$', 'SY'],
        'governorates' => ['المحافظات السورية', 'Syrian governorates', '^[A-Z]{3}$', 'DAM'],
        'weekdays' => ['أيام الأسبوع', 'Weekdays', '^[A-Z]{3}$', 'SAT'],
    ];

    public const GOVERNORATES = [
        'DAM' => ['دمشق', 'Damascus'], 'RDM' => ['ريف دمشق', 'Rif Dimashq'], 'ALP' => ['حلب', 'Aleppo'], 'HMS' => ['حمص', 'Homs'], 'HMA' => ['حماة', 'Hama'],
        'LAT' => ['اللاذقية', 'Latakia'], 'TAR' => ['طرطوس', 'Tartus'], 'IDL' => ['إدلب', 'Idlib'], 'DEZ' => ['دير الزور', 'Deir ez-Zor'], 'RAQ' => ['الرقة', 'Raqqa'],
        'HAS' => ['الحسكة', 'Al-Hasakah'], 'DAR' => ['درعا', 'Daraa'], 'SWD' => ['السويداء', 'As-Suwayda'], 'QUN' => ['القنيطرة', 'Quneitra'],
    ];

    public const WEEKDAYS = ['SAT' => ['السبت', 'Saturday', 6], 'SUN' => ['الأحد', 'Sunday', 0], 'MON' => ['الإثنين', 'Monday', 1], 'TUE' => ['الثلاثاء', 'Tuesday', 2],
        'WED' => ['الأربعاء', 'Wednesday', 3], 'THU' => ['الخميس', 'Thursday', 4], 'FRI' => ['الجمعة', 'Friday', 5]];

    /**
     * قوائم الشركة: key => [ar, en, group, owner, pattern, example, required, hierarchical, parent_key, source, attrs_schema]
     * departments/branches «مرتبطة» بجداولها الحالية (لا نسخة ثانية من البيانات) وتُدار من صفحاتها.
     */
    public static function lists(): array
    {
        $bool = fn ($k, $ar, $en) => ['key' => $k, 'ar' => $ar, 'en' => $en, 'type' => 'bool'];
        return [
            'departments' => ['الإدارات والأقسام', 'Departments', 'org', 'hr_director', '^[A-Za-z][A-Za-z0-9_-]{1,19}$', 'HR10', true, false, null, 'departments', null],
            'branches' => ['الفروع', 'Branches', 'org', 'admin', '^[A-Za-z][A-Za-z0-9_-]{1,19}$', 'DAM01', true, false, null, 'locations', null],
            'grades' => ['الدرجات الوظيفية', 'Job grades', 'people', 'hr_director', '^G[0-9]{2}$', 'G01', true, false, null, null,
                [['key' => 'rank', 'ar' => 'الترتيب', 'en' => 'Rank', 'type' => 'number']]],
            'job_families' => ['العائلات الوظيفية', 'Job families', 'people', 'hr_director', '^[A-Z]{3}$', 'ADM', false, false, null, null, null],
            'jobs' => ['المسميات الوظيفية', 'Job titles', 'people', 'hr_director', '^JB-[A-Z0-9]{2,6}-[0-9]{3}$', 'JB-HR-014', true, false, 'job_families', null,
                [['key' => 'grade', 'ar' => 'الدرجة المعتادة', 'en' => 'Usual grade', 'type' => 'list', 'list' => 'grades']]],
            'worker_categories' => ['فئات العاملين', 'Worker categories', 'people', 'hr_director', '^[A-Z]{3}$', 'EMP', true, false, null, null, [
                $bool('payroll', 'يدخل الرواتب', 'In payroll'), $bool('leave', 'إجازات', 'Leave'), $bool('insurance', 'تأمينات', 'Insurance'),
                ['key' => 'portal', 'ar' => 'الواجهة', 'en' => 'Portal', 'type' => 'select', 'options' => ['employee', 'limited', 'expert', 'volunteer', 'trainee', 'none']],
            ]],
            'contract_types' => ['أنواع العقود', 'Contract types', 'people', 'hr_director', '^[A-Z]{2}-[A-Z]$', 'FT-D', true, false, null, null, [
                ['key' => 'months', 'ar' => 'المدة الافتراضية (شهر، 0 = مفتوحة)', 'en' => 'Default length (months, 0 = open)', 'type' => 'number'],
                ['key' => 'probation_months', 'ar' => 'فترة التجربة (شهر)', 'en' => 'Probation (months)', 'type' => 'number'],
            ]],
            'pay_elements' => ['عناصر الراتب', 'Pay elements', 'pay', 'finance', '^[ED]-[0-9]{3}$', 'E-110', false, false, null, null, [
                ['key' => 'kind', 'ar' => 'النوع', 'en' => 'Kind', 'type' => 'select', 'options' => ['earning', 'deduction']],
                $bool('taxable', 'خاضع لضريبة الدخل', 'Income tax'), $bool('insured', 'خاضع للتأمينات', 'Social insurance'), $bool('eos', 'يدخل أساس نهاية الخدمة', 'End-of-service base'),
                ['key' => 'channel', 'ar' => 'قناة الصرف', 'en' => 'Paid through', 'type' => 'select', 'options' => ['payroll', 'cash']],
            ]],
            'cost_centers' => ['مراكز الكلفة', 'Cost centres', 'pay', 'finance', '^CC-[0-9]{4}$', 'CC-1020', false, false, null, null, null],
            'mission_types' => ['أنواع المهمات', 'Mission types', 'ops', 'hr_director', '^MT-[0-9]{2}$', 'MT-30', false, false, null, null, [
                $bool('travel', 'سفر (بدل سفر)', 'Travel (allowance)'), $bool('financial', 'له أثر مالي', 'Has a pay effect'),
            ]],
            'mission_categories' => ['تصنيفات المهمات', 'Mission categories', 'ops', 'hr_director', '^[A-Z]{2,4}-[0-9]{2}$', 'SAL-01', false, false, 'mission_types', null, null],
            'bonus_types' => ['أنواع المكافآت', 'Bonus types', 'ops', 'hr_director', '^BN-[0-9]{2}$', 'BN-10', false, false, null, null, [
                ['key' => 'element', 'ar' => 'عنصر الراتب', 'en' => 'Pay element', 'type' => 'list', 'list' => 'pay_elements'],
                ['key' => 'method', 'ar' => 'طريقة الحساب', 'en' => 'Calculation', 'type' => 'select', 'options' => ['fixed', 'percent', 'free', 'hours', 'formula']],
                ['key' => 'cap', 'ar' => 'السقف', 'en' => 'Cap', 'type' => 'number'],
            ]],
            'doc_types' => ['أنواع الوثائق', 'Document types', 'docs', 'hr_director', '^[A-Z]{1,3}$', 'F', false, false, null, null, null],
        ];
    }

    /** القيم الافتراضية لقوائم الشركة: key => [[code, ar, en, attrs?, parent?]] */
    public static function defaults(): array
    {
        $g = ['مبتدئ', 'مساعد', 'أخصائي', 'أخصائي أول', 'مشرف', 'رئيس قسم', 'مدير', 'مدير أول', 'الإدارة العليا'];
        $ge = ['Entry', 'Assistant', 'Specialist', 'Senior specialist', 'Supervisor', 'Head of section', 'Manager', 'Senior manager', 'Top management'];
        $grades = []; foreach ($g as $i => $ar) $grades[] = [sprintf('G%02d', $i + 1), $ar, $ge[$i], ['rank' => $i + 1]];
        $wc = fn ($pay, $leave, $ins, $portal) => ['payroll' => $pay, 'leave' => $leave, 'insurance' => $ins, 'portal' => $portal];
        $el = fn ($kind, $tax = false, $ins = false, $eos = false, $ch = 'payroll') => ['kind' => $kind, 'taxable' => $tax, 'insured' => $ins, 'eos' => $eos, 'channel' => $ch];
        return [
            'grades' => $grades,
            'job_families' => [['ADM', 'إدارية', 'Administration'], ['FIN', 'مالية ومحاسبة', 'Finance & accounting'], ['HRM', 'موارد بشرية', 'Human resources'],
                ['TEC', 'تقنية', 'Technology'], ['SAL', 'مبيعات وتسويق', 'Sales & marketing'], ['OPS', 'عمليات', 'Operations'], ['CSR', 'خدمة عملاء', 'Customer service'], ['LEG', 'قانونية', 'Legal']],
            'jobs' => [],
            'worker_categories' => [
                ['EMP', 'موظف', 'Employee', $wc(true, true, true, 'employee')], ['TRL', 'عقد تجريبي قصير', 'Short trial contract', $wc(false, false, false, 'limited')],
                ['TMP', 'مؤقت / موسمي', 'Temporary / seasonal', $wc(true, true, true, 'limited')], ['CNS', 'استشاري', 'Consultant', $wc(false, false, false, 'expert')],
                ['EXP', 'خبير من القائمة', 'Roster expert', $wc(false, false, false, 'expert')], ['VOL', 'متطوع', 'Volunteer', $wc(false, false, false, 'volunteer')],
                ['STG', 'متدرب ستاج', 'Intern (stage)', $wc(false, false, false, 'trainee')],
            ],
            'contract_types' => [
                ['FT-D', 'محدد المدة', 'Fixed term', ['months' => 12, 'probation_months' => 3], null, 'fixed'],
                ['FT-U', 'غير محدد المدة', 'Open-ended', ['months' => 0, 'probation_months' => 3], null, 'open'],
                ['PJ-D', 'عقد مشروع', 'Project contract', ['months' => 6, 'probation_months' => 0], null, 'project'],
                ['PT-D', 'دوام جزئي', 'Part time', ['months' => 12, 'probation_months' => 3]],
            ],
            'pay_elements' => [
                ['E-100', 'الراتب الأساسي', 'Basic salary', $el('earning', true, true, true)], ['E-101', 'بدل سكن', 'Housing allowance', $el('earning', true)],
                ['E-102', 'بدل نقل', 'Transport allowance', $el('earning')], ['E-103', 'بدل وقود', 'Fuel allowance', $el('earning')],
                ['E-110', 'بدل سفر يومي محلي', 'Local daily travel allowance', $el('earning')], ['E-111', 'بدل سفر يومي خارجي', 'Foreign daily travel allowance', $el('earning')],
                ['E-120', 'مصاريف انتقال', 'Transport expenses', $el('earning')], ['E-121', 'مصاريف مستردة بفواتير', 'Reimbursed expenses', $el('earning')],
                ['E-130', 'إقامة مستردة', 'Reimbursed accommodation', $el('earning')],
                ['E-200', 'مكافأة إنجاز مهمة', 'Mission completion bonus', $el('earning', true)], ['E-210', 'مكافأة أداء دورية', 'Periodic performance bonus', $el('earning', true)],
                ['E-220', 'مكافأة استثنائية', 'Exceptional bonus', $el('earning', true)], ['E-230', 'أتعاب محاضر داخلي', 'Internal trainer fee', $el('earning', true)],
                ['E-240', 'عمل إضافي', 'Overtime', $el('earning', true, true)], ['E-250', 'عمولة أو حافز إنتاج', 'Commission / incentive', $el('earning', true)],
                ['E-260', 'مكافأة التزام بالدوام', 'Attendance bonus', $el('earning', true)],
                ['D-300', 'اشتراك التأمينات', 'Social insurance', $el('deduction')], ['D-310', 'تسوية سلفة مهمة', 'Mission advance settlement', $el('deduction')],
                ['D-320', 'سلفة على الراتب', 'Salary advance', $el('deduction')], ['D-330', 'حسم تأخر', 'Lateness deduction', $el('deduction')],
                ['D-340', 'حسم غياب', 'Absence deduction', $el('deduction')], ['D-350', 'إجازة بلا أجر', 'Unpaid leave', $el('deduction')],
                ['D-360', 'جزاء تأديبي', 'Disciplinary deduction', $el('deduction')],
            ],
            'cost_centers' => [],
            'mission_types' => [
                ['MT-10', 'مهمة داخلية', 'Internal mission', ['travel' => false, 'financial' => false]], ['MT-20', 'مهمة ميدانية داخل المحافظة', 'Field mission (same governorate)', ['travel' => false, 'financial' => true]],
                ['MT-30', 'مهمة سفر محلية', 'Domestic travel', ['travel' => true, 'financial' => true]], ['MT-40', 'مهمة سفر خارجية', 'Foreign travel', ['travel' => true, 'financial' => true]],
                ['MT-50', 'مهمة تدريبية', 'Training mission', ['travel' => true, 'financial' => true]], ['MT-60', 'تدريب كمحاضر داخلي', 'Internal trainer', ['travel' => false, 'financial' => true]],
                ['MT-70', 'مهمة فريق أو فرع', 'Team / branch mission', ['travel' => false, 'financial' => false]],
            ],
            'mission_categories' => [['SAL-01', 'مبيعات', 'Sales', null, 'MT-20'], ['AUD-01', 'تدقيق', 'Audit', null, 'MT-20'], ['MNT-01', 'صيانة', 'Maintenance', null, 'MT-20'],
                ['REP-01', 'تمثيل رسمي', 'Official representation', null, 'MT-30'], ['BR-01', 'افتتاح فرع', 'Branch opening', null, 'MT-30']],
            'bonus_types' => [
                ['BN-10', 'مكافأة إنجاز مهمة', 'Mission completion bonus', ['element' => 'E-200', 'method' => 'fixed']], ['BN-20', 'مكافأة أداء دورية', 'Periodic performance bonus', ['element' => 'E-210', 'method' => 'percent']],
                ['BN-30', 'مكافأة استثنائية', 'Exceptional bonus', ['element' => 'E-220', 'method' => 'free']], ['BN-40', 'أتعاب محاضر داخلي', 'Internal trainer fee', ['element' => 'E-230', 'method' => 'hours']],
                ['BN-50', 'عمل إضافي', 'Overtime', ['element' => 'E-240', 'method' => 'hours']], ['BN-60', 'عمولة أو حافز إنتاج', 'Commission / incentive', ['element' => 'E-250', 'method' => 'formula']],
                ['BN-70', 'مكافأة التزام بالدوام', 'Attendance bonus', ['element' => 'E-260', 'method' => 'fixed']],
            ],
            'doc_types' => [['POL', 'سياسة', 'Policy'], ['PRO', 'إجراء', 'Procedure'], ['WF', 'مخطط سير عمل', 'Workflow'], ['F', 'نموذج', 'Form'],
                ['JD', 'وصف وظيفي', 'Job description'], ['CT', 'قالب عقد', 'Contract template'], ['RPT', 'تقرير', 'Report']],
        ];
    }

    /** قوالب القطاعات: عائلات + مسميات مقترحة تُضاف للقوائم وهي مسودة (FR-CS-008) */
    public const SECTORS = [
        'trading' => ['شركة تجارية', 'Trading company', [
            ['ADM', 'مدير عام', 'General manager', 'G09'], ['ADM', 'مساعد إداري', 'Administrative assistant', 'G02'], ['FIN', 'محاسب', 'Accountant', 'G03'], ['FIN', 'مدير مالي', 'Finance manager', 'G07'],
            ['HRM', 'أخصائي موارد بشرية', 'HR specialist', 'G03'], ['SAL', 'مندوب مبيعات', 'Sales representative', 'G02'], ['SAL', 'مدير مبيعات', 'Sales manager', 'G07'], ['OPS', 'أمين مستودع', 'Storekeeper', 'G02'], ['CSR', 'موظف خدمة عملاء', 'Customer service agent', 'G02']]],
        'hotel' => ['فندق', 'Hotel', [
            ['OPS', 'موظف استقبال', 'Receptionist', 'G02'], ['OPS', 'مشرف تدبير فندقي', 'Housekeeping supervisor', 'G05'], ['OPS', 'عامل تدبير فندقي', 'Room attendant', 'G01'], ['OPS', 'طاهٍ رئيسي', 'Head chef', 'G06'],
            ['OPS', 'نادل', 'Waiter', 'G01'], ['ADM', 'مدير الفندق', 'Hotel manager', 'G09'], ['FIN', 'محاسب', 'Accountant', 'G03'], ['CSR', 'مسؤول علاقات الضيوف', 'Guest relations officer', 'G03']]],
        'restaurant' => ['مطعم', 'Restaurant', [
            ['OPS', 'طاهٍ', 'Cook', 'G02'], ['OPS', 'مساعد طاهٍ', 'Kitchen helper', 'G01'], ['OPS', 'نادل', 'Waiter', 'G01'], ['OPS', 'كاشير', 'Cashier', 'G02'], ['ADM', 'مدير المطعم', 'Restaurant manager', 'G07']]],
        'factory' => ['معمل', 'Factory', [
            ['OPS', 'عامل إنتاج', 'Production worker', 'G01'], ['OPS', 'مشرف خط إنتاج', 'Line supervisor', 'G05'], ['OPS', 'مدير الإنتاج', 'Production manager', 'G07'], ['TEC', 'فني صيانة', 'Maintenance technician', 'G03'],
            ['OPS', 'مراقب جودة', 'Quality inspector', 'G03'], ['OPS', 'أمين مستودع', 'Storekeeper', 'G02'], ['FIN', 'محاسب', 'Accountant', 'G03']]],
        'ngo' => ['جمعية / منظمة', 'NGO', [
            ['ADM', 'مدير المشاريع', 'Programme manager', 'G07'], ['OPS', 'منسق مشروع', 'Project coordinator', 'G04'], ['OPS', 'عامل ميداني', 'Field officer', 'G02'], ['FIN', 'محاسب منح', 'Grants accountant', 'G03'], ['ADM', 'مسؤول متطوعين', 'Volunteer coordinator', 'G03']]],
        'holding' => ['قابضة', 'Holding', [
            ['ADM', 'الرئيس التنفيذي', 'Chief executive', 'G09'], ['FIN', 'مدير مالي للمجموعة', 'Group finance manager', 'G08'], ['HRM', 'مدير موارد بشرية للمجموعة', 'Group HR manager', 'G08'], ['LEG', 'مستشار قانوني', 'Legal counsel', 'G06'], ['TEC', 'مدير تقنية المعلومات', 'IT manager', 'G07']]],
    ];

    public static function seedSystem(): void
    {
        $sort = 0;
        foreach (self::SYSTEM as $key => [$ar, $en, $pattern, $ex]) {
            $l = CodeList::withoutGlobalScopes()->firstOrCreate(['company_id' => null, 'key' => $key], ['name_ar' => $ar, 'name_en' => $en, 'group' => 'system', 'pattern' => $pattern, 'example' => $ex, 'system' => true, 'sort' => 100 + ++$sort]);
            $rows = match ($key) {
                'currencies' => array_map(fn ($r) => [$r['code'], $r['ar'], $r['en'], null], json_decode(file_get_contents(resource_path('codes/currencies.json')), true)),
                'countries' => array_map(fn ($r) => [$r['code'], $r['ar'], $r['en'], null], json_decode(file_get_contents(resource_path('codes/countries.json')), true)),
                'governorates' => array_map(fn ($k, $v) => [$k, $v[0], $v[1], null], array_keys(self::GOVERNORATES), self::GOVERNORATES),
                'weekdays' => array_map(fn ($k, $v) => [$k, $v[0], $v[1], ['day' => $v[2]]], array_keys(self::WEEKDAYS), self::WEEKDAYS),
            };
            foreach ($rows as $i => [$code, $iar, $ien, $attrs])
                CodeItem::withoutGlobalScopes()->firstOrCreate(['code_list_id' => $l->id, 'code' => $code], ['company_id' => null, 'name_ar' => $iar, 'name_en' => $ien, 'attrs' => $attrs, 'sort' => $i + 1]);
        }
    }

    /** قوائم الشركة الحالية وقيمها الافتراضية — آمن للتكرار (لا يمس ما عدّلته الشركة) */
    public static function seedCompany(): void
    {
        $sort = 0; $ids = [];
        foreach (self::lists() as $key => [$ar, $en, $group, $owner, $pattern, $ex, $req, $hier, $parent, $source, $schema]) {
            $ids[$key] = CodeList::firstOrCreate(['key' => $key, 'system' => false], ['name_ar' => $ar, 'name_en' => $en, 'group' => $group, 'owner_role' => $owner, 'pattern' => $pattern,
                'example' => $ex, 'required' => $req, 'hierarchical' => $hier, 'parent_key' => $parent, 'source' => $source, 'attrs_schema' => $schema, 'sort' => ++$sort])->id;
        }
        foreach (self::defaults() as $key => $rows) {
            if (CodeItem::where('code_list_id', $ids[$key])->exists()) continue;
            $parentList = self::lists()[$key][8] ?? null;
            foreach ($rows as $i => $r) CodeItem::create(['code_list_id' => $ids[$key], 'code' => $r[0], 'name_ar' => $r[1], 'name_en' => $r[2], 'attrs' => $r[3] ?? null, 'legacy_code' => $r[5] ?? null, 'sort' => $i + 1,
                'parent_id' => $parentList && ! empty($r[4]) ? CodeItem::where('code_list_id', $ids[$parentList] ?? 0)->where('code', $r[4])->value('id') : null]);
        }
    }

    /**
     * يربط الموظفين الحاليين بالدليل: المسميات من عناوينهم الحالية (رمز JB-{الإدارة}-{تسلسل} تحت عائلة مقترحة)،
     * فئة العامل «موظف»، ونوع العقد من الحقل القديم fixed/open/project.
     */
    public static function backfillEmployees(): void
    {
        $list = fn ($k) => CodeList::where('key', $k)->where('system', false)->value('id');
        $emp = CodeItem::where('code_list_id', $list('worker_categories'))->where('code', 'EMP')->value('id');
        $contracts = CodeItem::where('code_list_id', $list('contract_types'))->whereNotNull('legacy_code')->pluck('id', 'legacy_code');
        $families = CodeItem::where('code_list_id', $list('job_families'))->pluck('id', 'code');
        $jobsList = $list('jobs');
        $fam = ['hr' => 'HRM', 'fin' => 'FIN', 'it' => 'TEC', 'sales' => 'SAL', 'ops' => 'OPS', 'cs' => 'CSR', 'legal' => 'LEG'];
        $depts = Department::get(['id', 'code'])->keyBy('id');
        $jobs = []; $seq = [];
        foreach (CodeItem::where('code_list_id', $jobsList)->get() as $j) $jobs[mb_strtolower(trim($j->name_ar))] = $j->id;
        foreach (Employee::orderBy('id')->get(['id', 'department_id', 'title_ar', 'title_en', 'contract', 'job_id', 'worker_category_id', 'contract_type_id']) as $e) {
            $set = [];
            if (! $e->worker_category_id && $emp) $set['worker_category_id'] = $emp;
            if (! $e->contract_type_id && $e->contract && isset($contracts[$e->contract])) $set['contract_type_id'] = $contracts[$e->contract];
            if (! $e->job_id && trim((string) $e->title_ar) !== '' && $jobsList) {
                $k = mb_strtolower(trim($e->title_ar));
                if (! isset($jobs[$k])) {
                    $dc = strtolower((string) ($depts[$e->department_id]->code ?? 'gen'));
                    $tag = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $dc) ?: 'GEN', 0, 6)); if (strlen($tag) < 2) $tag .= 'X';
                    $n = ($seq[$tag] ??= (int) CodeItem::where('code_list_id', $jobsList)->where('code', 'like', "JB-{$tag}-%")->count()) + 1; $seq[$tag] = $n;
                    $code = sprintf('JB-%s-%03d', $tag, $n);
                    while (CodeItem::where('code_list_id', $jobsList)->where('code', $code)->exists()) { $n++; $seq[$tag] = $n; $code = sprintf('JB-%s-%03d', $tag, $n); }
                    $jobs[$k] = CodeItem::create(['code_list_id' => $jobsList, 'code' => $code, 'name_ar' => trim($e->title_ar), 'name_en' => trim((string) $e->title_en) ?: trim($e->title_ar),
                        'parent_id' => $families[$fam[$dc] ?? 'ADM'] ?? null, 'sort' => $n])->id;
                }
                $set['job_id'] = $jobs[$k];
            }
            if ($set) Employee::whereKey($e->id)->update($set);
        }
        // الرموز المرتبطة بموظفين = مستخدمة ← مقفلة
        foreach (['job_id', 'worker_category_id', 'contract_type_id', 'grade_id', 'cost_center_id'] as $col)
            CodeItem::whereIn('id', Employee::whereNotNull($col)->distinct()->pluck($col))->whereNull('used_at')->update(['used_at' => now()]);
    }

    /** يضيف مسميات قالب القطاع (وما يلزم من عائلات) — في مرحلة التأسيس فقط */
    public static function loadSector(string $sector): int
    {
        $tpl = self::SECTORS[$sector] ?? null; if (! $tpl) return 0;
        $jobsList = CodeList::where('key', 'jobs')->where('system', false)->firstOrFail();
        $families = CodeItem::where('code_list_id', CodeList::where('key', 'job_families')->where('system', false)->value('id'))->pluck('id', 'code');
        $existing = CodeItem::where('code_list_id', $jobsList->id)->pluck('name_ar')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $added = 0;
        foreach ($tpl[2] as [$fam, $ar, $en, $grade]) {
            if (in_array(mb_strtolower($ar), $existing, true)) continue;
            $code = Codes::suggest($jobsList, $fam === 'HRM' ? 'HR' : $fam);
            CodeItem::create(['code_list_id' => $jobsList->id, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'parent_id' => $families[$fam] ?? null, 'attrs' => ['grade' => $grade], 'sort' => 1000 + $added]);
            $existing[] = mb_strtolower($ar); $added++;
        }
        return $added;
    }

    public static function slugTag(string $s): string { return strtoupper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', $s), 6, '')); }
}
