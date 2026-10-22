<?php

namespace App\Support;

use App\Models\ViolationType;

/**
 * دليل المخالفات الافتراضي = «02-جدول المخالفات» للشركة (قانون العمل 17/2010): ثلاث مجموعات (مواعيد العمل 10، نظام العمل 18، سلوك العامل 13)،
 * لكل مخالفة جزاء أول/ثاني/ثالث/رابع مرة (أو جزاء واحد). قابل للتعديل من الإعدادات (M16).
 * رموز الجزاء: N تنبيه · W إنذار كتابي · D{أيام} حسم أجر · PD تأخير الترفيع · PX الحرمان من علاوة الترفيع · X الفصل · F غرامة بمرسوم · A يُعتبر غائباً.
 * auto = ربط باقتراح آلي من الحضور (late15، late30، late60، late_over، absent، early).
 */
class ViolationCatalog
{
    public const GROUPS = ['time' => ['مواعيد العمل', 'Working hours'], 'work' => ['نظام العمل', 'Work rules'], 'conduct' => ['سلوك العامل', 'Conduct']];

    public const KINDS = ['note' => ['تنبيه', 'Notice'], 'warning' => ['إنذار كتابي', 'Written warning'], 'deduct' => ['حسم أجر', 'Pay deduction'],
        'promotion_delay' => ['تأخير موعد استحقاق علاوة الترفيع حتى سنة', 'Promotion raise delayed up to a year'], 'promotion_deny' => ['الحرمان من علاوة الترفيع الدوري', 'Periodic raise withheld'],
        'dismissal' => ['الفصل من الخدمة وفق أحكام القانون', 'Dismissal under the law'], 'fine' => ['الغرامة المحددة بالمرسوم 62 لعام 2009', 'Fine set by Decree 62/2009'],
        'absent' => ['يُمنع من الدخول ويُعتبر غائباً', 'Barred from entry and counted absent']];

