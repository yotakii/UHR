<?php

namespace App\Services\Assistant;

use App\Exceptions\ApiException;
use App\Models\AssistantMessage;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\WorkTask;
use App\Services\Ai\Writer;
use App\Services\Attendance\Attendance;
use App\Services\Attendance\WorkSchedules;
use App\Services\Engagement\Announcements;
use App\Services\Leave\LeaveService;
use App\Services\People\Directory;
use App\Services\People\EmployeeDocs;
use App\Services\Workflow\Workflow;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

/**
 * «المساعد» (المهمة 13): سؤال بلغة طبيعية ← جواب من بيانات الموظف نفسه (بقواعد ثابتة، فوري ودقيق) أو من سياسات الشركة
 * ودليل الاستخدام (أقرب الفقرات فوراً + ملخص قصير يكتبه النموذج المحلي بالتدفق)، أو إجراء بمعاينة وتأكيد (Actions).
 * كل شيء ضمن صلاحيات المستخدم وشركته؛ لا مبالغ رواتب في المحادثة أبداً (تبقى خلف التحقق من الهوية).
 */
class Assistant
{
    public const LINKS = ['/me/requests' => '/hr/my-requests', '/me/attendance' => '/hr/my-attendance', '/me/services' => '/hr/my-services', '/me/payslip' => '/hr/my-payslip',
        '/me/holidays' => '/hr/my-holidays', '/me/rewards' => '/hr/my-rewards', '/me/news' => '/hr/my-news', '/me/documents' => '/hr/my-documents', '/me/tasks' => '/hr/tasks',
        '/me/approvals' => '/hr/approvals', '/me/space' => '/hr/space', '/me/policies' => '/hr/policies', '/me/development' => '/hr/development', '/me/reviews' => '/hr/my-reviews'];
    private const POLICY_WORDS = ['سياس', 'نظام', 'قانون', 'شروط', 'شرط', 'قاعد', 'لايح', 'كيف', 'هل يحق', 'يحق', 'متي يحق', 'مسموح', 'يجوز', 'عقوب', 'policy', 'rule', 'how do', 'how can', 'how to', 'allowed', 'entitled'];
    private const ACT = ['اريد', 'اريد', 'بدي', 'ابي', 'ابغي', 'اطلب', 'قدم', 'قدملي', 'قدّم', 'احتاج', 'حابب', 'حاب', 'اخذ', 'اعطني', 'سجل', 'سجلي', 'want', 'need', 'request', 'apply', 'book', 'take', 'submit'];

    public function __construct(private Knowledge $kb, private Actions $actions, private LeaveService $leaves, private Attendance $att) {}

    /** يُحلّ عند الاستخدام (المتحكم مخزّن في المسار؛ والاختبارات تستبدل الكاتب) */
    private function writer(): Writer { return app(Writer::class); }

    public static function config(): array
    {
        $d = Settings::DEFAULTS['assistant'];
        $s = Settings::get('assistant') ?? [];
        $out = array_merge($d, array_intersect_key($s, $d));
        $out['actions'] = array_merge($d['actions'], array_intersect_key((array) ($s['actions'] ?? []), $d['actions']));
        return $out;
    }

    /** رابط الصفحة الشخصية حسب واجهة المستخدم (بوابة الموظف أو لوحة الإدارة) */
    public static function link(User $u, string $me): string
    {
        if ($u->portal() !== 'hr') return $me;
        [$path, $q] = array_pad(explode('?', $me, 2), 2, null);
        return (self::LINKS[$path] ?? $path).($q ? '?'.$q : '');
    }

    public function available(): bool { return self::config()['summaries'] && $this->writer()->available(); }

    public static function usedToday(User $u): int
    {
        return AssistantMessage::where('user_id', $u->id)->where('role', 'user')->where('created_at', '>=', now()->startOfDay())->count();
    }

    /** أسئلة مقترحة حسب الحساب */
    public function suggestions(User $u): array
    {
        $cfg = self::config();
        $s = [];
        if ($u->employee_id) {
            $s[] = L::t('كم رصيد إجازاتي؟', 'How much leave do I have left?');
            if ($cfg['actions']['leave']) $s[] = L::t('أريد إجازة سنوية من الأحد القادم لمدة يومين', 'I want annual leave from next Sunday for 2 days');
            $s[] = L::t('ما حالة طلباتي؟', 'What is the status of my requests?');
            $s[] = L::t('هل لدي تأخير يحتاج إلى تبرير؟', 'Do I have lateness to justify?');
            if ($cfg['actions']['certificate']) $s[] = L::t('أريد شهادة راتب', 'I need a salary certificate');
        }
        $s[] = L::t('متى العطلة الرسمية القادمة؟', 'When is the next public holiday?');
        $s[] = L::t('ما سياسة الإجازة المرضية؟', 'What is the sick leave policy?');
        if (Perm::can($u, 'attendance', 'view') && Perm::scope($u, 'attendance') !== 'own') $s[] = L::t('من الغائبون اليوم؟', 'Who is absent today?');
        if (Workflow::inbox($u)->isNotEmpty() || $u->portal() === 'hr') $s[] = L::t('ما الطلبات التي بانتظار اعتمادي؟', 'Which requests are waiting for my approval?');
        if (Perm::can($u, 'candidates', 'view')) $s[] = L::t('كم متقدماً جديداً هذا الأسبوع؟', 'How many new applicants this week?');
        if ($u->employee_id && $cfg['actions']['praise']) $s[] = L::t('أريد شكر زميل', 'I want to praise a colleague');
        return array_slice($s, 0, 9);
    }

    public function history(User $u, int $limit = 40): array
    {
        return AssistantMessage::where('user_id', $u->id)->latest('id')->limit($limit)->get()->reverse()->map(fn ($m) => self::row($m))->values()->all();
    }

    public static function row(AssistantMessage $m): array
    {
        $d = $m->data ?? [];
        return ['id' => $m->id, 'role' => $m->role, 'intent' => $m->intent, 'text' => $m->text, 'at' => $m->created_at?->toIso8601String(),
            'cards' => $d['cards'] ?? [], 'sources' => $d['sources'] ?? [], 'suggestions' => $d['suggestions'] ?? [], 'action' => Actions::client($m), 'summary' => $d['summary'] ?? null];
    }

