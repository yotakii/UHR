<?php

namespace App\Services\Assistant;

use App\Exceptions\ApiException;
use App\Models\AssistantMessage;
use App\Models\Employee;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Attendance\DayCalc;
use App\Services\Leave\LeaveService;
use App\Services\People\ServiceDesk;
use App\Services\Policies\TextExtractor;
use App\Services\Space\Praise;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * إجراءات «المساعد»: يجهّز الطلب من كلام الموظف (نوع الإجازة، التواريخ، البديل، السبب…) في بطاقة قابلة للتعديل،
 * ويفحصه بالقواعد نفسها التي تفحص الطلب من صفحته، ولا يُرسَل إلا بخطوتي تأكيد (ملاحظة الاختبار 84): «إرسال» ثم «تأكيد الإرسال»
 * مع بصمة الحقول (digest) — أي تعديل بعد المعاينة يعيد المعاينة. صلاحية البطاقة 30 دقيقة، والتنفيذ دائماً باسم صاحب الحساب.
 */
class Actions
{
    public const TTL_MINUTES = 30;

    public function __construct(private LeaveService $leaves) {}

    // ───────────── تجهيز البطاقات ─────────────

    public function leave(User $u, Employee $e, string $text, array $dates): array
    {
        $n = Text::norm($text);
        $types = collect(Settings::policy('leave_types'))->reject(fn ($t) => $t['key'] === 'justify')->values();
        $type = self::guessType($n, $types->all(), $dates['hours'] !== null);
        $t = $types->firstWhere('key', $type);
        $from = $dates['dates'][0] ?? null;
        $to = $dates['dates'][1] ?? null;
        if ($from && ! $to) $to = $dates['days'] && $t['unit'] === 'day' ? $this->extend($e, $from, (int) $dates['days'], (bool) $t['calendar']) : $from;
        $fields = ['type' => $type, 'from' => $from, 'to' => $t['unit'] === 'hour' ? $from : $to, 'hours' => $t['unit'] === 'hour' ? ($dates['hours'] ?: null) : null,
            'substitute_id' => $this->guessSubstitute($e, $text), 'reason' => self::after($text, ['بسبب', 'لان', 'لأن', 'because', 'due to', 'السبب'])];
        $label = app()->getLocale() === 'en' ? $t['en'] : $t['ar'];
        $intro = $from ? L::t("جهّزت لك طلب «{$label}». راجع التفاصيل وعدّل ما يلزم، ثم أرسل الطلب وأكّده.", "I prepared a “{$label}” request. Review and edit the details, then send and confirm.")
            : L::t("جهّزت لك طلب «{$label}». حدّد التاريخ وباقي التفاصيل، ثم أرسل الطلب وأكّده.", "I prepared a “{$label}” request. Choose the date and other details, then send and confirm.");
        return ['intent' => 'leave', 'text' => $intro, 'cards' => [], 'action' => $this->make('leave', $u, $e, $fields)];
    }

    public function justify(User $u, Employee $e, string $text, array $dates): array
    {
        $open = collect($this->leaves->issues($e))->where('open', true)->values();
        if ($open->isEmpty()) return ['intent' => 'justify', 'text' => L::t('لا توجد لديك أيام تأخير أو غياب يمكن تبريرها خلال المهلة الحالية.', 'You have no lateness or absence that can still be justified.'),
            'cards' => [['type' => 'link', 'label' => L::t('صفحة حضوري', 'My attendance'), 'to' => Assistant::link($u, '/me/attendance')]]];
        $date = collect($dates['dates'])->first(fn ($d) => $open->contains('date', $d)) ?? $open->first()['date'];
        $fields = ['date' => $date, 'reason' => self::after($text, ['بسبب', 'لان', 'لأن', 'because', 'السبب']) ?? ''];
        return ['intent' => 'justify', 'text' => L::t('جهّزت تبريراً لهذا اليوم. اكتب السبب وراجع، ثم أرسل وأكّد. يمكنك إرفاق مستند لاحقاً من صفحة «حضوري».', 'I prepared a justification. Write the reason, review, then send and confirm. You can attach a document later from “My attendance”.'),
            'cards' => [], 'action' => $this->make('justify', $u, $e, $fields)];
    }

