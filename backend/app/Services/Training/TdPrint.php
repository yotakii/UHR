<?php

namespace App\Services\Training;

use App\Models\DevelopmentPlan;
use App\Models\Employee;
use App\Models\Engagement;
use App\Models\TdCbp;
use App\Models\TdCertificate;
use App\Models\TdCommitment;
use App\Models\TdEnrollment;
use App\Models\TdImpact;
use App\Models\TdNeed;
use App\Models\TdPlan;
use App\Models\TdProgram;
use App\Models\TdQualification;
use App\Models\TdRating;
use App\Models\TdSession;
use App\Models\TdSurvey;
use App\Models\User;
use App\Models\VolunteerLog;
use App\Support\Audit;
use App\Support\Signature;
use App\Support\Tenant;

/**
 * بيانات طباعة نماذج التدريب HR-TD-F-01…14 + الشهادة + الإطار المنطقي لخطة بناء القدرات، بصيغة واحدة يعرضها `Paper`:
 * {code, title, blocks: [{type: kv|table|text|sign|qr, title?, rows|head|text|items}]} — التسميات أزواج [عربي، إنكليزي].
 */
class TdPrint
{
    public const MAP = ['TD1' => 'qualification', 'TD2' => 'need', 'TD3' => 'commitment', 'TD4' => 'plan', 'TD5' => 'impact', 'TD6' => 'employee', 'TD7' => 'session', 'TD8' => 'session',
        'TD9' => 'engagement', 'TD10' => 'engagement', 'TD11' => 'idp', 'TD12' => 'enrollment', 'TD13' => 'session', 'TD14' => 'session', 'TDC' => 'certificate', 'CBP' => 'cbp'];

    private static function e(?Employee $e): string { return $e ? trim($e->name.' ('.$e->code.')') : '—'; }
    private static function d($d): string { return $d ? (is_string($d) ? substr($d, 0, 10) : $d->toDateString()) : '—'; }
    private static function n($v, int $dp = 0): string { return $v === null ? '—' : number_format((float) $v, $dp); }

    /** من يحق له طباعة النموذج: الإدارة، أو صاحب السجل لنماذجه الشخصية */
    public static function build(string $code, int $id, User $u): array
    {
        if (! isset(self::MAP[$code])) abort(404);
        $hr = Td::manages($u); $me = $u->employee_id;
        $own = fn (?int $emp) => $hr || ($emp && ($emp === $me || Td::inScope($u, $emp)));
        $doc = match ($code) {
            'TD1' => self::qualification(TdQualification::findOrFail($id), $own),
            'TD2' => self::need(TdNeed::findOrFail($id), $own),
            'TD3' => self::commitment(TdCommitment::findOrFail($id), $own),
            'TD4' => $hr ? self::plan(TdPlan::findOrFail($id)) : abort(403),
            'TD5' => self::impact(TdImpact::findOrFail($id), $own, $u),
            'TD6' => $own($id) ? self::record(Employee::findOrFail($id)) : abort(403),
            'TD7' => $hr ? self::survey(TdSession::findOrFail($id)) : abort(403),
            'TD8' => ($hr || Sessions::isTrainer($u, $s = TdSession::findOrFail($id))) ? self::attendance(TdSession::findOrFail($id)) : abort(403),
            'TD9' => $hr ? self::agreement(Engagement::findOrFail($id)) : abort(403),
            'TD10' => $hr ? self::stageHours(Engagement::findOrFail($id)) : abort(403),
            'TD11' => self::idp(DevelopmentPlan::findOrFail($id), $own, $hr),
            'TD12' => self::applyPlan(TdEnrollment::findOrFail($id), $own),
            'TD13' => $hr ? self::rating(TdSession::findOrFail($id)) : abort(403),
            'TD14' => $hr ? self::quotes(TdSession::findOrFail($id)) : abort(403),
            'TDC' => self::certificate(TdCertificate::findOrFail($id), $own),
            'CBP' => $hr ? self::cbp(TdCbp::findOrFail($id)) : abort(403),
        };
        Audit::record('td.print', null, null, ['form' => $code, 'id' => $id]);
        return ['code' => $code] + $doc;
    }

