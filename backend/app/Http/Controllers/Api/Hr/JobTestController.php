<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\JobOpening;
use App\Models\JobTest;
use App\Services\Ai\AiClient;
use App\Services\Ai\ClaudeClient;
use App\Services\Recruitment\PreliminaryTests;
use App\Support\Audit;
use App\Support\L;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;

/** الاختبار الأولي للشاغر (D-34): الإعدادات، اقتراح الأسئلة، التعديل والاعتماد — وإعداد مفتاح Claude API للشركة */
class JobTestController extends Controller
{
    public function __construct(private PreliminaryTests $tests) {}

    public function show(int $id, AiClient $ai): JsonResponse
    {
        return $this->out(JobOpening::findOrFail($id), $ai);
    }

    public function generate(Request $request, int $id, AiClient $ai): JsonResponse
    {
        $job = JobOpening::findOrFail($id);
        $this->tests->generate($job, $request->user());
        return $this->out($job, $ai);
    }

    public function update(Request $request, int $id, AiClient $ai): JsonResponse
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
        return $this->out($job->refresh(), $ai);
    }

    private function out(JobOpening $job, AiClient $ai): JsonResponse
    {
        $t = JobTest::where('job_opening_id', $job->id)->first();
        return response()->json(['data' => [
            'job' => ['id' => $job->id, 'code' => $job->code, 'title' => ['ar' => $job->title_ar, 'en' => $job->title_en], 'status' => $job->status],
            'settings' => ['enabled' => $job->test_enabled, 'audience' => $job->test_audience, 'minutes' => $job->test_minutes, 'attempts' => $job->test_attempts, 'valid_days' => $job->test_valid_days],
            'test' => $t ? ['status' => $t->status, 'questions' => $t->questions, 'ai_generated' => (bool) $t->generated, 'edited' => $t->generated && $t->generated !== $t->questions,
                'generated_at' => $t->generated_at?->toIso8601String(), 'approved_at' => $t->approved_at?->toIso8601String()] : null,
            'ai' => $ai->configured(),
            'sent' => \App\Models\CandidateTest::whereHas('candidate', fn ($q) => $q->where('job_opening_id', $job->id))->count(),
        ]]);
    }

    /* ---------- مفتاح Claude API (إعدادات التوظيف) ---------- */

    public function ai(): JsonResponse
    {
        $key = ClaudeClient::key();
        $own = (bool) (Settings::get('ai')['api_key'] ?? null);
        return response()->json(['configured' => $key !== '', 'source' => $own ? 'company' : ($key !== '' ? 'server' : null), 'hint' => $key !== '' ? '…'.substr($key, -4) : null, 'model' => Settings::get('ai')['model'] ?? ClaudeClient::MODEL]);
    }

    public function saveAi(Request $request): JsonResponse
    {
        $d = $request->validate(['api_key' => ['nullable', 'string', 'max:300', 'regex:/^sk-ant-[A-Za-z0-9_\-]+$/']], ['api_key.regex' => L::t('مفتاح Claude API يبدأ بـ sk-ant-', 'A Claude API key starts with sk-ant-')]);
        $key = trim((string) ($d['api_key'] ?? ''));
        Settings::put('ai', ['api_key' => $key !== '' ? Crypt::encryptString($key) : null] + (Settings::get('ai') ?? []));
        Audit::record('settings.ai_key', null, null, ['set' => $key !== '']);
        return $this->ai();
    }
}
