<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Resources\HrCandidate;
use App\Models\Candidate;
use App\Models\JobOpening;
use App\Services\Recruitment\F25;
use App\Services\Recruitment\Pipeline;
use App\Services\Recruitment\PreliminaryTests;
use App\Support\L;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** M03 لوحة المتقدمين + M04 ملف المتقدم — كل الإجراءات عبر Pipeline (قواعد المسار على الخادم) */
class CandidateController extends Controller
{
    public function __construct(private Pipeline $pipeline) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['job' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:80']]);
        $q = Candidate::with(HrCandidate::WITH)->latest('created_at')->latest('id');
        if ($f['job'] ?? null) $q->where('job_opening_id', $f['job']);
        if ($t = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.mb_strtolower($t).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(name_en) like ?', [$like])->orWhereRaw('lower(ref) like ?', [$like]));
        }
        return response()->json([
            'data' => $q->limit(500)->get()->map(fn ($c) => HrCandidate::card($c)),
            'jobs' => JobOpening::orderByDesc('id')->get(['id', 'code', 'title_ar', 'title_en', 'status'])->map(fn ($j) => ['id' => $j->id, 'code' => $j->code, 'title' => ['ar' => $j->title_ar, 'en' => $j->title_en], 'status' => $j->status]),
            'sla' => \App\Support\Settings::get('stage_sla'),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => HrCandidate::full(Candidate::findOrFail($id), $request->user())]);
    }

    public function move(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['stage' => ['required', Rule::in(array_keys(Pipeline::STAGES))], 'reason' => ['nullable', 'string', 'max:500']]);
        abort_if($data['stage'] === 'rejected' && ! Perm::can($request->user(), 'candidates', 'decide'), 403, L::t('عدم القبول يحتاج صلاحية الاعتماد/الرفض.', 'Not selecting needs the approve/reject permission.'));
        $c = $this->pipeline->move(Candidate::findOrFail($id), $data['stage'], $data['reason'] ?? null, $request->user());
        return response()->json(['data' => HrCandidate::full($c, $request->user())]);
    }

    public function note(Request $request, int $id): JsonResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:1000']], ['required' => L::t('اكتب الملاحظة', 'Write the note')])['text'];
        $c = Candidate::findOrFail($id);
        $this->pipeline->note($c, trim($text), $request->user());
        return response()->json(['data' => HrCandidate::full($c->refresh(), $request->user())]);
    }

    public function interview(Request $request, int $id): JsonResponse
    {
        $rules = ['notes' => ['nullable', 'string', 'max:2000'], 'recommendation' => ['required', Rule::in(['recommended', 'reserve', 'rejected'])], 'ratings' => ['required', 'array']];
        foreach (F25::keys() as $k) $rules["ratings.{$k}"] = ['required', 'integer', 'between:1,5'];
        $data = $request->validate($rules, ['ratings.*.required' => L::t('قيّم كل المعايير', 'Rate every criterion')]);
        $c = Candidate::findOrFail($id);
        $this->pipeline->interview($c, $data['ratings'], $data['notes'] ?? null, $data['recommendation'], $request->user());
        return response()->json(['data' => HrCandidate::full($c->refresh(), $request->user())]);
    }

    public function offer(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'], 'contract' => ['required', Rule::in(['fixed', 'open', 'project'])], 'currency' => ['required', Rule::in(['USD', 'SYP'])],
            'basic' => ['required', 'numeric', 'min:1', 'max:100000000'], 'housing' => ['required', 'numeric', 'min:0'], 'transport' => ['required', 'numeric', 'min:0'], 'fuel' => ['required', 'numeric', 'min:0'],
            'leave_days' => ['required', 'integer', 'between:0,60'], 'start_date' => ['required', 'date', 'after_or_equal:today'],
        ], ['required' => L::t('هذا الحقل مطلوب', 'This field is required'), 'start_date.after_or_equal' => L::t('تاريخ المباشرة لا يكون في الماضي', 'The start date cannot be in the past'), 'basic.min' => L::t('أدخل الراتب الأساسي', 'Enter the basic salary')]);
        $c = Candidate::findOrFail($id);
        $this->pipeline->offer($c, $data, $request->user());
        return response()->json(['data' => HrCandidate::full($c->refresh(), $request->user())]);
    }

    /** الاختبار الأولي: إرسال/إعادة إرسال الرابط (ضمن عدد المحاولات) */
    public function sendTest(Request $request, PreliminaryTests $tests, int $id): JsonResponse
    {
        $c = Candidate::findOrFail($id);
        $tests->send($c, null, $request->user());
        return response()->json(['data' => HrCandidate::full($c->refresh(), $request->user())]);
    }

    /** مراجعة HR: درجات الأسئلة المفتوحة وملاحظة ← النتيجة النهائية (لا رفض آلي بالاختبار) */
    public function reviewTest(Request $request, PreliminaryTests $tests, int $id): JsonResponse
    {
        $d = $request->validate(['scores' => ['present', 'array'], 'scores.*' => ['nullable', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:1000']]);
        $c = Candidate::with('test')->findOrFail($id);
        abort_unless($c->test, 404);
        $tests->review($c->test, $d['scores'], $d['note'] ?? null, $request->user());
        return response()->json(['data' => HrCandidate::full($c->refresh(), $request->user())]);
    }
}
