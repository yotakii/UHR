<?php

namespace App\Support;

/** أسماء مقروءة لعمليات سجل التدقيق: النموذج + الفعل (إنشاء/تعديل/حذف) أو اسم العملية اليدوية */
class AuditLabels
{
    public const SUBJECTS = [
        'advance' => ['سلفة', 'Advance'], 'announcement' => ['إعلان', 'Announcement'], 'appointment' => ['قرار تعيين', 'Appointment'], 'appointment_approval' => ['اعتماد تعيين', 'Appointment approval'],
        'approval_chain' => ['سلسلة اعتماد', 'Approval chain'], 'asset' => ['عهدة', 'Asset'], 'asset_category' => ['فئة عهدة', 'Asset category'], 'candidate' => ['متقدم', 'Applicant'],
        'candidate_test' => ['اختبار متقدم', 'Applicant test'], 'company' => ['شركة', 'Company'], 'competency' => ['كفاءة', 'Competency'], 'complaint' => ['شكوى', 'Complaint'],
        'department' => ['إدارة', 'Department'], 'development_activity' => ['نشاط تطوير', 'Development activity'], 'development_plan' => ['خطة تطوير', 'Development plan'],
        'device' => ['جهاز حضور', 'Attendance device'], 'discipline_case' => ['مخالفة', 'Disciplinary case'], 'employee' => ['موظف', 'Employee'], 'employee_document' => ['مستند موظف', 'Employee document'],
        'exit_notice' => ['إشعار خروج', 'Exit notice'], 'form_template' => ['نموذج', 'Form'], 'holiday' => ['عطلة رسمية', 'Holiday'], 'internal_application' => ['ترشيح داخلي', 'Internal application'],
        'interview' => ['مقابلة', 'Interview'], 'job_opening' => ['شاغر', 'Job opening'], 'job_test' => ['اختبار شاغر', 'Job test'], 'leave_request' => ['طلب إجازة', 'Leave request'],
        'location' => ['موقع', 'Location'], 'message_template' => ['قالب رسالة', 'Message template'], 'month_sheet' => ['كشف شهري', 'Monthly sheet'], 'notification_channel' => ['قناة إشعار', 'Notification channel'],
        'offer' => ['عرض عمل', 'Offer'], 'onboarding' => ['مباشرة', 'Onboarding'], 'overtime_request' => ['تكليف إضافي', 'Overtime assignment'], 'penalty_approval' => ['اعتماد جزاء', 'Penalty approval'],
        'performance_award' => ['مكافأة تقييم', 'Performance award'], 'performance_cycle' => ['دورة تقييم', 'Performance cycle'], 'performance_form' => ['نموذج تقييم', 'Review form'],
        'performance_review' => ['تقييم', 'Review'], 'point_log' => ['نقاط', 'Points'], 'policy' => ['سياسة دوام', 'Work policy'], 'policy_document' => ['وثيقة سياسة', 'Policy document'],
        'policy_version' => ['إصدار سياسة', 'Policy version'], 'redemption' => ['استبدال نقاط', 'Redemption'], 'referral' => ['إحالة', 'Referral'], 'request_step' => ['خطوة طلب', 'Request step'],
        'resignation' => ['استقالة / إنهاء', 'Resignation / termination'], 'reward' => ['مكافأة متجر', 'Store reward'], 'role' => ['دور', 'Role'], 'role_permission' => ['صلاحية', 'Permission'],
        'salary_grant' => ['إذن رواتب', 'Salary access'], 'service_request' => ['طلب خدمة', 'Service request'], 'setting' => ['إعداد', 'Setting'], 'succession_candidate' => ['مرشح تعاقب', 'Succession candidate'],
        'succession_plan' => ['خطة تعاقب', 'Succession plan'], 'user' => ['مستخدم', 'User'], 'violation_type' => ['نوع مخالفة', 'Violation type'],
    ];

    /** بادئات العمليات اليدوية (للتصفية) */
    public const PREFIXES = [
        'auth' => ['الدخول والخروج', 'Sign-in'], 'mfa' => ['التحقق بخطوتين', 'Two-step verification'], 'salary' => ['الرواتب', 'Salaries'], 'settings' => ['الإعدادات', 'Settings'],
        'permissions' => ['الصلاحيات', 'Permissions'], 'file' => ['الملفات', 'Files'], 'print' => ['الطباعة', 'Printing'], 'backup' => ['النسخ الاحتياطي', 'Backups'], 'audit' => ['سجل التدقيق', 'Audit log'],
        'org' => ['الهيكل التنظيمي', 'Org chart'], 'platform' => ['المنصة', 'Platform'], 'system' => ['النظام', 'System'], 'cvdb' => ['قاعدة السير', 'CV database'],
        'employees' => ['الموظفون', 'Employees'], 'assets' => ['العهد', 'Custody'], 'payroll' => ['الرواتب والكشف', 'Payroll'], 'attendance' => ['الحضور', 'Attendance'],
        'performance' => ['التقييم', 'Performance'], 'analytics' => ['التحليلات', 'Analytics'], 'test' => ['الاختبار الأولي', 'Preliminary test'], 'exit' => ['الخروج', 'Exit'],
        'discipline' => ['المخالفات', 'Discipline'], 'certificate' => ['الشهادات', 'Certificates'], 'device' => ['أجهزة الحضور', 'Devices'], 'profile' => ['الملف الشخصي', 'Profile'],
        'account' => ['الحسابات', 'Accounts'], 'docs' => ['المستندات', 'Documents'],
    ];

