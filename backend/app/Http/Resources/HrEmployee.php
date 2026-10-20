<?php

namespace App\Http\Resources;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Attendance\RecentDays;
use App\Services\People\Directory;
use App\Services\People\EmployeeDocs;
use App\Support\Perm;
use App\Support\Settings;

/** مخرجات M07: صف الدليل، عقدة الهيكل، والملف الوظيفي بتبويباته */
class HrEmployee
{
    public const WITH = ['department', 'location'];

    public static function row(Employee $e): array
    {
        return [
            'id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'email' => $e->email, 'gender' => $e->gender,
            'title' => ['ar' => $e->title_ar, 'en' => $e->title_en],
            'dept' => ['id' => $e->department_id, 'code' => $e->department->code, 'color' => $e->department->color, 'ar' => $e->department->name_ar, 'en' => $e->department->name_en],
            'city' => ['ar' => $e->location->name_ar, 'en' => $e->location->name_en], 'hire_date' => $e->hire_date?->toDateString(), 'status' => $e->status,
        ];
    }

    public static function full(Employee $e, User $u): array
    {
        $e->loadMissing([...self::WITH, 'manager', 'onboarding', 'logs', 'assets.category', 'resignations']);
        $ent = Directory::entitlement($e);
        $shift = Settings::policy('shift');
        $hm = fn (int $m) => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        return array_merge(self::row($e), [
            'department_id' => $e->department_id, 'location_id' => $e->location_id, 'manager_id' => $e->manager_id,
            'title_ar' => $e->title_ar, 'title_en' => $e->title_en, 'name_ar' => $e->name, 'name_en' => $e->name_en,
            'manager' => $e->manager ? ['id' => $e->manager->id, 'ar' => $e->manager->name, 'en' => $e->manager->name_en] : null,
            'national_id' => $e->national_id, 'dob' => $e->dob?->toDateString(), 'phone' => $e->phone, 'nfc_uid' => $e->nfc_uid,
            'end_date' => $e->end_date?->toDateString(), 'shift' => $hm($shift['start']).' – '.$hm($shift['end']),
            'tenure_days' => Directory::tenureDays($e),
            'leave' => ['entitlement' => $ent, 'used' => $e->leave_used, 'remaining' => round($ent - $e->leave_used, 1), 'sick' => $e->sick_used],
            // مسار الحياة: ملف مباشرة (F-19) غير مثبّت بعد، واستقالة قائمة
            'onboarding' => (bool) $e->onboarding, 'resigned' => $e->resignations->contains(fn ($r) => $r->status !== 'rejected'),
            'attendance' => RecentDays::rows($e),
            'leaves' => LeaveRequest::where('employee_id', $e->id)->orderByDesc('from')->orderByDesc('id')->limit(100)->get()->map(fn (LeaveRequest $l) => [
                'id' => $l->id, 'type' => $l->type, 'type_name' => ['ar' => LeaveRequest::TYPES[$l->type][0] ?? $l->type, 'en' => LeaveRequest::TYPES[$l->type][1] ?? $l->type],
                'from' => $l->from->toDateString(), 'to' => $l->to->toDateString(), 'hours' => $l->hours, 'reason' => $l->reason, 'status' => $l->status]),
            'services' => ServiceRequest::where('employee_id', $e->id)->latest('id')->limit(50)->get()->map(fn (ServiceRequest $s) => [
                'id' => $s->id, 'kind' => $s->kind, 'note' => $s->note, 'status' => $s->status, 'at' => $s->created_at?->toIso8601String()]),
            'assets' => $e->assets->sortBy('id')->values()->map(fn (Asset $a) => HrOnboarding::asset($a)),
            'documents' => EmployeeDocs::visible($e, $u)->map(fn ($d) => EmployeeDocs::row($d))->values(),
            'logs' => $e->logs->sortBy([['at', 'desc'], ['id', 'desc']])->values()->map(fn ($l) => ['at' => $l->at->toIso8601String(), 'ar' => $l->text_ar, 'en' => $l->text_en, 'kind' => $l->kind, 'form' => $l->form]),
            'can' => ['edit' => Perm::can($u, 'employees', 'edit'), 'sensitive' => EmployeeDocs::canSensitive($u), 'onboarding' => (bool) $e->onboarding && ! $e->onboarding->confirmed_at],
        ]);
    }
}