    /**
     * السؤال: يحفظه، يجيب فوراً، ثم (عند الحاجة) يكتب الملخص بالتدفق. $emit يستقبل الأحداث:
     * ['t' => 'reply', 'message' => …] ثم ['t' => 'delta', 's' => …]… ثم ['t' => 'done', 'message' => …]
     */
    /** الفحوص قبل بدء التدفق (حتى تعود الأخطاء JSON عادياً) */
    public static function guard(User $u, string $text): string
    {
        $cfg = self::config();
        if (! $cfg['enabled']) throw new ApiException(L::t('المساعد غير مفعّل في شركتك.', 'The assistant is turned off for your company.'), 'assistant_off', 409);
        if (! $u->company_id) abort(403);
        $text = trim(mb_substr($text, 0, 600));
        if ($text === '') throw ApiException::field('text', L::t('اكتب سؤالك.', 'Write your question.'), 'required');
        if (self::usedToday($u) >= (int) $cfg['daily_limit']) throw new ApiException(L::t("بلغت الحد اليومي للأسئلة ({$cfg['daily_limit']}). يمكنك المتابعة غداً.", "You reached today's question limit ({$cfg['daily_limit']}). You can continue tomorrow."), 'assistant_limit', 429);
        return $text;
    }

    public function ask(User $u, string $text, callable $emit): AssistantMessage
    {
        $text = self::guard($u, $text);
        $lang = app()->getLocale() === 'en' ? 'en' : 'ar';
        AssistantMessage::create(['user_id' => $u->id, 'role' => 'user', 'text' => $text, 'lang' => $lang]);
        $r = $this->route($u, $text);
        $data = ['cards' => $r['cards'] ?? [], 'sources' => array_map(fn ($x) => array_diff_key($x, ['excerpt_full' => 1]), $r['sources'] ?? []), 'suggestions' => $r['suggestions'] ?? [], 'action' => $r['action'] ?? null];
        $summarize = ! empty($r['summarize']) && $this->available();
        if ($summarize) $data['summary'] = 'writing';
        $m = AssistantMessage::create(['user_id' => $u->id, 'role' => 'assistant', 'intent' => $r['intent'], 'text' => $r['text'], 'data' => $data, 'lang' => $lang]);
        $emit(['t' => 'reply', 'message' => self::row($m)]);
        if ($summarize) {
            try {
                $out = $this->writer()->stream($this->system($lang), $this->prompt($u, $text, $r['sources'], $lang), fn ($s) => $emit(['t' => 'delta', 's' => $s]));
                $out = self::clean($out);
                if ($out !== '') { $m->text = $out; $data['summary'] = 'done'; } else $data['summary'] = 'failed';
            } catch (\Throwable $e) {
                report($e);
                $data['summary'] = 'failed';
            }
            $m->data = $data; $m->save();
        }
        $emit(['t' => 'done', 'message' => self::row($m)]);
        return $m;
    }

    /** النص المكتوب بلا علامات تنسيق وبطول معقول */
    public static function clean(string $s): string
    {
        $s = preg_replace(['/\*\*|__|`/u', '/^#+\s*/mu'], '', $s);
        return trim(mb_substr($s, 0, 900));
    }

    private function system(string $lang): string
    {
        $co = Tenant::company();
        $name = $lang === 'en' ? ($co?->short_en ?: $co?->name_en ?: 'the company') : ($co?->short_ar ?: $co?->name_ar ?: 'الشركة');
        return $lang === 'en'
            ? "You are the HR assistant of {$name}. Answer the employee's question in English in 2 to 4 short sentences, using ONLY the numbered excerpts. Cite the excerpt number in brackets like [1]. If the excerpts do not contain the answer, say: \"I could not find a clear answer in the company documents; please contact HR.\" Never invent numbers, durations or rules. Do not mention that you are a model."
            : "أنت «المساعد» في نظام الموارد البشرية لـ{$name}. أجب عن سؤال الموظف بالعربية الفصحى في جملتين إلى أربع جمل قصيرة، معتمداً فقط على المقاطع المرقمة، واذكر رقم المقطع بين قوسين مثل [1]. إن لم تتضمن المقاطع الجواب فقل: «لم أجد إجابة واضحة في وثائق الشركة، يُرجى مراجعة الموارد البشرية.» لا تخترع أرقاماً أو مدداً أو قواعد غير مذكورة، ولا تذكر أنك نموذج.";
    }

    private function prompt(User $u, string $q, array $sources, string $lang): string
    {
        $lines = [];
        foreach ($sources as $i => $s) $lines[] = '['.($i + 1).'] '.($lang === 'en' ? $s['title']['en'] : $s['title']['ar']).($s['sub'] ? ' — '.$s['sub'] : '').': '.mb_substr($s['excerpt_full'] ?? $s['excerpt'], 0, 480);
        $facts = '';
        if ($e = $this->employee($u)) {
            $months = $e->hire_date ? (int) $e->hire_date->diffInMonths(now()) : 0;
            $st = in_array($e->status, ['onboarding', 'probation'], true) ? ($lang === 'en' ? 'on probation' : 'تحت التجربة') : ($lang === 'en' ? 'confirmed' : 'مثبّت');
            $facts = $lang === 'en' ? "Employee: {$months} months of service, {$st}.\n" : "الموظف: مدة خدمته {$months} شهراً، {$st}.\n";
        }
        return implode("\n", $lines)."\n\n".$facts.($lang === 'en' ? 'Question: ' : 'السؤال: ').$q;
    }

    private function employee(User $u): ?Employee
    {
        static $memo = [];
        return $memo[$u->id] ??= ($u->employee_id ? Employee::with(['department', 'manager'])->find($u->employee_id) : null) ?: null;
    }

    /** «اليوم» حسب المنطقة الزمنية لفرع الموظف (ملاحظة الاختبار 80) */
    private function today(?Employee $e): Carbon
    {
        $tz = $e ? (WorkSchedules::resolve($e, now()->toDateString())['tz'] ?? null) : null;
        return now($tz ?: config('app.timezone'))->startOfDay();
    }

    // ───────────── التوجيه ─────────────

