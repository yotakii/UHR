<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractCase;
use App\Services\Contracts\ContractCases;
use App\Services\Contracts\Contracts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «عقدي» في البوابتين (الدفعة 19): عقودي وتوقيعها، تقييم تجربتي للاطلاع والتوقيع،
 * وللمدير: تقييمات فريقه المطلوبة وتوقيعه كمدير قسم.
 */
class MyContractsController extends Controller
{
    public function __construct(private Contracts $contracts, private ContractCases $cases) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user(); $eid = $u->employee_id;
        $mine = $eid ? Contract::with(['employee', 'type'])->where('employee_id', $eid)->whereNotIn('status', ['cancelled', 'draft'])->orderByDesc('starts_on')->get() : collect();
        $myCases = $eid ? ContractCase::where('employee_id', $eid)->where('kind', 'probation')->whereNotNull('evaluation')->latest('id')->get() : collect();
        $team = $eid ? ContractCase::with('employee.department')->where('status', '!=', 'closed')->where(fn ($q) => $q->where(fn ($w) => $w->where('status', 'manager')->where('evaluator_id', $eid))
            ->orWhere(fn ($w) => $w->where('status', 'signatures')->whereHas('employee.department', fn ($d) => $d->where('head_id', $eid))))->get()->filter(fn ($k) => ContractCases::canEvaluate($u, $k) || ContractCases::canSignEval($u, $k)) : collect();
        return response()->json(['contracts' => $mine->map(fn ($c) => Contracts::row($c, $u) + ['body' => $c->body]),
            'evaluations' => $myCases->map(fn ($k) => ContractCases::row($k, $u, true)), 'team' => $team->values()->map(fn ($k) => ContractCases::row($k, $u, true))]);
    }

    public function sign(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['signature' => ['required', 'string']]);
        $c = Contract::where('employee_id', $request->user()->employee_id ?? 0)->findOrFail($id);
        $this->contracts->sign($c, $request->user(), 'employee', $d['signature']);
        return $this->index($request);
    }

    private function case(Request $r, int $id): ContractCase
    {
        $k = ContractCase::with('employee.department')->findOrFail($id);
        $u = $r->user();
        abort_unless($u->employee_id && ($k->employee_id === $u->employee_id || $k->evaluator_id === $u->employee_id || $k->employee->department?->head_id === $u->employee_id), 404);
        return $k;
    }

    public function evaluate(Request $request, int $id): JsonResponse
    {
        $this->cases->evaluate($this->case($request, $id), $request->user(), $request->all());
        return $this->index($request);
    }

    public function signEval(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->cases->signEval($this->case($request, $id), $request->user(), $d['note'] ?? null);
        return $this->index($request);
    }

    public function print(Request $request, string $kind, int $id): JsonResponse
    {
        $u = $request->user(); $lang = app()->getLocale();
        if ($kind === 'contract') {
            $c = Contract::with(['employee.department', 'employee.location', 'type'])->where('employee_id', $u->employee_id ?? 0)->findOrFail($id);
            return response()->json(['contract' => Contracts::row($c), 'body' => $lang === 'en' ? Contracts::render($c, 'en') : ($c->body ?: Contracts::render($c, 'ar')),
                'signatures' => array_map(fn ($s) => $s + ['image' => ($s['file_id'] ?? null) ? \App\Support\Signature::dataUrl(\App\Models\StoredFile::find($s['file_id'])) : null,
                    'name' => ($s['user_id'] ?? null) ? \App\Models\User::find($s['user_id'])?->displayName($lang) : null], $c->signatures ?? [])]);
        }
        $k = $this->case($request, $id);
        $row = ContractCases::row($k, $u, true);
        if ($kind === 'letter') {
            abort_unless($k->employee_id === $u->employee_id && $row['letters'], 404);
            return response()->json(['case' => $row, 'which' => $row['letters'][0], 'text' => ContractCases::letterText($k, $row['letters'][0], $lang)]);
        }
        return response()->json(['case' => $row]);
    }
}
