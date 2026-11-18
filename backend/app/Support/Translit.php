<?php

namespace App\Support;

/**
 * اقتراح اسم إنجليزي من اسم عربي (FR-CS-022): قاموس للمفردات الإدارية الشائعة، وإلا نقل حرفي مبسّط.
 * الاقتراح قابل للتعديل دائماً قبل الحفظ.
 */
class Translit
{
    private const DICT = [
        'مدير' => 'Manager', 'مديرة' => 'Manager', 'عام' => 'General', 'العام' => 'General', 'مساعد' => 'Assistant', 'محاسب' => 'Accountant', 'رئيس' => 'Head', 'قسم' => 'Section', 'إدارة' => 'Department', 'الإدارة' => 'Management',
        'موظف' => 'Officer', 'أخصائي' => 'Specialist', 'اخصائي' => 'Specialist', 'مشرف' => 'Supervisor', 'منسق' => 'Coordinator', 'فني' => 'Technician', 'مهندس' => 'Engineer', 'سائق' => 'Driver', 'عامل' => 'Worker',
        'مبيعات' => 'Sales', 'المبيعات' => 'Sales', 'مشتريات' => 'Procurement', 'المشتريات' => 'Procurement', 'مالية' => 'Finance', 'المالية' => 'Finance', 'موارد' => 'Resources', 'الموارد' => 'Resources', 'بشرية' => 'Human', 'البشرية' => 'Human',
        'تسويق' => 'Marketing', 'التسويق' => 'Marketing', 'خدمة' => 'Service', 'عملاء' => 'Customer', 'العملاء' => 'Customer', 'جودة' => 'Quality', 'الجودة' => 'Quality', 'صيانة' => 'Maintenance', 'الصيانة' => 'Maintenance',
        'إنتاج' => 'Production', 'الإنتاج' => 'Production', 'مستودع' => 'Warehouse', 'المستودع' => 'Warehouse', 'أمين' => 'Keeper', 'تقنية' => 'Technology', 'المعلومات' => 'Information', 'معلومات' => 'Information',
        'قانوني' => 'Legal', 'مستشار' => 'Counsel', 'تدريب' => 'Training', 'التدريب' => 'Training', 'مكافأة' => 'Bonus', 'بدل' => 'Allowance', 'سكن' => 'Housing', 'نقل' => 'Transport', 'مواصلات' => 'Transport', 'وجبات' => 'Meals',
        'سفر' => 'Travel', 'مهمة' => 'Mission', 'ميدانية' => 'Field', 'داخلية' => 'Internal', 'خارجية' => 'External', 'فرع' => 'Branch', 'مركز' => 'Centre', 'كلفة' => 'Cost', 'أول' => 'Senior', 'أولى' => 'Senior',
        'استقبال' => 'Reception', 'نادل' => 'Waiter', 'طاه' => 'Chef', 'طاهٍ' => 'Chef', 'كاشير' => 'Cashier', 'مندوب' => 'Representative', 'سكرتير' => 'Secretary', 'حارس' => 'Guard', 'أمن' => 'Security', 'الأمن' => 'Security',
        'و' => 'and', 'التنفيذي' => 'Executive', 'تنفيذي' => 'Executive', 'مشاريع' => 'Projects', 'المشاريع' => 'Projects', 'مشروع' => 'Project',
    ];

    private const MAP = ['ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'aa', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z', 'س' => 's',
        'ش' => 'sh', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'ة' => 'a', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ء' => '', 'ؤ' => 'u', 'ئ' => 'e'];

    public static function en(string $ar): string
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $ar)));
        $out = []; $dict = 0;
        foreach ($words as $w) {
            if ($w === '') continue;
            if (isset(self::DICT[$w])) { $out[] = self::DICT[$w]; $dict++; continue; }
            $bare = preg_replace('/^ال/u', '', $w);
            if (isset(self::DICT[$bare])) { $out[] = self::DICT[$bare]; $dict++; continue; }
            if (preg_match('/^[A-Za-z0-9-]+$/', $w)) { $out[] = $w; continue; }
            $lat = ''; foreach (mb_str_split($bare) as $ch) $lat .= self::MAP[$ch] ?? (preg_match('/[A-Za-z0-9]/', $ch) ? $ch : '');
            if ($lat !== '') $out[] = ucfirst($lat);
        }
        // الإضافة العربية «مدير المبيعات» تُقلب في الإنكليزية «Sales Manager»
        if (count($out) > 1 && $dict === count($out) && ! in_array('and', $out, true)) $out = array_reverse($out);
        return implode(' ', $out);
    }
}
