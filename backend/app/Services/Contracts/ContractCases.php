<?php

namespace App\Services\Contracts;

use App\Exceptions\ApiException;
use App\Models\Contract;
use App\Models\ContractCase;
use App\Models\ContractException;
use App\Models\DisciplineCase;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Services\Codes\Codes;
use App\Services\Exit\ExitFlow;
use App\Services\Onboarding\OnboardingFlow;
use App\Services\Workflow\Workflow;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * حالات التجديد وتقييم فترة التجربة (WF-CR-01 / WF-CR-02): الفتح الآلي بالمهل، تقييم المدير (نموذج التجربة العشريني أو تقييم التجديد)،
 * تواقيع التقييم، مراجعة HR بقائمة تحقق، اقتراح القرار بالقواعد، القرار أو مسار الاستثناء، ثم التنفيذ (عقد جديد، تثبيت، تمديد، أو إنهاء خدمة).
 */
class ContractCases
{
    public function __construct(private Contracts $contracts) {}

    private static function cfg(): array { return Contracts::cfg(); }
    private static function rejectMin(): int { return (int) Settings::policy('leave_rules')['reject_min']; }

    /** يفتح حالة لعقد (آلياً من المهمة اليومية أو يدوياً من HR) */
    public function open(Contract $c, string $kind, ?User $by = null): ContractCase
    {
        $c->loadMissing(['employee.manager', 'employee.department']);
        $due = $kind === 'renewal' ? $c->ends_on : $c->probation_ends_on;
        if (! $due || $c->status !== 'active') throw new ApiException(L::t('لا يوجد تاريخ انتهاء لهذا العقد.', 'This contract has no end date for that.'), 'no_due', 409);
        if ($kind === 'probation' && $c->employee->status !== 'probation') throw new ApiException(L::t('الموظف ليس في فترة التجربة.', 'The employee is not on probation.'), 'not_in_probation', 409);
        if ($x = ContractCase::where('contract_id', $c->id)->where('kind', $kind)->where('status', '!=', 'closed')->first()) return $x;
        $cfg = self::cfg(); $e = $c->employee;
        $evaluator = $e->manager ?? ($e->department?->head_id && $e->department->head_id !== $e->id ? Employee::find($e->department->head_id) : null);
        $case = ContractCase::create(['kind' => $kind, 'ref_no' => Contracts::ref($kind === 'renewal' ? 'RNW' : 'PRB'), 'employee_id' => $e->id, 'contract_id' => $c->id, 'due_on' => $due,
            'status' => 'manager', 'evaluator_id' => $evaluator?->id, 'manager_due' => now()->addDays((int) $cfg['manager_days'])->toDateString(), 'summary' => self::summary($e, $c), 'opened_at' => now()]);
        $e->log(($kind === 'renewal' ? 'فتح حالة تجديد العقد ' : 'فتح تقييم فترة التجربة ')."{$case->ref_no} — الاستحقاق {$due->toDateString()}",
            ($kind === 'renewal' ? 'Contract renewal case ' : 'Probation evaluation ')."{$case->ref_no} opened — due {$due->toDateString()}", 'info');
        $label = [$kind === 'renewal' ? 'تقييم تجديد عقد' : 'تقييم فترة تجربة', $kind === 'renewal' ? 'contract renewal evaluation' : 'probation evaluation'];
        if ($evaluator && ($uid = $evaluator->user()->value('id'))) Notify::user($uid, "{$label[0]} {$e->name} بانتظارك — المهلة {$case->manager_due->toDateString()}", ucfirst($label[1])." for {$e->name_en} awaits you — due {$case->manager_due->toDateString()}", 'contract-eval');
        Notify::hr("فُتحت حالة {$case->ref_no}: {$label[0]} {$e->name}".($evaluator ? '' : ' — بلا مدير مباشر، يقيّمه الموارد البشرية'), "Case {$case->ref_no} opened: {$label[1]} for {$e->name_en}".($evaluator ? '' : ' — no direct manager, HR evaluates'), "contract-case:{$case->id}");
        return $case;
    }

    /** ملخص الموظف داخل الحالة: سنوات الخدمة التعاقدية، آخر تقييم أداء، الجزاءات خلال سنة */
    public static function summary(Employee $e, Contract $c): array
    {
        $r = PerformanceReview::where('employee_id', $e->id)->whereNotNull('score')->where('status', 'approved')->latest('approved_at')->first();
        return ['years' => Contracts::continuousYears($e, $c), 'hire_date' => $e->hire_date?->toDateString(), 'review' => $r ? ['score' => (float) $r->score, 'performance' => $r->performance] : null,
            'penalties' => DisciplineCase::where('employee_id', $e->id)->where('status', 'decided')->where('decided_at', '>=', now()->subYear())->count(),
            'contracts' => Contract::where('employee_id', $e->id)->whereIn('status', ['active', 'renewed', 'expired_no_action'])->count()];
    }

