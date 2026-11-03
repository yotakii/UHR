<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\JobOpening;
use App\Models\Location;
use App\Models\ReviewBatch;
use App\Services\Recruitment\CvScreening;
use App\Services\Recruitment\MatchScore;
use App\Support\Audit;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** فرز السير (11f): فلاتر، قراءة جماعية، تقدّم الدفعة، وترتيب النتائج + تصدير Excel */
class CvScreeningController extends Controller
{
    public function __construct(private CvScreening $svc) {}

    private function filters(Request $r): array
    {
        return $r->validate([
            'job' => ['nullable', 'integer'], 'dept' => ['nullable', 'integer'], 'major' => ['nullable', 'string', 'max:80'], 'q' => ['nullable', 'string', 'max:80'],
            'degree' => ['nullable', 'integer', 'between:0,4'], 'years_min' => ['nullable', 'integer', 'between:0,50'], 'years_max' => ['nullable', 'integer', 'between:0,50'],
            'city' => ['nullable', 'string', 'max:40'], 'english' => ['nullable', 'in:excellent,good,fair,weak'], 'match_min' => ['nullable', 'integer', 'between:0,100'],
            'stage' => ['nullable', 'in:open,all,new,screening,interview,technical,offer,hired,rejected'], 'read' => ['nullable', 'in:none,done,running,failed'],
            'fit' => ['nullable', 'in:strong,good,partial,weak'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'sort' => ['nullable', 'in:'.implode(',', CvScreening::SORTS)],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $rows = $this->svc->query($f)->limit(CvScreening::MAX_BATCH)->get();
        $total = $this->svc->query($f)->toBase()->getCountForPagination();
        $batch = $this->svc->latest($request->user());
        return response()->json([
            'data' => $rows->values()->map(fn (Candidate $c, $i) => CvScreening::row($c, $i + 1)),
            'total' => $total, 'shown' => $rows->count(),
            'status' => $this->svc->status(),
            'batch' => $batch ? $this->svc->progress($batch) : null,
            'jobs' => JobOpening::orderByDesc('id')->get(['id', 'code', 'title_ar', 'title_en', 'status', 'department_id'])->map(fn ($j) => ['id' => $j->id, 'code' => $j->code, 'ar' => $j->title_ar, 'en' => $j->title_en, 'status' => $j->status, 'dept' => $j->department_id]),
            'departments' => Department::where('active', true)->orderBy('sort')->get(['id', 'name_ar', 'name_en'])->map(fn ($d) => ['id' => $d->id, 'ar' => $d->name_ar, 'en' => $d->name_en]),
            'cities' => Location::where('active', true)->orderBy('id')->get()->map(fn ($l) => ['code' => $l->code, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'degrees' => array_values(MatchScore::DEGREES),
            'majors' => $this->svc->majors(),
            'can' => ['run' => Perm::can($request->user(), 'candidates', 'edit'), 'export' => Perm::can($request->user(), 'candidates', 'export')],
        ]);
    }

    public function run(Request $request): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer'], 'force' => ['nullable', 'boolean'], 'filters' => ['nullable', 'array']]);
        $b = $this->svc->start($request->user(), $d['ids'], (bool) ($d['force'] ?? false), $d['filters'] ?? []);
        return response()->json(['batch' => $this->svc->progress($b)], 201);
    }

    public function batch(Request $request, int $id): JsonResponse
    {
        $b = ReviewBatch::where('user_id', $request->user()->id)->findOrFail($id);
        return response()->json(['batch' => $this->svc->progress($b)]);
    }

    /** النتائج مرتبة كما تظهر — بصلاحية «تصدير» */
    public function export(Request $request): BinaryFileResponse
    {
        $f = $this->filters($request);
        $rows = $this->svc->query($f)->limit(2000)->get();
        $path = tempnam(sys_get_temp_dir(), 'scr').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName('Screening')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
        $w->addRow(Row::fromValues(['الترتيب', 'الرقم', 'الاسم', 'الشاغر', 'الإدارة', 'المؤهل', 'الاختصاص', 'سنوات الخبرة', 'آخر وظيفة', 'المرحلة', 'المطابقة٪', 'قراءة السيرة /100', 'التقدير', 'الملخص', 'نقاط القوة', 'النواقص', 'تاريخ التقديم'], (new Style())->setFontBold()));
        $fit = ['strong' => 'مناسب جداً', 'good' => 'مناسب', 'partial' => 'مناسب جزئياً', 'weak' => 'ضعيف المناسبة'];
        foreach ($rows->values() as $i => $c) {
            $r = CvScreening::row($c, $i + 1); $rv = $r['review'];
            $done = $rv && $rv['status'] === 'done';
            $w->addRow(Row::fromValues([$i + 1, $c->ref, $c->name, $c->jobOpening->title_ar, $c->jobOpening->department?->name_ar ?? '', MatchScore::DEGREES[(int) $c->degree_level]['ar'] ?? '',
                $r['major'] ?? '', $c->years_exp, $r['last'] ?? '', $c->stage, $c->match_score, $done ? $rv['score'] : '', $done ? ($fit[$rv['fit']] ?? '') : '',
                $done ? (string) $rv['summary'] : '', $done ? implode(' · ', $rv['strengths']) : '', $done ? implode(' · ', $rv['gaps']) : '', $r['applied_at']]));
        }
        $w->close();
        Audit::record('cv.screening_export', null, null, ['filters' => $f, 'count' => $rows->count()]);
        return response()->download($path, 'UHR-CV-screening-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }
}
