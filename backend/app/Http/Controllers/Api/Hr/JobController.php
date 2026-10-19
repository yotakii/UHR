<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\JobRequest;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\Location;
use App\Services\Recruitment\JobPublishing;
use App\Services\Recruitment\MatchScore;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** الوظائف الشاغرة في نظام الشركة: القائمة بعدد المتقدمين لكل مرحلة، الإنشاء والتعديل والنشر والإغلاق */
class JobController extends Controller
{
    public function index(): JsonResponse
    {
        $jobs = JobOpening::with(['department', 'location'])->orderByRaw("case status when 'open' then 0 when 'draft' then 1 else 2 end")->orderByDesc('posted_at')->orderByDesc('id')->get();
        $counts = Candidate::selectRaw('job_opening_id, stage, count(*) as n')->groupBy('job_opening_id', 'stage')->get()->groupBy('job_opening_id');
        $company = Tenant::company();
        return response()->json([
            'company' => $company->card() + ['portal_enabled' => $company->portal_enabled, 'portal_review' => $company->portal_review],
            'data' => $jobs->map(fn ($j) => $this->present($j) + ['pipeline' => collect(['new', 'screening', 'interview', 'technical', 'offer', 'hired', 'rejected'])
                ->mapWithKeys(fn ($s) => [$s => (int) ($counts[$j->id] ?? collect())->firstWhere('stage', $s)?->n])]),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(JobOpening::with(['department', 'location'])->findOrFail($id), true)]);
    }

    /** قيم نموذج الشاغر: الإدارات، المدن، أنواع الدوام، المؤهلات */
    public function lookups(): JsonResponse
    {
        return response()->json([
            'departments' => Department::where('active', true)->orderBy('sort')->orderBy('id')->get()->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'locations' => Location::where('active', true)->orderBy('id')->get()->map(fn ($l) => ['id' => $l->id, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'types' => collect(JobOpening::TYPES)->map(fn ($t, $k) => ['code' => $k] + $t)->values(),
            'degrees' => MatchScore::DEGREES,
        ]);
    }

    public function store(JobRequest $request, JobPublishing $svc): JsonResponse
    {
        return response()->json(['data' => $this->present($svc->save($request->validated())->load(['department', 'location']), true)], 201);
    }

    public function update(JobRequest $request, JobPublishing $svc, int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($svc->save($request->validated(), JobOpening::findOrFail($id))->load(['department', 'location']), true)]);
    }

    public function status(Request $request, JobPublishing $svc, int $id): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'closed'])]]);
        return response()->json(['data' => $this->present($svc->setStatus(JobOpening::findOrFail($id), $data['status'])->load(['department', 'location']), true)]);
    }

    private function present(JobOpening $j, bool $full = false): array
    {
        $out = [
            'id' => $j->id, 'code' => $j->code, 'title' => ['ar' => $j->title_ar, 'en' => $j->title_en],
            'dept' => ['id' => $j->department_id, 'ar' => $j->department->name_ar, 'en' => $j->department->name_en],
            'city' => ['id' => $j->location_id, 'ar' => $j->location->name_ar, 'en' => $j->location->name_en],
            'type' => JobOpening::TYPES[$j->employment_type] ?? null, 'openings' => $j->openings, 'min_years' => $j->min_years, 'degree' => $j->degree,
            'salary_min' => $j->salary_min, 'salary_max' => $j->salary_max, 'currency' => $j->currency,
            'status' => $j->status, 'visibility' => $j->visibility, 'portal_status' => $j->portal_status, 'portal_note' => $j->portal_note,
            'posted_at' => $j->posted_at?->toDateString(), 'closes_at' => $j->closes_at?->toDateString(),
            'hard_to_fill' => $j->hard_to_fill, 'requires_photo' => $j->requires_photo, 'accepting' => $j->acceptingApplications(), 'test_enabled' => $j->test_enabled,
        ];
        if ($full) $out += ['title_ar' => $j->title_ar, 'title_en' => $j->title_en, 'summary_ar' => $j->summary_ar, 'summary_en' => $j->summary_en,
            'requirements' => $j->requirements, 'department_id' => $j->department_id, 'location_id' => $j->location_id, 'employment_type' => $j->employment_type];
        return $out;
    }
}