    public function route(User $u, string $text): array
    {
        $n = Text::norm($text);
        $cfg = self::config();
        $e = $this->employee($u);
        $policyQ = Text::has($n, self::POLICY_WORDS);
        $act = Text::has($n, self::ACT);
        $dates = Dates::parse($text, $this->today($e));
        $mine = fn (array $w) => Text::has($n, $w);

        // تحية وشكر
        if (preg_match('/^(شكرا|شكرا لك|شكرا جزيلا|يعطيك العافيه|thanks|thank you|thx|merci)\b/u', $n)) return $this->say('thanks', L::t('على الرحب والسعة. هل من شيء آخر؟', 'You are welcome. Anything else?'));
        if (mb_strlen($n) <= 30 && preg_match('/^(مرحبا|اهلا|السلام|صباح|مساء|هاي|hello|hi|hey|good morning)/u', $n) || $mine(['ماذا تستطيع', 'ماذا يمكنك', 'شو بتعمل', 'شو بتقدر', 'شو فيك', 'بتقدر تعمل', 'شو بتعرف', 'مساعده', 'ساعدني', 'help', 'what can you'])) return $this->help($u);

        // «كيف…؟» شرح من الدليل والسياسات دائماً (لا بيانات ولا إجراءات)؛ السلفة لها جواب خاص بمصادره
        $how = $mine(['كيف', 'طريقه', 'خطوات', 'how do', 'how can', 'how to']);
        if ($mine(['سلفه', 'advance'])) return $this->advance($u);
        if ($mine(['متي يبدا الدوام', 'متي ينتهي الدوام', 'ساعات الدوام', 'ساعه الدوام', 'اوقات الدوام', 'وقت الدوام', 'موعد الدوام', 'دوامي اليوم', 'جدول دوامي', 'ورديتي', 'مناوبتي', 'working hours', 'my shift', 'work hours'])) return $this->shift($u, $e);
        if ($mine(['يوم عطله', 'ايام العطله', 'العطله الاسبوعيه', 'عطله اسبوعيه', 'ايام الدوام', 'ايام العمل', 'weekend', 'work days'])) return $this->shift($u, $e);
        // أنواع الإجازات وشروطها من إعدادات الشركة (والأهلية حسب ملف الموظف)
        if (($mine(['انواع الاجازات', 'الاجازات التي', 'اي اجازات', 'ما الاجازات', 'leave types', 'types of leave']) || ! $act && ! $dates['dates'] && ($leaveT = Actions::mentionedType($n)) && $mine(['يحق', 'استحق', 'بستحق', 'شروط', 'شرط', 'كم يوم', 'مدفوع', 'مده', 'entitled', 'eligible', 'how long']))) {
            return $this->leaveTypes($u, $e, $leaveT ?? null, $text);
        }
        if ($how) return $this->knowledge($u, $text);

        // إجراءات (بمعاينة وتأكيد)
        if ($mine(['شهاده', 'افاده', 'certificate']) && ! $policyQ) return $this->needEmployee($e) ?? $this->gate('certificate', fn () => $this->actions->certificate($u, $e, $n));
        if ($mine(['تبرير', 'برر', 'ابرر', 'justif', 'excuse'])) return $this->needEmployee($e) ?? ($policyQ ? $this->knowledge($u, $text) : $this->gate('justify', fn () => $this->actions->justify($u, $e, $text, $dates)));
        if ($mine(['اشكر', 'شكر زميل', 'اشكر زميل', 'شكر للزميل', 'praise']) && ! $policyQ) return $this->needEmployee($e) ?? $this->gate('praise', fn () => $this->actions->praise($u, $e, $text));
        $leaveWord = $mine(['اجاز', 'مغادره', 'leave', 'day off', 'time off', 'vacation']);
        if ($leaveWord && ! $policyQ && ! $mine(['رصيد', 'متبقي', 'باقي', 'كم يوم', 'balance', 'remaining', 'how many']) && ($act || $dates['dates'])) {
            return $this->needEmployee($e) ?? $this->gate('leave', fn () => $this->actions->leave($u, $e, $text, $dates));
        }

        // بيانات الموظف نفسه
        if (! $policyQ || $mine(['رصيدي', 'طلباتي', 'حضوري', 'نقاطي', 'مهامي'])) {
            if ($mine(['رصيد', 'متبقي', 'باقي لي', 'كم يوم', 'balance', 'remaining', 'how much leave', 'how many days', 'how many leave', 'days left', 'days do i have'])) return $this->needEmployee($e) ?? $this->balance($u, $e);
            if ($mine(['طلباتي', 'طلبي', 'حاله طلب', 'حاله الطلب', 'وين صار', 'وين وصل', 'my request', 'request status', 'status of my'])) return $this->needEmployee($e) ?? $this->requests($u, $e);
            if ($mine(['الغايبين', 'الغايبون', 'غايب اليوم', 'من غاب', 'الحضور اليوم', 'حضور اليوم', 'المتاخرين', 'المتاخرون', 'who is absent', 'absent today', 'attendance today', 'who is late'])) {
                if (Perm::can($u, 'attendance', 'view') && Perm::scope($u, 'attendance') !== 'own') return $this->hrToday($u, $e);
                if (! $e) return $this->needEmployee($e);
                $r = $this->attendance($u, $e);
                $r['text'] = L::t('حضور الزملاء متاح لمن يملك صلاحية الاطلاع عليه. هذا ملخص حضورك أنت: ', 'Colleagues’ attendance is only for those with access to it. Here is your own summary: ').$r['text'];
                return $r;
            }
            if ($mine(['حضوري', 'دوامي', 'تاخير', 'تاخري', 'تاخرت', 'تاخر', 'غيابي', 'غياب', 'بصمه', 'attendance', 'late', 'absen'])) return $this->needEmployee($e) ?? $this->attendance($u, $e);
            if ($mine(['عطله', 'العطل', 'عطل رسميه', 'عيد', 'holiday'])) return $this->holidays($u, $e);
            if ($mine(['راتب', 'رواتب', 'معاش', 'قسيمه', 'salary', 'payslip', 'pay slip'])) return $this->salary($u, $e);
            if ($mine(['مهامي', 'مهام', 'مهمه', 'task'])) return $this->needEmployee($e) ?? $this->tasks($u, $e);
            if ($mine(['اعتمادي', 'اعتماد', 'موافقتي', 'بانتظاري', 'بانتظار موافقتي', 'approval', 'pending'])) return $this->approvals($u);
            if ($mine(['توقيع', 'اوقع', 'وقع', 'وثائقي', 'sign'])) return $this->needEmployee($e) ?? $this->documents($u, $e);
            if ($mine(['نقاط', 'نقطه', 'مكافات', 'مكافا', 'points', 'reward'])) return $this->needEmployee($e) ?? $this->points($u, $e);
            if ($mine(['مديري', 'المدير المباشر', 'مدير مباشر', 'my manager'])) return $this->needEmployee($e) ?? $this->manager($u, $e);
            if ($mine(['اعلان', 'اعلانات', 'تعميم', 'اخبار', 'news', 'announcement'])) return $this->news($u, $e);
            if ($mine(['متقدم', 'مرشح', 'applicant', 'candidate']) && Perm::can($u, 'candidates', 'view')) return $this->applicants($u);
        }
        return $this->knowledge($u, $text);
    }

