<?php

namespace App\Support;

use App\Models\FormTemplate;

/**
 * سجل النماذج الافتراضي (SRS §10.5): نماذج الديمو برموزها F-xx، ونماذج الشركة الرسمية HR.01-0x-F-0y.
 * الرمز المطبوع يُبنى من بادئة نماذج الشركة (الإعدادات)، والإصدار وتاريخه قابلان للتعديل من M16.
 */
class Forms
{
    /** code => [ar, en, module, sensitive] */
    public const DEFAULTS = [
        'F-19' => ['قائمة إجراءات المباشرة', 'Onboarding checklist', 'employees', false],
        'F-20' => ['إقرار إبراء الذمة الوقائي', 'Precautionary clearance declaration', 'employees', false],
        'F-22' => ['تعهد المحافظة على سرية العمل', 'Confidentiality undertaking', 'employees', false],
        'F-25' => ['تقييم المقابلة الأولية', 'Initial interview assessment', 'candidates', false],
        'F-27' => ['طلب توظيف داخلي', 'Internal application', 'internal', false],
        'F-28' => ['طلب توظيف', 'Employment application', 'candidates', false],
        'F-29' => ['عرض العمل الرسمي', 'Official job offer', 'candidates', true],
        'F-31' => ['قرار التعيين المالي والإداري', 'Appointment decision', 'candidates', true],
        'F-32' => ['مذكرة الترحيب بالموظف الجديد', 'New employee welcome memo', 'employees', false],
        'COC' => ['إقرار ميثاق الأخلاق والسلوك المهني', 'Code of ethics & conduct acknowledgment', 'employees', false],
        'CUS' => ['سند استلام عهدة', 'Custody receipt', 'assets', false],
        'F-33' => ['المخالصة النهائية', 'Final settlement', 'payroll', true],
        'HR.01-01-F-01' => ['إنذار كتابي', 'Written warning', 'discipline', true],
        'HR.01-01-F-02' => ['شكوى موظف', 'Employee complaint', 'discipline', true],
        'HR.01-01-F-03' => ['طلب إجازة / تبرير غياب', 'Leave request / absence justification', 'leaves', false],
        'HR.01-01-F-04' => ['طلب تكليف بساعات إضافية', 'Overtime assignment request', 'attendance', false],
        'HR.01-03-F-02' => ['طلب استقالة', 'Resignation request', 'employees', true],
        'HR.01-03-F-03' => ['إنهاء عقد', 'Contract termination', 'employees', true],
        'HR.01-03-F-04' => ['براءة ذمة', 'Clearance certificate', 'employees', true],
        'HR.01-03-F-05' => ['تسليم مهام', 'Handover of duties', 'employees', false],
        'HR.01-03-F-06' => ['شهادة خبرة / خدمة', 'Experience / service certificate', 'employees', true],
        'HR.01-03-F-07' => ['تسليم عهدة موظف', 'Employee custody handover', 'assets', false],
    ];

    public const ISSUED_ON = '2025-04-01';

    /** الرمز المطبوع: Uniqueness-HR-01-F-28 للنماذج الرقمية، وUniqueness-HR.01-01-F-03 لنماذج الشركة */
    public static function ref(string $code): string
    {
        $prefix = (string) (Settings::get('company')['form_prefix'] ?? 'HR-01');
        return str_starts_with($code, 'HR.') ? explode('-', $prefix)[0].'-'.$code : "{$prefix}-{$code}";
    }

    /** يملأ السجل للشركة الحالية — آمن للتكرار (لا يغيّر ما عدّلته الشركة) */
    public static function seed(): void
    {
        foreach (self::DEFAULTS as $code => [$ar, $en, $module, $sensitive]) {
            FormTemplate::firstOrCreate(['code' => $code], ['ref' => self::ref($code), 'title_ar' => $ar, 'title_en' => $en, 'module' => $module, 'issue' => '01', 'issued_on' => self::ISSUED_ON, 'sensitive' => $sensitive]);
        }
    }
}