    /** [group, title_ar, title_en, article, penalties, auto] */
    public const DEFAULTS = [
        ['time', 'التأخر عن مواعيد العمل لمدة تصل إلى 15 دقيقة دون إنذار أو عذر مقبول إذا لم يترتب عن التأخير تعطيل عمال آخرين', 'Late up to 15 minutes without excuse, no disruption to others', '95/ب/3', ['W', 'D0.25', 'D0.5', 'D1'], 'late15'],
        ['time', 'التأخر عن مواعيد العمل لمدة تصل إلى 15 دقيقة دون إنذار أو عذر مقبول إذا ترتب عن التأخير تعطيل عمال آخرين', 'Late up to 15 minutes without excuse, disrupting others', '95/ب/3', ['D0.25', 'D1', 'D2', 'D3'], null],
        ['time', 'التأخر عن مواعيد العمل أكثر من 15 دقيقة ولا يزيد عن 30 دقيقة دون إنذار أو عذر مقبول إذا لم يترتب عن التأخير تعطيل عمال آخرين', 'Late 15–30 minutes without excuse, no disruption to others', '95/ب/3', ['D0.25', 'D0.5', 'D1', 'D2'], 'late30'],
        ['time', 'التأخر عن مواعيد العمل أكثر من 15 دقيقة ولا يزيد عن 30 دقيقة دون إنذار أو عذر مقبول إذا ترتب عن التأخير تعطيل عمال آخرين', 'Late 15–30 minutes without excuse, disrupting others', '95/ب/3', ['D0.5', 'D1', 'D2', 'D3'], null],
        ['time', 'التأخر عن مواعيد العمل أكثر من 30 دقيقة ولا يزيد عن 60 دقيقة دون إنذار أو عذر مقبول إذا لم يترتب عن التأخير تعطيل عمال آخرين', 'Late 30–60 minutes without excuse, no disruption to others', '95/ب/3', ['D0.5', 'D1', 'D2', 'D3'], 'late60'],
        ['time', 'التأخر عن مواعيد العمل أكثر من 30 دقيقة ولا يزيد عن 60 دقيقة دون إنذار أو عذر مقبول إذا ترتب عن التأخير تعطيل عمال آخرين', 'Late 30–60 minutes without excuse, disrupting others', '95/ب/3', ['D1', 'D2', 'D3', 'D4'], null],
        ['time', 'التأخر عن مواعيد الحضور ساعة أو أكثر دون إنذار أو عذر مقبول', 'Late one hour or more without excuse', '95/ب/3', ['A'], 'late_over'],
        ['time', 'الغياب عن العمل يوماً كاملاً أو أياماً لا تتجاوز الخمسة بدون إذن أو عذر مقبول (مع حسم أجر أيام الغياب)', 'Absence of up to five days without leave or excuse (absent days are also unpaid)', '95/ب/3', ['D0.25', 'D0.5', 'D1', 'D2'], 'absent'],
        ['time', 'ترك العمل أو الانصراف قبل الميعاد المحدد بدون إذن أو عذر', 'Leaving work early without permission or excuse', '95/ب/3', ['D0.25', 'D0.5', 'D1', 'D2'], 'early'],
        ['time', 'البقاء في مكان العمل أو العودة إليه بعد انتهاء المواعيد بدون إذن أو عذر مقبول بما لا يتجاوز الربع ساعة', 'Staying or returning after hours without permission (up to 15 minutes)', '95/ب/3', ['W', 'D0.25', 'D0.5', 'D1'], null],

        ['work', 'الخروج من غير المكان المخصص إذا كانت تعليمات المنشأة تقضي بذلك', 'Leaving by a non-designated exit against instructions', '95/ب/3', ['W', 'D0.25', 'D0.5', 'D1'], null],
        ['work', 'استقبال زائرين في مكان العمل بدون إذن', 'Receiving visitors at work without permission', '95/ب/3', ['D0.25', 'D0.5', 'D1', 'D2'], null],
        ['work', 'تناول الطعام في غير المكان المخصص أو الميعاد المحدد لذلك', 'Eating outside the designated place or time', '95/ب/3', ['W', 'D0.5', 'D1', 'D2'], null],
        ['work', 'التسكع أو التواجد في غير محل العمل أثناء ساعات العمل', 'Loitering away from the workplace during working hours', '95/ب/3', ['D0.25', 'D0.5', 'D1', 'D2'], null],
        ['work', 'الانصراف عن العمل بأمور مختلفة كقراءة المجلات أو المطبوعات وتوزيعها', 'Neglecting work for other matters such as reading or distributing publications', '95/ب/3', ['D0.5', 'D1', 'D2', 'D3'], null],
        ['work', 'استعمال مكان العمل أو أدواته أو آلياته أو خاماته لأغراض شخصية دون إذن', 'Using the workplace, tools or materials for personal purposes without permission', '95/ب', ['D5', 'PD', 'PX', 'X'], null],
        ['work', 'كتابة بيانات أو عبارات أو لصق إعلانات على جدران المنشأة أو غيرها في مكان العمل بدون إذن', 'Writing statements or posting notices on walls without permission', '', ['W', 'D0.25', 'D0.5', 'D1'], null],
        ['work', 'مخالفة التعليمات المتعلقة بإثبات الحضور والانصراف', 'Breaking the check-in/check-out instructions', '95/ب', ['D0.25', 'D0.5', 'D1', 'D2'], null],
        ['work', 'تعمد إنقاص الإنتاج', 'Deliberately reducing output', '64', ['D5', 'PD', 'PX', 'X'], null],
        ['work', 'الإسراف في استهلاك الخامات بدون سبب مقبول', 'Wasting materials without a valid reason', '95/ب/4', ['D5', 'PD', 'PX', 'X'], null],
        ['work', 'النوم أثناء العمل في الحالات التي تستدعي اليقظة المستمرة دون التسبب بإلحاق الضرر بصاحب العمل', 'Sleeping on duty where constant alertness is required, without harm', '', ['D5', 'PD', 'PX', 'X'], null],
        ['work', 'النوم أثناء العمل في الحالات التي تستدعي اليقظة المستمرة مع التسبب بإلحاق الضرر بصاحب العمل', 'Sleeping on duty where constant alertness is required, causing harm', '', ['PD', 'X'], null],
        ['work', 'العبث أو إتلاف ماكينات المنشأة أو آلاتها أو خاماتها عمداً', 'Deliberately tampering with or damaging machines or materials', '95/ب/4', ['D5', 'PD', 'PX', 'X'], null],
        ['work', 'تعمد التأخر في إنجاز العمل بموعده المحدد', 'Deliberately delaying work past its deadline', '', ['D5', 'PD', 'PX', 'X'], null],
        ['work', 'العمل لدى الغير دون موافقة صاحب العمل', 'Working for others without the employer’s approval', '96/ب', ['X'], null],
        ['work', 'العمل لدى الغير بما لا يتفق مع كرامة العمل', 'Working for others in a way that offends the dignity of work', '', ['X'], null],
        ['work', 'تمكين الغير من التعرف على أسرار منشأة صاحب العمل', 'Disclosing the employer’s secrets', '96/ب', ['X'], null],
        ['work', 'الاحتفاظ بأصل أية ورقة أو مستند خاص بالعمل', 'Keeping the original of any work paper or document', '', ['X'], null],

        ['conduct', 'إدخال أشياء غير مصرح بها أو إجراء معاملات تجارية داخل مكان العمل', 'Bringing unauthorised items or trading at the workplace', '95/ب', ['D5', 'PD', 'PX', 'X'], null],
        ['conduct', 'التدخين أثناء العمل في أماكن غير مسموح فيها بالتدخين', 'Smoking at work where smoking is not allowed', 'مرسوم 62/2009', ['F'], null],
        ['conduct', 'التشاجر مع الزملاء أو إحداث مشاغبات في محل العمل', 'Fighting with colleagues or causing disturbances', '95/ب', ['D1', 'D2', 'D3', 'D5'], null],
        ['conduct', 'الامتناع عن توقيع الكشف الطبي عند الطلب', 'Refusing a medical examination when requested', '95/ب', ['D1', 'D2', 'D3', 'D5'], null],
        ['conduct', 'عدم توريد النقود المحصلة لحساب المنشأة في المواعيد المحددة بدون عذر مقبول', 'Not handing over collected money on time without excuse', '95/ب', ['D5', 'X'], null],
        ['conduct', 'ممارسة نشاط مماثل لنشاط صاحب العمل أثناء سريان العقد أو الاشتراك فيه كعامل أو شريك', 'Running or joining a competing business while under contract', '', ['D5', 'PD', 'PX', 'X'], null],
        ['conduct', 'جمع نقود أو إعانات أو توقيعات بدون إذن مع مراعاة القوانين والأنظمة النافذة', 'Collecting money, donations or signatures without permission', '', ['D5', 'PD', 'PX', 'X'], null],
        ['conduct', 'إساءة معاملة زبائن صاحب العمل', 'Mistreating the employer’s customers', '', ['D5', 'PD', 'PX', 'X'], null],
        ['conduct', 'الاقتراض من زبائن صاحب العمل', 'Borrowing from the employer’s customers', '', ['X'], null],
        ['conduct', 'قبول نقود أو هدايا من زبائن صاحب العمل أو ممن يمارسون نشاطاً مماثلاً بقصد الإضرار بالمنشأة', 'Accepting money or gifts from customers or competitors to harm the company', '95/ب/6', ['X'], null],
        ['conduct', 'تقديم نقود أو هدايا لعامل آخر في المنشأة لتحقيق أغراض تضر بالمنشأة', 'Giving money or gifts to another employee for purposes that harm the company', '96/و', ['X'], null],
        ['conduct', 'إهانة الرؤساء أو وكلائهم أو الزملاء', 'Insulting superiors, their deputies or colleagues', '95/ب/6', ['D5', 'PD', 'PX', 'X'], null],
        ['conduct', 'الاعتداء على الزملاء', 'Assaulting colleagues', '95/ب/6', ['X'], null],
    ];