    private function gate(string $kind, callable $fn): array
    {
        if (! self::config()['actions'][$kind]) {
            $to = ['leave' => '/me/requests', 'justify' => '/me/attendance', 'certificate' => '/me/services', 'praise' => '/me/space?praise=1'][$kind];
            return $this->say($kind, L::t('هذا الإجراء غير متاح من المساعد في شركتك. يمكنك تنفيذه من صفحته مباشرة.', 'This action is not available from the assistant in your company. You can do it from its page.'),
                [['type' => 'link', 'label' => L::t('فتح الصفحة', 'Open the page'), 'to' => $to]]);
        }
        return $fn();
    }

    private function say(string $intent, string $text, array $cards = [], array $extra = []): array
    {
        return ['intent' => $intent, 'text' => $text, 'cards' => $cards] + $extra;
    }

    private function needEmployee(?Employee $e): ?array
    {
        return $e ? null : $this->say('no_employee', L::t('لا يوجد ملف موظف مرتبط بحسابك، لذلك لا تتوفر لديك بيانات إجازات أو حضور. يمكنني الإجابة عن أسئلة السياسات والاستخدام.', 'Your account is not linked to an employee file, so there is no leave or attendance data. I can answer policy and how-to questions.'));
    }

    // ───────────── أجوبة البيانات ─────────────

    public static function months(int $n): string
    {
        return match (true) { $n === 0 => 'أقل من شهر', $n === 1 => 'شهر واحد', $n === 2 => 'شهران', $n <= 10 => "{$n} أشهر", default => "{$n} شهراً" };
    }

    public static function days(float $n, string $lang = 'ar'): string
    {
        $n = round($n, 2); $i = (int) $n; $int = abs($n - $i) < 0.001;
        if ($lang === 'en') return ($int ? $i : $n).' '.($n == 1 ? 'day' : 'days');
        if (! $int) return "{$n} يوم";
        return match (true) { $i === 0 => 'لا أيام', $i === 1 => 'يوم واحد', $i === 2 => 'يومان', $i <= 10 => "{$i} أيام", default => "{$i} يوماً" };
    }

    private function d(float $n): string { return self::days($n, app()->getLocale() === 'en' ? 'en' : 'ar'); }

    private function help(User $u): array
    {
        return $this->say('help', L::t('أهلاً بك. أنا «المساعد»: أجيب عن رصيد إجازاتك وحضورك وطلباتك والعطل الرسمية وسياسات الشركة وطريقة استخدام النظام، ويمكنني تجهيز طلب إجازة أو تبرير أو شهادة أو شكر زميل لتراجعه وتؤكده قبل الإرسال. اختر سؤالاً أو اكتب سؤالك.',
            'Hello. I am the assistant: I answer about your leave balance, attendance, requests, public holidays, company policies and how to use the system, and I can prepare a leave, justification, certificate or praise for you to review and confirm before sending. Pick a question or type yours.'),
            [], ['suggestions' => $this->suggestions($u)]);
    }

    private function balance(User $u, Employee $e): array
    {
        $b = $this->leaves->balance($e);
        $text = L::t('رصيدك السنوي المتبقي '.$this->d($b['remaining']).' من أصل '.$this->d($b['entitlement']).'، استخدمت منه '.$this->d($b['used']).($b['held'] > 0 ? '، ومحجوز لطلبات قيد الاعتماد '.$this->d($b['held']) : '').'.',
            'Your remaining annual leave is '.$this->d($b['remaining']).' of '.$this->d($b['entitlement']).'; you used '.$this->d($b['used']).($b['held'] > 0 ? ', and '.$this->d($b['held']).' are held by pending requests' : '').'.');
        if ($b['probation']) $text .= ' '.L::t('أنت في فترة التجربة، ولها قواعد خاصة في الإجازات المدفوعة.', 'You are on probation, which has special rules for paid leave.');
        $items = [['label' => L::t('السنوية المتبقية', 'Annual left'), 'value' => $b['remaining'], 'sub' => L::t('من ', 'of ').$b['entitlement'], 'tone' => 'brand'],
            ['label' => L::t('المرضية المستخدمة', 'Sick used'), 'value' => $b['sick'], 'sub' => $b['sick_limit'] ? L::t('من ', 'of ').$b['sick_limit'] : null, 'tone' => 'info'],
            ['label' => L::t('الطارئة', 'Emergency'), 'value' => $b['emergency']['used'], 'sub' => L::t('من ', 'of ').$b['emergency']['limit'], 'tone' => 'brass'],
            ['label' => L::t('غير المدفوعة', 'Unpaid'), 'value' => $b['unpaid']['used'], 'sub' => L::t('من ', 'of ').$b['unpaid']['limit'], 'tone' => 'neutral']];
        return $this->say('balance', $text, [['type' => 'stats', 'items' => $items], ['type' => 'link', 'label' => L::t('صفحة إجازاتي وطلباتي', 'My leave & requests'), 'to' => self::link($u, '/me/requests')]],
            ['suggestions' => array_values(array_filter([self::config()['actions']['leave'] ? L::t('أريد إجازة سنوية من الأحد القادم لمدة يومين', 'I want annual leave from next Sunday for 2 days') : null, L::t('ما سياسة الإجازة السنوية؟', 'What is the annual leave policy?')]))]);
    }

    public static function statusLabel(string $s): array
    {
        return match (true) {
            in_array($s, LeaveRequest::PENDING, true) || str_starts_with($s, 'pending') => ['ar' => 'قيد الاعتماد', 'en' => 'Pending', 'tone' => 'brass'],
            $s === 'approved' || $s === 'done' => ['ar' => 'مقبول', 'en' => 'Approved', 'tone' => 'brand'],
            $s === 'rejected' => ['ar' => 'مرفوض', 'en' => 'Rejected', 'tone' => 'danger'],
            $s === 'cancelled' => ['ar' => 'ملغى', 'en' => 'Cancelled', 'tone' => 'neutral'],
            default => ['ar' => $s, 'en' => $s, 'tone' => 'neutral'],
        };
    }