    public function certificate(User $u, Employee $e, string $n): array
    {
        $kind = Text::has($n, ['راتب', 'salary']) ? 'salary_cert' : 'experience_cert';
        preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $n, $mail);
        preg_match('/\+?\d[\d\s()-]{6,}\d/u', $n, $tel);
        $fields = ['kind' => $kind, 'addressee' => ['name' => '', 'cr_no' => '', 'email' => $mail[0] ?? '', 'phone' => isset($tel[0]) ? trim($tel[0]) : ''], 'notify_consent' => false, 'note' => ''];
        $label = ServiceRequest::KINDS[$kind][app()->getLocale() === 'en' ? 1 : 0];
        return ['intent' => 'certificate', 'text' => $kind === 'salary_cert'
            ? L::t("جهّزت طلب «{$label}». تتطلب الشهادة بيانات الجهة الموجهة إليها (الاسم، السجل التجاري، البريد، الهاتف). أكملها ثم أرسل وأكّد.", "I prepared a “{$label}” request. It needs the addressee's name, commercial registration, email and phone. Complete them, then send and confirm.")
            : L::t("جهّزت طلب «{$label}». بيانات الجهة اختيارية. راجع ثم أرسل وأكّد.", "I prepared an “{$label}” request. The addressee is optional. Review, then send and confirm."),
            'cards' => [], 'action' => $this->make('certificate', $u, $e, $fields)];
    }

    public function praise(User $u, Employee $e, string $text): array
    {
        $cfg = Praise::config();
        $n = Text::norm($text);
        $ids = $this->findPeople($e, $text);
        $value = collect($cfg['values'])->first(fn ($v) => Text::has($n, [Text::norm($v['ar'])]) || Text::has($n, [mb_strtolower($v['en'])]))['key'] ?? ($cfg['values'][0]['key'] ?? '');
        $msg = self::after($text, ['على', 'لانه', 'لأنه', 'لانها', 'لأنها', 'for', 'because']) ?? '';
        if (mb_strlen($msg) < (int) $cfg['min_message']) $msg = '';
        return ['intent' => 'praise', 'text' => L::t($ids ? 'جهّزت شكراً لزميلك. اختر القيمة واكتب ما فعله، ثم انشر وأكّد.' : 'جهّزت بطاقة شكر. اختر الزميل والقيمة واكتب ما فعله، ثم انشر وأكّد.',
            $ids ? 'I prepared a praise for your colleague. Pick the value and write what they did, then post and confirm.' : 'I prepared a praise card. Pick the colleague and value, write what they did, then post and confirm.'),
            'cards' => [], 'action' => $this->make('praise', $u, $e, ['recipients' => $ids, 'value' => $value, 'message' => $msg])];
    }

    // ───────────── المعاينة والتنفيذ ─────────────

    private function make(string $kind, User $u, Employee $e, array $fields): array
    {
        $p = $this->preview($kind, $u, $e, $fields);
        return ['kind' => $kind, 'status' => 'pending', 'fields' => $p['fields'], 'errors' => $p['errors'], 'warnings' => $p['warnings'], 'info' => $p['info'],
            'digest' => self::digest($p['fields']), 'expires_at' => now()->addMinutes(self::TTL_MINUTES)->toIso8601String(), 'result' => null];
    }

    public static function digest(array $fields): string { return sha1(json_encode(self::sortKeys($fields), JSON_UNESCAPED_UNICODE)); }

    private static function sortKeys(array $a): array { ksort($a); foreach ($a as &$v) if (is_array($v) && array_keys($v) !== range(0, count($v) - 1)) $v = self::sortKeys($v); return $a; }

    /** @return array{fields: array, errors: array, warnings: array, info: array} */
    public function preview(string $kind, User $u, Employee $e, array $in): array
    {
        return match ($kind) {
            'leave' => $this->previewLeave($u, $e, $in),
            'justify' => $this->previewJustify($e, $in),
            'certificate' => $this->previewCertificate($in),
            'praise' => $this->previewPraise($u, $e, $in),
        };
    }

    private function previewLeave(User $u, Employee $e, array $in): array
    {
        $t = LeaveService::type((string) ($in['type'] ?? '')) ?? LeaveService::type('annual');
        $f = ['type' => $t['key'], 'from' => self::date($in['from'] ?? null), 'to' => $t['unit'] === 'hour' ? self::date($in['from'] ?? null) : self::date($in['to'] ?? null),
            'hours' => $t['unit'] === 'hour' ? (int) ($in['hours'] ?? 0) ?: null : null, 'substitute_id' => (int) ($in['substitute_id'] ?? 0) ?: null, 'reason' => trim(mb_substr((string) ($in['reason'] ?? ''), 0, 500))];
        $c = $f['from'] ? $this->leaves->check($e, $f, $u) : ['errors' => ['from' => L::t('حدّد تاريخ البداية.', 'Choose the start date.')], 'warnings' => [], 'days' => 0, 'levels' => []];
        $en = app()->getLocale() === 'en';
        $types = collect(Settings::policy('leave_types'))->reject(fn ($x) => $x['key'] === 'justify')->map(fn ($x) => ['key' => $x['key'], 'label' => $en ? $x['en'] : $x['ar'], 'unit' => $x['unit'], 'doc' => $x['doc'] ?? 'none'])->values()->all();
        $subs = Employee::current()->where('department_id', $e->department_id)->whereKeyNot($e->id)->orderBy('name')->limit(60)->get(['id', 'name', 'name_en'])
            ->map(fn ($x) => ['id' => $x->id, 'label' => $en ? ($x->name_en ?: $x->name) : $x->name])->all();
        $warn = [];
        if (isset($c['warnings']['over_balance'])) $warn[] = L::t('المدة أكبر من رصيدك المتبقي ('.$c['warnings']['over_balance']['remaining'].') — يحتاج المعتمد إلى ملاحظة.', 'Longer than your remaining balance ('.$c['warnings']['over_balance']['remaining'].') — the approver must add a note.');
        if (isset($c['warnings']['over_sick'])) $warn[] = L::t('تتجاوز حد الإجازات المرضية لهذه السنة.', 'Exceeds this year’s sick leave limit.');
        if (isset($c['warnings']['conflict'])) $warn[] = L::t('لدى زملاء في إدارتك إجازات في الفترة نفسها: ', 'Colleagues in your department are on leave then: ').implode('، ', array_map(fn ($x) => $en ? $x['en'] : $x['ar'], $c['warnings']['conflict']));
        if (($t['doc'] ?? 'none') === 'required') $warn[] = L::t('هذا النوع يتطلب مستنداً قبل الاعتماد النهائي — أرفقه من صفحة طلباتي بعد الإرسال.', 'This type needs a document before final approval — attach it from My requests after sending.');
        return ['fields' => $f, 'errors' => $c['errors'], 'warnings' => $warn,
            'info' => ['types' => $types, 'unit' => $t['unit'], 'substitutes' => $subs, 'days' => $c['days'] ?? 0, 'levels' => array_map(fn ($l) => $en ? $l['en'] : $l['ar'], $c['levels'] ?? []),
                'substitute_required' => $t['unit'] === 'day' && (bool) LeaveService::rules()['substitute_required']]];
    }

    private function previewJustify(Employee $e, array $in): array
    {
        $open = collect($this->leaves->issues($e))->where('open', true)->values();
        $f = ['date' => self::date($in['date'] ?? null), 'reason' => trim(mb_substr((string) ($in['reason'] ?? ''), 0, 500))];
        $err = [];
        if (! $f['date'] || ! $open->contains('date', $f['date'])) $err['date'] = L::t('اختر يوماً من الأيام التي يمكن تبريرها.', 'Choose one of the days that can be justified.');
        if (mb_strlen($f['reason']) < 5) $err['reason'] = L::t('اكتب سبباً واضحاً (5 أحرف على الأقل).', 'Write a clear reason (at least 5 characters).');
        return ['fields' => $f, 'errors' => $err, 'warnings' => [],
            'info' => ['issues' => $open->map(fn ($i) => ['date' => $i['date'], 'label' => $i['date'].' — '.Assistant::dayStatus($i['status']).($i['late'] ? ' '.$i['late'].L::t(' دقيقة', ' min') : '')])->all()]];
    }

    private function previewCertificate(array $in): array
    {
        $kind = in_array($in['kind'] ?? '', ['salary_cert', 'experience_cert'], true) ? $in['kind'] : 'experience_cert';
        $a = (array) ($in['addressee'] ?? []);
        $f = ['kind' => $kind, 'addressee' => ['name' => trim((string) ($a['name'] ?? '')), 'cr_no' => trim((string) ($a['cr_no'] ?? '')), 'email' => trim((string) ($a['email'] ?? '')), 'phone' => trim((string) ($a['phone'] ?? ''))],
            'notify_consent' => (bool) ($in['notify_consent'] ?? false), 'note' => trim(mb_substr((string) ($in['note'] ?? ''), 0, 1000))];
        $err = [];
        if ($kind === 'salary_cert') {
            foreach (['name' => L::t('اسم الجهة مطلوب.', 'Addressee name is required.'), 'cr_no' => L::t('رقم السجل التجاري مطلوب.', 'Commercial registration is required.'),
                'email' => L::t('بريد الجهة مطلوب.', 'Addressee email is required.'), 'phone' => L::t('هاتف الجهة مطلوب.', 'Addressee phone is required.')] as $k => $m) if ($f['addressee'][$k] === '') $err['addressee.'.$k] = $m;
        }
        if ($f['addressee']['email'] !== '' && ! filter_var($f['addressee']['email'], FILTER_VALIDATE_EMAIL)) $err['addressee.email'] = L::t('البريد غير صالح.', 'Invalid email.');
        $en = app()->getLocale() === 'en';
        return ['fields' => $f, 'errors' => $err, 'warnings' => [], 'info' => ['kinds' => array_map(fn ($k) => ['key' => $k, 'label' => ServiceRequest::KINDS[$k][$en ? 1 : 0]], ['salary_cert', 'experience_cert'])]];
    }

    private function previewPraise(User $u, Employee $e, array $in): array
    {
        $cfg = Praise::config();
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($in['recipients'] ?? [])), fn ($i) => $i > 0 && $i !== $e->id)));
        $people = Employee::current()->whereIn('id', $ids)->get(['id', 'name', 'name_en']);
        $f = ['recipients' => $people->pluck('id')->values()->all(), 'value' => (string) ($in['value'] ?? ''), 'message' => trim(mb_substr((string) ($in['message'] ?? ''), 0, 1000))];
        $err = [];
        if (! $f['recipients']) $err['recipients'] = L::t('اختر زميلاً واحداً على الأقل.', 'Choose at least one colleague.');
        if (count($f['recipients']) > (int) $cfg['max_recipients']) $err['recipients'] = L::t("حتى {$cfg['max_recipients']} زملاء.", "Up to {$cfg['max_recipients']} colleagues.");
        if (! collect($cfg['values'])->contains('key', $f['value'])) $err['value'] = L::t('اختر قيمة.', 'Choose a value.');
        if (mb_strlen($f['message']) < (int) $cfg['min_message']) $err['message'] = L::t("اكتب ما فعله ({$cfg['min_message']} أحرف على الأقل).", "Write what they did (at least {$cfg['min_message']} characters).");
        $en = app()->getLocale() === 'en';
        $client = Praise::client($u);
        if ($client['left'] <= 0) $err['value'] = L::t('بلغت حد الشكر لهذا الشهر.', 'You reached this month’s praise limit.');
        return ['fields' => $f, 'errors' => $err, 'warnings' => [], 'info' => ['values' => array_map(fn ($v) => ['key' => $v['key'], 'label' => $en ? $v['en'] : $v['ar'], 'color' => $v['color']], $cfg['values']),
            'people' => $people->map(fn ($x) => ['id' => $x->id, 'label' => $en ? ($x->name_en ?: $x->name) : $x->name])->values()->all(), 'points' => (int) $cfg['points'], 'left' => $client['left'], 'max' => (int) $cfg['max_recipients']]];
    }

    /** ما يُرسَل للواجهة من الإجراء المحفوظ في الرسالة */
    public static function client(AssistantMessage $m): ?array
    {
        $a = $m->data['action'] ?? null;
        if (! $a) return null;
        if ($a['status'] === 'pending' && now()->gt(Carbon::parse($a['expires_at']))) $a['status'] = 'expired';
        return $a;
    }

    /**
     * قرار المستخدم على البطاقة: preview (يعيد الفحص بعد التعديل)، cancel، confirm (يحتاج digest المعاينة الأخيرة وبلا أخطاء).
     */
    public function decide(User $u, AssistantMessage $m, string $decision, array $fields, ?string $digest): array
    {
        if ($m->user_id !== $u->id || $m->role !== 'assistant') abort(404);
        $a = self::client($m);
        if (! $a) abort(404);
        if ($a['status'] !== 'pending') throw new ApiException($a['status'] === 'expired' ? L::t('انتهت صلاحية هذه البطاقة. اطلب الإجراء من جديد.', 'This card has expired. Ask again.') : L::t('تم التعامل مع هذه البطاقة.', 'This card was already handled.'), 'action_closed', 409);
        $e = Employee::findOrFail($u->employee_id);
        if ($decision === 'cancel') { $a['status'] = 'cancelled'; return $this->store($m, $a); }
        $p = $this->preview($a['kind'], $u, $e, $fields ?: $a['fields']);
        $a = array_merge($a, ['fields' => $p['fields'], 'errors' => $p['errors'], 'warnings' => $p['warnings'], 'info' => $p['info'], 'digest' => self::digest($p['fields'])]);
        if ($decision !== 'confirm' || $p['errors'] || $digest !== $a['digest']) return $this->store($m, $a);
        try {
            $a['result'] = $this->execute($a['kind'], $u, $e, $p['fields']);
            $a['status'] = 'done';
        } catch (ValidationException $x) {
            $a['errors'] = array_map(fn ($v) => is_array($v) ? $v[0] : $v, $x->errors());
        } catch (ApiException $x) {
            $a['errors'] = $x->errors ? array_map(fn ($v) => is_array($v) ? $v[0] : $v, $x->errors) : ['_' => $x->getMessage()];
        }
        return $this->store($m, $a);
    }

    private function store(AssistantMessage $m, array $a): array
    {
        $d = $m->data; $d['action'] = $a; $m->data = $d; $m->save();
        return $a;
    }

    private function execute(string $kind, User $u, Employee $e, array $f): array
    {
        $en = app()->getLocale() === 'en';
        switch ($kind) {
            case 'leave':
                $l = $this->leaves->submit($e, $f, $u);
                $t = LeaveService::type($l->type);
                return ['text' => L::t("أُرسل طلب «{$t['ar']}» (".$l->from->toDateString().($l->to->equalTo($l->from) ? '' : ' — '.$l->to->toDateString()).') وبدأ مسار الاعتماد. ستصلك الإشعارات بكل قرار.',
                    "Your “{$t['en']}” request (".$l->from->toDateString().($l->to->equalTo($l->from) ? '' : ' — '.$l->to->toDateString()).') was sent for approval. You will be notified of each decision.'), 'to' => Assistant::link($u, '/me/requests')];
            case 'justify':
                $this->leaves->justify($e, $f['date'], $f['reason'], $u);
                return ['text' => L::t("أُرسل تبرير يوم {$f['date']} إلى مديرك ثم الموارد البشرية.", "The justification for {$f['date']} was sent to your manager, then HR."), 'to' => Assistant::link($u, '/me/attendance')];
            case 'certificate':
                $s = app(ServiceDesk::class)->submit($e, ['kind' => $f['kind'], 'addressee' => $f['addressee'], 'notify_consent' => $f['notify_consent'], 'note' => $f['note'] ?: null], $u);
                return ['text' => L::t('أُرسل طلب «'.ServiceRequest::KINDS[$s->kind][0].'». تصدر الشهادة بعد الاعتماد وتجدها في «خدماتي».', 'Your “'.ServiceRequest::KINDS[$s->kind][1].'” request was sent. The certificate is issued after approval in “My services”.'), 'to' => Assistant::link($u, '/me/services')];
            case 'praise':
                $p = app(Praise::class)->give($u, $f);
                return ['text' => L::t('نُشر شكرك في مجتمع الشركة ووصل الإشعار إلى زملائك.', 'Your praise was posted in the company community and your colleagues were notified.'), 'to' => Assistant::link($u, '/me/space').'/p/'.$p->id];
        }
        abort(422);
    }

    // ───────────── قراءة الكلام ─────────────

    /** نوع مذكور صراحة في الكلام (بلا افتراض) */
    public static function mentionedType(string $n): ?string
    {
        $types = collect(Settings::policy('leave_types'))->reject(fn ($t) => $t['key'] === 'justify')->values()->all();
        $k = self::guessType($n, $types, false, false);
        return $k === '' ? null : $k;
    }

    /** نوع الإجازة من الكلام: كلمات خاصة ثم اسم النوع كما في السياسة؛ وإلا ساعية إن ذُكرت ساعات، وإلا السنوية */
    public static function guessType(string $n, array $types, bool $hours, bool $default = true): string
    {
        $keys = array_column($types, 'key');
        $syn = ['hourly' => ['ساعيه', 'ساعات', 'مغادره', 'hourly', 'hours'], 'emergency' => ['طارئ', 'طاري', 'مستعجل', 'emergency', 'urgent'], 'sick' => ['مرض', 'مريض', 'sick', 'ill'],
            'unpaid' => ['بلا راتب', 'بدون راتب', 'غير مدفوع', 'unpaid'], 'annual' => ['سنويه', 'اداريه', 'annual', 'vacation'], 'marriage' => ['زواج', 'عرس', 'marriage', 'wedding'],
            'death' => ['وفاه', 'عزاء', 'death', 'bereavement'], 'maternity' => ['امومه', 'ولاده', 'maternity'], 'paternity' => ['ابوه', 'paternity'], 'hajj' => ['حج', 'عمره', 'hajj', 'umrah'],
            'injury' => ['اصابه', 'injury'], 'mission' => ['مهمه عمل', 'مهمه رسميه', 'business trip', 'mission']];
        foreach ($syn as $k => $w) if (in_array($k, $keys, true) && Text::has($n, $w)) return $k;
        foreach ($types as $t) {
            $words = array_filter(explode(' ', Text::norm(str_replace(['إجازة', 'اجازة', '(', ')', '/'], ' ', $t['ar']))), fn ($w) => mb_strlen($w) >= 3);
            foreach ($words as $w) if (Text::has($n, [mb_substr($w, 0, 4)])) return $t['key'];
        }
        if (! $default) return '';
        if ($hours && in_array('hourly', $keys, true)) return 'hourly';
        return in_array('annual', $keys, true) ? 'annual' : ($keys[0] ?? 'annual');
    }

    /** آخر يوم بعد عدد من أيام العمل (أو التقويمية) بدءاً من اليوم الأول */
    private function extend(Employee $e, string $from, int $days, bool $calendar): string
    {
        $d = Carbon::parse($from.' 12:00:00'); $n = 0; $guard = 0;
        while ($guard++ < 120) {
            $x = $d->toDateString();
            if ($calendar || (! DayCalc::weekend($x, $e) && ! DayCalc::holiday($x))) { $n++; if ($n >= $days) return $x; }
            $d->addDay();
        }
        return $from;
    }

    private static function date($v): ?string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        try { return Carbon::createFromFormat('Y-m-d', $v)->toDateString(); } catch (\Throwable) { return null; }
    }

    /** النص بعد أول كلمة دالة (السبب، الرسالة) */
    public static function after(string $text, array $marks): ?string
    {
        foreach ($marks as $mk) {
            if (preg_match('/(^|\s)'.preg_quote($mk, '/').'\s+(.{3,})$/u', $text, $m)) return trim(mb_substr($m[2], 0, 500));
        }
        return null;
    }

    /** أشخاص مذكورون بالاسم في الكلام (مطابقة أسماء الموظفين الحاليين بعد التطبيع) */
    private function findPeople(Employee $me, string $text): array
    {
        $n = ' '.Text::norm($text).' ';
        $out = [];
        foreach (Employee::current()->whereKeyNot($me->id)->get(['id', 'name', 'name_en']) as $x) {
            $parts = array_values(array_filter(explode(' ', TextExtractor::norm($x->name)), fn ($w) => mb_strlen($w) >= 2 && ! in_array($w, ['ال', 'بن'], true)));
            $first = $parts[0] ?? null; $second = $parts[1] ?? null;
            $en = array_values(array_filter(explode(' ', mb_strtolower((string) $x->name_en))));
            $hit = ($first && $second && str_contains($n, " {$first} ") && (str_contains($n, " {$second} ") || str_contains($n, " ".preg_replace('/^ال/u', '', $second)." ")))
                || (count($en) >= 2 && str_contains($n, " {$en[0]} ") && str_contains($n, " {$en[1]} "));
            if ($hit) $out[] = $x->id;
        }
        if (! $out) {   // الاسم الأول وحده إن كان فريداً
            foreach (Employee::current()->whereKeyNot($me->id)->get(['id', 'name']) as $x) {
                $first = explode(' ', TextExtractor::norm($x->name))[0] ?? '';
                if (mb_strlen($first) >= 3 && str_contains($n, " {$first} ")) $out[$first][] = $x->id;
            }
            $out = array_merge(...array_values(array_filter($out, fn ($ids) => count($ids) === 1))) ?: [];
        }
        return array_slice(array_values(array_unique($out)), 0, 5);
    }

    private function guessSubstitute(Employee $e, string $text): ?int
    {
        if (! preg_match('/(بديلي|البديل|بديل|substitute)\s+(.+)$/u', $text, $m)) return null;
        $ids = $this->findPeople($e, $m[2]);
        return $ids[0] ?? null;
    }
}