    public static function isHr(User $u): bool { return Perm::can($u, 'contracts', 'edit') && Perm::scope($u, 'contracts') === 'all'; }

    /** المقيِّم: المدير المباشر (أو رئيس الإدارة)، وإن لم يوجد فالموارد البشرية */
    public static function canEvaluate(User $u, ContractCase $k): bool
    {
        if ($k->status !== 'manager' || $u->employee_id === $k->employee_id) return false;
        return $k->evaluator_id ? $u->employee_id === $k->evaluator_id || self::isHr($u) : self::isHr($u);
    }

    public function evaluate(ContractCase $k, User $u, array $v): ContractCase
    {
        if (! self::canEvaluate($u, $k)) throw new ApiException(L::t('التقييم من المدير المباشر للموظف.', "The evaluation is for the employee's direct manager."), 'forbidden', 403);
        $cfg = self::cfg(); $scale = collect($cfg['scale'])->keyBy('key'); $err = [];
        $notes = trim((string) ($v['notes'] ?? ''));
        if ($k->kind === 'probation') {
            $items = []; $just = [];
            foreach ($cfg['items'] as $it) {
                $g = $v['items'][$it['key']] ?? null;
                if (! $scale->has($g)) { $err["items.{$it['key']}"] = L::t('اختر التقدير.', 'Choose a rating.'); continue; }
                $items[$it['key']] = $g;
                $j = trim((string) ($v['justify'][$it['key']] ?? ''));
                if ($g !== $cfg['justify_other_than'] && mb_strlen($j) < 3) $err["justify.{$it['key']}"] = L::t('برّر التقدير المختلف عن «جيد».', 'Justify a rating other than «good».');
                if ($j !== '') $just[$it['key']] = mb_substr($j, 0, 500);
            }
            $rec = in_array($v['recommendation'] ?? '', ['confirm', 'not_confirm'], true) ? $v['recommendation'] : null;
            if (! $rec) $err['recommendation'] = L::t('اكتب توصيتك: تثبيت أو عدم تثبيت.', 'State your recommendation: confirm or not.');
            if ($err) throw new ApiException(L::t('أكمل نموذج التقييم.', 'Complete the evaluation form.'), 'invalid', 422, array_map(fn ($m) => [$m], $err));
            $max = count($items) * (int) $scale->max('score');
            $total = $max ? (int) round(array_sum(array_map(fn ($g) => $scale[$g]['score'], $items)) * 100 / $max) : 0;
            $band = self::band($total);
            $eval = ['items' => $items, 'justify' => $just, 'total' => $total, 'grade' => $band['grade'], 'recommendation' => $rec, 'notes' => $notes,
                'weak' => array_keys(array_filter($items, fn ($g) => $scale[$g]['score'] < ($scale[$cfg['justify_other_than']]['score'] ?? 3))), 'by' => $u->id, 'at' => now()->toIso8601String()];
            // التواقيع بالتسلسل: الموظف ثم مدير القسم (إن كان غير المقيِّم)، ثم مراجعة HR
            $head = $k->employee->department?->head_id;
            $sigs = [['role' => 'employee', 'at' => null, 'user_id' => null, 'note' => null]];
            if ($head && $head !== $u->employee_id && $head !== $k->employee_id) $sigs[] = ['role' => 'dept_head', 'at' => null, 'user_id' => null, 'note' => null];
            $k->update(['evaluation' => $eval, 'signatures' => $sigs, 'status' => 'signatures']);
            if ($uid = $k->employee->user()->value('id')) Notify::user($uid, 'تقييم فترة تجربتك جاهز للاطلاع والتوقيع', 'Your probation evaluation is ready to read and sign', 'contracts');
        } else {
            $rating = $v['rating'] ?? null;
            if (! $scale->has($rating)) $err['rating'] = L::t('اختر التقدير العام.', 'Choose the overall rating.');
            $rec = in_array($v['recommendation'] ?? '', ['renew', 'permanent', 'not_renew'], true) ? $v['recommendation'] : null;
            if (! $rec) $err['recommendation'] = L::t('اختر التوصية.', 'Choose the recommendation.');
            if (mb_strlen($notes) < 10) $err['notes'] = L::t('اكتب مبرر التوصية (10 أحرف على الأقل).', 'Write the reason (at least 10 characters).');
            if ($err) throw new ApiException(L::t('أكمل نموذج التقييم.', 'Complete the evaluation form.'), 'invalid', 422, array_map(fn ($m) => [$m], $err));
            $k->update(['evaluation' => ['rating' => $rating, 'recommendation' => $rec, 'notes' => $notes, 'by' => $u->id, 'at' => now()->toIso8601String()],
                'status' => 'hr_review', 'hr_due' => now()->addDays((int) $cfg['hr_days'])->toDateString()]);
            Notify::hr("تقييم التجديد {$k->ref_no} جاهز للمراجعة", "Renewal evaluation {$k->ref_no} is ready for review", "contract-case:{$k->id}");
        }
        $k->employee->log("تعبئة تقييم {$k->ref_no} ({$u->displayName('ar')})", "Evaluation {$k->ref_no} filled ({$u->displayName('en')})");
        return $k->refresh();
    }