    private function requests(User $u, Employee $e): array
    {
        $en = app()->getLocale() === 'en';
        $leaves = LeaveRequest::where('employee_id', $e->id)->latest('id')->limit(6)->get()->map(function ($l) use ($en) {
            $t = LeaveService::type($l->type); $st = self::statusLabel($l->status);
            return ['at' => $l->created_at, 'title' => $t ? ($en ? $t['en'] : $t['ar']) : ($l->type === 'justify' ? L::t('تبرير', 'Justification') : $l->type),
                'sub' => $l->from?->toDateString().($l->to && ! $l->to->equalTo($l->from) ? ' — '.$l->to->toDateString() : ''), 'badge' => ['label' => $en ? $st['en'] : $st['ar'], 'tone' => $st['tone']]];
        });
        $services = ServiceRequest::where('employee_id', $e->id)->latest('id')->limit(4)->get()->map(function ($s) use ($en) {
            $st = self::statusLabel($s->status);
            return ['at' => $s->created_at, 'title' => $s->workflowLabel($en ? 'en' : 'ar'), 'sub' => $s->created_at?->toDateString(), 'badge' => ['label' => $en ? $st['en'] : $st['ar'], 'tone' => $st['tone']]];
        });
        $all = $leaves->concat($services)->sortByDesc('at')->take(6)->map(fn ($x) => collect($x)->except('at')->all())->values()->all();
        if (! $all) return $this->say('requests', L::t('لا توجد لديك طلبات بعد.', 'You have no requests yet.'), [['type' => 'link', 'label' => L::t('تقديم طلب', 'New request'), 'to' => self::link($u, '/me/requests')]]);
        $pending = $leaves->concat($services)->filter(fn ($x) => $x['badge']['tone'] === 'brass')->count();
        return $this->say('requests', L::t($pending ? "لديك {$pending} ".($pending === 1 ? 'طلب' : 'طلبات')." قيد الاعتماد. هذه آخر طلباتك:" : 'هذه آخر طلباتك:', $pending ? "You have {$pending} pending. Your latest requests:" : 'Your latest requests:'),
            [['type' => 'list', 'items' => $all], ['type' => 'link', 'label' => L::t('كل طلباتي', 'All my requests'), 'to' => self::link($u, '/me/requests')]]);
    }

    private function attendance(User $u, Employee $e): array
    {
        $today = $this->today($e);
        $p = $this->att->period(Employee::whereKey($e->id), $today->copy()->startOfMonth()->toDateString(), $today->toDateString())['rows'][0] ?? null;
        $open = collect($this->leaves->issues($e))->where('open', true)->values();
        if (! $p) return $this->say('attendance', L::t('لا توجد بيانات حضور لهذا الشهر بعد.', 'No attendance data for this month yet.'));
        $text = L::t("هذا الشهر: حضرت {$p['present']} ".($p['present'] === 1 ? 'يوماً' : 'أيام')."، وتأخرت في {$p['late_days']} ".($p['late_days'] === 1 ? 'يوم' : 'أيام')." (مجموع {$p['late']} دقيقة)، والغياب {$p['absent']}، والأيام الناقصة {$p['incomplete']}.",
            "This month: {$p['present']} days present, late on {$p['late_days']} days ({$p['late']} minutes), {$p['absent']} absent, {$p['incomplete']} incomplete.");
        if ($open->isNotEmpty()) $text .= ' '.L::t("لديك {$open->count()} ".($open->count() === 1 ? 'يوم يمكن تبريره' : 'أيام يمكن تبريرها').' خلال المهلة.', "{$open->count()} day(s) can still be justified.");
        $cards = [['type' => 'stats', 'items' => [['label' => L::t('حضور', 'Present'), 'value' => $p['present'], 'tone' => 'brand'], ['label' => L::t('أيام تأخير', 'Late days'), 'value' => $p['late_days'], 'sub' => $p['late'].L::t(' دقيقة', ' min'), 'tone' => 'brass'],
            ['label' => L::t('غياب', 'Absent'), 'value' => $p['absent'], 'tone' => 'danger'], ['label' => L::t('ناقص', 'Incomplete'), 'value' => $p['incomplete'], 'tone' => 'neutral']]]];
        if ($open->isNotEmpty()) $cards[] = ['type' => 'list', 'title' => L::t('بحاجة إلى تبرير', 'Needs justification'), 'items' => $open->take(5)->map(fn ($i) => ['title' => $i['date'], 'sub' => self::dayStatus($i['status']).($i['late'] ? ' · '.$i['late'].L::t(' دقيقة', ' min') : '')])->all()];
        $cards[] = ['type' => 'link', 'label' => L::t('صفحة حضوري', 'My attendance'), 'to' => self::link($u, '/me/attendance')];
        return $this->say('attendance', $text, $cards, ['suggestions' => $open->isNotEmpty() && self::config()['actions']['justify'] ? [L::t('أريد تبرير التأخير', 'I want to justify my lateness')] : []]);
    }

    public static function dayStatus(string $s): string
    {
        return ['late' => L::t('تأخير', 'Late'), 'absent' => L::t('غياب', 'Absent'), 'incomplete' => L::t('يوم ناقص', 'Incomplete'), 'justified' => L::t('مبرَّر', 'Justified')][$s] ?? $s;
    }

