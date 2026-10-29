<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\JobOpening;
use App\Models\JobTest;
use App\Services\Recruitment\PreliminaryTests;
use App\Support\L;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** الاختبار الأولي للشاغر (D-34): الإعدادات، كتابة الأسئلة وتعديلها واعتمادها */
class JobTestController extends Controller
{
    public function __construct(private PreliminaryTests $tests) {}

    public function show(int $id): JsonResponse
    {
        return $this->out(JobOpening::findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $job = JobOpening::findOrFail($id);
        $d = $request->validate([
            'enabled' => ['required', 'boolean'], 'audience' => ['required', Rule::in(['all', 'passed'])], 'minutes' => ['required', 'integer', 'between:5,180'],
            'attempts' => ['required', 'integer', 'between:1,3'], 'valid_days' => ['required', 'integer', 'between:1,30'],
            'questions' => ['present', 'array', 'max:30'], 'questions.*.id' => ['nullable', 'string', 'max:20'], 'questions.*.type' => ['required', Rule::in(PreliminaryTests::TYPES)], 'questions.*.text' => ['required', 'string', 'max:2000'],
            'questions.*.options' => ['array', 'max:6'], 'questions.*.answer' => ['integer'], 'questions.*.points' => ['required', 'integer', 'between:1,100'], 'questions.*.rubric' => ['nullable', 'string', 'max:1000'],
            'approve' => ['boolean'],
        ], ['questions.*.text.required' => L::t('اكتب نص كل سؤال.', 'Write the text of every question.')]);
        $job->update(['test_enabled' => $d['enabled'], 'test_audience' => $d['audience'], 'test_minutes' => $d['minutes'], 'test_attempts' => $d['attempts'], 'test_valid_days' => $d['valid_days']]);
        $this->tests->save($job, $d['questions'], (bool) ($d['approve'] ?? false), $request->user());
        return $this->out($job->refresh());
    }

    private function out(JobOpening $job): JsonResponse
    {
        $t = JobTest::where('job_opening_id', $job->id)->first();
        return response()->json(['data' => [
            'job' => ['id' => $job->id, 'code' => $job->code, 'title' => ['ar' => $job->title_ar, 'en' => $job->title_en], 'status' => $job->status],
            'settings' => ['enabled' => $job->test_enabled, 'audience' => $job->test_audience, 'minutes' => $job->test_minutes, 'attempts' => $job->test_attempts, 'valid_days' => $job->test_valid_days],
            'test' => $t ? ['status' => $t->status, 'questions' => $t->questions, 'approved_at' => $t->approved_at?->toIso8601String()] : null,
            'sent' => \App\Models\CandidateTest::whereHas('candidate', fn ($q) => $q->where('job_opening_id', $job->id))->count(),
        ]]);
    }
}
