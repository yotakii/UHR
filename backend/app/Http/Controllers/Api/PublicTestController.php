<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CandidateTest;
use App\Services\Recruitment\PreliminaryTests;
use App\Support\L;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * صفحة الاختبار الأولي للمتقدم (/test/{token}): الرابط السري يكفي للدخول. الأسئلة تظهر بعد «بدء الاختبار» فقط،
 * وبلا الإجابات الصحيحة أو معايير التصحيح. الوقت والمهلة على الخادم.
 */
class PublicTestController extends Controller
{
    public function __construct(private PreliminaryTests $tests) {}

    public function show(string $token): JsonResponse
    {
        return $this->within($token, fn (CandidateTest $ct) => $this->out($ct));
    }

    public function start(string $token): JsonResponse
    {
        return $this->within($token, fn (CandidateTest $ct) => $this->out($this->tests->start($ct)));
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $answers = $request->validate(['answers' => ['present', 'array'], 'answers.*' => ['nullable']])['answers'];
        return $this->within($token, fn (CandidateTest $ct) => $this->out($this->tests->submit($ct, $answers)));
    }

    private function within(string $token, callable $fn): JsonResponse
    {
        $ct = strlen($token) === 48 ? CandidateTest::allCompanies()->where('token', $token)->first() : null;
        if (! $ct) throw new ApiException(L::t('رابط الاختبار غير صالح.', 'This test link is not valid.'), 'test_not_found', 404);
        return Tenant::run($ct->company_id, function () use ($ct, $fn) {
            if ($ct->overdue()) $this->tests->expire($ct);
            return $fn($ct->refresh());
        });
    }

    private function out(CandidateTest $ct): JsonResponse
    {
        $c = $ct->candidate()->with('jobOpening.company')->first();
        $job = $c->jobOpening; $co = $job->company;
        $live = $ct->status === 'started';
        return response()->json(['data' => [
            'status' => $ct->status, 'minutes' => $ct->minutes, 'count' => count($ct->questions), 'expires_at' => $ct->expires_at->toIso8601String(),
            'deadline_at' => $ct->deadline_at?->toIso8601String(), 'now' => now()->toIso8601String(), 'submitted_at' => $ct->submitted_at?->toIso8601String(),
            'job' => ['ar' => $job->title_ar, 'en' => $job->title_en], 'company' => ['ar' => $co->name_ar, 'en' => $co->name_en, 'slug' => $co->slug, 'logo' => $co->card()['logo'] ?? null],
            'name' => $c->name,
            'questions' => $live ? array_map(fn ($q) => ['id' => $q['id'], 'type' => $q['type'], 'text' => $q['text'], 'options' => $q['options'], 'points' => $q['points']], $ct->questions) : [],
        ]]);
    }
}
