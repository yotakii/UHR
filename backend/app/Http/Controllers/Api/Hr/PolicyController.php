<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\Policy;
use App\Services\Attendance\AttendancePolicies;
use App\Services\Attendance\DayCalc;
use App\Support\L;
use App\Support\Perm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** M16 — سياسات الدوام بتاريخ نفاذ والعطل الرسمية (SRS §10.6) */
class PolicyController extends Controller
{
    /** العطل ثابتة التاريخ في سوريا (SRS §10.6) — الأعياد الدينية تختلف كل سنة فتُضاف يدوياً */
    public const FIXED_HOLIDAYS = [
        ['01-01', 'رأس السنة الميلادية', 'New Year'], ['03-21', 'عيد الأم', "Mother's Day"], ['04-17', 'عيد الجلاء', 'Evacuation Day'],
        ['05-01', 'عيد العمال', 'Labour Day'], ['05-06', 'عيد الشهداء', "Martyrs' Day"], ['10-06', 'ذكرى حرب تشرين', 'Tishreen War Day'], ['12-25', 'عيد الميلاد المجيد', 'Christmas'],
    ];

    public function __construct(private AttendancePolicies $policies) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->policies->all() + ['can' => ['edit' => Perm::can($request->user(), 'policies', 'edit')]]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['key' => ['required', 'string'], 'department_id' => ['nullable', 'integer'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'value' => ['required', 'array']]);
        $this->policies->save($d['key'], $d['department_id'] ?? null, $d['effective_from'], $d['value'], $request->user());
        return $this->index($request);
    }

    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate(['key' => ['required', 'string'], 'department_id' => ['nullable', 'integer'], 'value' => ['required', 'array']]);
        return response()->json($this->policies->preview($d['key'], $d['department_id'] ?? null, $d['value']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->policies->delete(Policy::findOrFail($id));
        return $this->index($request);
    }

    public function holidays(Request $request): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? now()->year);
        return response()->json(['year' => $year, 'data' => Holiday::whereYear('date', $year)->orderBy('date')->get()->map(fn ($h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'ar' => $h->name_ar, 'en' => $h->name_en])]);
    }

    public function addHoliday(Request $request): JsonResponse
    {
        $d = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'days' => ['nullable', 'integer', 'between:1,10'], 'name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['required', 'string', 'max:120']]);
        $start = \Illuminate\Support\Carbon::parse($d['date']);
        for ($i = 0; $i < ($d['days'] ?? 1); $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            if (Holiday::whereDate('date', $date)->exists()) throw ValidationException::withMessages(['date' => [L::t("يوجد عطلة مسجلة بتاريخ {$date}", "A holiday already exists on {$date}")]]);
        }
        for ($i = 0; $i < ($d['days'] ?? 1); $i++) Holiday::create(['date' => $start->copy()->addDays($i)->toDateString(), 'name_ar' => $d['name_ar'], 'name_en' => $d['name_en']]);
        DayCalc::forgetHolidays($start->year); DayCalc::forgetHolidays($start->copy()->addDays(($d['days'] ?? 1) - 1)->year);
        return $this->holidays(new Request(['year' => $start->year]));
    }

    /** يضيف العطل ثابتة التاريخ لسنة (ما كان موجوداً يُترك) */
    public function fixedHolidays(Request $request): JsonResponse
    {
        $year = (int) $request->validate(['year' => ['required', 'integer', 'between:2000,2100']])['year'];
        foreach (self::FIXED_HOLIDAYS as [$md, $ar, $en]) if (! Holiday::whereDate('date', "{$year}-{$md}")->exists()) Holiday::create(['date' => "{$year}-{$md}", 'name_ar' => $ar, 'name_en' => $en]);
        DayCalc::forgetHolidays($year);
        return $this->holidays(new Request(['year' => $year]));
    }

    public function deleteHoliday(int $id): JsonResponse
    {
        $h = Holiday::findOrFail($id);
        $year = $h->date->year;
        $h->delete();
        DayCalc::forgetHolidays($year);
        return $this->holidays(new Request(['year' => $year]));
    }
}