    public static function band(int $total): array
    {
        $bands = collect(self::cfg()['bands'])->sortByDesc('from')->values();
        return $bands->first(fn ($b) => $total >= (int) $b['from']) ?? $bands->last();
    }

    public static function canSignEval(User $u, ContractCase $k): ?string
    {
        if ($k->status !== 'signatures') return null;
        $next = collect($k->signatures ?? [])->first(fn ($s) => ! $s['at']);
        if (! $next) return null;
        $ok = $next['role'] === 'employee' ? $u->employee_id === $k->employee_id : $u->employee_id && $u->employee_id === $k->employee->department?->head_id;
        return $ok ? $next['role'] : null;
    }

    /** توقيع التقييم بالتسلسل (POL-CR-14): الموظف يطّلع ويوقّع (مع ملاحظة اختيارية)، ثم مدير القسم */
    public function signEval(ContractCase $k, User $u, ?string $note): ContractCase
    {
        $k->loadMissing('employee.department');
        $role = self::canSignEval($u, $k) ?? throw new ApiException(L::t('التوقيع التالي ليس لك.', 'The next signature is not yours.'), 'not_your_turn', 403);
        $sigs = $k->signatures; $i = collect($sigs)->search(fn ($s) => ! $s['at']);
        $sigs[$i] = ['role' => $role, 'at' => now()->toIso8601String(), 'user_id' => $u->id, 'note' => trim((string) $note) ?: null];
        $done = collect($sigs)->every(fn ($s) => $s['at']);
        $k->update(['signatures' => $sigs] + ($done ? ['status' => 'hr_review', 'hr_due' => now()->addDays((int) self::cfg()['hr_days'])->toDateString()] : []));
        $k->employee->log("توقيع تقييم {$k->ref_no}: ".($role === 'employee' ? 'الموظف' : 'مدير القسم'), "Evaluation {$k->ref_no} signed: ".($role === 'employee' ? 'employee' : 'department head'));
        if ($done) Notify::hr("اكتملت تواقيع تقييم {$k->ref_no} — بانتظار مراجعة الموارد البشرية", "Evaluation {$k->ref_no} fully signed — awaiting HR review", "contract-case:{$k->id}");
        elseif (($h = $k->employee->department?->head_id) && ($uid = Employee::find($h)?->user()->value('id'))) Notify::user($uid, "تقييم {$k->ref_no} بانتظار توقيعك كمدير القسم", "Evaluation {$k->ref_no} awaits your signature as department head", 'contracts');
        return $k->refresh();
    }

    /** مراجعة HR بقائمة تحقق (POL-CR-03): اعتماد للقرار أو إعادة للمدير بملاحظة */
    public function review(ContractCase $k, User $u, bool $approve, array $checks, ?string $note): ContractCase
    {
        if ($k->status !== 'hr_review') throw new ApiException(L::t('الحالة ليست بانتظار المراجعة.', 'The case is not awaiting review.'), 'not_pending', 409);
        if (! self::isHr($u) || $u->employee_id === $k->employee_id) throw new ApiException(L::t('المراجعة من الموارد البشرية.', 'Review is for Human Resources.'), 'forbidden', 403);
        if (! $approve) {
            if (mb_strlen(trim((string) $note)) < 5) throw ApiException::field('note', L::t('اكتب ما يجب على المدير استكماله.', 'Write what the manager must complete.'), 'reason');
            $k->update(['status' => 'manager', 'review' => ['returned' => trim($note), 'by' => $u->id, 'at' => now()->toIso8601String()], 'manager_due' => now()->addDays((int) self::cfg()['manager_days'])->toDateString(), 'signatures' => null]);
            if ($k->evaluator_id && ($uid = Employee::find($k->evaluator_id)?->user()->value('id'))) Notify::user($uid, "أُعيد تقييم {$k->ref_no} إليك: {$note}", "Evaluation {$k->ref_no} was returned to you: {$note}", 'contract-eval');
            return $k->refresh();
        }
        $need = array_column(self::cfg()['review_checks'], 'key');
        $missing = array_diff($need, array_keys(array_filter($checks)));
        if ($missing) throw ApiException::field('checks', L::t('أكمل قائمة التحقق قبل الانتقال للقرار.', 'Complete the checklist before the decision.'), 'checks');
        $k->update(['review' => ['checks' => array_fill_keys($need, true), 'note' => trim((string) $note) ?: null, 'by' => $u->id, 'at' => now()->toIso8601String()],
            'status' => 'decision', 'suggestion' => $this->suggest($k)]);
        return $k->refresh();
    }