    /** 'D0.5' → ['kind' => 'deduct', 'days' => 0.5] */
    public static function penalty(string $code): array
    {
        if (str_starts_with($code, 'D')) return ['kind' => 'deduct', 'days' => (float) substr($code, 1)];
        return ['kind' => ['N' => 'note', 'W' => 'warning', 'PD' => 'promotion_delay', 'PX' => 'promotion_deny', 'X' => 'dismissal', 'F' => 'fine', 'A' => 'absent'][$code]];
    }

    /** نص الجزاء بلغة */
    public static function label(array $p, string $lang = 'ar'): string
    {
        $k = self::KINDS[$p['kind']] ?? [$p['kind'], $p['kind']];
        if ($p['kind'] !== 'deduct') return $lang === 'en' ? $k[1] : $k[0];
        $d = (float) $p['days'];
        $ar = match (true) { $d == 0.25 => 'ربع يوم', $d == 0.5 => 'نصف يوم', $d == 1.0 => 'يوم', $d == 2.0 => 'يومين', default => rtrim(rtrim(number_format($d, 2), '0'), '.').' أيام' };
        return $lang === 'en' ? 'Deduct '.rtrim(rtrim(number_format($d, 2), '0'), '.').' day(s) of pay' : 'حسم أجر '.$ar;
    }

    /** يملأ الدليل للشركة الحالية إن كان فارغاً */
    public static function seed(): void
    {
        if (ViolationType::exists()) return;
        $n = ['time' => 0, 'work' => 0, 'conduct' => 0];
        foreach (self::DEFAULTS as [$group, $ar, $en, $article, $pen, $auto]) {
            ViolationType::create(['group' => $group, 'number' => ++$n[$group], 'title_ar' => $ar, 'title_en' => $en, 'article' => $article ?: null,
                'penalties' => array_map([self::class, 'penalty'], $pen), 'auto' => $auto, 'active' => true]);
        }
    }
}
