<?php

namespace App\Services\Onboarding;

/**
 * F-19 — قائمة تحقق إجراءات الموظف الجديد (28 بنداً، F19 في rules.ts).
 * البنود التلقائية تُنجز من مصدرها ولا تُبدَّل يدوياً (SRS M06 «إنجازات تلقائية»).
 */
class F19
{
    public const ITEMS = [
        ['تحضير قرار التعيين وأخذ الموافقات الإدارية', 'Prepare appointment decision and approvals'],
        ['إرسال قائمة الأوراق المطلوبة والتحاليل الطبية للمرشح', 'Send required documents & medical tests list'],
        ['استلام الأوراق الثبوتية والتحقق من صحتها', 'Receive and verify identity documents'],
        ['إبلاغ المدير المباشر بتاريخ المباشرة المعتمد', 'Notify direct manager of start date'],
        ['تحضير مذكرة الترحيب وتعميمها على الأقسام', 'Prepare and circulate welcome memo'],
        ['طلب للمعلوماتية: بريد، تحويلة، حاسب، هاتف', 'IT request: email, extension, PC, phone'],
        ['تحضير مكتب ومعدات وقرطاسية', 'Prepare desk, equipment and stationery'],
        ['إضافة الموظف على الهيكل التنظيمي', 'Add employee to the org structure'],
        ['التأكد من توقيع عرض العمل والقرار', 'Confirm signed offer and decision'],
        ['توقيع عقد العمل (3 نسخ) وميثاق السلوك المهني', 'Sign contract (3 copies) and code of conduct'],
        ['توقيع تعهد المحافظة على سرية العمل', 'Sign confidentiality undertaking'],
        ['توقيع التعهد بالتدريب والاشتراك بالتأمينات', 'Sign training and insurance undertaking'],
        ['توقيع إبراء ذمة وقائي', 'Sign precautionary clearance'],
        ['إعداد الملف الورقي والرقمي ومنح الرقم الوظيفي', 'Create paper & digital file, issue employee ID'],
        ['تعريف الموظف على النظام وجهاز الحضور ورصيد الإجازات', 'Introduce HR system, attendance device and leave'],
        ['إضافة الموظف لجدول الرواتب', 'Add to payroll'],
        ['إصدار بطاقة الشركة وبطاقة العمل', 'Issue company ID and business card'],
        ['شرح السياسات العامة وتسليم الوصف الوظيفي', 'Explain policies and hand over job description'],
        ['ضم الموظف للتأمينات الاجتماعية', 'Register with social insurance'],
        ['فتح حساب بنكي للراتب إن لزم', 'Open salary bank account if needed'],
        ['جولة تعريفية في أقسام الشركة', 'Orientation tour'],
        ['تعيين زميل مرشد للموظف', 'Assign onboarding buddy'],
        ['تسليم العهدة وتوقيع نموذج الاستلام', 'Hand over custody and sign receipt'],
        ['إضافة الموظف لقنوات التواصل الرسمية', 'Add to official team channels'],
        ['تحديد أهداف فترة التجربة (3 أشهر)', 'Set probation goals (3 months)'],
        ['مراجعة أسبوعية مع مدير القسم', 'Weekly review with manager'],
        ['مراجعة شهرية من الموارد البشرية', 'Monthly HR review'],
        ['تقييم نهاية التجربة وتثبيت التعيين', 'Probation review and confirmation'],
    ];

    /** فهرس البند => مصدره التلقائي */
    public const AUTO = [4 => 'memo', 10 => 'f22', 12 => 'f20', 22 => 'custody', 27 => 'confirm'];

    public static function blank(int ...$done): array
    {
        return array_map(fn ($i) => in_array($i, $done, true), range(0, count(self::ITEMS) - 1));
    }
}