    /** محرك الاقتراح (FR-CR-008): نتيجة التقييم والتوصية وسنوات الخدمة التعاقدية المتواصلة */
    public function suggest(ContractCase $k): array
    {
        $cfg = self::cfg(); $ev = $k->evaluation ?? [];
        if ($k->kind === 'probation') {
            $band = self::band((int) ($ev['total'] ?? 0));
            $d = $band['decision'] === 'confirm' ? 'confirm' : ($band['decision'] === 'terminate' ? 'terminate' : 'decide');
            return ['decision' => $d, 'rule' => 'bands', 'grade' => $band['grade'], 'total' => $ev['total'] ?? null, 'recommendation' => $ev['recommendation'] ?? null];
        }
        $scale = collect($cfg['scale'])->keyBy('key');
        $score = $scale[$ev['rating'] ?? '']['score'] ?? 0; $min = $scale[$cfg['min_rating_renew']]['score'] ?? 3;
        $years = Contracts::continuousYears($k->employee, $k->contract);
        if (($ev['recommendation'] ?? '') === 'not_renew' || $score < $min) return ['decision' => 'not_renew', 'rule' => $score < $min ? 'BR-CR-07' : 'recommendation', 'years' => $years];
        if (($ev['recommendation'] ?? '') === 'permanent' || $years >= (float) $cfg['open_after_years'])
            return ['decision' => 'convert', 'type_code' => $cfg['open_type'], 'months' => 0, 'rule' => ($ev['recommendation'] ?? '') === 'permanent' ? 'POL-CR-05' : 'BR-CR-06', 'years' => $years];
        return ['decision' => 'renew', 'type_code' => $cfg['fixed_type'], 'months' => (int) $cfg['default_months'], 'rule' => 'POL-CR-04', 'years' => $years];
    }

    public const DECISIONS = ['renewal' => ['renew', 'convert', 'not_renew'], 'probation' => ['confirm', 'terminate', 'extend']];

    /**
     * قرار صاحب الصلاحية. ما يخالف الاقتراح (أو مدة أقل من السياسة) = استثناء بمبرر إلزامي واعتماد أعلى (POL-CR-06/07).
     * @param array{decision: string, contract_type_id?: int|null, months?: int|null, reason?: string|null} $v
     */
    public function decide(ContractCase $k, User $u, array $v): ContractCase
    {
        if ($k->status !== 'decision') throw new ApiException(L::t('الحالة ليست بانتظار القرار.', 'The case is not awaiting a decision.'), 'not_pending', 409);
        if (! Perm::can($u, 'contracts', 'decide') || $u->employee_id === $k->employee_id) throw new ApiException(L::t('القرار من صلاحية مدير الموارد البشرية.', 'The decision belongs to the HR director.'), 'forbidden', 403);
        $d = $v['decision'] ?? '';
        if (! in_array($d, self::DECISIONS[$k->kind], true)) throw ApiException::field('decision', L::t('اختر القرار.', 'Choose the decision.'), 'invalid');
        $cfg = self::cfg(); $sug = $k->suggestion ?? $this->suggest($k);
        $dec = ['decision' => $d, 'reason' => trim((string) ($v['reason'] ?? '')) ?: null, 'by' => $u->id, 'at' => now()->toIso8601String()];
        if (in_array($d, ['renew', 'convert'], true)) {
            $type = Codes::resolve('contract_types', $v['contract_type_id'] ?? ($d === 'convert' ? $cfg['open_type'] : $cfg['fixed_type']))
                ?? throw ApiException::field('contract_type_id', L::t('اختر نوع العقد.', 'Choose the contract type.'), 'invalid');
            $dec['contract_type_id'] = $type->id;
            $dec['months'] = array_key_exists('months', $v) && $v['months'] !== null && $v['months'] !== '' ? (int) $v['months'] : Contracts::typeMonths($type);
        }
        if ($d === 'extend') {
            if (! $cfg['allow_extension']) throw ApiException::field('decision', L::t('تمديد التجربة غير مسموح في إعدادات الشركة.', 'Extending probation is off in the company settings.'), 'extension_off');
            $dec['months'] = (int) ($v['months'] ?? 0);
            if ($dec['months'] < 1 || $dec['months'] > (int) $cfg['extension_max_months']) throw ApiException::field('months', str_replace(':n', (string) $cfg['extension_max_months'], L::t('مدة التمديد من شهر إلى :n.', 'Extension is 1 to :n month(s).')), 'invalid');
            if (ContractCase::where('contract_id', $k->contract_id)->where('outcome', 'extended')->exists()) throw ApiException::field('decision', L::t('مُدِّدت فترة التجربة مرة سابقاً.', 'Probation was already extended once.'), 'extended_once');
            if (mb_strlen((string) $dec['reason']) < 5) throw ApiException::field('reason', L::t('اكتب مبرر التمديد.', 'Write the reason for extending.'), 'reason');
        }
        // هل القرار استثناء؟
        $exception = $sug['decision'] !== 'decide' && $d !== $sug['decision'] && $d !== 'extend';
        if (! $exception && in_array($d, ['renew', 'convert'], true) && isset($sug['type_code']) && Codes::resolve('contract_types', $sug['type_code'])?->id === $dec['contract_type_id']
            && $dec['months'] > 0 && $dec['months'] < (int) ($sug['months'] ?: $cfg['default_months'])) $exception = true;   // عقد أقصر من السياسة
        $dec['exception'] = $exception;
        if ($exception) {
            if (mb_strlen((string) $dec['reason']) < self::rejectMin()) throw ApiException::field('reason', str_replace(':n', (string) self::rejectMin(), L::t('القرار يخالف السياسة: اكتب المبرر (:n أحرف على الأقل) ليُرفع للاعتماد.', 'This decision departs from the policy: write the reason (at least :n characters) for approval.')), 'reason');
            return DB::transaction(function () use ($k, $u, $dec) {
                $x = ContractException::create(['contract_case_id' => $k->id, 'employee_id' => $k->employee_id, 'decision' => $dec, 'reason' => $dec['reason'], 'status' => 'pending', 'requested_by' => $u->id]);
                $k->update(['status' => 'exception', 'decision' => $dec]);
                $k->employee->log("قرار عقد مخالف للسياسة بانتظار الاعتماد ({$k->ref_no})", "Contract decision outside the policy awaiting approval ({$k->ref_no})", 'warn');
                app(Workflow::class)->start($x, $k->employee, Workflow::chain('contract_exception'), $u);
                return $k->refresh();
            });
        }
        return DB::transaction(fn () => $this->apply($k, $u, $dec));
    }