    private function hrToday(User $u, ?Employee $e): array
    {
        $date = $this->today($e)->toDateString();
        $d = $this->att->daily(Directory::scoped($u, 'attendance'), $date);
        $c = $d['counts'];
        $absent = $d['rows']->where('status', 'absent')->take(8)->map(fn ($r) => ['title' => app()->getLocale() === 'en' ? $r['employee']['name']['en'] : $r['employee']['name']['ar'], 'sub' => $r['employee']['code']])->values()->all();
        return $this->say('hr_today', L::t("اليوم ({$date}): حاضر {$c['present']}، متأخر {$c['late']}، غائب {$c['absent']}، في إجازة {$c['leave']}، ناقص {$c['incomplete']} — ضمن نطاق صلاحيتك.",
            "Today ({$date}): {$c['present']} present, {$c['late']} late, {$c['absent']} absent, {$c['leave']} on leave, {$c['incomplete']} incomplete — within your scope."),
            array_values(array_filter([['type' => 'stats', 'items' => [['label' => L::t('حاضر', 'Present'), 'value' => $c['present'], 'tone' => 'brand'], ['label' => L::t('متأخر', 'Late'), 'value' => $c['late'], 'tone' => 'brass'],
                ['label' => L::t('غائب', 'Absent'), 'value' => $c['absent'], 'tone' => 'danger'], ['label' => L::t('إجازة', 'Leave'), 'value' => $c['leave'], 'tone' => 'info']]],
                $absent ? ['type' => 'list', 'title' => L::t('الغائبون', 'Absent'), 'items' => $absent] : null,
                ['type' => 'link', 'label' => L::t('سجل الحضور', 'Attendance'), 'to' => '/hr/attendance']])));
    }

    private function holidays(User $u, ?Employee $e): array
    {
        $today = $this->today($e)->toDateString();
        $groups = [];
        foreach (Holiday::where('date', '>=', $today)->orderBy('date')->limit(40)->get() as $h) {
            $last = array_key_last($groups);
            if ($last !== null && $groups[$last]['ar'] === $h->name_ar && Carbon::parse($groups[$last]['to'])->addDay()->isSameDay($h->date)) { $groups[$last]['to'] = $h->date->toDateString(); $groups[$last]['days']++; continue; }
            $groups[] = ['from' => $h->date->toDateString(), 'to' => $h->date->toDateString(), 'days' => 1, 'ar' => $h->name_ar, 'en' => $h->name_en ?: $h->name_ar];
        }
        $link = ['type' => 'link', 'label' => L::t('كل العطل الرسمية', 'All public holidays'), 'to' => self::link($u, '/me/holidays')];
        if (! $groups) return $this->say('holidays', L::t('لا توجد عطل رسمية مسجلة قادمة حالياً.', 'No upcoming public holidays are recorded.'), [$link]);
        $g = $groups[0]; $en = app()->getLocale() === 'en';
        $when = $g['from'] === $g['to'] ? $g['from'] : $g['from'].' — '.$g['to'];
        return $this->say('holidays', L::t("العطلة الرسمية القادمة: «{$g['ar']}» ({$when})، ومدتها ".$this->d($g['days']).'.', "The next public holiday is “{$g['en']}” ({$when}), ".$this->d($g['days']).'.'),
            [['type' => 'list', 'items' => array_map(fn ($x) => ['title' => $en ? $x['en'] : $x['ar'], 'sub' => ($x['from'] === $x['to'] ? $x['from'] : $x['from'].' — '.$x['to']).' · '.$this->d($x['days'])], array_slice($groups, 0, 4))], $link]);
    }

    private function salary(User $u, ?Employee $e): array
    {
        $cards = $e ? [['type' => 'link', 'label' => L::t('كشفي الشهري', 'My monthly sheet'), 'to' => self::link($u, '/me/payslip')]] : [];
        $cards[] = ['type' => 'link', 'label' => L::t('طلب شهادة راتب', 'Request a salary certificate'), 'to' => self::link($u, '/me/services?new=salary_cert')];
        return $this->say('salary', L::t('تفاصيل الراتب محمية ولا تظهر في المحادثة. افتح «كشفي الشهري» وتحقق من هويتك لترى المبالغ وتفاصيل الحسميات والإضافي.',
            'Salary details are protected and are never shown in the chat. Open “My monthly sheet” and verify your identity to see the amounts, deductions and overtime.'), $cards);
    }

    private function advance(User $u): array
    {
        $r = $this->kb->search($u, 'السلفه على الراتب شروط السلفه', 2);
        return $this->say('advance', L::t('تُطلب السلفة من «خدماتي» ← «سلفة على الراتب». يحسب النظام الحد المتاح لك تلقائياً حسب سياسة الشركة وما استحققته من راتب هذا الشهر، ويظهر المبلغ بعد التحقق من هويتك.',
            'Request an advance from “My services” → “Salary advance”. The system calculates your available limit from company policy and what you have earned this month; the amount appears after identity verification.'),
            [['type' => 'link', 'label' => L::t('طلب سلفة', 'Request an advance'), 'to' => self::link($u, '/me/services?new=advance')]], ['sources' => $this->sources($r)]);
    }

