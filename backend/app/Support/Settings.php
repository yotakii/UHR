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
            'domain' => 'uniqueness.sy', 'careers_url' => 'https://careers.uniqueness.sy', 'form_prefix' => 'Uniqueness-HR-01',
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
            'probation_at' => 14,
            // ملاحظة الاختبار 55: الحد الأدنى لعمر المتقدم والموظف الجديد
            'min_age' => 18,
            // ميزانية العرض (ملاحظتا 56 و70): إجمالي العرض فوق الحد الأعلى لراتب الشاغر → approval موافقة المعتمِد قبل الإرسال | block منع | off بلا رقابة
            'offer_budget' => 'approval',
            // لا يُصدر عرض لمن تقييم مقابلته F-25 «غير موصى به» أو بلا تقييم
            'offer_needs_interview' => true,
            // الاستقطاب الداخلي (D-22، SRS §10.14): أدنى مدة خدمة بالأشهر، خارج فترة التجربة، وبلا إنذار أو جزاء خلال أشهر
            'internal_min_months' => 12, 'internal_no_probation' => true, 'internal_clean_months' => 6],
        // الإعلانات (D-06): اعتماد قبل النشر (لا افتراضياً — HR ينشر مباشرة)، وأقصى حجم للصورة/المرفق
        'announcements' => ['require_approval' => false, 'max_mb' => 10],
        // «الساحة» (12a): من ينشئ مجتمعات الاهتمام (everyone | moderators)، الصور، مهلة تعديل المنشور، التفاعلات المتاحة، الاستطلاعات والأسئلة، والإخفاء التلقائي بعد عدد بلاغات
        'space' => ['enabled' => true, 'create_communities' => 'everyone', 'max_images' => 4, 'max_mb' => 8, 'edit_minutes' => 60,
            'reactions' => ['like', 'love', 'celebrate', 'insightful', 'support'], 'polls' => true, 'questions' => true, 'auto_hide_reports' => 3],
        // 12b «شكر زميل»: قيم الشركة (شارات)، نقاط لكل مستلم (0 = بلا نقاط)، حد شهري لكل مرسل، أقل طول للرسالة، وأقصى عدد للمستلمين
        'praise' => ['enabled' => true, 'values' => [
            ['key' => 'teamwork', 'ar' => 'روح الفريق', 'en' => 'Teamwork', 'color' => 'brand'],
            ['key' => 'excellence', 'ar' => 'الإتقان', 'en' => 'Excellence', 'color' => 'brass'],
            ['key' => 'initiative', 'ar' => 'المبادرة', 'en' => 'Initiative', 'color' => 'info'],
            ['key' => 'integrity', 'ar' => 'الأمانة', 'en' => 'Integrity', 'color' => 'ink-3'],
            ['key' => 'customer', 'ar' => 'خدمة العملاء', 'en' => 'Customer care', 'color' => 'brand'],
            ['key' => 'learning', 'ar' => 'التعلّم والمشاركة', 'en' => 'Learning & sharing', 'color' => 'info']],
            'points' => 5, 'monthly_limit' => 10, 'min_message' => 10, 'max_recipients' => 5],
        // 12b الاحتفالات التلقائية في مجتمع الشركة: أعياد الميلاد (بلا السنة، ولمن لم يخفها)، سنويات الخدمة، المنضمون الجدد، الترقيات
        'celebrations' => ['enabled' => true, 'birthdays' => true, 'anniversaries' => true, 'new_joiners' => true, 'promotions' => true],
        // 13 «المساعد»: تفعيله، الملخصات من نصوص السياسات (على الخادم)، الإجراءات المسموحة من المحادثة (كلها بمعاينة وتأكيد)،
        // حد الأسئلة اليومي لكل مستخدم، ومدة الاحتفاظ بالمحادثة
        'assistant' => ['enabled' => true, 'summaries' => true, 'actions' => ['leave' => true, 'justify' => true, 'certificate' => true, 'praise' => true], 'daily_limit' => 80, 'history_days' => 30],
        // M13 تقييم 360 (SRS M13): أوزان الأدوار (مجموعها 100)، السلّم، عدد الزملاء، حدود مستوى الأداء على 9-Box،
        // وجدول المكافآت حسب النتيجة (D-28 — من المالية/HR، فارغ افتراضياً: كل نتيجة تُحوَّل للمراجعة)
        // التعاقب وخطط التطوير (D-23، D-24 — SRS §10.14): مقياس الجاهزية، دورة مراجعة الخطة (ربعية)، ومراجعة خطط التعاقب
        'development' => ['readiness' => [['key' => 'now', 'ar' => 'جاهز الآن', 'en' => 'Ready now'], ['key' => '1y', 'ar' => 'خلال سنة', 'en' => 'Within a year'], ['key' => '3y', 'ar' => 'خلال 3 سنوات', 'en' => 'Within 3 years']],
            'review_months' => 3, 'succession_review_months' => 12],
        // حماية الرواتب بخطوتين (SRS §4.4): الفتح يتجدد مع الاستخدام (unlock_minutes بلا حركة، بحد أقصى max_minutes)، المحاولات قبل الإيقاف ومدته،
        // صلاحية الرمز المرسل وطلب الموافقة من الموبايل، عدد رموز الطوارئ، الطرق المتاحة، سجل المحاولات لكل مستخدم،
        // وإذن الوصول لرواتب الآخرين (10b): مطلوب؟ مدته بالساعات، والأدوار المعفاة منه (المعتمِد نفسه)
        'salary_vault' => ['unlock_minutes' => 5, 'max_minutes' => 60, 'max_attempts' => 5, 'lockout_seconds' => 60, 'otp_minutes' => 2, 'link_seconds' => 120, 'backup_count' => 8, 'audit_keep' => 200,
            'methods' => ['link' => true, 'passkey' => true, 'phone' => true, 'app' => true],
            'grant_required' => true, 'grant_hours' => 8, 'grant_exempt_roles' => ['ceo']],
        'performance' => ['weights' => ['manager' => 50, 'peers' => 30, 'self' => 20], 'scale' => 5, 'peers' => 3, 'high_from' => 4.0, 'mid_from' => 3.0, 'rewards' => []],
        // معايرة المطابقة (لكل شركة، والأوزان قابلة للتجاوز لكل شاغر) — القيم الافتراضية = الديمو، كلها نسب مئوية
        'match' => [
            'weights' => ['exp' => 30, 'deg' => 25, 'sal' => 20, 'eng' => 15, 'file' => 10],                // مجموعها 100، و0 يلغي المعيار
            'exp' => ['meets' => 70, 'bonus_years' => 3, 'below_max' => 60],                                 // يستوفي الحد ← meets٪ ثم يرتفع خطياً حتى 100٪ بعد bonus_years سنوات إضافية؛ دون الحد ← حتى below_max٪ بالتناسب
            'deg' => ['above' => 100, 'equal' => 85, 'one_below' => 40, 'lower' => 10],
            'sal' => ['to_mid' => 100, 'to_max' => 75, 'over' => 50, 'over_penalty' => 2],                    // حتى منتصف النطاق، حتى أعلاه، فوقه يبدأ من over٪ وينقص over_penalty نقطة لكل 1٪ زيادة
            'eng' => ['excellent' => 100, 'good' => 70, 'fair' => 40, 'weak' => 10],
            'file' => ['cv' => true, 'education' => true, 'experience' => true, 'refs' => true, 'linkedin' => true], // ما يُحسب في اكتمال الملف
        ],
        // قالب الطباعة (SRS §10.4): الشعار وحجمه ومحاذاته والهامش
        'print' => ['logo' => true, 'logo_height' => 56, 'align' => 'start', 'margin_mm' => 14],
        // الملفات الإضافية للموظف (SRS §10.11، D-25): الأنواع، الصيغ والحجم، أيام التنبيه قبل الانتهاء، والأدوار التي ترى «الحساس»
        // عملة صرف الرواتب (ملاحظتا الاختبار 73/78): currency '' = كل موظف بعملة راتبه؛ pay_day يوم الصرف (0 = آخر يوم بالشهر) وبتاريخه يؤخذ سعر الصرف؛
        // diff_alert_pct تنبيه الفروقات عن الشهر السابق (ملاحظة 72)
        // مذكرة الترحيب F-32 (ملاحظة 53): نص الشركة بمتغيرات (فارغ = النص الافتراضي)، والتعميم على الشركة كلها أو على إدارة الموظف فقط
        'welcome_memo' => ['ar' => '', 'en' => '', 'audience' => 'all'],
        // النقاط من الأداء (ملاحظة 61) — تُضاف لرصيد متجر المكافآت: إنجاز مهمة حسب أولويتها (late_factor عند التأخر)،
        // تحقيق مؤشر أداء فردي (kpi_met عند 100% وkpi_exceed عند exceed_pct فأكثر)، ونتيجة التقييم المعتمد حسب المستوى
        'points' => ['enabled' => true, 'task' => ['low' => 2, 'normal' => 5, 'high' => 10, 'urgent' => 15], 'late_factor' => 0.5,
            'kpi_met' => 20, 'kpi_exceed' => 30, 'exceed_pct' => 110, 'review' => ['high' => 50, 'mid' => 25, 'low' => 0]],
        'payroll_sheet' => ['currency' => '', 'pay_day' => 0, 'diff_alert_pct' => 10],
        'docs' => [
            'types' => [
                ['key' => 'id', 'ar' => 'هوية شخصية', 'en' => 'ID card'], ['key' => 'passport', 'ar' => 'جواز سفر', 'en' => 'Passport'],
                ['key' => 'degree', 'ar' => 'شهادة علمية', 'en' => 'Degree certificate'], ['key' => 'contract', 'ar' => 'عقد عمل', 'en' => 'Employment contract'],
                ['key' => 'medical', 'ar' => 'تقرير طبي', 'en' => 'Medical report'], ['key' => 'experience', 'ar' => 'شهادة خبرة', 'en' => 'Experience certificate'],
                ['key' => 'license', 'ar' => 'رخصة / إجازة مزاولة', 'en' => 'License / permit'], ['key' => 'other', 'ar' => 'أخرى', 'en' => 'Other'],
            ],
            'extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'docx'], 'max_mb' => 10, 'alert_days' => 30, 'sensitive_roles' => ['hr_director', 'hr_officer'],
        ],
        // سجل التدقيق (SRS §10.14): أيام العرض في الواجهة، ثم أرشيف دائم بلا حذف
        'audit' => ['visible_days' => 30],
        // تصدير الهيكل PDF (D-33): names_titles = الاسم والمسمى، titles = المسميات فقط؛ levels = عدد المستويات في كل صفحة
        'org_chart' => ['mode' => 'names_titles', 'levels' => 3],
        // نسخ بيانات الشركة (zip): عدد النسخ المحفوظة، وهل تشمل الملفات المرفوعة
        'backup' => ['keep' => 5, 'include_files' => true],
        // الحسابات (11e): صلاحية رابط تفعيل الحساب أو تعيين كلمة المرور بالساعات
        'accounts' => ['invite_hours' => 72, 'pw_min' => 8, 'pw_upper' => false, 'pw_symbol' => false, 'max_attempts' => 5, 'lock_minutes' => 5,
            // نطاقات البريد الرسمية لحسابات الموظفين (فارغ = أي بريد)، وطريقة «نسيت كلمة المرور»: email = رابط إلى بريد الحساب، hr = طلب إلى الموارد البشرية
            'email_domains' => [], 'reset_mode' => 'email'],
        // بنك أسئلة المقابلة الأولية والمهارات (ملاحظتا 30 و58): يُنسخ لكل شاغر ويُعدَّل فيه، ويمكن تبديل السؤال أثناء المقابلة
        'interview_bank' => ['questions' => [
            ['ar' => 'حدّثنا عن خبرتك الأخيرة وأهم إنجاز حققته فيها.', 'en' => 'Tell us about your latest role and your main achievement there.'],
            ['ar' => 'لماذا ترغب بالانضمام إلى الشركة وإلى هذه الوظيفة تحديداً؟', 'en' => 'Why do you want to join the company and this role in particular?'],
            ['ar' => 'صف موقفاً صعباً واجهته في العمل وكيف تعاملت معه.', 'en' => 'Describe a difficult situation at work and how you handled it.'],
            ['ar' => 'كيف ترتّب أولوياتك عند تزاحم المهام والمواعيد؟', 'en' => 'How do you prioritise when tasks and deadlines pile up?'],
            ['ar' => 'ما أبرز نقاط قوتك، وما الذي تعمل على تطويره؟', 'en' => 'What are your main strengths, and what are you working to improve?'],
            ['ar' => 'كيف تتعامل مع ملاحظة أو نقد من مديرك؟', 'en' => 'How do you respond to feedback or criticism from your manager?'],
            ['ar' => 'أين ترى نفسك مهنياً بعد ثلاث سنوات؟', 'en' => 'Where do you see yourself professionally in three years?'],
            ['ar' => 'متى يمكنك المباشرة، وهل لديك التزامات تؤثر على الدوام؟', 'en' => 'When can you start, and do you have commitments that affect working hours?'],
        ], 'skills' => [
            ['ar' => 'التواصل والعرض', 'en' => 'Communication'], ['ar' => 'حل المشكلات', 'en' => 'Problem solving'], ['ar' => 'العمل ضمن فريق', 'en' => 'Teamwork'],
            ['ar' => 'المعرفة التقنية بالمجال', 'en' => 'Technical knowledge'], ['ar' => 'التنظيم وإدارة الوقت', 'en' => 'Organisation and time management'],
        ]],
        'stage_sla' => ['new' => 2, 'screening' => 3, 'interview' => 5, 'technical' => 5, 'offer' => 4],
        // الخروج M11 (HR.01-03، SRS §10.8): أسباب الاستقالة، أسباب انتهاء الخدمة (م37) والفصل (F-03)، أسئلة الاستبيان ومقابلة الخروج،
        // وجهات تسليم العهدة F-07 وبنودها (auto = يُحسب من النظام: handover من F-05، assets من سجل العهد، leave من الرصيد)
        'offboarding' => [
            'reasons' => [
                ['key' => 'offer', 'ar' => 'فرصة عمل أخرى', 'en' => 'Another job offer'], ['key' => 'travel', 'ar' => 'السفر', 'en' => 'Travel'],
                ['key' => 'family', 'ar' => 'ظروف عائلية أو شخصية', 'en' => 'Family or personal reasons'], ['key' => 'study', 'ar' => 'أسباب صحية أو دراسية', 'en' => 'Health or study reasons'],
                ['key' => 'conditions', 'ar' => 'الراتب أو شروط العمل', 'en' => 'Pay or working conditions'], ['key' => 'other', 'ar' => 'أسباب أخرى', 'en' => 'Other reasons'],
            ],
            'causes' => [
                ['key' => 'dismissal', 'ar' => 'فصل من العمل', 'en' => 'Dismissal'], ['key' => 'transfer', 'ar' => 'إنهاء عقد ونقله لشركة أخرى', 'en' => 'Transfer to another company'],
                ['key' => 'contract_end', 'ar' => 'انتهاء العقد المحدد المدة', 'en' => 'End of fixed-term contract'], ['key' => 'agreement', 'ar' => 'الاتفاق بين الطرفين', 'en' => 'Mutual agreement'],
                ['key' => 'probation', 'ar' => 'خلال فترة التجربة', 'en' => 'During probation'], ['key' => 'disability', 'ar' => 'العجز عن العمل', 'en' => 'Inability to work'],
                ['key' => 'retirement', 'ar' => 'التقاعد', 'en' => 'Retirement'], ['key' => 'death', 'ar' => 'الوفاة', 'en' => 'Death'],
                ['key' => 'residence', 'ar' => 'إلغاء الإقامة', 'en' => 'Residence cancelled'], ['key' => 'harassment', 'ar' => 'التحرش', 'en' => 'Harassment'],
                ['key' => 'force_majeure', 'ar' => 'القوة القاهرة', 'en' => 'Force majeure'],
            ],
            'dismissal_reasons' => [
                ['key' => 'performance', 'ar' => 'ضعف الأداء', 'en' => 'Poor performance'], ['key' => 'rules', 'ar' => 'مخالفة الأنظمة الداخلية للشركة', 'en' => 'Breach of company rules'],
                ['key' => 'conduct', 'ar' => 'التصرف بطريقة غير احترافية', 'en' => 'Unprofessional conduct'], ['key' => 'attendance', 'ar' => 'عدم الالتزام بالدوام', 'en' => 'Poor attendance'],
                ['key' => 'other', 'ar' => 'أسباب أخرى', 'en' => 'Other reasons'],
            ],
            'questions' => [
                ['key' => 'why', 'ar' => 'لماذا تترك العمل؟', 'en' => 'Why are you leaving?'],
                ['key' => 'keep', 'ar' => 'هل هناك تغييرات أو تحسينات كانت ستجعلك تبقى؟', 'en' => 'What changes would have made you stay?'],
                ['key' => 'liked', 'ar' => 'ما أكثر شيء أحببته خلال عملك في الشركة؟', 'en' => 'What did you like most about working here?'],
                ['key' => 'disliked', 'ar' => 'ما أقل شيء أحببته خلال عملك في الشركة؟', 'en' => 'What did you like least?'],
                ['key' => 'managers', 'ar' => 'كيف تقيّم أداء مشرفك ومديرك المباشر؟', 'en' => 'How do you rate your supervisor and direct manager?'],
                ['key' => 'relations', 'ar' => 'كيف تقيّم علاقتك بمديرك وزملائك وبيئة العمل؟', 'en' => 'How was your relationship with your manager, colleagues and the work environment?'],
                ['key' => 'growth', 'ar' => 'هل قدّمت لك الشركة تدريباً كافياً وفرصاً للتقدم ومكافآت جيدة؟', 'en' => 'Did the company give you enough training, advancement and rewards?'],
                ['key' => 'ideas', 'ar' => 'هل لديك اقتراحات تجعل العمل في الشركة أكثر فائدة وإنتاجية؟', 'en' => 'Any suggestions to make work here more useful and productive?'],
            ],
            'sections' => [
                ['key' => 'manager', 'ar' => 'إدارة الموظف', 'en' => 'Employee’s department', 'role' => 'manager', 'items' => [
                    ['key' => 'handover', 'ar' => 'تسليم المهام حسب نموذج F-05', 'en' => 'Tasks handed over (F-05)', 'auto' => 'handover'], ['key' => 'stamps', 'ar' => 'الأختام', 'en' => 'Stamps']]],
                ['key' => 'it', 'ar' => 'تقانة المعلومات', 'en' => 'IT', 'role' => 'admin', 'items' => [
                    ['key' => 'devices', 'ar' => 'استلام الأجهزة وحالتها', 'en' => 'Devices returned and their condition'], ['key' => 'accounts', 'ar' => 'توقيف الحسابات (البرامج، النظام الأمني، Windows)', 'en' => 'Accounts stopped (software, security system, Windows)'],
                    ['key' => 'backup', 'ar' => 'نسخ البيانات (backup)', 'en' => 'Data backed up']]],
                ['key' => 'legal', 'ar' => 'القانوني / التنفيذي', 'en' => 'Legal / executive', 'role' => 'executive', 'items' => [['key' => 'powers', 'ar' => 'التفويضات والوكالات', 'en' => 'Delegations and powers of attorney']]],
                ['key' => 'admin', 'ar' => 'الشؤون الإدارية', 'en' => 'Administrative affairs', 'role' => 'hr_director', 'items' => [
                    ['key' => 'assets', 'ar' => 'كل الأصول من سجل العهد', 'en' => 'All assets in the custody register', 'auto' => 'assets'], ['key' => 'mobile', 'ar' => 'خط الجوال', 'en' => 'Mobile line'],
                    ['key' => 'internet', 'ar' => 'خط الإنترنت', 'en' => 'Internet line'], ['key' => 'vehicle', 'ar' => 'المركبة ومستنداتها', 'en' => 'Vehicle and its papers'], ['key' => 'keys', 'ar' => 'المفاتيح', 'en' => 'Keys']]],
                ['key' => 'finance', 'ar' => 'المالية', 'en' => 'Finance', 'role' => 'finance', 'items' => [
                    ['key' => 'work_advances', 'ar' => 'سلف العمل', 'en' => 'Work advances', 'amount' => true], ['key' => 'personal_advances', 'ar' => 'سلف شخصية', 'en' => 'Personal advances', 'amount' => true],
                    ['key' => 'loans', 'ar' => 'قروض', 'en' => 'Loans', 'amount' => true], ['key' => 'guarantee', 'ar' => 'كفالة موظف آخر', 'en' => 'Guarantee for another employee', 'amount' => true]]],
                ['key' => 'hr', 'ar' => 'الموارد البشرية', 'en' => 'Human Resources', 'role' => 'hr_director', 'items' => [
                    ['key' => 'leave', 'ar' => 'الإجازات المستحقة والمستخدمة والمتبقية', 'en' => 'Leave earned, used and remaining', 'auto' => 'leave'], ['key' => 'health', 'ar' => 'بطاقات التأمين الصحي', 'en' => 'Health insurance cards'],
                    ['key' => 'id_card', 'ar' => 'البطاقة التعريفية', 'en' => 'Staff ID card'], ['key' => 'grants', 'ar' => 'منح الدراسة والتدريب', 'en' => 'Study and training grants']]],
            ],
        ],
        // دليل الترميز (الدفعة 18): بوابة التأسيس، طلبات التغيير بعد الاعتماد، من يعتمد، قوالب الرموز المركّبة
        'codes' => ['gate' => false, 'change_requests' => true, 'self_approve' => false, 'admin_approves' => true, 'approver_roles' => ['ceo', 'admin'],
            'restructure_role' => 'ceo', 'unused_months' => 12, 'company_code' => '', 'templates' => []],
        // العقود والتجديد وتقييم التجربة (الدفعة 19 — الجزء D): كل المهل والعتبات والقوالب قابلة للتعديل
        'contracts' => [
            'renewal_open_days' => 30, 'probation_open_days' => 15, 'manager_days' => 7, 'hr_days' => 7, 'radar_days' => [90, 60, 30, 15],
            'default_months' => 12, 'probation_months' => 3, 'open_after_years' => 5, 'min_rating_renew' => 'good',
            'fixed_type' => 'FT-D', 'open_type' => 'FT-U', 'allow_extension' => true, 'extension_max_months' => 3,
            'exit_cause_not_renewed' => 'contract_end', 'exit_cause_probation' => 'probation', 'fail_last_day' => 'decision',
            'signers' => ['employee', 'hr_director', 'executive'], 'copies' => ['employee', 'file', 'insurance'], 'sign_mode' => 'both',
            'review_checks' => [['key' => 'complete', 'ar' => 'اكتمال بنود التقييم', 'en' => 'Evaluation complete'], ['key' => 'signatures', 'ar' => 'اكتمال التواقيع', 'en' => 'Signatures complete'],
                ['key' => 'policy', 'ar' => 'مطابقة التوصية للسياسة', 'en' => 'Recommendation matches the policy']],
            'scale' => [['key' => 'excellent', 'ar' => 'ممتاز', 'en' => 'Excellent', 'score' => 5], ['key' => 'very_good', 'ar' => 'جيد جداً', 'en' => 'Very good', 'score' => 4],
                ['key' => 'good', 'ar' => 'جيد', 'en' => 'Good', 'score' => 3], ['key' => 'average', 'ar' => 'متوسط', 'en' => 'Average', 'score' => 2],
                ['key' => 'weak', 'ar' => 'ضعيف', 'en' => 'Weak', 'score' => 1], ['key' => 'poor', 'ar' => 'رديء', 'en' => 'Poor', 'score' => 0]],
            'justify_other_than' => 'good',
            'items' => [['key' => 'i01', 'ar' => 'استيعاب قواعد وأساليب العمل', 'en' => 'Grasp of work rules and methods'], ['key' => 'i02', 'ar' => 'الالتزام بالسياسات والإجراءات', 'en' => 'Commitment to policies and procedures'], ['key' => 'i03', 'ar' => 'إنجاز العمل بالمستوى المطلوب', 'en' => 'Work done to the required standard'], ['key' => 'i04', 'ar' => 'إنجاز العمل في الموعد', 'en' => 'Work done on time'], ['key' => 'i05', 'ar' => 'الاجتهاد والتجاوب مع ضغط العمل', 'en' => 'Diligence under pressure'], ['key' => 'i06', 'ar' => 'الترتيب والنظام', 'en' => 'Order and organisation'], ['key' => 'i07', 'ar' => 'المبادرة والابتكار', 'en' => 'Initiative and innovation'], ['key' => 'i08', 'ar' => 'العمل دون مراقبة', 'en' => 'Working without supervision'], ['key' => 'i09', 'ar' => 'الاهتمام بتطوير العمل', 'en' => 'Interest in improving the work'], ['key' => 'i10', 'ar' => 'تقبل التوجيهات والانتقادات', 'en' => 'Accepting guidance and criticism'], ['key' => 'i11', 'ar' => 'التعاون ومساعدة الزملاء', 'en' => 'Cooperation with colleagues'], ['key' => 'i12', 'ar' => 'تحمل مسؤولية أكبر', 'en' => 'Taking on more responsibility'], ['key' => 'i13', 'ar' => 'اتخاذ القرارات', 'en' => 'Decision making'], ['key' => 'i14', 'ar' => 'المرونة والتكيف', 'en' => 'Flexibility and adaptation'], ['key' => 'i15', 'ar' => 'الإخلاص والمحافظة على مصالح العمل', 'en' => 'Loyalty to the work'], ['key' => 'i16', 'ar' => 'تحديد الأولويات', 'en' => 'Setting priorities'], ['key' => 'i17', 'ar' => 'المحافظة على السرية', 'en' => 'Confidentiality'], ['key' => 'i18', 'ar' => 'احترام الغير', 'en' => 'Respect for others'], ['key' => 'i19', 'ar' => 'المظهر اللائق', 'en' => 'Appropriate appearance'], ['key' => 'i20', 'ar' => 'السلوك الشخصي', 'en' => 'Personal conduct']],
            'bands' => [['from' => 90, 'grade' => 'excellent', 'decision' => 'confirm'], ['from' => 75, 'grade' => 'very_good', 'decision' => 'confirm'], ['from' => 55, 'grade' => 'good', 'decision' => 'confirm'],
                ['from' => 40, 'grade' => 'average', 'decision' => 'decide'], ['from' => 25, 'grade' => 'weak', 'decision' => 'terminate'], ['from' => 0, 'grade' => 'poor', 'decision' => 'terminate']],
            'template_ar' => "عقد عمل :type\n\nبين :company (الطرف الأول) و:name، الرقم الوطني :national_id (الطرف الثاني).\n\n1. يعمل الطرف الثاني بوظيفة :title في :department — :branch.\n2. مدة العقد :duration تبدأ في :start:end_text.\n3. فترة التجربة :probation.\n4. يلتزم الطرف الثاني بالأنظمة واللوائح الداخلية للشركة ومدونة السلوك المهني.\n5. يُحرَّر هذا العقد على ثلاث نسخ: للموظف، ولملفه، وللتأمينات الاجتماعية.",
            'template_en' => "Employment contract :type\n\nBetween :company (first party) and :name, national ID :national_id (second party).\n\n1. The second party works as :title in :department — :branch.\n2. The contract runs :duration from :start:end_text.\n3. Probation: :probation.\n4. The second party follows the company rules and the code of conduct.\n5. This contract is made in three copies: the employee, the file and social insurance.",
            'letters' => [
                'renewed' => ['ar' => 'نحيطكم علماً بأنه تقرر تجديد عقد عملكم :type اعتباراً من :start:end_text. نتمنى لكم دوام التوفيق.', 'en' => 'We are pleased to inform you that your contract has been renewed (:type) from :start:end_text.'],
                'not_renewed' => ['ar' => 'نحيطكم علماً بأنه تقرر عدم تجديد عقد عملكم الذي ينتهي في :end، وستتواصل معكم الموارد البشرية لاستكمال إجراءات إنهاء الخدمة.', 'en' => 'We inform you that your contract ending on :end will not be renewed. Human Resources will contact you to complete the exit procedure.'],
                'confirmed' => ['ar' => 'يسرنا إبلاغكم باجتيازكم فترة التجربة بنجاح وتثبيتكم في وظيفة :title اعتباراً من :date.', 'en' => 'We are pleased to inform you that you passed probation and are confirmed as :title from :date.'],
                'terminated' => ['ar' => 'نحيطكم علماً بأنه تقرر إنهاء عقد عملكم خلال فترة التجربة بناءً على نتيجة التقييم، ويكون آخر يوم عمل :last_day.', 'en' => 'We inform you that your contract is ended during probation based on the evaluation result. Your last working day is :last_day.'],
            ],
        ],
        // المهمات والسفر والمكافآت (الدفعة 20 — الجزء B): جدول المعدلات حسب الدرجة بعملة لكل عمود وتاريخ سريان، وكل القواعد قابلة للتعديل
        'missions' => [
            'rates' => [['grade' => 'G01', 'local' => 50000, 'local_currency' => 'SYP', 'foreign' => 350000, 'foreign_currency' => 'SYP', 'hotel_local' => 0, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 0, 'hotel_foreign_currency' => 'USD', 'ticket' => 'economy', 'valid_from' => '2020-01-01'], ['grade' => 'G02', 'local' => 50000, 'local_currency' => 'SYP', 'foreign' => 350000, 'foreign_currency' => 'SYP', 'hotel_local' => 0, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 0, 'hotel_foreign_currency' => 'USD', 'ticket' => 'economy', 'valid_from' => '2020-01-01'], ['grade' => 'G03', 'local' => 100000, 'local_currency' => 'SYP', 'foreign' => 100, 'foreign_currency' => 'USD', 'hotel_local' => 200000, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 120, 'hotel_foreign_currency' => 'USD', 'ticket' => 'economy', 'valid_from' => '2020-01-01'], ['grade' => 'G04', 'local' => 100000, 'local_currency' => 'SYP', 'foreign' => 100, 'foreign_currency' => 'USD', 'hotel_local' => 200000, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 120, 'hotel_foreign_currency' => 'USD', 'ticket' => 'economy', 'valid_from' => '2020-01-01'], ['grade' => 'G05', 'local' => 125000, 'local_currency' => 'SYP', 'foreign' => 100, 'foreign_currency' => 'USD', 'hotel_local' => 250000, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 150, 'hotel_foreign_currency' => 'USD', 'ticket' => 'economy', 'valid_from' => '2020-01-01'], ['grade' => 'G06', 'local' => 125000, 'local_currency' => 'SYP', 'foreign' => 100, 'foreign_currency' => 'USD', 'hotel_local' => 250000, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 150, 'hotel_foreign_currency' => 'USD', 'ticket' => 'economy', 'valid_from' => '2020-01-01'], ['grade' => 'G07', 'local' => 150000, 'local_currency' => 'SYP', 'foreign' => 100, 'foreign_currency' => 'USD', 'hotel_local' => 300000, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 150, 'hotel_foreign_currency' => 'USD', 'ticket' => 'economy', 'valid_from' => '2020-01-01'], ['grade' => 'G08', 'local' => 150000, 'local_currency' => 'SYP', 'foreign' => 100, 'foreign_currency' => 'USD', 'hotel_local' => 300000, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 150, 'hotel_foreign_currency' => 'USD', 'ticket' => 'business', 'valid_from' => '2020-01-01'], ['grade' => 'G09', 'local' => 150000, 'local_currency' => 'SYP', 'foreign' => 100, 'foreign_currency' => 'USD', 'hotel_local' => 300000, 'hotel_local_currency' => 'SYP', 'hotel_foreign' => 150, 'hotel_foreign_currency' => 'USD', 'ticket' => 'business', 'valid_from' => '2020-01-01']],
            'default_grade' => 'G03', 'fuel_price' => 75000, 'fuel_currency' => 'SYP', 'km_per_tank' => 150, 'depreciation_pct' => 15,
            'close_days' => 5, 'cutoff_day' => 25, 'installments' => 1, 'half_travel_days' => false, 'block_unclosed' => true,
            'over_limit' => 'cap', 'expense_cap' => 0, 'expense_currency' => 'SYP',
            'stages' => [['name' => 'تحضير', 'en' => 'Preparation', 'weight' => 10], ['name' => 'سفر', 'en' => 'Travel', 'weight' => 10], ['name' => 'تنفيذ', 'en' => 'Execution', 'weight' => 50],
                ['name' => 'عودة', 'en' => 'Return', 'weight' => 10], ['name' => 'تقرير', 'en' => 'Report', 'weight' => 20]],
            'proof_types' => ['MT-30', 'MT-40', 'MT-50'],
            'completion_bonus' => ['MT-10' => 0, 'MT-20' => 0, 'MT-30' => 0, 'MT-40' => 0, 'MT-50' => 0, 'MT-60' => 0, 'MT-70' => 0],
            'trainer_rates' => ['beginner' => 15000, 'intermediate' => 25000, 'advanced' => 40000], 'trainer_currency' => 'SYP',
            'attendance_bonus' => 0, 'attendance_bonus_currency' => 'SYP', 'bonus_over_cap_role' => 'ceo', 'bonus_monthly_cap' => 0,
        ],
        // التأهيل والتدريب والتطوير (الدفعة 23 — SRS التدريب): قواعد BR-01…18 وكل قيمة مفتوحة في القسم 15 بقيمة افتراضية
        // كود تضمين شواغر الشركة في موقعها (FEATURES 05b): إطار مستقل يُحدَّث تلقائياً، والمواقع المسموح لها بتضمينه
        'embed' => ['enabled' => true, 'sites' => [], 'limit' => 20, 'theme' => 'light', 'show_logo' => true, 'show_filters' => true, 'color' => ''],
        'training' => [
            'mode' => 'hr_only',                    // FR-TD-010: hr_only | self_service (المدراء والموظفون يقترحون)
            'probation_block' => true,              // BR-01
            'pass_mark' => 75, 'attendance_min' => 85, 'excellence' => 90,   // BR-02 / BR-14
            'ban_threshold' => 70,                  // BR-03: نتائج المتدربين دون هذه النسبة ← توصية حظر
            'capacity' => ['general' => [8, 20], 'workshop' => [10, 20], 'lecture' => [0, 20], 'group' => [15, 20]],   // BR-04
            'min_quotes' => 3,                      // BR-05: عروض التدريب الخارجي
            'commitment_pct' => 30,                 // BR-06: التكلفة للفرد ≥ نسبة من الراتب الشهري
            'commitment_on_request' => true,        // أو البرنامج بطلب من الموظف
            'commitment_tiers' => [['from' => 0, 'to' => 300, 'months' => 3], ['from' => 300, 'to' => 1000, 'months' => 6], ['from' => 1000, 'to' => 3000, 'months' => 12], ['from' => 3000, 'to' => 0, 'months' => 24]],
            'commitment_currency' => 'USD',
            'recovery' => 'prorata',                // BR-13: full | prorata
            'min_evidence' => 2,                    // BR-08
            'tna_from_month' => 10, 'tna_to_month' => 11,   // BR-11
            'impact_points' => [30, 60, 90],        // BR-12
            'impact_form_point' => 60,              // نقطة F-05 (قائمة السلوك)
            'impact_pass' => 60,                    // متوسط F-05 الذي يُحدّث مستوى الكفاءة
            'remind_days' => 3, 'escalate_days' => 7,   // BR-17
            'survey_min' => 60,                     // BR-16: نسبة استجابة الاستبيان قبل الإغلاق
            'gap_threshold' => 1,                   // 10.1: فجوة ≥ مستوى واحد ← احتياج مقترح
            'weights' => ['impact' => 1, 'gap' => 1, 'affected' => 1, 'urgency' => 1],   // FR-TD-029
            'cert_alerts' => [90, 60, 30],          // FR-TD-103
            'stage_hours_pct' => 85, 'stage_min_rating' => 3, 'stage_free_max_months' => 6,   // BR-15 / POL-22
            'peak_seasons' => [],                   // [{from: MM-DD, to: MM-DD, name}]
            'cost_types' => ['trainer', 'center', 'room', 'materials', 'hospitality', 'travel', 'certificates'],
            'logistics' => ['القاعة والتهوية والإضاءة', 'أنظمة العرض والصوت والإنترنت', 'المواد والأدوات', 'الضيافة', 'الإسعافات والسلامة', 'السفر والإقامة عند الحاجة'],
            'survey_items' => ['وضوح مواضيع الدورة والأمثلة', 'تمكن المحاضر من المعلومات', 'ارتباط الموضوع بطبيعة العمل', 'قدرة المحاضر على الربط بأمثلة حقيقية', 'قدرة المحاضر على الجذب والحث على المشاركة', 'تنظيم الوقت', 'ملاءمة القاعة والتجهيزات'],
            'impact_items' => ['تعزيز التفاعل والتعاون في الفريق', 'تطوير مهارات الاتصال ضمن بيئة العمل', 'المبادرة بطرح أفكار لآلية عمل جديدة', 'مدى تطبيق المعلومات ضمن بيئة العمل', 'انخفاض الأخطاء عقب التدريب', 'سرعة الإنجاز', 'جودة المخرجات', 'الالتزام بالإجراءات', 'نقل المعرفة للزملاء'],
            'qualification_templates' => [],        // [{key, ar, en, job_ids[], department_ids[], stations: [{department_id, days, topics}]}]
            'commitment_ar' => "تعهد الالتزام بالتدريب والعمل\n\nأنا الموقّع أدناه :name، أتعهد بحضور دورة «:course» من :from إلى :to، وبالعمل لدى :company مدة :months شهراً من تاريخ انتهاء الدورة مقابل تكلفتها البالغة :cost.\nأتعهد بتنفيذ المهام بكفاءة خلال فترة التدريب، والامتثال للسياسات والأخلاقيات المهنية وحماية سرية المعلومات.\nفي حال انتهاء خدمتي بطلبي قبل انقضاء مدة الالتزام، أسدد قيمة التدريب :mode.",
            'commitment_en' => "Training & service commitment\n\nI, the undersigned :name, commit to attending «:course» from :from to :to, and to working for :company for :months months after the course ends, in return for its cost of :cost.\nI commit to performing my duties well during the training, complying with the policies and professional ethics and protecting confidential information.\nIf my service ends at my request before the commitment period ends, I will repay the training cost :mode.",
        ],
        // العاملون من غير الموظفين (الدفعة 22 — الجزء E): حدود التطوع، فحص طبيعة العلاقة، الاعتماد، الشهادة، قائمة الخبراء، سرية الأتعاب
        'externals' => [
            'max_months' => 6,                      // POL-VL-04: المدة القصوى لاتفاقية التطوع
            'max_weekly_hours' => 20,               // POL-VL-04: الحد الأعلى للساعات الأسبوعية
            'warn_pct' => 80,                       // تنبيه عند بلوغ هذه النسبة من الحد
            'relation_threshold' => 3,              // E.2: عدد الإجابات «نعم» التي تحيل التكليف لاعتماد أعلى
            'replaces_max_months' => 3,             // حالة «يحل محل موظف»: مدة قصوى ومبرر واعتماد الإدارة العليا
            'default_relation' => 'alongside',      // replaces | alongside | independent
            'approver' => 'hr_director', 'high_approver' => 'ceo',
            'pledges' => ['agreement', 'confidentiality', 'conduct', 'safety'],   // POL-VL-05: وثائق إلزامية قبل التفعيل
            'onboarding' => [['ar' => 'التعريف بالمنشأة وأنظمتها', 'en' => 'Introduction to the organisation'], ['ar' => 'إجراءات السلامة والحماية', 'en' => 'Safety & safeguarding'], ['ar' => 'التعريف بالمشرف والفريق', 'en' => 'Meet the supervisor and team']],
            'expense_cap' => 50000, 'expense_currency' => 'SYP',   // POL-VL-03: حد تعويض المصاريف لكل طلب
            'hour_value' => 0, 'hour_currency' => 'SYP',          // القيمة التقديرية لساعة التطوع في التقارير
            'end_warn_days' => 14,                  // FR-VL-015: تنبيه قبل انتهاء الاتفاقية
            'roster_review_months' => 12,           // POL-RS-08: طلب تحديث بيانات الخبير
            'roster_inactive_days' => 30,           // تعطيل من لا يستجيب لطلب التحديث
            'shortlist_min' => 2,                   // POL-RS-09: أكثر من خبير في القائمة المختصرة
            'doc_alert_days' => 30,                 // FR-RS-003: تنبيه قبل انتهاء صلاحية الشهادات
            'fee_roles' => ['hr_director', 'finance', 'ceo'],     // POL-RS-07: من يرى الأتعاب والتقييمات والملاحظات
            'agreement_ar' => "اتفاقية تطوع مؤقتة\n\nبين :company (الجهة المستضيفة) والسيد/ة :name (المتطوع)، رقم الهوية :national_id.\n\nالجهة المستضيفة: فرع :branch، :department، والمشرف :supervisor.\nالمدة: من :from إلى :to، بمعدل :hours ساعة أسبوعياً.\nالعلاقة التشغيلية: :relation.\n\n1. التطوع دون أجر، ولا تنشئ هذه الاتفاقية علاقة عمل.\n2. المهام المحددة والمخرجات المتوقعة: :tasks\n3. يلتزم المتطوع بالحضور المتفق عليه، والسرية، ومدونة السلوك، وإجراءات السلامة والحماية، والمحافظة على الممتلكات.\n4. تلتزم الجهة بالتوجيه والتدريب الأولي، وبيئة آمنة، ومشرف مسؤول، وتعويض المصاريف المتفق عليها، وشهادة تطوع.\n5. التعويضات إن وُجدت: مصاريف فعلية موثقة بحد أعلى :cap لكل طلب.\n6. يجوز لأي طرف إنهاء الاتفاقية، مع إعادة أي عهدة.\n7. حماية البيانات، والملكية الفكرية للمخرجات للجهة المستضيفة.",
            'agreement_en' => "Temporary volunteer agreement\n\nBetween :company (the host) and :name (the volunteer), ID :national_id.\n\nHost: :branch branch, :department, supervisor :supervisor.\nPeriod: :from to :to, :hours hours a week.\nOperational relation: :relation.\n\n1. Volunteering is unpaid and this agreement does not create an employment relationship.\n2. Tasks and expected outputs: :tasks\n3. The volunteer commits to the agreed attendance, confidentiality, the code of conduct, safety and safeguarding procedures and care of property.\n4. The host provides orientation and initial training, a safe environment, a responsible supervisor, the agreed expense reimbursement and a volunteering certificate.\n5. Reimbursement, if any: documented actual expenses up to :cap per claim.\n6. Either party may end the agreement; any custody is returned.\n7. Data protection; intellectual property of outputs belongs to the host.",
        ],
        // NFC وتعدد الفروع (الدفعة 21 — الجزء C): قرار البصم خارج الفرع، نوافذ التكرار، تنبيهات الأمان، الحضور بملصق الفرع
        // وكيل جهاز الموظف UHR-Agent (D-15): الخروج بالبطاقة يقفل جهازه — الافتراضي من ورقة القواعد: قفل وتسجيل خروج Windows بلا إيقاف تشغيل، تحذير 60 ثانية
        'pc_agent' => ['enabled' => true, 'action' => 'logoff', 'warn_seconds' => 60, 'postpone_minutes' => 0, 'sources' => ['card'],
            'fresh_minutes' => 10, 'queue_hours' => 12, 'offline_minutes' => 3, 'poll_seconds' => 10, 'notify_employee' => true],
        'nfc' => [
            'off_branch' => 'flag',                 // reject | flag (قبول مع تعليم للمراجعة)
            'mission_access' => true,               // المهمة المعتمدة إلى فرع آخر تمنح صلاحية مؤقتة آلياً
            'duplicate_seconds' => 60,              // القراءة نفسها خلال هذه النافذة تُتجاهل
            'direction' => 'auto',                  // auto (نوع الجهاز ثم تسلسل البصمات) | sequence
            'signature' => 'optional',              // optional | required (توقيع HMAC لكل رسالة)
            'activation_hours' => 72,               // صلاحية رمز التفعيل
            'offline_minutes' => 10,                // انقطاع النبضة ← تنبيه «جهاز منقطع»
            'drift_seconds' => 120,                 // فرق توقيت ساعة الجهاز المقبول
            'unknown_alert' => 3,                   // بطاقة غير معروفة تتكرر N مرات في اليوم ← تنبيه
            'buddy_seconds' => 3,                   // بطاقتان على الجهاز نفسه خلال ثوانٍ…
            'buddy_repeat' => 3,                    // …تكرر ذلك N مرات خلال 30 يوماً ← تنبيه
            'auto_disable' => true,                 // تعطيل البطاقات تلقائياً عند انتهاء الخدمة
            'sticker' => true,                      // الحضور بالهاتف بمسح ملصق الفرع
            'sticker_geo' => true,                  // يتطلب الموقع الجغرافي ضمن نطاق الفرع
            'card_security' => 'uid',               // uid | memory | secure (للعلم — يحدد نوع القارئ المطلوب)
        ],
    ];

    /** إعدادات المنصة (بلا شركة): نصوص بوابة فرص العمل العامة — SRS §10.15 */
    public const PLATFORM_DEFAULTS = [
        // النسخة الكاملة للخادم (D-27): يومياً في الوقت المحدد، والاحتفاظ بآخر keep نسخة
        'backup' => ['enabled' => true, 'time' => '02:30', 'keep' => 14],
        // البريد الصادر (11e): خادم SMTP للمنصة كلها — host فارغ = إعداد الخادم (MAIL_* في البيئة). password مشفّر. log_days = مدة حفظ سجل البريد
        // بوابة لكل شركة (86): base_domain فارغ = لا عناوين فرعية؛ وإلا {slug}.base_domain يفتح بوابة الشركة
        'portal_hosts' => ['base_domain' => '', 'redirect_employees' => true],   // الموظف الداخل من البوابة العامة يُنقل لعنوان بوابة شركته
        // إشعارات تطبيق الجوال (Firebase): مفتاح حساب الخدمة JSON مشفّراً؛ فارغ = بلا إشعارات خارج التطبيق
        'push' => ['enabled' => true, 'credentials' => ''],
        'mail' => ['host' => '', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '', 'from_address' => '', 'from_name' => 'UHR', 'log_days' => 90],
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
        // portal_checkin: زر «تسجيل الحضور» من بوابة الموظف (M14)؛ geo_*: نطاق جغرافي اختياري (نصف القطر 0 = بلا قيد)
        'terminal' => ['cooldown' => 10, 'incomplete_after' => 120, 'portal_checkin' => true, 'geo_lat' => 0, 'geo_lng' => 0, 'geo_radius' => 0],
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
            // المرضية (نظام العاملين م. الإجازة المرضية): 90 يوماً بـ70٪ ثم 90 يوماً بـ80٪ في السنة — الحد السنوي = مجموعهما
            'sick_first_days' => 90, 'sick_first_paid' => 70, 'sick_second_days' => 90, 'sick_second_paid' => 80,
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
        // المخالفات M17 (SRS §10.7): سقف الحسم أيام أجر بالشهر، مهلة القرار من الاكتشاف، مدة احتساب التكرار، صلاحية HR حتى حسم hr_max_days
        // (ما فوقه وتأخير/حرمان الترفيع والفصل للمدير العام)، والاقتراح الآلي من الحضور بعد عدد مرات بالشهر
        'discipline' => ['cap_days' => 5, 'deadline_days' => 15, 'repeat_months' => 6, 'hr_max_days' => 3, 'suggest_late' => 3, 'suggest_absent' => 1, 'suggest_early' => 2],
        'sick_leave' => ['full' => 30, 'p80' => 30, 'p75' => 30],
        'probation_days' => 90,
        // الخروج (HR.01-03): الإخطار، مهلة قرار المدير العام، بدل الإشعار عند مخالفته (أشهر أجر)، إعانة الوفاة، مهلة الانفكاك من التأمينات
        'exit' => ['notice_days' => 60, 'decision_days' => 30, 'notice_pay_months' => 2, 'death_grant_months' => 2, 'insurance_days' => 15],
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
    public static function development(): array
    {
        $d = self::DEFAULTS['development'];
        $out = array_merge($d, array_intersect_key(self::get('development') ?? [], $d));
        // مفاتيح الجاهزية ثابتة (now/1y/3y)، والتسميات قابلة للتعديل
        $labels = collect($out['readiness'])->keyBy('key');
        $out['readiness'] = array_map(fn ($r) => ['key' => $r['key'], 'ar' => $labels[$r['key']]['ar'] ?? $r['ar'], 'en' => $labels[$r['key']]['en'] ?? $r['en']], $d['readiness']);
        return $out;
    }

    public static function performance(): array
    {
        $saved = self::get('performance') ?? [];
        $d = self::DEFAULTS['performance'];
        $out = array_merge($d, array_intersect_key($saved, $d));
        $out['weights'] = array_merge($d['weights'], array_intersect_key($saved['weights'] ?? [], $d['weights']));
        return $out;
    }

    public static function vault(): array
    {
        $saved = self::get('salary_vault') ?? [];
        $d = self::DEFAULTS['salary_vault'];
        $out = array_merge($d, array_intersect_key($saved, $d));
        $out['methods'] = array_merge($d['methods'], array_intersect_key($saved['methods'] ?? [], $d['methods']));
        return $out;
    }

    /** إعداد بمفاتيح ثابتة: المحفوظ فوق الافتراضي */
    public static function merged(string $key): array
    {
        return array_merge(self::DEFAULTS[$key], array_intersect_key(self::get($key) ?? [], self::DEFAULTS[$key]));
    }

    public static function platformMerged(string $key): array
    {
        return array_merge(self::PLATFORM_DEFAULTS[$key], array_intersect_key(self::platform($key) ?? [], self::PLATFORM_DEFAULTS[$key]));
    }

    public static function docs(): array
    {
        return array_merge(self::DEFAULTS['docs'], array_intersect_key(self::get('docs') ?? [], self::DEFAULTS['docs']));
    }

    /** إعدادات الخروج (M11): القوائم المحفوظة فوق الافتراضي */
    public static function offboarding(): array
    {
        return array_merge(self::DEFAULTS['offboarding'], array_intersect_key(self::get('offboarding') ?? [], self::DEFAULTS['offboarding']));
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