    public function decideException(ContractException $x, User $u, bool $approve, ?string $note): ContractException
    {
        $x = app(Workflow::class)->decide($x, $u, $approve, $note, false, function (ContractException $x) {
            $k = ContractCase::with('employee')->findOrFail($x->contract_case_id);
            $this->apply($k, User::findOrFail($x->requested_by), $x->decision + ['approved_exception' => true]);
        });
        if (! $approve) {
            $k = ContractCase::findOrFail($x->contract_case_id);
            $k->update(['status' => 'decision', 'decision' => null]);
            Notify::hr("رُفض الاستثناء في الحالة {$k->ref_no}: {$note}", "The exception for case {$k->ref_no} was declined: {$note}", "contract-case:{$k->id}");
        }
        return $x;
    }

    /** تنفيذ القرار */
    public function apply(ContractCase $k, User $by, array $dec): ContractCase
    {
        $k->loadMissing(['employee.user', 'contract']); $e = $k->employee; $c = $k->contract; $cfg = self::cfg();
        $letter = fn (string $kind) => Notify::user($e->user?->id, self::letterText($k, $kind, 'ar'), self::letterText($k, $kind, 'en'), 'contracts');
        switch ($dec['decision']) {
            case 'renew': case 'convert':
                $start = $c->ends_on ? $c->ends_on->copy()->addDay()->toDateString() : now()->toDateString();
                $new = $this->contracts->create($by, $e, ['contract_type_id' => $dec['contract_type_id'], 'months' => $dec['months'], 'starts_on' => $start, 'probation_months' => 0,
                    'exception' => (bool) ($dec['exception'] ?? false), 'exception_reason' => ($dec['exception'] ?? false) ? $dec['reason'] : null], $c, $k, 'renewal');
                $k->update(['status' => 'preparing', 'outcome' => $dec['decision'] === 'convert' ? 'converted' : 'renewed', 'decision' => $dec, 'new_contract_id' => $new->id]);
                $letter('renewed');
                break;
            case 'not_renew':
                $last = max($c->ends_on?->toDateString() ?? now()->toDateString(), now()->toDateString());
                $r = app(ExitFlow::class)->terminate($by, $e, ['cause' => $cfg['exit_cause_not_renewed'], 'last_day' => $last, 'rehire' => true,
                    'notes' => 'عدم تجديد العقد — '.$k->ref_no.($dec['reason'] ? ': '.$dec['reason'] : '')]);
                $k->update(['status' => 'closed', 'outcome' => 'not_renewed', 'decision' => $dec, 'resignation_id' => $r->id, 'closed_at' => now()]);
                $letter('not_renewed');
                Audit::record('contract.not_renewed', $c, null, ['case' => $k->ref_no, 'exit' => $r->ref_no]);
                break;
            case 'confirm':
                $ob = $e->onboarding()->first();
                if ($ob && ! $ob->confirmed_at) app(OnboardingFlow::class)->confirm($e, $by, 'تقييم فترة التجربة '.$k->ref_no);
                else { $e->update(['status' => 'active']); $e->log("تثبيت بعد اجتياز فترة التجربة ({$k->ref_no})", "Confirmed after probation ({$k->ref_no})", 'ok'); }
                $k->update(['status' => 'closed', 'outcome' => 'confirmed', 'decision' => $dec, 'closed_at' => now()]);
                $letter('confirmed');
                Audit::record('employee.probation_passed', $e, null, ['case' => $k->ref_no, 'total' => $k->evaluation['total'] ?? null]);
                break;
            case 'terminate':
                $last = $cfg['fail_last_day'] === 'probation_end' ? max($k->due_on->toDateString(), now()->toDateString()) : now()->toDateString();
                $r = app(ExitFlow::class)->terminate($by, $e, ['cause' => $cfg['exit_cause_probation'], 'last_day' => $last, 'rehire' => true,
                    'notes' => 'عدم اجتياز فترة التجربة — '.$k->ref_no.($dec['reason'] ? ': '.$dec['reason'] : '')]);
                $k->update(['status' => 'closed', 'outcome' => 'terminated', 'decision' => $dec + ['last_day' => $last], 'resignation_id' => $r->id, 'closed_at' => now()]);
                $letter('terminated');
                Audit::record('employee.probation_failed', $e, null, ['case' => $k->ref_no, 'exit' => $r->ref_no]);
                break;
            case 'extend':
                $c->update(['probation_ends_on' => $c->probation_ends_on->copy()->addMonthsNoOverflow($dec['months'])->toDateString(), 'probation_months' => $c->probation_months + $dec['months']]);
                $k->update(['status' => 'closed', 'outcome' => 'extended', 'decision' => $dec, 'closed_at' => now()]);
                $e->log("تمديد فترة التجربة {$dec['months']} شهر حتى {$c->probation_ends_on->toDateString()}: {$dec['reason']}", "Probation extended {$dec['months']} month(s) until {$c->probation_ends_on->toDateString()}: {$dec['reason']}", 'warn');
                Notify::user($e->user?->id, "مُدِّدت فترة تجربتك حتى {$c->probation_ends_on->toDateString()}", "Your probation was extended until {$c->probation_ends_on->toDateString()}", 'contracts');
                break;
        }
        return $k->refresh();
    }

