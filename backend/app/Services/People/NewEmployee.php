<?php

namespace App\Services\People;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Onboarding;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmployeeWelcome;
use App\Services\Onboarding\F19;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use App\Support\Settings;
use App\Support\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * إنشاء ملف موظف بلا مسار توظيف: معالج «إضافة موظف» (M06 — addEmployee في الديمو) واستيراد الموظفين الحاليين من Excel.
 * المعالج: حالة «قيد المباشرة» بالبندين 1 و14 من F-19 وعهد بانتظار التوقيع. الاستيراد: حالة active بلا F-19.
 * لكل موظف حساب دخول بكلمة مرور مؤقتة تُرسل بالبريد ويُلزم بتغييرها أول دخول.
 */
class NewEmployee
{
    public const CURRENCIES = ['USD', 'SYP', 'EUR'];

    /**
     * تحقق وتطبيع سطر واحد (من المعالج أو من ملف الاستيراد). $codes/$emails/$ids = قيم سبقت في نفس الملف.
     * @return array{0: array, 1: array<string, string>} [القيم المطبّعة، الأخطاء حسب الحقل]
     */
    public static function check(array $r, bool $import, array $seen = []): array
    {
        $err = []; $v = [];
        $txt = fn ($k) => trim((string) ($r[$k] ?? ''));
        $need = fn ($k, $ar, $en) => $txt($k) === '' ? $err[$k] = L::t("{$ar} مطلوب", "{$en} is required") : null;

        foreach (['name' => ['الاسم', 'Name'], 'name_en' => ['الاسم بالإنكليزية', 'English name'], 'title_ar' => ['المسمى الوظيفي', 'Job title'], 'title_en' => ['المسمى بالإنكليزية', 'English title'], 'email' => ['البريد', 'Email']] as $k => [$ar, $en]) {
            $need($k, $ar, $en); $v[$k] = mb_substr($txt($k), 0, 190);
        }
        $v['email'] = mb_strtolower($v['email']);
        if ($v['email'] !== '' && ! filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $err['email'] = L::t('صيغة البريد غير صحيحة', 'Invalid email');
        elseif ($v['email'] !== '' && (in_array($v['email'], $seen['emails'] ?? [], true) || Employee::where('email', $v['email'])->exists() || User::where('email', $v['email'])->exists())) $err['email'] = L::t('البريد مستخدم لحساب آخر', 'This email is already used');

        $g = mb_strtolower($txt('gender'));
        $v['gender'] = in_array($g, ['m', 'ذكر', 'male'], true) ? 'M' : (in_array($g, ['f', 'أنثى', 'انثى', 'female'], true) ? 'F' : null);
        if (! $v['gender']) $err['gender'] = L::t('الجنس: M أو F', 'Gender: M or F');

        $v['national_id'] = preg_replace('/\s+/', '', $txt('national_id')) ?: null;
        if (! $import && ! $v['national_id']) $err['national_id'] = L::t('الرقم الوطني مطلوب', 'National ID is required');
        elseif ($v['national_id'] && (! preg_match('/^\d{6,20}$/', $v['national_id']))) $err['national_id'] = L::t('الرقم الوطني أرقام فقط', 'National ID must be digits');
        elseif ($v['national_id'] && (in_array($v['national_id'], $seen['ids'] ?? [], true) || Employee::where('national_id', $v['national_id'])->exists())) $err['national_id'] = L::t('الرقم الوطني مسجّل لموظف آخر', 'National ID belongs to another employee');

        $v['phone'] = mb_substr($txt('phone'), 0, 40) ?: null;
        if (! $import && (! $v['phone'] || strlen(preg_replace('/\D/', '', $v['phone'])) < 9)) $err['phone'] = L::t('رقم الجوال مطلوب', 'Mobile number is required');

        foreach (['dob' => [! $import, 'تاريخ الولادة', 'Birth date'], 'hire_date' => [true, 'تاريخ المباشرة', 'Start date']] as $k => [$req, $ar, $en]) {
            $v[$k] = self::date($r[$k] ?? null);
            if ($v[$k] === false || ($req && ! $v[$k])) $err[$k] = $v[$k] === false ? L::t("{$ar}: صيغة غير صحيحة (YYYY-MM-DD)", "{$en}: invalid date (YYYY-MM-DD)") : L::t("{$ar} مطلوب", "{$en} is required");
        }
        if ($v['dob'] && Carbon::parse($v['dob'])->diffInYears(now()) < 16) $err['dob'] = L::t('العمر أقل من 16 سنة', 'Younger than 16');

        $dept = $r['department_id'] ?? null ? Department::find((int) $r['department_id']) : self::byCodeOrName(Department::class, $txt('department'));
        $loc = $r['location_id'] ?? null ? Location::where('active', true)->find((int) $r['location_id']) : self::byCodeOrName(Location::class, $txt('location'));
        if (! $dept) $err['department_id'] = L::t('الإدارة غير موجودة', 'Unknown department');
        if (! $loc) $err['location_id'] = L::t('مقر العمل غير موجود', 'Unknown location');
        $v['department_id'] = $dept?->id; $v['location_id'] = $loc?->id;

        $v['manager_id'] = null; $v['manager_code'] = null;
        if ($r['manager_id'] ?? null) {
            $v['manager_id'] = Employee::current()->find((int) $r['manager_id'])?->id;
            if (! $v['manager_id']) $err['manager_id'] = L::t('المدير غير موجود', 'Unknown manager');
        } elseif ($mc = $txt('manager_code')) {
            $v['manager_id'] = Employee::current()->where('code', $mc)->value('id');
            if (! $v['manager_id'] && in_array($mc, $seen['codes'] ?? [], true)) $v['manager_code'] = $mc;   // مدير من سطر سابق في نفس الملف
            elseif (! $v['manager_id']) $err['manager_code'] = L::t('رقم المدير غير موجود', 'Unknown manager code');
        }
        $v['manager_id'] ??= $v['manager_code'] ? null : $dept?->head_id;

        $sal = str_replace([',', ' '], '', $txt('salary'));
        $v['salary'] = is_numeric($sal) ? round((float) $sal, 2) : null;
        if ($v['salary'] === null || $v['salary'] < 0) $err['salary'] = L::t('الراتب رقم مطلوب', 'Salary must be a number');
        $v['currency'] = strtoupper($txt('currency') ?: 'USD');
        if (! in_array($v['currency'], self::CURRENCIES, true)) $err['currency'] = L::t('العملة: USD أو SYP أو EUR', 'Currency: USD, SYP or EUR');

        $v['code'] = $import ? ($txt('code') ?: null) : null;
        if ($v['code'] && (in_array($v['code'], $seen['codes'] ?? [], true) || Employee::where('code', $v['code'])->exists())) $err['code'] = L::t('الرقم الوظيفي مستخدم', 'Employee ID already used');
        $v['nfc_uid'] = $import ? (strtoupper($txt('nfc_uid')) ?: null) : null;
        if ($v['nfc_uid'] && Employee::allCompanies()->where('nfc_uid', $v['nfc_uid'])->exists()) $err['nfc_uid'] = L::t('رقم البطاقة مسجّل لموظف آخر', 'Card UID belongs to another employee');
        return [$v, $err];
    }

    /** معالج إضافة موظف: ملف «قيد المباشرة» + حساب + عهد بانتظار التوقيع */
    public function create(array $input, array $assetIds, User $by): Employee
    {
        [$v, $err] = self::check($input, false);
        if ($err) throw ValidationException::withMessages(array_map(fn ($m) => [$m], $err));
        return DB::transaction(function () use ($v, $assetIds, $by) {
            $assets = Asset::whereIn('id', $assetIds)->lockForUpdate()->get();
            if ($assets->count() !== count(array_unique($assetIds)) || $assets->contains(fn ($a) => $a->status !== 'available')) throw ApiException::field('asset_ids', L::t('بعض العهد المختارة لم تعد متاحة — حدّث القائمة.', 'Some selected assets are no longer available — refresh the list.'), 'asset_unavailable');
            $e = $this->make($v, 'onboarding', Counters::employeeCode(), Employee::newNfcUid());
            Onboarding::create(['employee_id' => $e->id, 'checklist' => F19::blank(0, 13)]);
            $e->log("إنشاء ملف موظف مباشرة (معالج إضافة موظف) — {$e->code} ({$by->displayName('ar')})", "Employee file created via the wizard — {$e->code} ({$by->displayName('en')})", 'ok');
            foreach ($assets as $a) {
                $a->update(['employee_id' => $e->id, 'status' => 'pending_ack', 'assigned_at' => now()]);
                $e->log("تسليم عهدة: {$a->name_ar} ({$a->tag}) — بانتظار توقيع الاستلام", "Custody issued: {$a->name_en} ({$a->tag}) — awaiting signature");
            }
            $this->account($e, true);
            Notify::hr("أُضيف الموظف {$e->name} — بدأت إجراءات المباشرة", "{$e->name_en} added — onboarding started", "onboard:{$e->id}");
            Audit::record('employee.added', $e, null, ['via' => 'wizard', 'assets' => $assets->pluck('tag')]);
            return $e;
        });
    }

    /** استيراد سطر جاهز (بعد المعاينة): موظف active بلا F-19 */
    public function import(array $v, bool $invite, User $by, array &$codes): Employee
    {
        $code = $v['code'] ?: Counters::employeeCode();
        if ($v['manager_code']) $v['manager_id'] = $codes[$v['manager_code']] ?? null;
        $e = $this->make($v, 'active', $code, $v['nfc_uid']);
        $e->log("استيراد ملف الموظف من Excel — {$e->code} ({$by->displayName('ar')})", "Employee imported from Excel — {$e->code} ({$by->displayName('en')})", 'ok');
        $this->account($e, $invite);
        $codes[$code] = $e->id;
        if (preg_match('/^'.preg_quote(Settings::prefix('employee'), '/').'-(\d+)$/', $code, $m)) Counters::atLeast('employee', (int) $m[1]);
        return $e;
    }

    private function make(array $v, string $status, string $code, ?string $nfc): Employee
    {
        return Employee::create([
            'code' => $code, 'name' => $v['name'], 'name_en' => $v['name_en'], 'gender' => $v['gender'], 'title_ar' => $v['title_ar'], 'title_en' => $v['title_en'],
            'department_id' => $v['department_id'], 'location_id' => $v['location_id'], 'email' => $v['email'], 'phone' => $v['phone'], 'hire_date' => $v['hire_date'],
            'dob' => $v['dob'], 'national_id' => $v['national_id'], 'salary' => $v['salary'], 'currency' => $v['currency'], 'status' => $status, 'nfc_uid' => $nfc, 'manager_id' => $v['manager_id'],
        ]);
    }

    /** حساب الدخول: كلمة مرور مؤقتة + إلزام بالتغيير، تُرسل بالبريد */
    private function account(Employee $e, bool $invite): User
    {
        $password = Str::password(12, symbols: false);
        $u = User::create(['name' => $e->name, 'name_en' => $e->name_en, 'email' => $e->email, 'password' => $password, 'must_change_password' => true, 'active' => true,
            'employee_id' => $e->id, 'company_id' => Tenant::id()]);
        $u->roles()->sync(Role::where('key', 'employee')->pluck('id'));
        if ($invite) { $co = Tenant::company(); $u->notify((new EmployeeWelcome($co->name_ar, $co->name_en, $e->code, $password))->afterCommit()); }
        return $u;
    }

    /** تاريخ من خلية Excel (تاريخ، رقم تسلسلي، أو نص) — null فارغ، false غير صالح */
    public static function date(mixed $x): string|false|null
    {
        if ($x instanceof \DateTimeInterface) return Carbon::instance($x)->toDateString();
        $s = trim((string) $x);
        if ($s === '') return null;
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) return Carbon::create(1899, 12, 30)->addDays((int) $s)->toDateString();
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $f) {
            $d = \DateTime::createFromFormat("!{$f}", $s);
            if ($d && $d->format($f) === $s) return $d->format('Y-m-d');
        }
        return false;
    }

    private static function byCodeOrName(string $model, string $s): mixed
    {
        if ($s === '') return null;
        $l = mb_strtolower($s);
        return $model::where(fn ($q) => $q->whereRaw('lower(code) = ?', [$l])->orWhere('name_ar', $s)->orWhereRaw('lower(name_en) = ?', [$l]))->first();
    }
}
