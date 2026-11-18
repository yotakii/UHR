<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Engagement;
use App\Models\TdEnrollment;
use App\Models\TdHistory;
use App\Models\TdProgram;
use App\Models\TdRoom;
use App\Models\TdSession;
use App\Models\User;
use App\Services\Training\Td;
use App\Services\Training\TdExtras;
use App\Services\Training\TdPrint;
use App\Services\Training\TdReports;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** الدفعة 27 — إكمال موديول التدريب: القاعات، اعتماد العروض، التأجيل وإعادة الفتح والاستبعاد، السجل السابق، الاستيراد، التقارير، الطباعة، الروابط */
class TrainingExtraController extends Controller
{
    public function __construct(private TdExtras $x) {}

    private function hr(Request $r): void { if (! Td::manages($r->user())) abort(403); }

    // ———————————————— القاعات ————————————————

    public function rooms(Request $r): JsonResponse
    {
        return response()->json(['data' => TdRoom::orderBy('name')->get()->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'name_en' => $x->name_en, 'location_id' => $x->location_id, 'capacity' => $x->capacity, 'equipment' => $x->equipment ?? [], 'active' => $x->active])]);
    }

    public function saveRoom(Request $r, ?int $id = null): JsonResponse
    {
        $v = $r->validate(['name' => ['required', 'string', 'max:120'], 'name_en' => ['nullable', 'string', 'max:120'], 'location_id' => ['nullable', 'integer'], 'capacity' => ['required', 'integer', 'between:1,1000'],
            'equipment' => ['nullable', 'array'], 'equipment.*' => ['string', 'max:60'], 'active' => ['nullable', 'boolean']]);
        $this->x->saveRoom($v, $r->user(), $id ? TdRoom::findOrFail($id) : null);
        return $this->rooms($r);
    }

    // ———————————————— التنفيذ ————————————————

    public function quote(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['choice' => ['required', 'integer', 'min:0'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $a = $this->x->submitQuote(TdSession::findOrFail($id), $v['choice'], $v['reason'] ?? null, $r->user());
        return response()->json(['status' => $a->status]);
    }

    public function postpone(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(['reason' => ['required', 'string', 'max:1000'], 'meetings' => ['nullable', 'array', 'max:60'], 'meetings.*.date' => ['required', 'date'], 'meetings.*.from' => ['nullable', 'date_format:H:i'], 'meetings.*.to' => ['nullable', 'date_format:H:i']]);
        $s = $this->x->postpone(TdSession::findOrFail($id), $v['meetings'] ?? null, $v['reason'], $r->user());
        return response()->json(['status' => $s->status, 'starts_on' => $s->starts_on->toDateString()]);
    }

    public function reopen(Request $r, int $id): JsonResponse
    {
        $s = $this->x->reopen(TdSession::findOrFail($id), (string) $r->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $r->user());
        return response()->json(['status' => $s->status]);
    }

    public function exclude(Request $r, int $id): JsonResponse
    {
        $en = $this->x->exclude(TdEnrollment::findOrFail($id), (string) $r->validate(['reason' => ['required', 'string', 'max:1000']])['reason'], $r->user());
        return response()->json(['status' => $en->status]);
    }

    public function summarize(Request $r, int $id): JsonResponse
    {
        $this->hr($r);
        return response()->json(['data' => TdReports::summarize(TdSession::findOrFail($id))]);
    }

    // ———————————————— السجل السابق للنظام ————————————————

    public function history(Request $r): JsonResponse
    {
        $e = Td::scoped($r->user())->findOrFail((int) $r->query('employee'));
        return response()->json(['data' => TdExtras::historyRows($e)]);
    }

    public function saveHistory(Request $r, ?int $id = null): JsonResponse
    {
        $v = $r->validate(['employee_id' => [$id ? 'nullable' : 'required', 'integer'], 'title' => ['required', 'string', 'max:200'], 'provider' => ['nullable', 'string', 'max:160'], 'place' => ['nullable', 'string', 'max:160'],
            'kind' => ['nullable', 'in:internal,external'], 'from_on' => ['nullable', 'date'], 'to_on' => ['nullable', 'date', 'after_or_equal:from_on'], 'hours' => ['nullable', 'numeric', 'between:0,5000'],
            'cost' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'file_id' => ['nullable', 'integer'], 'note' => ['nullable', 'string', 'max:1000']]);
        $h = $this->x->saveHistory($v, $r->user(), $id ? TdHistory::findOrFail($id) : null);
        return response()->json(['data' => TdExtras::historyRows(\App\Models\Employee::findOrFail($h->employee_id))]);
    }

    public function deleteHistory(Request $r, int $id): JsonResponse
    {
        $this->hr($r);
        $h = TdHistory::findOrFail($id); Td::scoped($r->user())->findOrFail($h->employee_id);
        $e = $h->employee_id; $h->delete();
        return response()->json(['data' => TdExtras::historyRows(\App\Models\Employee::findOrFail($e))]);
    }

    // ———————————————— استيراد الاحتياجات ————————————————

    public function needsTemplate(Request $r)
    {
        $this->hr($r);
        return response()->download(TdReports::needsTemplate(), 'training-needs.xlsx')->deleteFileAfterSend();
    }

    public function needsPreview(Request $r): JsonResponse
    {
        $this->hr($r);
        $f = $r->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120']])['file'];
        return response()->json(TdReports::needsPreview($f->getRealPath(), $r->user()));
    }

    public function needsImport(Request $r): JsonResponse
    {
        $this->hr($r);
        $f = $r->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:5120']])['file'];
        return response()->json(TdReports::needsImport($f->getRealPath(), $r->user()));
    }

    // ———————————————— التقارير ————————————————

    public function reports(Request $r, string $kind): JsonResponse
    {
        $this->hr($r);
        $year = (int) $r->query('year', now()->year);
        return response()->json(['data' => match ($kind) {
            'scorecard' => TdReports::scorecard(),
            'costs' => TdReports::costs($year, $r->query('currency')),
            'impact' => TdReports::programImpact(TdProgram::findOrFail((int) $r->query('program'))),
            'impacts' => TdProgram::where('active', true)->orderBy('code')->get()->map(fn ($p) => TdReports::programImpact($p))->filter(fn ($x) => $x['sessions'] > 0)->values(),
            'stage' => TdReports::stageRevenue($year),
            'group' => TdReports::groupPlan($year),
            default => abort(404),
        }]);
    }

    /** تصدير تقرير بـ Excel */
    public function export(Request $r, string $kind)
    {
        $this->hr($r);
        $year = (int) $r->query('year', now()->year);
        [$head, $rows] = match ($kind) {
            'costs' => [['ref', 'title', 'status', 'planned', 'quote', 'actual', 'variance', 'variance_pct', 'trainees'], collect(TdReports::costs($year)['rows'])->map(fn ($x) => [$x['ref'], $x['title'], $x['status'], $x['planned'], $x['quote'], $x['actual'], $x['variance'], $x['variance_pct'], $x['trainees']])],
            'scorecard' => [['kind', 'name', 'status', 'sessions', 'weighted', 'l1', 'results', 'pass_rate'], collect(TdReports::scorecard())->map(fn ($x) => [$x['kind'], $x['name'], $x['status'], $x['sessions'], $x['weighted'], $x['l1'], $x['results'], $x['pass_rate']])],
            'impacts' => [['code', 'title', 'sessions', 'trainees', 'passed', 'cost', 'l1', 'l2_gain', 'l3', 'l4_kpi', 'roi'], TdProgram::orderBy('code')->get()->map(fn ($p) => TdReports::programImpact($p))->filter(fn ($x) => $x['sessions'] > 0)
                ->map(fn ($x) => [$x['program']['code'], $x['program']['title'], $x['sessions'], $x['trainees'], $x['passed'], $x['cost'], $x['l1'], $x['l2']['gain'], $x['l3']['avg'], $x['l4']['kpi_change'], $x['l4']['roi']])],
            'summary' => [['key', 'value'], collect(\App\Services\Training\Records::reports($year)['kpis'])->map(fn ($v, $k) => [$k, $v])->values()],
            default => abort(404),
        };
        $path = tempnam(sys_get_temp_dir(), 'tdr').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer(); $w->openToFile($path);
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues($head));
        foreach ($rows as $row) $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v, $row)));
        $w->close();
        Audit::record('td_report.exported', null, null, ['kind' => $kind, 'year' => $year]);
        return response()->download($path, "training-{$kind}-{$year}.xlsx")->deleteFileAfterSend();
    }

    // ———————————————— الطباعة ————————————————

    public function print(Request $r, string $code, int $id): JsonResponse
    {
        $doc = TdPrint::build(strtoupper($code), $id, $r->user());
        return response()->json(['data' => $doc]);
    }

    // ———————————————— التدريب العملي برسوم ————————————————

    public function stageFee(Request $r, int $id): JsonResponse
    {
        $this->hr($r);
        $v = $r->validate(['fee' => ['nullable', 'numeric', 'min:0'], 'currency' => ['nullable', 'string', 'size:3'], 'paid' => ['nullable', 'boolean'], 'ref' => ['nullable', 'string', 'max:60']]);
        $g = Engagement::findOrFail($id);
        $g->update(['stage_fee' => $v['fee'] ?? $g->stage_fee, 'stage_fee_currency' => $v['currency'] ?? $g->stage_fee_currency,
            'stage_fee_paid_at' => array_key_exists('paid', $v) ? ($v['paid'] ? now() : null) : $g->stage_fee_paid_at, 'stage_fee_ref' => $v['ref'] ?? $g->stage_fee_ref]);
        return response()->json(['ok' => true]);
    }

    // ———————————————— التقويم ————————————————

    public function calendarLink(Request $r): JsonResponse
    {
        $t = TdExtras::calendarToken($r->user(), $r->isMethod('post'));
        return response()->json(['url' => rtrim((string) config('app.url'), '/').'/api/public/td-calendar/'.$t.'.ics']);
    }

    /** اشتراك تقويم للقراءة فقط (Outlook/Google): دورات المستخدم، أو كل الدورات لمسؤول التدريب */
    public function calendarFeed(string $token)
    {
        $u = User::where('calendar_token', $token)->where('active', true)->first() ?? abort(404);
        \App\Support\Tenant::set($u->company_id);
        $req = Request::create('/', 'GET'); $req->setUserResolver(fn () => $u);
        return app(TrainingController::class)->ical($req);
    }

    // ———————————————— الروابط العامة ————————————————

    public function managerInfo(string $token): JsonResponse { return response()->json(['data' => TdExtras::managerInfo($token)]); }

    public function managerAnswer(Request $r, string $token): JsonResponse
    {
        return response()->json(['data' => TdExtras::managerAnswer($token, (bool) $r->validate(['ok' => ['required', 'boolean']])['ok'])]);
    }

    public function commitInfo(string $token): JsonResponse { return response()->json(['data' => TdExtras::commitInfo($token)]); }
    public function commitCode(string $token): JsonResponse { TdExtras::commitCode($token); return response()->json(['sent' => true]); }

    public function commitSign(Request $r, string $token): JsonResponse
    {
        $v = $r->validate(['code' => ['required', 'string', 'max:10'], 'signature' => ['required', 'string', 'starts_with:data:image/png;base64,']]);
        return response()->json(['data' => TdExtras::commitSign($token, $v['code'], $v['signature'])]);
    }
}