    /** نص الخطاب الآلي (POL-CR-08) من قوالب الإعدادات */
    public static function letterText(ContractCase $k, string $kind, string $lang): string
    {
        $k->loadMissing(['employee', 'newContract.type', 'contract']);
        $tpl = self::cfg()['letters'][$kind][$lang] ?? '';
        $n = $k->newContract; $en = $lang === 'en';
        return strtr($tpl, [':name' => $en ? $k->employee->name_en : $k->employee->name, ':title' => $en ? $k->employee->title_en : $k->employee->title_ar,
            ':type' => $n?->type ? ($en ? $n->type->name_en : $n->type->name_ar) : '', ':start' => $n?->starts_on?->toDateString() ?? '',
            ':end_text' => $n?->ends_on ? ($en ? ' until '.$n->ends_on->toDateString() : ' حتى '.$n->ends_on->toDateString()) : ($n ? ($en ? ' (open-ended)' : ' (غير محدد المدة)') : ''),
            ':end' => $k->contract?->ends_on?->toDateString() ?? $k->due_on->toDateString(), ':date' => now()->toDateString(), ':last_day' => $k->decision['last_day'] ?? now()->toDateString()]);
    }

    /** التجديد الجماعي (FR-CR-016): القرار نفسه لحالات مقترحها هو هذا القرار */
    public function bulk(User $u, array $ids, string $decision): array
    {
        $done = []; $skipped = [];
        foreach (ContractCase::with(['employee', 'contract'])->whereIn('id', $ids)->get() as $k) {
            if ($k->status !== 'decision' || ($k->suggestion['decision'] ?? null) !== $decision) { $skipped[] = $k->ref_no; continue; }
            try { $this->decide($k, $u, ['decision' => $decision, 'contract_type_id' => isset($k->suggestion['type_code']) ? $k->suggestion['type_code'] : null, 'months' => $k->suggestion['months'] ?? null]); $done[] = $k->ref_no; }
            catch (ApiException $ex) { $skipped[] = $k->ref_no.' — '.$ex->getMessage(); }
        }
        return ['done' => $done, 'skipped' => $skipped];
    }

