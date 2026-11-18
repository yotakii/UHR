<?php

namespace App\Services\Training;

use App\Exceptions\ApiException;
use App\Models\Competency;
use App\Models\Employee;
use App\Models\Engagement;
use App\Models\TdCenter;
use App\Models\TdEnrollment;
use App\Models\TdImpact;
use App\Models\TdNeed;
use App\Models\TdPlan;
use App\Models\TdProgram;
use App\Models\TdRating;
use App\Models\TdSession;
use App\Models\TdSurvey;
use App\Models\TdTrainer;
use App\Models\User;
use App\Support\L;

/**
 * تقارير الإكمال: بطاقة أداء المدرب الموزونة (POL-16)، مطابقة التكلفة المخططة والمعروضة والفعلية (POL-19)، بطاقة أثر البرنامج
 * بمستويات كيركباتريك الأربعة، إيراد التدريب العملي برسوم، استيراد الاحتياجات من Excel، وخلاصة آراء المتدربين.
 */
class TdReports
{
    /** الدرجة الموزونة من 100 لتقييم F-13 */
    public static function weighted(?array $scores): ?float
    {
        if (! $scores) return null;
        $w = Td::cfg()['trainer_weights']; $sum = 0; $tw = 0;
        foreach ($w as $k => $x) if (isset($scores[$k])) { $sum += (float) $scores[$k] * (float) $x; $tw += (float) $x; }
        return $tw ? round($sum * 20 / $tw, 1) : null;
    }

    public static function scorecard(): array
    {
        $rows = [];
        foreach ([['trainer', TdTrainer::class, 'trainer_id'], ['center', TdCenter::class, 'center_id']] as [$kind, $cls, $col]) foreach ($cls::orderBy('name')->get() as $p) {
            $ids = TdSession::where($col, $p->id)->pluck('id');
            if ($ids->isEmpty()) continue;
            $ratings = TdRating::whereIn('session_id', $ids)->get();
            $w = $ratings->map(fn ($r) => self::weighted($r->scores))->filter();
            $l1 = TdSurvey::whereIn('session_id', $ids)->avg('avg');
            $results = TdEnrollment::whereIn('session_id', $ids)->whereNotNull('post_score')->avg('post_score');
            $pass = TdEnrollment::whereIn('session_id', $ids)->where('status', 'completed');
            $rows[] = ['kind' => $kind, 'id' => $p->id, 'name' => $p->name, 'status' => $p->status, 'sessions' => $ids->count(), 'weighted' => $w->isNotEmpty() ? round($w->avg(), 1) : null,
                'l1' => $l1 !== null ? round((float) $l1, 2) : null, 'results' => $results !== null ? round((float) $results, 1) : null,
                'pass_rate' => ($n = $pass->count()) ? round($pass->clone()->where('result', 'passed')->count() * 100 / $n, 1) : null];
        }
        usort($rows, fn ($a, $b) => ($b['weighted'] ?? -1) <=> ($a['weighted'] ?? -1));
        return $rows;
    }

    /** POL-19: لكل تنفيذ — التقديري في الخطة، العرض المعتمد، والفعلي حسب البند — والفرق */
    public static function costs(int $year, ?string $currency = null): array
    {
        $plan = TdPlan::where('year', $year)->where('scope', 'company')->first();
        $cur = $currency ?: ($plan?->currency ?? 'SYP');
        $rows = TdSession::with('program:id,code,title')->whereYear('starts_on', $year)->whereNotIn('status', ['cancelled'])->orderBy('starts_on')->get()->map(function ($s) use ($cur) {
            $on = $s->starts_on->toDateString();
            $planned = $s->plan_item_id ? (float) \App\Models\TdPlanItem::whereKey($s->plan_item_id)->value('estimated_cost') : null;
            $quote = isset($s->quotes[$s->quote_choice ?? -1]) ? Td::convert((float) ($s->quotes[$s->quote_choice]['price'] ?? 0), $s->quotes[$s->quote_choice]['currency'] ?? $s->currency, $cur, $on) : null;
            $by = collect($s->costs ?? [])->groupBy('type')->map(fn ($g) => round((float) $g->sum(fn ($c) => Td::convert((float) ($c['amount'] ?? 0), $c['currency'] ?? $s->currency, $cur, $c['date'] ?? $on)), 2));
            $actual = (float) $by->sum();
            $base = $quote ?? $planned;
            return ['id' => $s->id, 'ref' => $s->ref, 'title' => $s->title, 'status' => $s->status, 'planned' => $planned, 'quote' => $quote !== null ? round($quote, 2) : null, 'actual' => round($actual, 2), 'by_type' => $by,
                'variance' => $base !== null ? round($actual - $base, 2) : null, 'variance_pct' => $base ? round(($actual - $base) * 100 / $base, 1) : null,
                'trainees' => TdEnrollment::where('session_id', $s->id)->whereIn('status', ['confirmed', 'completed'])->count()];
        });
        return ['currency' => $cur, 'rows' => $rows->values(), 'totals' => ['planned' => round((float) $rows->sum('planned'), 2), 'quote' => round((float) $rows->sum('quote'), 2), 'actual' => round((float) $rows->sum('actual'), 2),
            'budget' => $plan?->budget, 'over' => $rows->filter(fn ($r) => ($r['variance'] ?? 0) > 0)->count()]];
    }

