<?php

namespace App\Http\Controllers\Api\Employee;

use App\Http\Controllers\Controller;
use App\Models\PerformanceForm;
use App\Models\PerformanceReview;
use App\Services\Performance\Reviews;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «تقييماتي» (M13 من جهة الموظف): نماذج التقييم المطلوبة مني (ذاتي، كمدير، كزميل) في الدورات المفتوحة،
 * ونتائجي المعتمدة — درجات الزملاء تظهر مجمّعة فقط (بلا أسماء).
 */
class MyReviewsController extends Controller
{
    public function __construct(private Reviews $reviews) {}

    public function index(Request $request): JsonResponse
    {
        $u = $request->user();
        $forms = PerformanceForm::with(['employee.department', 'review.cycle'])->where('rater_user_id', $u->id)
            ->whereHas('review.cycle', fn ($q) => $q->where('status', 'open'))->orderByRaw("case status when 'pending' then 0 else 1 end")->orderBy('id')->get();
        $results = $u->employee_id ? PerformanceReview::with(['cycle', 'award'])->where('employee_id', $u->employee_id)->where('status', 'approved')->latest('approved_at')->limit(8)->get() : collect();
        return response()->json(['forms' => $forms->map(fn (PerformanceForm $f) => ['id' => $f->id, 'role' => $f->role, 'status' => $f->status, 'ratings' => (object) ($f->ratings ?? []), 'potential' => $f->potential, 'comment' => $f->comment,
                'cycle' => ['id' => $f->review->cycle->id, 'name' => ['ar' => $f->review->cycle->name_ar, 'en' => $f->review->cycle->name_en], 'ends_on' => $f->review->cycle->ends_on->toDateString()],
                'employee' => ['id' => $f->employee->id, 'code' => $f->employee->code, 'name' => ['ar' => $f->employee->name, 'en' => $f->employee->name_en], 'title' => ['ar' => $f->employee->title_ar, 'en' => $f->employee->title_en],
                    'dept' => $f->employee->department ? ['ar' => $f->employee->department->name_ar, 'en' => $f->employee->department->name_en] : null]]),
            'results' => $results->map(fn (PerformanceReview $r) => ['id' => $r->id, 'cycle' => ['ar' => $r->cycle->name_ar, 'en' => $r->cycle->name_en], 'score' => $r->score, 'performance' => $r->performance, 'potential' => $r->potential,
                'ratings' => $r->ratings, 'approved_at' => $r->approved_at?->toIso8601String(),
                'award' => $r->award && in_array($r->award->status, ['approved', 'paid'], true) ? ['kind' => $r->award->kind, 'amount' => $r->award->amount, 'currency' => $r->award->currency, 'points' => $r->award->points, 'status' => $r->award->status, 'month' => $r->award->month] : null]),
            // سبب غياب النماذج: لا دورة مفتوحة، أو دورة مسودة بانتظار فتحها، أو أرسلت كل نماذجك
            'cycles' => ['open' => \App\Models\PerformanceCycle::where('status', 'open')->get()->map(fn ($c) => ['ar' => $c->name_ar, 'en' => $c->name_en, 'ends_on' => $c->ends_on->toDateString()]),
                'draft' => \App\Models\PerformanceCycle::where('status', 'draft')->count()],
            'competencies' => Reviews::competencies()->map(fn ($c) => ['id' => $c->id, 'ar' => $c->name_ar, 'en' => $c->name_en]), 'scale' => (int) Reviews::config()['scale']]);
    }

    public function fill(Request $request, int $id): JsonResponse
    {
        $request->validate(['ratings' => ['nullable', 'array'], 'potential' => ['nullable', 'integer'], 'comment' => ['nullable', 'string'], 'submit' => ['required', 'boolean']]);
        $this->reviews->fill(PerformanceForm::with('review')->findOrFail($id), $request->user(), $request->all(), $request->boolean('submit'));
        return $this->index($request);
    }
}