    /** المهمة اليومية: فتح الحالات بالمهل، التذكير بعد المهلة، والعقود المنتهية دون إجراء (BR-CR-01/02/09) */
    public function sweep(): array
    {
        $cfg = self::cfg(); $today = now()->toDateString(); $n = ['opened' => 0, 'reminded' => 0, 'lapsed' => 0];
        foreach (Contract::with('employee')->where('status', 'active')->get() as $c) {
            foreach ([['renewal', $c->ends_on, (int) $cfg['renewal_open_days']], ['probation', $c->probation_ends_on, (int) $cfg['probation_open_days']]] as [$kind, $due, $days]) {
                if (! $due || $due->toDateString() > now()->addDays($days)->toDateString() || $due->toDateString() < now()->subDays(1)->toDateString()) continue;
                if ($kind === 'probation' && $c->employee->status !== 'probation') continue;
                if (ContractCase::where('contract_id', $c->id)->where('kind', $kind)->where(fn ($q) => $q->where('status', '!=', 'closed')->orWhereDate('due_on', $due))->exists()) continue;
                $this->open($c, $kind); $n['opened']++;
            }
        }
        foreach (ContractCase::with('employee')->whereIn('status', ['manager', 'hr_review'])->get() as $k) {
            $late = $k->status === 'manager' ? $k->manager_due : $k->hr_due;
            if (! $late || $late->toDateString() >= $today || ($k->reminded_at && $k->reminded_at->toDateString() === $today)) continue;
            $who = $k->status === 'manager' ? ($k->evaluator_id ? Employee::find($k->evaluator_id)?->user()->value('id') : null) : null;
            if ($who) Notify::user($who, "تذكير: تقييم {$k->ref_no} تجاوز مهلته", "Reminder: evaluation {$k->ref_no} is overdue", 'contract-eval');
            Notify::hr("تصعيد: الحالة {$k->ref_no} متأخرة عن مهلتها ({$late->toDateString()})", "Escalation: case {$k->ref_no} is past its deadline ({$late->toDateString()})", "contract-case:{$k->id}");
            $k->update(['reminded_at' => now()]); $n['reminded']++;
        }
        // عقد انتهى دون قرار ← «منتهٍ دون إجراء» وتصعيد يومي
        foreach (Contract::with('employee')->whereIn('status', ['active', 'expired_no_action'])->whereNotNull('ends_on')->whereDate('ends_on', '<', $today)->get() as $c) {
            $successor = Contract::where('previous_id', $c->id)->whereIn('status', [...Contract::LIVE])->exists();
            $exiting = ContractCase::where('contract_id', $c->id)->where('outcome', 'not_renewed')->exists() || $c->employee->status === 'terminated';
            if ($successor || $exiting) continue;
            if ($c->status === 'active') { $c->update(['status' => 'expired_no_action']); $c->employee->log("انتهى العقد {$c->ref_no} دون قرار تجديد", "Contract {$c->ref_no} ended without a renewal decision", 'danger'); }
            $k = ContractCase::where('contract_id', $c->id)->where('kind', 'renewal')->latest('id')->first();
            if ($k && $k->escalated_at && $k->escalated_at->toDateString() === $today) continue;
            $k?->update(['escalated_at' => now(), 'lapsed_at' => $k->lapsed_at ?? now()]);
            Notify::hr("العقد {$c->ref_no} للموظف {$c->employee->name} منتهٍ دون إجراء منذ {$c->ends_on->toDateString()}", "Contract {$c->ref_no} of {$c->employee->name_en} ended without action on {$c->ends_on->toDateString()}", $k ? "contract-case:{$k->id}" : 'contracts');
            $n['lapsed']++;
        }
        return $n;
    }