    /** بطاقة أثر برنامج: L1 رضا، L2 مكسب المعرفة، L3 تطبيق السلوك (F-05)، L4 مؤشر الأداء والعائد */
    public static function programImpact(TdProgram $p): array
    {
        $sessions = TdSession::where('program_id', $p->id)->whereNotIn('status', ['cancelled'])->get(); $ids = $sessions->pluck('id');
        $ens = TdEnrollment::whereIn('session_id', $ids)->where('status', 'completed')->get();
        $gain = $ens->filter(fn ($e) => $e->pre_score !== null && $e->post_score !== null);
        $beh = TdImpact::whereIn('enrollment_id', $ens->pluck('id'))->where('kind', 'behavior')->where('status', 'done')->get();
        $kpi = TdImpact::whereIn('enrollment_id', $ens->pluck('id'))->where('kind', 'kpi')->where('status', 'done')->avg('avg');
        $roi = $sessions->map(fn ($s) => $s->roi['pct'] ?? null)->filter();
        $cost = (float) $sessions->sum(fn ($s) => Sessions::totalCost($s));
        return ['program' => ['id' => $p->id, 'code' => $p->code, 'title' => $p->title], 'sessions' => $sessions->count(), 'trainees' => $ens->count(), 'passed' => $ens->where('result', 'passed')->count(), 'cost' => round($cost, 2),
            'cost_per_trainee' => $ens->count() ? round($cost / $ens->count(), 2) : null,
            'l1' => ($v = TdSurvey::whereIn('session_id', $ids)->avg('avg')) !== null ? round((float) $v, 2) : null,
            'l2' => ['pre' => $gain->isNotEmpty() ? round($gain->avg('pre_score'), 1) : null, 'post' => $gain->isNotEmpty() ? round($gain->avg('post_score'), 1) : null, 'gain' => $gain->isNotEmpty() ? round($gain->avg(fn ($e) => $e->post_score - $e->pre_score), 1) : null],
            'l3' => ['avg' => $beh->isNotEmpty() ? round($beh->avg('avg'), 1) : null, 'n' => $beh->count(), 'pass' => $beh->filter(fn ($x) => $x->avg >= (float) Td::cfg()['impact_pass'])->count()],
            'l4' => ['kpi_change' => $kpi !== null ? round((float) $kpi, 1) : null, 'roi' => $roi->isNotEmpty() ? round($roi->avg(), 1) : null]];
    }

