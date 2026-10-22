<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Employee;
use App\Services\Assets\AssetImport;
use App\Services\Assets\Custody;
use App\Services\People\Directory;
use App\Support\Audit;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** M10 — العهد (Custody في الديمو): السجل، التسليم والاسترجاع، الصيانة والاستبعاد، سجل الحركة، التصدير والاستيراد (D-13) */
class CustodyController extends Controller
{
    public function __construct(private Custody $custody) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:all,'.implode(',', Asset::STATUSES)], 'category' => ['nullable', 'integer']]);
        $u = $request->user();
        $q = $this->custody->list($f);
        if (Perm::scope($u, 'assets') !== 'all') $q->whereIn('employee_id', Directory::scoped($u, 'assets')->select('id'));
        return response()->json(['summary' => $this->custody->summary(), 'data' => $q->limit(1000)->get()->map(fn ($a) => Custody::row($a)),
            'categories' => AssetCategory::orderBy('id')->get()->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'ar' => $c->name_ar, 'en' => $c->name_en]),
            'employees' => Employee::current()->orderBy('code')->get(['id', 'code', 'name', 'name_en'])->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'ar' => $e->name, 'en' => $e->name_en]),
            'can' => ['create' => Perm::can($u, 'assets', 'create'), 'edit' => Perm::can($u, 'assets', 'edit'), 'export' => Perm::can($u, 'assets', 'export')]]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => Custody::row($this->custody->save(null, $request->all(), $request->user())->load(['category', 'employee']))], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => Custody::row($this->custody->save(Asset::findOrFail($id), $request->all(), $request->user())->load(['category', 'employee']))]);
    }

    public function issue(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'note' => ['nullable', 'string', 'max:500']]);
        $a = $this->custody->issue(Asset::findOrFail($id), Employee::current()->findOrFail($d['employee_id']), $request->user(), $d['note'] ?? null);
        return response()->json(['data' => Custody::row($a->load(['category', 'employee']))]);
    }

    public function takeBack(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['condition' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:500']]);
        $a = $this->custody->takeBack(Asset::findOrFail($id), $d['condition'], $d['note'] ?? null, $request->user());
        return response()->json(['data' => Custody::row($a->load(['category', 'employee']))]);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:available,maintenance,retired'], 'note' => ['nullable', 'string', 'max:500']]);
        $a = $this->custody->setStatus(Asset::findOrFail($id), $d['status'], $d['note'] ?? null, $request->user());
        return response()->json(['data' => Custody::row($a->load(['category', 'employee']))]);
    }

    public function history(int $id): JsonResponse
    {
        $a = Asset::with(['category', 'employee'])->findOrFail($id);
        return response()->json(['data' => Custody::row($a), 'history' => Custody::history($a)]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $rows = $this->custody->list($request->only(['q', 'status', 'category']))->get();
        $path = tempnam(sys_get_temp_dir(), 'ast').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName('Custody')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(1));
        $w->addRow(Row::fromValues(['الرمز', 'الأصل', 'Asset', 'الفئة', 'الرقم التسلسلي', 'القيمة', 'الحالة الفنية', 'الحالة', 'الحائز', 'الرقم الوظيفي', 'تاريخ التسليم', 'تاريخ التوقيع'], (new Style())->setFontBold()));
        foreach ($rows as $a) $w->addRow(Row::fromValues([$a->tag, $a->name_ar, $a->name_en, $a->category->name_ar, $a->serial ?? '', $a->value, $a->condition, $a->status,
            $a->employee?->name ?? '', $a->employee?->code ?? '', $a->assigned_at?->toDateString() ?? '', $a->signed_at?->toDateString() ?? '']));
        $w->close();
        Audit::record('assets.export', null, null, ['count' => $rows->count()]);
        return response()->download($path, 'UHR-custody.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function template(AssetImport $import): BinaryFileResponse
    {
        return response()->download($import->template(), 'UHR-custody-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    public function preview(Request $request, AssetImport $import): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120']]);
        $rows = $import->preview($request->file('file'));
        return response()->json(['rows' => $rows, 'ok' => count(array_filter($rows, fn ($r) => ! $r['errors'])), 'errors' => count(array_filter($rows, fn ($r) => $r['errors']))]);
    }

    public function import(Request $request, AssetImport $import): JsonResponse
    {
        $d = $request->validate(['file' => ['required', 'file', 'max:5120'], 'paper_signed' => ['nullable', 'boolean']]);
        return response()->json($import->commit($request->file('file'), (bool) ($d['paper_signed'] ?? false), $request->user()), 201);
    }
}
