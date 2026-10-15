<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** البحث الشامل (Ctrl K — M15): الموظفون (6) ثم المتقدمون (5) ثم الشواغر (4)، كلٌّ حسب صلاحية عرض وحدته. الصفحات تُبحث في الواجهة */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:80']])['q'] ?? '');
        $out = ['employees' => [], 'candidates' => [], 'jobs' => []];
        if (mb_strlen($q) < 2) return response()->json($out);
        $user = $request->user();
        $like = '%'.mb_strtolower($q).'%';
        $match = fn ($cols) => fn ($w) => collect($cols)->each(fn ($c) => $w->orWhereRaw("lower({$c}) like ?", [$like]));

        if (Perm::can($user, 'employees')) {
            $out['employees'] = Employee::with('department')->where('status', '!=', 'terminated')->where($match(['name', 'name_en', 'code', 'email']))
                ->orderBy('code')->limit(6)->get()->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en],
                    'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'dept' => ['ar' => $e->department->name_ar, 'en' => $e->department->name_en]]);
        }
        if (Perm::can($user, 'candidates')) {
            $out['candidates'] = Candidate::with('jobOpening')->where($match(['name', 'name_en', 'ref', 'email']))->latest('id')->limit(5)->get()
                ->map(fn ($c) => ['id' => $c->id, 'ref' => $c->ref, 'name' => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name], 'stage' => $c->stage,
                    'job' => ['ar' => $c->jobOpening->title_ar, 'en' => $c->jobOpening->title_en]]);
        }
        if (Perm::can($user, 'jobs')) {
            $out['jobs'] = JobOpening::where($match(['title_ar', 'title_en', 'code']))->latest('id')->limit(4)->get()
                ->map(fn ($j) => ['id' => $j->id, 'code' => $j->code, 'title' => ['ar' => $j->title_ar, 'en' => $j->title_en], 'status' => $j->status]);
        }
        return response()->json($out);
    }
}
