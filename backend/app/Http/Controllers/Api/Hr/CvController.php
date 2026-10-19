<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Location;
use App\Services\Cv\CvDatabase;
use App\Services\Recruitment\MatchScore;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** قاعدة السير الذاتية (D-02): بحث وفلترة، أرشفة، وتصدير بالصلاحية — كل عرض جماعي وتصدير مسجّل في التدقيق */
class CvController extends Controller
{
    public function __construct(private CvDatabase $db) {}

    private function filters(Request $r): array
    {
        return $r->validate(['q' => ['nullable', 'string', 'max:80'], 'degree' => ['nullable', 'integer', 'between:0,4'], 'years' => ['nullable', 'integer', 'between:0,40'],
            'city' => ['nullable', 'string', 'max:40'], 'status' => ['nullable', 'in:active,archived'], 'job' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $page = $this->db->query($f)->paginate(40);
        Audit::record('cvdb.search', null, null, ['filters' => array_diff_key($f, ['page' => 1]), 'count' => $page->count(), 'total' => $page->total(), 'page' => $page->currentPage(),
            'fields' => ['name', 'degree', 'major', 'experience', 'city', 'job', 'match']]);
        return response()->json([
            'data' => collect($page->items())->map(fn (Candidate $c) => self::row($c)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'pages' => $page->lastPage()],
            'cities' => Location::where('active', true)->orderBy('id')->get()->map(fn ($l) => ['code' => $l->code, 'ar' => $l->name_ar, 'en' => $l->name_en]),
            'degrees' => array_values(MatchScore::DEGREES),
        ]);
    }

    public static function row(Candidate $c): array
    {
        $ed = $c->education[0] ?? []; $ex = $c->experience[0] ?? [];
        return [
            'id' => $c->id, 'ref' => $c->ref, 'name' => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name], 'gender' => $c->gender, 'stage' => $c->stage, 'match' => $c->match_score,
            'degree' => $c->degree_level, 'phase' => $ed['phase'] ?? null, 'major' => $ed['major'] ?? null, 'institute' => $ed['institute'] ?? null, 'years_exp' => $c->years_exp,
            'last' => $ex ? ['position' => $ex['position'] ?? '', 'company' => $ex['company'] ?? ''] : null, 'address' => $c->address,
            'job' => ['id' => $c->job_opening_id, 'code' => $c->jobOpening->code, 'title' => ['ar' => $c->jobOpening->title_ar, 'en' => $c->jobOpening->title_en],
                'city' => ['ar' => $c->jobOpening->location->name_ar, 'en' => $c->jobOpening->location->name_en]],
            'cv' => $c->cv ? ['id' => $c->cv->id, 'name' => $c->cv->name] : null, 'cv_status' => $c->cv_status,
            'applied_at' => $c->created_at?->toDateString(), 'archived_at' => $c->cv_archived_at?->toDateString(),
        ];
    }

    /** تصدير Excel بالفلاتر الحالية — بصلاحية «تصدير» فقط */
    public function export(Request $request): BinaryFileResponse
    {
        $f = $this->filters($request);
        $rows = $this->db->query($f)->limit(5000)->get();
        $path = tempnam(sys_get_temp_dir(), 'cv').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName('CVs')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
        $w->addRow(Row::fromValues(['الرقم', 'الاسم', 'البريد', 'الجوال', 'العنوان', 'المؤهل', 'الاختصاص', 'سنوات الخبرة', 'آخر وظيفة', 'الشاغر', 'المرحلة', 'المطابقة٪', 'تاريخ التقديم'], (new Style())->setFontBold()));
        foreach ($rows as $c) $w->addRow(Row::fromValues(CvDatabase::exportRow($c)));
        $w->close();
        Audit::record('cvdb.export', null, null, ['filters' => $f, 'count' => $rows->count(), 'fields' => CvDatabase::EXPORT_FIELDS]);
        return response()->download($path, 'UHR-CVs-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $c = Candidate::with(['jobOpening.location', 'cv'])->where('keep_cv', true)->findOrFail($id);
        $request->boolean('restore') ? $this->db->restore($c, $request->user()) : $this->db->archive($c, $request->user());
        return response()->json(['data' => self::row($c->refresh())]);
    }
}