    /** الخطة المجمّعة للمجموعة القابضة: خطة كل شركة (الأم والتابعة) وتقدّمها بعملة الأم */
    public static function groupPlan(int $year): array
    {
        $me = \App\Support\Tenant::company(); if (! $me) return ['companies' => [], 'totals' => []];
        $cur = TdPlan::where('year', $year)->where('scope', 'company')->value('currency') ?? 'USD';
        $rows = [];
        foreach (\App\Models\Company::where('id', $me->id)->orWhere('parent_id', $me->id)->orderBy('id')->get() as $c) $rows[] = \App\Support\Tenant::run($c, function () use ($c, $year, $cur) {
            $p = TdPlan::where('year', $year)->where('scope', 'company')->first();
            $pr = $p ? Plans::progress($p) : null;
            $conv = fn ($v) => $p ? round(Td::convert((float) $v, $p->currency, $cur), 2) : 0;
            return ['company' => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en], 'plan' => $p ? ['id' => $p->id, 'status' => $p->status, 'currency' => $p->currency] : null,
                'budget' => $p ? $conv($p->budget) : 0, 'planned' => $pr ? $conv($pr['planned_cost']) : 0, 'actual' => $pr ? $conv($pr['actual_cost']) : 0, 'items' => $pr['items'] ?? 0, 'done' => $pr['done'] ?? 0,
                'trained' => $pr['trained'] ?? 0, 'hours' => $pr['hours'] ?? 0, 'headcount' => Employee::current()->count()];
        });
        $sum = fn ($k) => round(array_sum(array_column($rows, $k)), 2);
        return ['currency' => $cur, 'companies' => $rows, 'totals' => ['budget' => $sum('budget'), 'planned' => $sum('planned'), 'actual' => $sum('actual'), 'items' => $sum('items'), 'done' => $sum('done'), 'trained' => $sum('trained'), 'hours' => $sum('hours'), 'headcount' => $sum('headcount')]];
    }

    /** إيراد التدريب العملي برسوم (POL-22) */
    public static function stageRevenue(int $year): array
    {
        $rows = Engagement::with('external:id,name')->where('stage_type', 'fee')->whereYear('starts_on', $year)->get()
            ->map(fn ($g) => ['id' => $g->id, 'ref' => $g->ref, 'name' => $g->external?->name, 'from' => $g->starts_on?->toDateString(), 'to' => $g->ends_on?->toDateString(), 'fee' => (float) $g->stage_fee, 'currency' => $g->stage_fee_currency,
                'paid' => (bool) $g->stage_fee_paid_at, 'paid_at' => $g->stage_fee_paid_at, 'ref_no' => $g->stage_fee_ref]);
        return ['rows' => $rows->values(), 'by_currency' => $rows->groupBy('currency')->map(fn ($g) => ['due' => round($g->sum('fee'), 2), 'paid' => round($g->where('paid', true)->sum('fee'), 2)])];
    }

    // ———————————————— استيراد الاحتياجات ————————————————

    public const NEED_COLS = ['employee_code', 'title', 'competency', 'path', 'priority', 'cause', 'behavior', 'condition', 'criterion', 'preferred_date', 'estimated_cost', 'note'];

    public static function needsTemplate(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tdn').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer(); $w->openToFile($path);
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(self::NEED_COLS));
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['UHR-1010', 'التفاوض مع الموردين', Competency::where('active', true)->value('name_ar') ?? '', 'training', 'normal', 'knowledge', 'يتفاوض على عقود التوريد', 'باستخدام قائمة الأسعار المعتمدة', 'خفض التكلفة 5%', '2027-03', '500', '']));
        $w->addNewSheetAndMakeItCurrent()->setName('codes');
        foreach ([['path', implode(' | ', Td::PATHS)], ['priority', 'low | normal | high | urgent'], ['cause', implode(' | ', Td::CAUSES)]] as $r) $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($r));
        foreach (Competency::where('active', true)->orderBy('sort')->get(['name_ar']) as $c) $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['competency', $c->name_ar]));
        $w->close();
        return $path;
    }

    /** @return array{rows: array, ok: int, bad: int} */
    public static function needsPreview(string $file, User $u): array
    {
        $r = new \OpenSpout\Reader\XLSX\Reader(); $r->open($file);
        $out = []; $head = null;
        foreach ($r->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $row) {
                $vals = array_map(fn ($c) => is_object($c->getValue()) ? $c->getValue()->format('Y-m-d') : trim((string) $c->getValue()), $row->getCells());
                if ($head === null) { $head = array_map('strtolower', $vals); continue; }
                if (! array_filter($vals)) continue;
                $v = array_combine(array_slice($head, 0, count($vals)), array_slice($vals, 0, count($head))) + array_fill_keys(self::NEED_COLS, '');
                $err = [];
                $e = $v['employee_code'] ? Td::scoped($u)->where('code', $v['employee_code'])->first() : null;
                if ($v['employee_code'] && ! $e) $err[] = L::t('رقم موظف غير معروف أو خارج نطاقك', 'Unknown or out-of-scope employee code');
                if (mb_strlen($v['title']) < 3) $err[] = L::t('عنوان الاحتياج مطلوب', 'Title is required');
                $comp = $v['competency'] ? Competency::where('name_ar', $v['competency'])->orWhere('name_en', $v['competency'])->value('id') : null;
                if ($v['competency'] && ! $comp) $err[] = L::t('كفاءة غير معروفة', 'Unknown competency');
                if ($v['path'] && ! in_array($v['path'], Td::PATHS, true)) $err[] = L::t('مسار غير صحيح', 'Invalid path');
                if ($v['priority'] && ! in_array($v['priority'], ['low', 'normal', 'high', 'urgent'], true)) $err[] = L::t('أولوية غير صحيحة', 'Invalid priority');
                if ($v['cause'] && ! in_array($v['cause'], Td::CAUSES, true)) $err[] = L::t('سبب غير صحيح', 'Invalid cause');
                if ($v['estimated_cost'] !== '' && ! is_numeric($v['estimated_cost'])) $err[] = L::t('تكلفة غير رقمية', 'Cost is not a number');
                $out[] = ['row' => $i, 'data' => ['employee_id' => $e?->id, 'employee' => $e ? $e->name : null, 'title' => $v['title'], 'competency_id' => $comp, 'path' => $v['path'] ?: 'training', 'priority' => $v['priority'] ?: null,
                    'cause' => $v['cause'] ?: null, 'objective' => array_filter(['behavior' => $v['behavior'], 'condition' => $v['condition'], 'criterion' => $v['criterion']]) ?: null,
                    'preferred_date' => $v['preferred_date'] ?: null, 'estimated_cost' => $v['estimated_cost'] !== '' ? (float) $v['estimated_cost'] : null, 'note' => $v['note'] ?: null], 'errors' => $err];
            }
            break;
        }
        $r->close();
        return ['rows' => $out, 'ok' => count(array_filter($out, fn ($x) => ! $x['errors'])), 'bad' => count(array_filter($out, fn ($x) => (bool) $x['errors']))];
    }

    public static function needsImport(string $file, User $u): array
    {
        $p = self::needsPreview($file, $u);
        if ($p['bad']) throw new ApiException(L::t("في الملف {$p['bad']} سطر خاطئ — صحّحها ثم أعد الاستيراد.", "{$p['bad']} row(s) have errors — fix them and import again."), 'import_errors', 422);
        $needs = app(Needs::class); $n = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($p, $u, $needs, &$n) {
            foreach ($p['rows'] as $r) { $needs->save(array_filter($r['data'], fn ($v) => $v !== null) + ['source' => 'request', 'status' => 'submitted'], $u); $n++; }
        });
        \App\Support\Audit::record('td_needs.imported', null, null, ['count' => $n]);
        return ['imported' => $n];
    }

    // ———————————————— خلاصة آراء المتدربين (اختياري، النموذج المحلي) ————————————————

    public static function summarize(TdSession $s): array
    {
        $texts = TdSurvey::where('session_id', $s->id)->get()->flatMap(fn ($r) => array_filter(array_values((array) $r->answers), fn ($x) => is_string($x) && mb_strlen(trim($x)) > 3))->take(60)->values();
        if ($texts->isEmpty()) throw new ApiException(L::t('لا توجد آراء مكتوبة لتلخيصها.', 'There are no written comments to summarise.'), 'no_comments', 422);
        $ai = app(\App\Services\Ai\AiClient::class);
        if (! $ai->available()) throw new ApiException(L::t('خدمة التلخيص غير متاحة الآن.', 'The summary service is not available now.'), 'summary_unavailable', 503);
        $out = $ai->json('لخّص آراء المتدربين عن دورة تدريبية بالعربية الفصحى في نقاط قصيرة: نقاط القوة، ونقاط التحسين، واقتراحات. لا تذكر أسماء.',
            "آراء المتدربين في دورة «{$s->title}»:\n- ".$texts->implode("\n- "),
            ['type' => 'object', 'properties' => ['strengths' => ['type' => 'array', 'items' => ['type' => 'string']], 'improve' => ['type' => 'array', 'items' => ['type' => 'string']], 'suggestions' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['strengths', 'improve', 'suggestions']]);
        $sum = ['strengths' => array_slice($out['strengths'] ?? [], 0, 5), 'improve' => array_slice($out['improve'] ?? [], 0, 5), 'suggestions' => array_slice($out['suggestions'] ?? [], 0, 5), 'n' => $texts->count(), 'at' => now()->toIso8601String()];
        $s->update(['ai_summary' => $sum]);
        return $sum;
    }
}