    /** أنواع الإجازات من سياسة الشركة: الحد، نسبة الأجر، المهلة، المستند، الشروط — ونوع محدد مع أهلية الموظف */
    private function leaveTypes(User $u, ?Employee $e, ?string $key, string $q): array
    {
        $en = app()->getLocale() === 'en';
        $types = collect(Settings::policy('leave_types'))->reject(fn ($t) => $t['key'] === 'justify')->values();
        $line = function (array $t) use ($en, $e) {
            $bits = [];
            if (! empty($t['max_days'])) $bits[] = L::t('حتى '.self::days((float) $t['max_days']), 'up to '.self::days((float) $t['max_days'], 'en'));
            if (isset($t['paid']) && ! ($t['key'] === 'sick' && ! empty(LeaveService::rules()['sick_first_days']))) $bits[] = (int) $t['paid'] >= 100 ? L::t('مدفوعة', 'paid') : ((int) $t['paid'] <= 0 ? L::t('غير مدفوعة', 'unpaid') : L::t("مدفوعة بنسبة {$t['paid']}٪", "{$t['paid']}% paid"));
            if (! empty($t['notice'])) $bits[] = L::t('تُطلب مسبقاً حسب مدتها', 'needs advance notice');
            if (($t['doc'] ?? 'none') === 'required') $bits[] = L::t('بمستند إلزامي', 'document required');
            if ($t['key'] === 'sick') { $r = LeaveService::rules(); if (! empty($r['sick_first_days'])) $bits[] = L::t("أول {$r['sick_first_days']} يوماً بنسبة {$r['sick_first_paid']}٪ ثم {$r['sick_second_days']} يوماً بنسبة {$r['sick_second_paid']}٪ في السنة", "first {$r['sick_first_days']} days at {$r['sick_first_paid']}%, then {$r['sick_second_days']} days at {$r['sick_second_paid']}% a year"); }
            if (! empty($t['after_months'])) $bits[] = L::t('بعد '.self::months((int) $t['after_months']).' من الخدمة', "after {$t['after_months']} months of service");
            if (! empty($t['confirmed'])) $bits[] = L::t('بعد التثبيت', 'after probation');
            if (! empty($t['once'])) $bits[] = L::t('مرة واحدة خلال الخدمة', 'once during service');
            return ['title' => $en ? $t['en'] : $t['ar'], 'sub' => implode($en ? ', ' : '، ', $bits)];
        };
        $link = ['type' => 'link', 'label' => L::t('تقديم طلب إجازة', 'Request leave'), 'to' => self::link($u, '/me/requests')];
        $t = $key ? $types->firstWhere('key', $key) : null;
        if (! $t) return $this->say('leave_types', L::t('أنواع الإجازات في شركتك وشروطها:', 'Leave types in your company and their conditions:'), [['type' => 'list', 'items' => $types->map($line)->all()], $link],
            ['sources' => $this->sources($this->kb->search($u, $q, 2))]);
        $l = $line($t);
        $text = "«{$l['title']}»: ".($l['sub'] ?: L::t('بلا شروط خاصة', 'no special conditions')).'.';
        if ($e) {
            $why = [];
            $months = $e->hire_date ? (int) $e->hire_date->diffInMonths(now()) : 0;
            if (! empty($t['after_months']) && $months < $t['after_months']) $why[] = L::t('مدة خدمتك '.self::months($months).' فقط', "your service is only {$months} months");
            if (($t['key'] === 'maternity' && $e->gender === 'M') || ($t['key'] === 'paternity' && $e->gender === 'F')) $why[] = L::t('هذا النوع لا ينطبق على ملفك', 'this type does not apply to you');
            if (! empty($t['confirmed']) && in_array($e->status, ['onboarding', 'probation'], true)) $why[] = L::t('أنت في فترة التجربة', 'you are on probation');
            if (! empty($t['once']) && LeaveRequest::where('employee_id', $e->id)->where('type', $t['key'])->whereIn('status', [...LeaveRequest::PENDING, 'approved'])->exists()) $why[] = L::t('حصلت عليها سابقاً', 'you already had it');
            $text .= ' '.($why ? L::t('حسب ملفك لا تستحقها الآن: ', 'Based on your file you are not eligible now: ').implode($en ? ', ' : '، ', $why).'.' : L::t('حسب ملفك تستوفي شروطها.', 'Based on your file you meet its conditions.'));
        }
        return $this->say('leave_types', $text, [$link], ['sources' => $this->sources($this->kb->search($u, $q, 2))]);
    }

    /** جدول دوام الموظف الفعلي اليوم (الجدول/الوردية/سياسة الإدارة) — من WorkSchedules */
    private function shift(User $u, ?Employee $e): array
    {
        $today = $this->today($e)->toDateString();
        $r = $e ? WorkSchedules::resolve($e, $today) : ['shift' => Settings::schedule('shift', $today), 'work_days' => Settings::schedule('work_days', $today), 'schedule' => null];
        $hm = fn ($m) => sprintf('%02d:%02d', intdiv((int) $m, 60), (int) $m % 60);
        $start = $r['shift']['start'] ?? null; $end = $r['shift']['end'] ?? null;
        $start = is_numeric($start) ? $hm($start) : (string) $start; $end = is_numeric($end) ? $hm($end) : (string) $end;
        $en = app()->getLocale() === 'en';
        $names = $en ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $days = implode($en ? ', ' : '، ', array_map(fn ($d) => $names[(int) $d] ?? $d, (array) ($r['work_days'] ?? [])));
        $grace = (int) ($r['shift']['grace'] ?? 0);
        return $this->say('shift', L::t("دوامك من {$start} إلى {$end}، وأيام العمل: {$days}.".($grace ? " مهلة السماح {$grace} دقيقة." : ''), "Your hours are {$start} to {$end}; work days: {$days}.".($grace ? " Grace period {$grace} minutes." : '')),
            [['type' => 'link', 'label' => L::t('العطل الرسمية', 'Public holidays'), 'to' => self::link($u, '/me/holidays')]]);
    }

    private function tasks(User $u, Employee $e): array
    {
        $rows = WorkTask::where('employee_id', $e->id)->whereIn('status', ['todo', 'in_progress', 'review'])->orderByRaw('due_on is null')->orderBy('due_on')->limit(6)->get();
        $link = ['type' => 'link', 'label' => L::t('مهامي ومؤشراتي', 'My tasks & KPIs'), 'to' => self::link($u, '/me/tasks')];
        if ($rows->isEmpty()) return $this->say('tasks', L::t('لا توجد لديك مهام مفتوحة.', 'You have no open tasks.'), [$link]);
        $today = $this->today($e)->toDateString();
        $late = $rows->filter(fn ($t) => $t->due_on && $t->due_on->toDateString() < $today && $t->status !== 'review')->count();
        $st = ['todo' => L::t('جديدة', 'New'), 'in_progress' => L::t('قيد التنفيذ', 'In progress'), 'review' => L::t('بانتظار الاعتماد', 'Awaiting approval')];
        return $this->say('tasks', L::t("لديك {$rows->count()} ".($rows->count() === 1 ? 'مهمة مفتوحة' : 'مهام مفتوحة').($late ? "، منها {$late} متأخرة" : '').'.', "You have {$rows->count()} open task(s)".($late ? ", {$late} overdue" : '').'.'),
            [['type' => 'list', 'items' => $rows->map(fn ($t) => ['title' => $t->title, 'sub' => ($t->due_on ? L::t('الموعد ', 'Due ').$t->due_on->toDateString().' · ' : '').$st[$t->status],
                'badge' => $t->due_on && $t->due_on->toDateString() < $today && $t->status !== 'review' ? ['label' => L::t('متأخرة', 'Overdue'), 'tone' => 'danger'] : null])->values()->all()], $link]);
    }

    private function approvals(User $u): array
    {
        $n = Workflow::inbox($u)->count();
        $link = ['type' => 'link', 'label' => L::t('صندوق الاعتمادات', 'Approvals inbox'), 'to' => self::link($u, '/me/approvals')];
        return $this->say('approvals', $n ? L::t("بانتظار قرارك {$n} ".($n === 1 ? 'طلب' : ($n <= 10 ? 'طلبات' : 'طلباً')).'. افتح صندوق الاعتمادات لمراجعتها.', "{$n} request(s) are waiting for your decision.") : L::t('لا توجد طلبات بانتظار قرارك الآن.', 'Nothing is waiting for your decision now.'), [$link]);
    }

