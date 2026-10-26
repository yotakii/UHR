<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Analytics\PeopleAnalytics;
use App\Support\Audit;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use App\Services\Security\SalaryVault;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** التحليلات (Analytics في الديمو) + تصدير كل جدول إلى Excel (ورقة لكل تقرير) */
class AnalyticsController extends Controller
{
    public function __construct(private PeopleAnalytics $analytics) {}

    public function index(Request $request): JsonResponse
    {
        $can = Perm::can($request->user(), 'payroll');
        $open = SalaryVault::open($request->user());
        return response()->json($this->analytics->build($can && $open) + ['pay_masked' => $can && ! $open]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $d = $this->analytics->build(Perm::can($request->user(), 'payroll') && SalaryVault::open($request->user()));
        $path = tempnam(sys_get_temp_dir(), 'ana').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $bold = (new Style())->setFontBold();
        $sheet = function (string $name, array $head, array $rows, bool $first = false) use ($w, $bold) {
            $s = $first ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
            $s->setName($name)->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(1));
            $w->addRow(Row::fromValues($head, $bold));
            foreach ($rows as $r) $w->addRow(Row::fromValues($r));
        };
        $sheet('القوى العاملة', ['الإدارة', 'عدد الموظفين'], array_map(fn ($r) => [$r['ar'], $r['n']], $d['headcount']['by_dept']->all()), true);
        $sheet('سنوات الخدمة', ['من (سنة)', 'إلى (سنة)', 'العدد'], array_map(fn ($t) => [$t['from'], $t['to'], $t['n']], $d['tenure']));
        if ($d['pay']) $sheet('كتلة الرواتب', ['الإدارة', 'مجموع الرواتب', 'العملة'], array_map(fn ($r) => [$r['ar'], $r['v'], $d['currency']], $d['pay']->all()));
        $sheet('الحضور', array_merge(['الإدارة'], $d['heat']['days']), array_map(fn ($r) => array_merge([$r['ar']], array_map(fn ($c) => $c['rate'] === null ? '' : round($c['rate'] * 100).'% · '.$c['late'].' متأخر', $r['cells'])), $d['heat']['rows']->all()));
        $sheet('قمع التوظيف', ['المرحلة', 'وصلها أو تجاوزها'], array_map(fn ($f) => [$f['ar'], $f['n']], $d['funnel']));
        $sheet('أنواع الطلبات', ['النوع', 'العدد'], array_map(fn ($l) => [$l['ar'], $l['n']], $d['leaves']->all()));
        $w->close();
        Audit::record('analytics.export', null, null, ['pay' => (bool) $d['pay']]);
        return response()->download($path, 'UHR-analytics.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }
}