    private static function qualification(TdQualification $q, $own): array
    {
        if (! $own($q->employee_id)) abort(403);
        $e = Employee::find($q->employee_id);
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الموظف', 'Employee'], self::e($e)], [['المسمى', 'Title'], $e?->title_ar ?? '—'], [['تاريخ المباشرة', 'Start date'], self::d($e?->hire_date)],
                [['النوع', 'Kind'], $q->kind === 'rotation' ? 'تدوير وظيفي داخلي' : 'برنامج تأهيل موظف جديد'], [['نسبة الإنجاز', 'Progress'], $q->progress.'%']]],
            ['type' => 'table', 'title' => ['المحطات', 'Stations'], 'head' => [['الإدارة', 'Department'], ['المدرب', 'Trainer'], ['المحاور', 'Topics'], ['من', 'From'], ['إلى', 'To'], ['الملاحظات', 'Notes'], ['التوقيع', 'Signed']],
                'rows' => array_map(fn ($s) => [\App\Models\Department::whereKey($s['department_id'] ?? 0)->value('name_ar') ?? '—', Employee::whereKey($s['trainer_id'] ?? 0)->value('name') ?? '—', $s['topics'] ?? '', $s['from'] ?? '', $s['to'] ?? '', $s['note'] ?? '', $s['signed_at'] ? ($s['signed_by'] ?? '✓').' · '.substr($s['signed_at'], 0, 10) : ''], $q->stations ?? [])],
            ['type' => 'kv', 'title' => ['تقييم نهاية البرنامج', 'Final evaluation'], 'rows' => [[['التوصية', 'Recommendation'], ['confirm' => 'التثبيت', 'extend' => 'تمديد التجربة', 'not_confirm' => 'عدم التثبيت'][$q->evaluation['recommendation'] ?? ''] ?? '—'],
                [['الملاحظات', 'Notes'], $q->evaluation['note'] ?? '—'], [['المقيّم', 'Evaluator'], $q->evaluation['by'] ?? '—']]],
            ['type' => 'sign', 'items' => [[['الموظف', 'Employee']], [['المدير المباشر', 'Line manager']], [['الموارد البشرية', 'HR']]]],
        ]];
    }

    private static function need(TdNeed $n, $own): array
    {
        if (! $own($n->employee_id) && ! Td::manages(request()->user())) abort(403);
        $o = $n->objective ?? [];
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الرقم', 'Ref'], $n->ref], [['الاحتياج', 'Need'], $n->title], [['الموظف', 'Employee'], self::e(Employee::find($n->employee_id))],
                [['الإدارة', 'Department'], \App\Models\Department::whereKey($n->department_id)->value('name_ar') ?? '—'], [['المسار', 'Path'], $n->path], [['الأولوية', 'Priority'], $n->priority.' ('.$n->score.')'],
                [['المستوى الحالي ← المطلوب', 'Current → required'], ($n->current_level ?? '—').' ← '.($n->required_level ?? '—')], [['المصدر', 'Source'], $n->source], [['الحالة', 'Status'], $n->status]]],
            ['type' => 'kv', 'title' => ['تحليل السبب والأدلة', 'Cause & evidence'], 'rows' => [[['السبب', 'Cause'], $n->cause ?? '—'], [['الأدلة', 'Evidence'], implode('، ', $n->evidence ?? []) ?: '—'], [['مبرر التجاوز', 'Override'], $n->evidence_override ?? '—']]],
            ['type' => 'kv', 'title' => ['الهدف التدريبي', 'Learning objective'], 'rows' => [[['السلوك المطلوب', 'Behaviour'], $o['behavior'] ?? $o['specific'] ?? '—'], [['الشرط', 'Condition'], $o['condition'] ?? '—'], [['المعيار', 'Criterion'], $o['criterion'] ?? $o['measurable'] ?? '—']]],
            ['type' => 'sign', 'items' => [[['رئيس الإدارة', 'Department head']], [['الموارد البشرية', 'HR']]]],
        ]];
    }

    private static function commitment(TdCommitment $c, $own): array
    {
        if (! $own($c->employee_id)) abort(403);
        $sig = $c->signature_file_id ? Signature::dataUrl(\App\Models\StoredFile::find($c->signature_file_id)) : null;
        return ['blocks' => [
            ['type' => 'text', 'text' => $c->body],
            ['type' => 'kv', 'rows' => [[['الموظف', 'Employee'], self::e(Employee::find($c->employee_id))], [['التكلفة', 'Cost'], self::n($c->cost, 2).' '.$c->currency], [['مدة الالتزام', 'Commitment'], $c->months.' شهراً'],
                [['من', 'From'], self::d($c->starts_on)], [['إلى', 'To'], self::d($c->ends_on)], [['الحالة', 'Status'], $c->status]]],
            ['type' => 'sign', 'items' => [[['توقيع الموظف', 'Employee signature'], null, $sig], [['الموارد البشرية', 'HR']]]],
        ]];
    }

    private static function plan(TdPlan $p): array
    {
        $items = $p->items()->orderBy('month')->get(); $pr = Plans::progress($p);
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['السنة', 'Year'], (string) $p->year], [['العنوان', 'Title'], $p->title], [['الموازنة', 'Budget'], self::n($p->budget).' '.$p->currency], [['المخطط', 'Planned'], self::n($pr['planned_cost']).' '.$p->currency], [['الحالة', 'Status'], $p->status]]],
            ['type' => 'table', 'head' => [['#', '#'], ['البرنامج', 'Programme'], ['المسار', 'Path'], ['الأسلوب', 'Delivery'], ['الشهر', 'Month'], ['المتدربون', 'Trainees'], ['التكلفة التقديرية', 'Est. cost'], ['الهدف', 'Objective'], ['الحالة', 'Status']],
                'rows' => $items->values()->map(fn ($i, $k) => [(string) ($k + 1), $i->title, $i->path, $i->delivery, (string) ($i->month ?? '—'), (string) ($i->trainees ?? '—'), self::n($i->estimated_cost), (string) ($i->objective ?? ''), $i->status])->all()],
            ['type' => 'sign', 'items' => [[['مدير الموارد البشرية', 'HR director']], [['المالية', 'Finance']], [['الرئيس التنفيذي', 'CEO']]]],
        ]];
    }

    private static function impact(TdImpact $i, $own, User $u): array
    {
        $en = TdEnrollment::with('session')->findOrFail($i->enrollment_id);
        if (! $own($en->employee_id) && $u->employee_id !== $i->manager_id) abort(403);
        $p = TdProgram::find($en->session->program_id); $items = $p?->impact_items ?: Td::cfg()['impact_items'];
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الموظف', 'Employee'], self::e(Employee::find($en->employee_id))], [['الدورة', 'Course'], $en->session->title], [['انتهت في', 'Ended'], self::d($en->session->ends_on)],
                [['نقطة القياس', 'Point'], $i->point.' يوماً'], [['المتوسط', 'Average'], $i->avg !== null ? $i->avg.'%' : '—'], [['الحالة', 'Status'], $i->status]]],
            ['type' => 'table', 'head' => [['البند', 'Item'], ['20%', '20%'], ['40%', '40%'], ['60%', '60%'], ['80%', '80%'], ['100%', '100%']],
                'rows' => array_map(fn ($t, $k) => [$t, ...array_map(fn ($v) => (int) (($i->ratings[$k] ?? 0) === $v) ? '✓' : '', [20, 40, 60, 80, 100])], $items, array_keys($items))],
            ['type' => 'kv', 'rows' => [[['ملاحظات المدير', 'Manager notes'], (string) ($i->notes ?? '—')]]],
            ['type' => 'sign', 'items' => [[['المدير المباشر', 'Line manager']], [['الموارد البشرية', 'HR']]]],
        ]];
    }

    private static function record(Employee $e): array
    {
        $r = Records::record($e);
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الموظف', 'Employee'], self::e($e)], [['المسمى', 'Title'], $e->title_ar ?? '—'], [['الدورات المجتازة', 'Courses passed'], (string) $r['totals']['courses']], [['إجمالي الساعات', 'Total hours'], self::n($r['totals']['hours_all'], 1)]]],
            ['type' => 'table', 'title' => ['الدورات', 'Courses'], 'head' => [['الدورة', 'Course'], ['الجهة', 'Provider'], ['من', 'From'], ['إلى', 'To'], ['الساعات', 'Hours'], ['الحضور', 'Attendance'], ['النتيجة', 'Result']],
                'rows' => collect($r['enrollments'])->map(fn ($x) => [$x['title'] ?? '', $x['provider'] ?? '—', $x['from'] ?? '', $x['to'] ?? '', self::n($x['hours'], 1), $x['attendance_pct'] !== null ? $x['attendance_pct'].'%' : '—', $x['result'] ?? $x['status']])->all()],
            ['type' => 'table', 'title' => ['تدريب سابق لاستخدام النظام', 'Training before the system'], 'head' => [['الدورة', 'Course'], ['الجهة', 'Provider'], ['من', 'From'], ['إلى', 'To'], ['الساعات', 'Hours']],
                'rows' => array_map(fn ($h) => [$h['title'], $h['provider'] ?? '—', $h['from'] ?? '', $h['to'] ?? '', self::n($h['hours'], 1)], $r['history'])],
            ['type' => 'table', 'title' => ['الشهادات', 'Certificates'], 'head' => [['الشهادة', 'Certificate'], ['الرقم', 'No.'], ['الإصدار', 'Issued'], ['الصلاحية', 'Valid to'], ['الحالة', 'Status']],
                'rows' => collect($r['certificates'])->map(fn ($c) => [$c['title'], $c['no'] ?? '—', $c['issued_on'], $c['valid_to'] ?? '—', $c['status']])->all()],
        ]];
    }

    private static function survey(TdSession $s): array
    {
        $items = Td::cfg()['survey_items']; $rows = TdSurvey::where('session_id', $s->id)->get();
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الدورة', 'Course'], $s->title.' ('.$s->ref.')'], [['التاريخ', 'Dates'], self::d($s->starts_on).' — '.self::d($s->ends_on)], [['الاستجابات', 'Responses'], (string) $rows->count()], [['المتوسط', 'Average'], self::n($rows->avg('avg'), 2).' / 5']]],
            ['type' => 'table', 'head' => [['البند', 'Item'], ['المتوسط', 'Average'], ['1', '1'], ['2', '2'], ['3', '3'], ['4', '4'], ['5', '5']],
                'rows' => array_map(fn ($t, $k) => [$t, self::n($rows->avg(fn ($r) => $r->ratings[$k] ?? null), 2), ...array_map(fn ($v) => (string) $rows->filter(fn ($r) => ($r->ratings[$k] ?? 0) === $v)->count(), [1, 2, 3, 4, 5])], $items, array_keys($items))],
            ['type' => 'text', 'title' => ['آراء المتدربين', 'Comments'], 'text' => $rows->flatMap(fn ($r) => array_filter(array_values((array) $r->answers), 'is_string'))->filter()->map(fn ($t) => '— '.$t)->implode("\n") ?: '—'],
            ['type' => 'qr', 'title' => ['رابط الاستبيان', 'Survey link'], 'text' => rtrim((string) config('app.frontend_url'), '/').'/td-survey/'.$s->survey_token],
        ]];
    }

    private static function attendance(TdSession $s): array
    {
        $ens = TdEnrollment::with('employee:id,code,name,department_id')->where('session_id', $s->id)->whereIn('status', ['confirmed', 'completed'])->orderBy('id')->get();
        $lab = ['present' => '✓', 'absent' => '✗', 'late' => 'م', 'excused' => 'ع'];
        return ['landscape' => count($s->meetings ?? []) > 6, 'blocks' => [
            ['type' => 'kv', 'rows' => [[['الدورة', 'Course'], $s->title.' ('.$s->ref.')'], [['المكان', 'Place'], (string) ($s->place ?? '—')], [['المدرب', 'Trainer'], \App\Models\TdTrainer::whereKey($s->trainer_id)->value('name') ?? '—']]],
            ['type' => 'table', 'head' => [['#', '#'], ['الموظف', 'Employee'], ...array_map(fn ($m) => [$m['date'], $m['date']], $s->meetings ?? []), ['النسبة', '%'], ['التوقيع', 'Signature']],
                'rows' => $ens->values()->map(fn ($en, $k) => [(string) ($k + 1), self::e($en->employee), ...array_map(fn ($i) => $lab[$en->attendance[$i] ?? ''] ?? '', array_keys($s->meetings ?? [])), $en->attendance_pct !== null ? $en->attendance_pct.'%' : '', ''])->all()],
            ['type' => 'text', 'text' => '✓ حاضر · ✗ غائب · م متأخر · ع معذور'],
            ['type' => 'qr', 'title' => ['رمز تسجيل الحضور', 'Check-in code'], 'text' => rtrim((string) config('app.frontend_url'), '/').'/td-checkin/'.$s->survey_token],
            ['type' => 'sign', 'items' => [[['المدرب', 'Trainer']], [['منسق التدريب', 'Training coordinator']]]],
        ]];
    }

    private static function agreement(Engagement $g): array
    {
        $x = $g->external()->first();
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['المتدرب', 'Intern'], (string) ($x?->name ?? '—')], [['الإدارة', 'Department'], \App\Models\Department::whereKey($g->department_id)->value('name_ar') ?? '—'], [['المشرف', 'Supervisor'], self::e(Employee::find($g->supervisor_id))],
                [['النوع', 'Type'], ['paid' => 'مدفوع', 'free' => 'مجاني', 'fee' => 'برسوم'][$g->stage_type ?? ''] ?? '—'], [['من', 'From'], self::d($g->starts_on)], [['إلى', 'To'], self::d($g->ends_on)], [['الساعات المطلوبة', 'Required hours'], (string) ($g->required_hours ?? '—')],
                [['الرسوم', 'Fee'], $g->stage_fee ? self::n($g->stage_fee).' '.$g->stage_fee_currency : '—']]],
            ['type' => 'text', 'text' => (string) ($g->body ?: $g->tasks)],
            ['type' => 'sign', 'items' => [[['المتدرب', 'Intern']], [['المشرف', 'Supervisor']], [['الموارد البشرية', 'HR']]]],
        ]];
    }

    private static function stageHours(Engagement $g): array
    {
        $logs = VolunteerLog::where('engagement_id', $g->id)->orderBy('date')->get(); $ev = $g->stage_eval ?? $g->evaluation ?? [];
        $approved = (float) $logs->where('status', 'approved')->sum('hours');
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['المتدرب', 'Intern'], (string) ($g->external()->value('name') ?? '—')], [['الساعات المعتمدة', 'Approved hours'], self::n($approved, 1)], [['المطلوب', 'Required'], (string) ($g->required_hours ?? '—')],
                [['نسبة الإنجاز', 'Completion'], $g->required_hours ? (int) round($approved * 100 / max(1, (float) $g->required_hours)).'%' : '—']]],
            ['type' => 'table', 'head' => [['التاريخ', 'Date'], ['الساعات', 'Hours'], ['المهمة', 'Task'], ['الحالة', 'Status']], 'rows' => $logs->map(fn ($l) => [self::d($l->date), self::n($l->hours, 1), (string) $l->activity, $l->status])->all()],
            ['type' => 'kv', 'title' => ['التقييم', 'Evaluation'], 'rows' => collect($ev)->map(fn ($v, $k) => [[(string) $k, (string) $k], is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) $v])->values()->all()],
            ['type' => 'sign', 'items' => [[['المشرف', 'Supervisor']], [['الموارد البشرية', 'HR']]]],
        ]];
    }

    private static function idp(DevelopmentPlan $p, $own, bool $hr): array
    {
        if (! $hr && ! ($p->employee_id && $own($p->employee_id))) abort(403);
        $acts = $p->activities()->orderBy('due_on')->get(); $mix = \App\Services\Development\DevelopmentPlans::mix($acts);
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الخطة', 'Plan'], $p->title], [['الموظف', 'Employee'], self::e($p->employee_id ? Employee::find($p->employee_id) : null)], [['الفجوات', 'Gaps'], (string) ($p->gaps ?? '—')],
                [['المراجعة القادمة', 'Next review'], self::d($p->next_review)], [['مزيج التعلم 70-20-10', 'Learning mix'], "{$mix['70']} / {$mix['20']} / {$mix['10']}"]]],
            ['type' => 'table', 'title' => ['الأهداف', 'Goals'], 'head' => [['الهدف', 'Goal'], ['تحقق', 'Done']], 'rows' => array_map(fn ($g) => [$g['text'] ?? '', ! empty($g['done']) ? '✓' : ''], $p->goals ?? [])],
            ['type' => 'table', 'title' => ['الأنشطة', 'Activities'], 'head' => [['النشاط', 'Activity'], ['النوع', 'Kind'], ['الاستحقاق', 'Due'], ['الإنجاز', 'Progress'], ['الحالة', 'Status']],
                'rows' => $acts->map(fn ($a) => [$a->title, $a->kind, self::d($a->due_on), $a->progress.'%', $a->status])->all()],
            ['type' => 'sign', 'items' => [[['الموظف', 'Employee']], [['المدير المباشر', 'Line manager']], [['الموارد البشرية', 'HR']]]],
        ]];
    }

    private static function applyPlan(TdEnrollment $en, $own): array
    {
        if (! $own($en->employee_id)) abort(403);
        $s = TdSession::find($en->session_id); $ap = $en->apply_plan ?? [];
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الموظف', 'Employee'], self::e(Employee::find($en->employee_id))], [['الدورة', 'Course'], (string) $s?->title], [['انتهت في', 'Ended'], self::d($s?->ends_on)]]],
            ['type' => 'table', 'title' => ['ما سأطبقه', 'What I will apply'], 'head' => [['الإجراء', 'Action'], ['الموعد', 'When'], ['المؤشر', 'Measure']],
                'rows' => array_map(fn ($i) => [$i['text'] ?? $i['title'] ?? '', $i['due'] ?? $i['when'] ?? '', $i['measure'] ?? ''], $ap['items'] ?? (array_is_list($ap) ? $ap : []))],
            ['type' => 'kv', 'rows' => [[['الدعم المطلوب', 'Support needed'], (string) ($ap['support'] ?? '—')]]],
            ['type' => 'table', 'title' => ['المتابعة 30-60-90', 'Follow-up 30-60-90'], 'head' => [['النقطة', 'Point'], ['الاستحقاق', 'Due'], ['النتيجة', 'Result'], ['الحالة', 'Status']],
                'rows' => TdImpact::where('enrollment_id', $en->id)->orderBy('point')->get()->map(fn ($i) => [$i->point.' يوماً', self::d($i->due_on), $i->avg !== null ? $i->avg.'%' : '—', $i->status])->all()],
            ['type' => 'sign', 'items' => [[['الموظف', 'Employee']], [['المدير المباشر', 'Line manager']]]],
        ]];
    }

    private static function rating(TdSession $s): array
    {
        $r = TdRating::where('session_id', $s->id)->first(); $w = Td::cfg()['trainer_weights'];
        $lab = ['content' => 'المحتوى', 'trainer' => 'أداء المدرب', 'punctuality' => 'الالتزام بالمواعيد', 'facilities' => 'التجهيزات', 'results' => 'نتائج المتدربين', 'value' => 'القيمة مقابل التكلفة'];
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الدورة', 'Course'], $s->title.' ('.$s->ref.')'], [['المدرب', 'Trainer'], \App\Models\TdTrainer::whereKey($s->trainer_id)->value('name') ?? '—'], [['الجهة', 'Centre'], \App\Models\TdCenter::whereKey($s->center_id)->value('name') ?? '—'],
                [['المتوسط', 'Average'], $r ? $r->avg.' / 5' : '—'], [['الدرجة الموزونة', 'Weighted score'], $r ? TdReports::weighted($r->scores).'%' : '—'], [['التوصية', 'Recommendation'], ['approve' => 'اعتماد', 'watch' => 'مراقبة', 'ban' => 'حظر'][$r?->recommendation ?? ''] ?? '—']]],
            ['type' => 'table', 'head' => [['المعيار', 'Criterion'], ['الوزن', 'Weight'], ['التقدير (1-5)', 'Score (1-5)']], 'rows' => array_map(fn ($k) => [$lab[$k] ?? $k, ($w[$k] ?? 0).'%', (string) ($r?->scores[$k] ?? '—')], array_keys($lab))],
            ['type' => 'kv', 'rows' => [[['ملاحظات', 'Notes'], (string) ($r?->note ?? '—')]]],
            ['type' => 'sign', 'items' => [[['منسق التدريب', 'Training coordinator']], [['مدير الموارد البشرية', 'HR director']]]],
        ]];
    }

    private static function quotes(TdSession $s): array
    {
        $crit = Td::cfg()['quote_criteria']; $rank = collect(TdExtras::quoteRank($s))->keyBy('index');
        return ['blocks' => [
            ['type' => 'kv', 'rows' => [[['الدورة', 'Course'], $s->title.' ('.$s->ref.')'], [['التاريخ', 'Dates'], self::d($s->starts_on).' — '.self::d($s->ends_on)], [['العرض المختار', 'Chosen quote'], isset($s->quotes[$s->quote_choice ?? -1]) ? ($s->quotes[$s->quote_choice]['center'] ?? '') : '—'],
                [['حالة الاعتماد', 'Approval'], ['pending' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'rejected' => 'مرفوض'][$s->quote_status ?? ''] ?? '—'], [['المبرر', 'Justification'], (string) ($s->quote_reason ?? '—')]]],
            ['type' => 'table', 'head' => [['الجهة', 'Provider'], ['السعر', 'Price'], ['المدة', 'Duration'], ...array_map(fn ($c) => [$c['ar'].' ('.$c['weight'].'%)', $c['en'].' ('.$c['weight'].'%)'], $crit), ['الدرجة', 'Score']],
                'rows' => collect($s->quotes ?? [])->map(fn ($q, $i) => [($q['center'] ?? '').($i === $s->quote_choice ? ' ★' : ''), self::n($q['price'] ?? null).' '.($q['currency'] ?? $s->currency), (string) ($q['duration'] ?? ''),
                    ...array_map(fn ($c) => (string) ($rank[$i]['parts'][$c['key']] ?? ''), $crit), (string) ($rank[$i]['score'] ?? '')])->values()->all()],
            ['type' => 'sign', 'items' => [[['منسق التدريب', 'Training coordinator']], [['مدير الموارد البشرية', 'HR director']], [['المعتمد', 'Approver']]]],
        ]];
    }

    private static function certificate(TdCertificate $c, $own): array
    {
        if (! $own($c->employee_id)) abort(403);
        $cfg = Td::cfg(); $t = $cfg['cert_templates'][$c->type] ?? $cfg['cert_templates']['completion'];
        $e = Employee::find($c->employee_id); $co = Tenant::company();
        $body = strtr($t['body_ar'], [':company' => $co?->name_ar ?? '', ':name' => $e?->name ?? '', ':course' => $c->title, ':date' => self::d($c->issued_on)]);
        $bodyEn = strtr($t['body_en'], [':company' => $co?->name_en ?? '', ':name' => $e?->name_en ?? '', ':course' => $c->title, ':date' => self::d($c->issued_on)]);
        $url = rtrim((string) config('app.frontend_url'), '/').'/td-verify/'.$c->code;
        return ['certificate' => true, 'title_ar' => $t['ar'], 'title_en' => $t['en'], 'blocks' => [
            ['type' => 'text', 'text' => $body], ['type' => 'text', 'text' => $bodyEn, 'ltr' => true],
            ['type' => 'kv', 'rows' => [[['رقم الشهادة', 'Certificate no.'], (string) $c->no], [['تاريخ الإصدار', 'Issued'], self::d($c->issued_on)], [['صالحة حتى', 'Valid to'], self::d($c->valid_to)]]],
            ['type' => 'qr', 'title' => ['التحقق من الشهادة', 'Verify'], 'text' => $url],
            ['type' => 'sign', 'items' => [[[$cfg['cert_signer'], $cfg['cert_signer']]]]],
        ], 'linkedin' => $cfg['linkedin_share'] ? 'https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME&name='.rawurlencode($c->title).'&organizationName='.rawurlencode($co?->name_en ?? '')
            .'&issueYear='.$c->issued_on->year.'&issueMonth='.$c->issued_on->month.'&certId='.rawurlencode((string) $c->no).'&certUrl='.rawurlencode($url) : null];
    }

    /** الإطار المنطقي لخطة بناء القدرات: هدف ← قدرات ← مبادرات بمؤشراتها ومسؤوليها */
    private static function cbp(TdCbp $c): array
    {
        $gaps = Plans::cbpGaps($c); $rows = [];
        foreach ($c->goals ?? [] as $g) {
            $caps = collect($g['capacities'] ?? [])->map(fn ($x, $k) => (\App\Models\Competency::whereKey($x['competency_id'] ?? 0)->value('name_ar') ?? '').' ≥ '.($x['level'] ?? '').' × '.($x['count'] ?? 0).' (فجوة '.($gaps[$g['id']][$k]['gap'] ?? 0).')'.(! empty($x['by']) ? ' — '.$x['by'] : ''))->implode("\n");
            foreach ($g['initiatives'] ?: [[]] as $i) $rows[] = [$g['title'] ?? '', $caps, $i['title'] ?? '', $i['kind'] ?? '', $i['owner'] ?? '', $i['kpi'] ?? '', $i['due'] ?? '', isset($i['progress']) ? $i['progress'].'%' : ''];
        }
        return ['landscape' => true, 'blocks' => [
            ['type' => 'kv', 'rows' => [[['الخطة', 'Plan'], $c->title], [['المدة', 'Period'], self::d($c->starts_on).' — '.self::d($c->ends_on)], [['المسؤول', 'Owner'], (string) ($c->owner ?? '—')]]],
            ['type' => 'table', 'head' => [['الهدف', 'Goal'], ['القدرات المطلوبة', 'Capacities'], ['المبادرة', 'Initiative'], ['النوع', 'Kind'], ['المسؤول', 'Owner'], ['المؤشر', 'KPI'], ['الموعد', 'Due'], ['الإنجاز', 'Progress']], 'rows' => $rows],
        ]];
    }
}