    public const ACTIONS = [
        'auth.login' => ['تسجيل دخول', 'Signed in'], 'auth.logout' => ['تسجيل خروج', 'Signed out'], 'auth.failed' => ['محاولة دخول فاشلة', 'Failed sign-in'], 'auth.locked' => ['قفل الحساب بعد محاولات فاشلة', 'Account locked'],
        'auth.blocked' => ['دخول مرفوض لحساب موقوف', 'Blocked account sign-in'], 'auth.password_changed' => ['تغيير كلمة المرور', 'Password changed'], 'auth.password_reset' => ['إعادة تعيين كلمة المرور', 'Password reset'],
        'auth.reset_requested' => ['طلب استعادة كلمة المرور', 'Password reset requested'], 'user.password_reset' => ['إعادة تعيين كلمة مرور مستخدم', 'User password reset'], 'user.unlocked' => ['فك قفل حساب', 'Account unlocked'],
        'user.roles' => ['تعديل أدوار مستخدم', 'User roles changed'], 'mfa.enrolled' => ['ربط تطبيق المصادقة', 'Authenticator linked'], 'mfa.passkey_added' => ['إضافة مفتاح مرور', 'Passkey added'],
        'mfa.passkey_removed' => ['حذف مفتاح مرور', 'Passkey removed'], 'mfa.reset' => ['إلغاء ربط التحقق', 'Verification reset'], 'mfa.reset_by_admin' => ['إلغاء ربط التحقق من الإدارة', 'Verification reset by admin'],
        'salary.access_granted' => ['منح إذن الاطلاع على الرواتب', 'Salary access granted'], 'salary.access_revoked' => ['سحب إذن الرواتب', 'Salary access revoked'],
        'file.view' => ['عرض ملف', 'File viewed'], 'file.download' => ['تنزيل ملف', 'File downloaded'], 'print' => ['طباعة نموذج حساس', 'Sensitive form printed'],
        'permissions.updated' => ['تعديل الصلاحيات', 'Permissions updated'], 'policy.published' => ['نشر سياسة', 'Policy published'], 'policy.linked' => ['ربط فقرة بإعداد', 'Paragraph linked to a setting'],
        'backup.created' => ['إنشاء نسخة احتياطية', 'Backup created'], 'backup.downloaded' => ['تنزيل نسخة احتياطية', 'Backup downloaded'], 'backup.settings' => ['إعدادات النسخ الاحتياطي', 'Backup settings'],
        'audit.exported' => ['تصدير سجل التدقيق', 'Audit log exported'], 'audit.archive_downloaded' => ['تنزيل أرشيف التدقيق', 'Audit archive downloaded'], 'settings.audit' => ['إعدادات سجل التدقيق', 'Audit settings'],
        'org.exported' => ['تصدير الهيكل التنظيمي', 'Org chart exported'], 'system.installed' => ['تثبيت النظام', 'System installed'],
        'payroll.calculated' => ['احتساب الكشف الشهري', 'Monthly sheet calculated'], 'payroll.closed' => ['إقفال الكشف الشهري', 'Monthly sheet closed'], 'payroll.reopened' => ['إعادة فتح الكشف', 'Monthly sheet reopened'],
        'attendance.corrected' => ['تصحيح حضور', 'Attendance corrected'], 'certificate.issued' => ['إصدار شهادة', 'Certificate issued'], 'discipline.decided' => ['قرار جزاء', 'Penalty decided'],
        'employee.added' => ['إضافة موظف', 'Employee added'], 'employee.confirmed' => ['تثبيت موظف', 'Employee confirmed'], 'employees.imported' => ['استيراد موظفين', 'Employees imported'],
        'assets.imported' => ['استيراد عهد', 'Assets imported'], 'candidate.erased' => ['حذف بيانات متقدم', 'Applicant data erased'], 'cvdb.search' => ['بحث في قاعدة السير', 'CV database search'],
        'exit.closed' => ['إغلاق ملف خروج', 'Exit closed'], 'profile.phone' => ['تحديث رقم الجوال', 'Phone updated'], 'account.transferred' => ['نقل حساب إلى الخلف', 'Account handed over'],
    ];
    public const VERBS = ['created' => ['إنشاء', 'Created'], 'updated' => ['تعديل', 'Updated'], 'deleted' => ['حذف', 'Deleted']];

    /** @return array{0: string, 1: string} */
    public static function action(string $action): array
    {
        if (isset(self::ACTIONS[$action])) return self::ACTIONS[$action];
        [$head, $tail] = array_pad(explode('.', $action, 2), 2, '');
        if (isset(self::VERBS[$tail]) && isset(self::SUBJECTS[$head])) return [self::VERBS[$tail][0].' '.self::SUBJECTS[$head][0], self::VERBS[$tail][1].' '.mb_strtolower(self::SUBJECTS[$head][1])];
        if ($tail === 'export') { $g = self::prefix($head); return ['تصدير Excel — '.$g[0], 'Excel export — '.$g[1]]; }
        return [$action, $action];
    }

    public static function prefix(string $p): array { return self::PREFIXES[$p] ?? self::SUBJECTS[$p] ?? [$p, $p]; }

    public static function subject(?string $type): ?array
    {
        if (! $type) return null;
        $key = \Illuminate\Support\Str::snake(class_basename($type));
        return self::SUBJECTS[$key] ?? [$key, $key];
    }
}
