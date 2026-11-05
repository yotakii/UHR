<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Services\People\Directory;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * البحث الشامل (Ctrl K — M15): يبحث في البيانات الفعلية لا في أسماء الصفحات فقط (ملاحظة 51)، كلٌّ حسب صلاحية وحدته ونطاقها:
 * الموظفون (الاسم، الرقم الوظيفي، البريد، الجوال، الرقم الوطني، بطاقة الحضور)، المتقدمون، الشواغر،
 * و«سجلات»: العهد (الرمز، الرقم التسلسلي، الاسم)، الإدارات، ملفات المخالفات، طلبات الخروج، الشهادات الصادرة، فقرات السياسات، الإعلانات.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:80']])['q'] ?? '');
        $out = ['employees' => [], 'candidates' => [], 'jobs' => [], 'records' => []];
        if (mb_strlen($q) < 2) return response()->json($out);
        $user = $request->user();
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($q)).'%';
        $digits = preg_replace('/\D/', '', $q);
        $match = fn ($cols) => fn ($w) => collect($cols)->each(fn ($c) => $w->orWhereRaw("lower({$c}) like ?", [$like]));
        $bi = fn ($ar, $en) => ['ar' => (string) $ar, 'en' => (string) ($en ?: $ar)];
        $rec = function (string $kind, array $label, array $sub, string $to) use (&$out) { $out['records'][] = ['k' => $kind.count($out['records']), 'kind' => $kind, 'label' => $label, 'sub' => $sub, 'to' => $to]; };

        if (Perm::can($user, 'employees')) {
            $out['employees'] = Directory::scoped($user)->with('department')->where('status', '!=', 'terminated')
                ->where(function ($w) use ($match, $digits) {
                    $match(['name', 'name_en', 'code', 'email', 'title_ar', 'title_en'])($w);
                    if (strlen($digits) >= 4) $w->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') like ?", ['%'.$digits.'%'])->orWhere('national_id', 'like', '%'.$digits.'%');
                    $w->orWhereRaw("replace(lower(coalesce(nfc_uid, '')), ':', '') like ?", ['%'.str_replace(':', '', mb_strtolower(request('q'))).'%']);
                })->orderBy('code')->limit(6)->get()->map(fn ($e) => ['id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en],
                    'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'dept' => ['ar' => $e->department->name_ar, 'en' => $e->department->name_en]]);
        }
        if (Perm::can($user, 'candidates')) {
            $out['candidates'] = Candidate::with('jobOpening')->where($match(['name', 'name_en', 'ref', 'email', 'mobile']))->latest('id')->limit(5)->get()
                ->map(fn ($c) => ['id' => $c->id, 'ref' => $c->ref, 'name' => ['ar' => $c->name, 'en' => $c->name_en ?: $c->name], 'stage' => $c->stage,
                    'job' => ['ar' => $c->jobOpening->title_ar, 'en' => $c->jobOpening->title_en]]);
        }
        if (Perm::can($user, 'jobs')) {
            $out['jobs'] = JobOpening::where($match(['title_ar', 'title_en', 'code']))->latest('id')->limit(4)->get()
                ->map(fn ($j) => ['id' => $j->id, 'code' => $j->code, 'title' => ['ar' => $j->title_ar, 'en' => $j->title_en], 'status' => $j->status]);
        }
        if (Perm::can($user, 'org')) {
            foreach (\App\Models\Department::where($match(['name_ar', 'name_en', 'code']))->orderBy('sort')->limit(3)->get() as $d)
                $rec('dept', $bi($d->name_ar, $d->name_en), $bi('إدارة · '.$d->code, 'Department · '.$d->code), "/hr/employees?dept={$d->id}");
        }
        if (Perm::can($user, 'assets')) {
            foreach (\App\Models\Asset::with('employee')->where($match(['tag', 'serial', 'name_ar', 'name_en']))->orderBy('tag')->limit(4)->get() as $a)
                $rec('asset', $bi($a->name_ar, $a->name_en), $bi("عهدة · {$a->tag}".($a->employee ? ' · '.$a->employee->name : ''), "Asset · {$a->tag}".($a->employee ? ' · '.$a->employee->name_en : '')), "/hr/custody?asset={$a->id}");
        }
        $scope = fn (string $module) => Directory::scoped($user, $module)->select('id');
        if (Perm::can($user, 'discipline')) {
            foreach (\App\Models\DisciplineCase::with(['employee', 'type'])->whereIn('employee_id', $scope('discipline'))->whereRaw('lower(ref_no) like ?', [$like])->latest('id')->limit(3)->get() as $c)
                $rec('case', $bi("{$c->ref_no} — {$c->employee?->name}", "{$c->ref_no} — {$c->employee?->name_en}"), $bi('ملف مخالفة · '.($c->type?->title_ar ?? ''), 'Discipline case · '.($c->type?->title_en ?? '')), "/hr/discipline?case={$c->id}&tab=all");
        }
        if (Perm::can($user, 'employees')) {
            foreach (\App\Models\Resignation::with('employee')->whereIn('employee_id', $scope('employees'))->whereRaw('lower(coalesce(ref_no, \'\')) like ?', [$like])->latest('id')->limit(3)->get() as $r)
                $rec('exit', $bi("{$r->ref_no} — {$r->employee?->name}", "{$r->ref_no} — {$r->employee?->name_en}"), $bi($r->kind === 'termination' ? 'إنهاء خدمة' : 'استقالة', $r->kind === 'termination' ? 'Termination' : 'Resignation'), "/hr/offboarding?id={$r->id}&status=all");
        }
        if (Perm::can($user, 'leaves')) {
            foreach (\App\Models\ServiceRequest::with('employee')->whereIn('employee_id', $scope('leaves'))->whereNotNull('ref_no')->whereRaw('lower(ref_no) like ?', [$like])->latest('id')->limit(3)->get() as $s)
                $rec('cert', $bi("{$s->ref_no} — {$s->employee?->name}", "{$s->ref_no} — {$s->employee?->name_en}"), $bi('شهادة صادرة', 'Issued certificate'), "/hr/leaves/cert/{$s->id}");
        }
        if (Perm::can($user, 'policies')) {
            foreach (array_slice(app(\App\Services\Policies\PolicyLibrary::class)->search($user, $q), 0, 3) as $p)
                $rec('policy', $bi(mb_substr($p['text'], 0, 90), mb_substr($p['text'], 0, 90)), $bi("سياسة · {$p['document']['ar']} · فقرة {$p['n']}", "Policy · {$p['document']['en']} · ¶{$p['n']}"), "/hr/policies?doc={$p['document']['id']}&p={$p['id']}");
        }
        if (Perm::can($user, 'announcements')) {
            foreach (\App\Models\Announcement::where($match(['title_ar', 'title_en', 'text_ar']))->latest('id')->limit(3)->get() as $n)
                $rec('news', $bi($n->title_ar ?: mb_substr((string) $n->text_ar, 0, 80), $n->title_en ?: $n->title_ar), $bi('إعلان', 'Announcement'), '/hr/announcements');
        }
        return response()->json($out);
    }
}