    private function documents(User $u, Employee $e): array
    {
        $docs = EmployeeDocs::toSign($e);
        $link = ['type' => 'link', 'label' => L::t('وثائقي للتوقيع', 'My documents'), 'to' => self::link($u, '/me/documents')];
        if (! $docs) return $this->say('documents', L::t('لا توجد وثائق بانتظار توقيعك. تعهدات المباشرة وسندات العهد تظهر أيضاً في صفحة «وثائقي».', 'No documents are waiting for your signature. Onboarding undertakings and custody receipts also appear on “My documents”.'), [$link]);
        return $this->say('documents', L::t('لديك '.count($docs).' '.(count($docs) === 1 ? 'وثيقة' : 'وثائق').' بانتظار توقيعك:', count($docs).' document(s) are waiting for your signature:'),
            [['type' => 'list', 'items' => array_map(fn ($d) => ['title' => $d['title'] ?? '', 'sub' => ! empty($d['sign_due']) ? L::t('آخر موعد ', 'Due ').$d['sign_due'] : null], array_slice($docs, 0, 5))], $link]);
    }

    private function points(User $u, Employee $e): array
    {
        return $this->say('points', L::t("رصيد نقاطك في متجر المكافآت {$e->points} نقطة (المجموع التراكمي {$e->lifetime_points}). تكسب النقاط من الإحالات وإنجاز المهام وتحقيق المؤشرات وشكر الزملاء.",
            "Your rewards balance is {$e->points} points ({$e->lifetime_points} lifetime). You earn points from referrals, tasks, KPIs and colleague praise."),
            [['type' => 'link', 'label' => L::t('متجر المكافآت', 'Rewards store'), 'to' => self::link($u, '/me/rewards')]]);
    }

    private function manager(User $u, Employee $e): array
    {
        $m = $e->manager; $en = app()->getLocale() === 'en';
        if (! $m) return $this->say('manager', L::t('لا يوجد مدير مباشر مسجل في ملفك. راجع الموارد البشرية إن كان ذلك خطأ.', 'No direct manager is recorded in your file. Contact HR if this is wrong.'));
        return $this->say('manager', L::t("مديرك المباشر: {$m->name} — {$m->title_ar}. طلباتك تصل إليه أولاً ثم إلى الموارد البشرية حسب مسار الاعتماد.", "Your direct manager is {$m->name_en} — {$m->title_en}. Your requests go to them first, then HR, according to the approval path."),
            [['type' => 'list', 'items' => [['title' => $en ? $m->name_en : $m->name, 'sub' => ($en ? $m->title_en : $m->title_ar).($e->department ? ' · '.($en ? $e->department->name_en : $e->department->name_ar) : '')]]]]);
    }

    private function news(User $u, ?Employee $e): array
    {
        $rows = Announcements::feed($e, 3); $en = app()->getLocale() === 'en';
        $link = ['type' => 'link', 'label' => L::t('إعلانات الشركة', 'Company news'), 'to' => $u->portal() === 'hr' && Perm::can($u, 'announcements', 'view') ? '/hr/announcements' : self::link($u, '/me/news')];
        if ($rows->isEmpty()) return $this->say('news', L::t('لا توجد إعلانات حالية.', 'There are no current announcements.'), [$link]);
        return $this->say('news', L::t('آخر إعلانات الشركة:', 'Latest company announcements:'),
            [['type' => 'list', 'items' => $rows->map(fn ($a) => ['title' => $en ? ($a->title_en ?: $a->title_ar ?: mb_substr($a->text_en ?: $a->text_ar, 0, 80)) : ($a->title_ar ?: mb_substr($a->text_ar, 0, 80)), 'sub' => $a->published_at?->toDateString()])->values()->all()], $link]);
    }

    private function applicants(User $u): array
    {
        $week = Candidate::where('created_at', '>=', now()->subDays(7))->count();
        $open = Candidate::whereNotIn('stage', ['hired', 'rejected'])->selectRaw('stage, count(*) n')->groupBy('stage')->pluck('n', 'stage');
        $names = ['new' => L::t('جديد', 'New'), 'screening' => L::t('فرز', 'Screening'), 'interview' => L::t('مقابلة', 'Interview'), 'technical' => L::t('فني', 'Technical'), 'offer' => L::t('عرض', 'Offer'), 'appointment' => L::t('تعيين', 'Appointment')];
        return $this->say('applicants', L::t("وصل {$week} ".($week === 1 ? 'طلب توظيف' : 'طلبات توظيف').' خلال آخر 7 أيام. توزيع الطلبات المفتوحة حسب المرحلة:', "{$week} application(s) arrived in the last 7 days. Open applications by stage:"),
            [['type' => 'stats', 'items' => collect($names)->map(fn ($l, $k) => ['label' => $l, 'value' => (int) ($open[$k] ?? 0), 'tone' => 'info'])->values()->all()], ['type' => 'link', 'label' => L::t('لوحة المتقدمين', 'Applicants board'), 'to' => '/hr/candidates']]);
    }

    // ───────────── السياسات ودليل الاستخدام ─────────────

    private function sources(array $hits): array
    {
        return array_map(fn ($h) => ['kind' => $h['kind'], 'title' => $h['title'], 'sub' => $h['sub'], 'excerpt' => mb_substr($h['text'], 0, 260).(mb_strlen($h['text']) > 260 ? '…' : ''), 'excerpt_full' => $h['text'], 'to' => $h['to']], $hits);
    }

    private function knowledge(User $u, string $q): array
    {
        $hits = $this->kb->search($u, $q, 3);
        if (! $hits) return $this->say('unknown', L::t('لم أجد إجابة لهذا السؤال في سياسات الشركة ودليل الاستخدام. جرّب صياغة أخرى أو أحد الأسئلة المقترحة، أو راجع الموارد البشرية.',
            'I could not find an answer in the company policies or the user guide. Try rephrasing, pick a suggested question, or contact HR.'), [], ['suggestions' => $this->suggestions($u)]);
        $src = $this->sources($hits);
        return $this->say('policy', L::t('هذه أقرب الفقرات من وثائق الشركة ودليل الاستخدام:', 'These are the closest passages from the company documents and the user guide:'), [], ['sources' => $src, 'summarize' => true]);
    }
}