    public static function row(ContractCase $k, ?User $u = null, bool $full = false): array
    {
        $k->loadMissing(['employee.department', 'contract.type', 'evaluator', 'newContract']);
        $e = $k->employee; $cfg = self::cfg();
        $base = ['id' => $k->id, 'kind' => $k->kind, 'ref_no' => $k->ref_no, 'status' => $k->status, 'outcome' => $k->outcome, 'due_on' => $k->due_on->toDateString(),
            'left' => (int) now()->startOfDay()->diffInDays($k->due_on, false), 'manager_due' => $k->manager_due?->toDateString(), 'hr_due' => $k->hr_due?->toDateString(),
            'late' => ($k->status === 'manager' && $k->manager_due?->isPast()) || ($k->status === 'hr_review' && $k->hr_due?->isPast()), 'lapsed' => (bool) $k->lapsed_at,
            'employee' => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en, 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en],
                'dept' => $e->department ? ['ar' => $e->department->name_ar, 'en' => $e->department->name_en] : null],
            'contract' => $k->contract ? Contracts::row($k->contract) : null, 'evaluator' => $k->evaluator ? ['id' => $k->evaluator->id, 'ar' => $k->evaluator->name, 'en' => $k->evaluator->name_en] : null,
            'suggestion' => $k->suggestion, 'decision' => $k->decision, 'total' => $k->evaluation['total'] ?? null, 'grade' => $k->evaluation['grade'] ?? ($k->evaluation['rating'] ?? null),
            'exception' => (bool) ($k->decision['exception'] ?? false), 'new_contract_id' => $k->new_contract_id, 'resignation_id' => $k->resignation_id,
            'can' => $u ? ['evaluate' => self::canEvaluate($u, $k), 'sign' => self::canSignEval($u, $k) !== null, 'review' => $k->status === 'hr_review' && self::isHr($u) && $u->employee_id !== $k->employee_id,
                'decide' => $k->status === 'decision' && Perm::can($u, 'contracts', 'decide') && $u->employee_id !== $k->employee_id] : null];
        if (! $full) return $base;
        $ev = $k->evaluation;
        // ملاحظات HR والقرار ومبررات الاستثناء لا تظهر للموظف نفسه
        $self = $u && $u->employee_id === $k->employee_id;
        return $base + ['summary' => $self ? null : $k->summary, 'evaluation' => $ev, 'signatures' => array_map(fn ($s) => $s + ['user' => $s['user_id'] ? User::find($s['user_id'])?->displayName(app()->getLocale()) : null], $k->signatures ?? []),
            'review' => $self ? null : $k->review, 'new_contract' => $k->newContract ? Contracts::row($k->newContract, $u) : null,
            'letters' => match ($k->outcome) { 'renewed', 'converted' => ['renewed'], 'not_renewed' => ['not_renewed'], 'confirmed' => ['confirmed'], 'terminated' => ['terminated'], default => [] },
            'form' => $k->kind === 'probation' ? ['items' => $cfg['items'], 'scale' => $cfg['scale'], 'justify_other_than' => $cfg['justify_other_than']] : ['scale' => $cfg['scale']],
            'review_checks' => $cfg['review_checks'], 'decisions' => self::DECISIONS[$k->kind], 'extension_max' => $cfg['allow_extension'] ? (int) $cfg['extension_max_months'] : 0];
    }

    /** صف في صندوق الاعتماد الموحد لطلب الاستثناء */
    public static function exceptionRow(ContractException $x, User $viewer): array
    {
        $x->loadMissing(['case.contract.type', 'employee', 'requester', 'steps.actor.employee', 'steps.user.employee', 'steps.delegator.employee']);
        $cur = Workflow::current($x); $k = $x->case; $d = $x->decision;
        $type = isset($d['contract_type_id']) ? \App\Models\CodeItem::find($d['contract_type_id']) : null;
        $names = ['renew' => ['تجديد', 'Renew'], 'convert' => ['تحويل إلى غير محدد المدة', 'Convert to open-ended'], 'not_renew' => ['عدم التجديد', 'Do not renew'],
            'confirm' => ['تثبيت', 'Confirm'], 'terminate' => ['إنهاء في التجربة', 'End during probation'], 'extend' => ['تمديد التجربة', 'Extend probation']];
        $sug = $k?->suggestion['decision'] ?? null;
        return ['kind' => 'contract_exception', 'id' => $x->id, 'type' => 'contract_exception', 'type_name' => ['ar' => $x->workflowLabel('ar'), 'en' => $x->workflowLabel('en')],
            'employee' => \App\Http\Resources\RequestRow::person($x->employee),
            'from' => $k?->due_on?->toDateString(), 'to' => $k?->due_on?->toDateString(), 'status' => $x->status, 'reason' => $x->reason,
            'contract_decision' => ['ref_no' => $k?->ref_no, 'case_id' => $k?->id, 'decision' => ['ar' => $names[$d['decision']][0] ?? $d['decision'], 'en' => $names[$d['decision']][1] ?? $d['decision']],
                'suggested' => $sug ? ['ar' => $names[$sug][0] ?? $sug, 'en' => $names[$sug][1] ?? $sug] : null, 'months' => $d['months'] ?? null, 'type' => $type ? ['ar' => $type->name_ar, 'en' => $type->name_en] : null],
            'warnings' => [], 'requested_by' => $x->requester ? ['ar' => $x->requester->displayName('ar'), 'en' => $x->requester->displayName('en')] : null,
            'created_at' => $x->created_at?->toIso8601String(), 'steps' => Workflow::steps($x), 'step_id' => $cur?->id, 'can_act' => $cur ? Workflow::canAct($viewer, $cur, $x) : false, 'can_cancel' => false];
    }

    /** التقارير (FR-CR-017) */
    public static function reports(string $from, string $to): array
    {
        $cases = ContractCase::with('employee.department')->whereBetween('due_on', [$from, $to])->get();
        $byDept = $cases->where('kind', 'renewal')->whereNotNull('outcome')->groupBy(fn ($k) => $k->employee->department?->name_ar ?? '—')
            ->map(fn ($g, $d) => ['dept' => $d, 'renewed' => $g->whereIn('outcome', ['renewed', 'converted'])->count(), 'not_renewed' => $g->where('outcome', 'not_renewed')->count()])->values();
        return ['ending' => Contract::with(['employee', 'type'])->whereIn('status', ['active', 'expired_no_action'])->whereBetween('ends_on', [$from, $to])->orderBy('ends_on')->get()->map(fn ($c) => Contracts::row($c)),
            'renewal' => $byDept, 'probation' => ['confirmed' => $cases->where('kind', 'probation')->where('outcome', 'confirmed')->count(), 'terminated' => $cases->where('kind', 'probation')->where('outcome', 'terminated')->count(),
                'extended' => $cases->where('kind', 'probation')->where('outcome', 'extended')->count(), 'open' => $cases->where('kind', 'probation')->where('status', '!=', 'closed')->count()],
            'late' => $cases->filter(fn ($k) => ($k->status === 'manager' && $k->manager_due?->isPast()) || ($k->status === 'hr_review' && $k->hr_due?->isPast()) || $k->lapsed_at)->map(fn ($k) => self::row($k))->values(),
            'exceptions' => $cases->filter(fn ($k) => $k->decision['exception'] ?? false)->map(fn ($k) => self::row($k))->values()];
    }
}
