<?php

namespace App\Services\People;

use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Support\L;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

/**
 * تعديل الملف الوظيفي (M07): البيانات الشخصية والوظيفية — الراتب خارجها (محمي بخطوتين، المهمة 10).
 * تغيير الإدارة أو المدير أو المسمى يُكتب في السجل الوظيفي، والتدقيق تلقائي على النموذج.
 */
class EmployeeProfile
{
    public function update(Employee $e, array $input, User $by): Employee
    {
        $d = Validator::make($input, [
            'name' => ['required', 'string', 'max:190'], 'name_en' => ['required', 'string', 'max:190'], 'gender' => ['required', Rule::in(['M', 'F'])],
            'title_ar' => ['required', 'string', 'max:190'], 'title_en' => ['required', 'string', 'max:190'],
            'department_id' => ['required', 'integer'], 'location_id' => ['required', 'integer'], 'manager_id' => ['nullable', 'integer'],
            'email' => ['required', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:40', 'regex:/^\+?[0-9\s()-]{6,39}$/'],
            'national_id' => ['nullable', 'regex:/^\d{6,20}$/'], 'dob' => ['nullable', 'date_format:Y-m-d', 'before:-16 years'],
        ], [], [
            'name' => L::t('الاسم', 'Name'), 'name_en' => L::t('الاسم بالإنكليزية', 'English name'), 'title_ar' => L::t('المسمى الوظيفي', 'Job title'),
            'title_en' => L::t('المسمى بالإنكليزية', 'English title'), 'email' => L::t('البريد', 'Email'), 'phone' => L::t('الجوال', 'Mobile'), 'national_id' => L::t('الرقم الوطني', 'National ID'), 'dob' => L::t('تاريخ الولادة', 'Birth date'),
        ])->validate();
        $d['email'] = mb_strtolower(trim($d['email']));

        if (! Department::whereKey($d['department_id'])->exists()) throw ApiException::field('department_id', L::t('الإدارة غير موجودة', 'Unknown department'), 'unknown');
        if (! Location::whereKey($d['location_id'])->exists()) throw ApiException::field('location_id', L::t('مقر العمل غير موجود', 'Unknown location'), 'unknown');
        if ($d['manager_id']) {
            if (! Employee::current()->whereKey($d['manager_id'])->exists()) throw ApiException::field('manager_id', L::t('المدير غير موجود', 'Unknown manager'), 'unknown');
            if ($this->loops($e->id, (int) $d['manager_id'])) throw ApiException::field('manager_id', L::t('لا يمكن أن يتبع الموظف لنفسه أو لأحد مرؤوسيه.', 'An employee cannot report to themselves or to someone in their own team.'), 'manager_loop');
        }
        if (Employee::where('email', $d['email'])->whereKeyNot($e->id)->exists() || User::where('email', $d['email'])->where(fn ($q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $e->id))->exists()) {
            throw ApiException::field('email', L::t('البريد مستخدم لحساب آخر', 'This email is already used'), 'email_taken');
        }
        if ($d['national_id'] && Employee::where('national_id', $d['national_id'])->whereKeyNot($e->id)->exists()) {
            throw ApiException::field('national_id', L::t('الرقم الوطني مسجّل لموظف آخر', 'National ID belongs to another employee'), 'national_id_taken');
        }

        return DB::transaction(function () use ($e, $d, $by) {
            $old = ['dept' => $e->department_id, 'mgr' => $e->manager_id, 'title' => $e->title_ar];
            $e->fill($d)->save();
            $e->user?->email !== $d['email'] && $e->user?->update(['email' => $d['email']]);   // البريد = اسم الدخول
            $e->load(['department', 'manager']);
            $who = fn ($l) => $by->displayName($l);
            if ($old['dept'] !== $e->department_id) $e->log("نقل إلى إدارة {$e->department->name_ar} — {$who('ar')}", "Moved to {$e->department->name_en} — {$who('en')}");
            if ($old['mgr'] !== $e->manager_id) $e->log('تغيير المدير المباشر: '.($e->manager?->name ?? '—')." — {$who('ar')}", 'Direct manager changed: '.($e->manager?->name_en ?? '—')." — {$who('en')}");
            if ($old['title'] !== $e->title_ar) $e->log("تغيير المسمى الوظيفي: {$e->title_ar} — {$who('ar')}", "Job title changed: {$e->title_en} — {$who('en')}");
            return $e;
        });
    }

    /** ربط/تغيير بطاقة NFC — الرقم يُقرأ من القارئ (لوحة مفاتيح USB) أو يُكتب، وفريد على مستوى المنصة */
    public function card(Employee $e, ?string $uid, User $by): Employee
    {
        $uid = $uid ? strtoupper(trim(preg_replace('/[\s-]+/', ':', trim($uid)), ':')) : null;
        if ($uid && ! preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){3,9}$/', $uid)) {
            if (preg_match('/^[0-9A-F]{8,20}$/', $uid) && strlen($uid) % 2 === 0) $uid = implode(':', str_split($uid, 2));   // قارئ يرسل الرقم بلا فواصل
            else throw ApiException::field('uid', L::t('رقم البطاقة غير صالح (مثال 04:A1:B2:C3:D4).', 'Invalid card UID (e.g. 04:A1:B2:C3:D4).'), 'card_invalid');
        }
        if ($uid && Employee::allCompanies()->where('nfc_uid', $uid)->whereKeyNot($e->id)->exists()) throw ApiException::field('uid', L::t('البطاقة مربوطة بموظف آخر.', 'This card belongs to another employee.'), 'card_taken');
        $old = $e->nfc_uid;
        if ($old === $uid) return $e;
        $e->update(['nfc_uid' => $uid]);
        $e->log($uid ? ($old ? "تغيير بطاقة الحضور: {$uid} (بدل {$old})" : "ربط بطاقة الحضور: {$uid}") : "إلغاء ربط بطاقة الحضور {$old}",
            $uid ? ($old ? "Attendance card changed: {$uid} (was {$old})" : "Attendance card linked: {$uid}") : "Attendance card {$old} unlinked", $uid ? 'ok' : 'warn');
        return $e;
    }

    /** هل يصبح $managerId (أو أحد مدرائه) هو الموظف نفسه؟ */
    private function loops(int $employeeId, int $managerId): bool
    {
        $seen = [];
        for ($m = $managerId; $m && ! isset($seen[$m]); $m = Employee::whereKey($m)->value('manager_id')) {
            if ($m === $employeeId) return true;
            $seen[$m] = true;
        }
        return false;
    }
}
