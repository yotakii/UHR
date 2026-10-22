<?php

namespace App\Services\Assets;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetMovement;
use App\Models\Employee;
use App\Models\User;
use App\Services\Onboarding\OnboardingFlow;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * العهد M10 (SRS §6 M10): كل أصل له حالة واحدة وحائز واحد، ولا يُعتبر مسلّماً قبل توقيع الموظف.
 * available ← (تسليم) pending_ack ← (توقيع الموظف من «وثائقي») assigned ← (استرجاع بحالته) available / maintenance.
 * وأصل في الصيانة يعود للمستودع، والمستبعد (retired) لا يُسلَّم. كل حركة تُسجَّل في asset_movements وفي السجل الوظيفي.
 */
class Custody
{
    /** سجل حركة */
    public static function move(Asset $a, string $action, ?int $employeeId = null, ?User $by = null, ?string $note = null, ?string $condition = null): void
    {
        AssetMovement::create(['asset_id' => $a->id, 'employee_id' => $employeeId, 'action' => $action, 'condition' => $condition, 'note' => $note, 'user_id' => $by?->id, 'at' => now()]);
    }

    public function list(array $f): Builder
    {
        $q = Asset::with(['category', 'employee'])->orderBy('tag');
        if (($f['status'] ?? 'all') !== 'all') $q->where('status', $f['status']);
        if ($f['category'] ?? null) $q->where('asset_category_id', $f['category']);
        if ($s = trim((string) ($f['q'] ?? ''))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
            $q->where(fn ($w) => $w->where('tag', 'ilike', $like)->orWhere('name_ar', 'ilike', $like)->orWhere('name_en', 'ilike', $like)->orWhere('serial', 'ilike', $like)
                ->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', $like)->orWhere('name_en', 'ilike', $like)->orWhere('code', 'ilike', $like)));
        }
        return $q;
    }

    public function summary(): array
    {
        $by = Asset::selectRaw('status, count(*) n, coalesce(sum(value),0) v')->groupBy('status')->get()->keyBy('status');
        $n = fn ($s) => (int) ($by[$s]->n ?? 0);
        return ['issued' => $n('assigned') + $n('pending_ack'), 'issued_value' => round((float) (($by['assigned']->v ?? 0) + ($by['pending_ack']->v ?? 0)), 2),
            'pending' => $n('pending_ack'), 'available' => $n('available'), 'maintenance' => $n('maintenance'), 'retired' => $n('retired'), 'total' => (int) $by->sum('n')];
    }

    /** إضافة أو تعديل بيانات أصل (لا يغيّر الحالة ولا الحائز) */
    public function save(?Asset $a, array $in, User $by): Asset
    {
        $d = Validator::make($in, [
            'name_ar' => ['required', 'string', 'max:190'], 'name_en' => ['required', 'string', 'max:190'], 'asset_category_id' => ['required', 'integer'],
            'serial' => ['nullable', 'string', 'max:60'], 'value' => ['required', 'numeric', 'min:0', 'max:10000000'], 'condition' => ['required', Rule::in(Asset::CONDITIONS)],
            'purchased_at' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['name_ar' => L::t('اسم الأصل', 'Asset name'), 'name_en' => L::t('الاسم بالإنكليزية', 'English name'), 'asset_category_id' => L::t('الفئة', 'Category'),
            'serial' => L::t('الرقم التسلسلي', 'Serial'), 'value' => L::t('القيمة', 'Value'), 'purchased_at' => L::t('تاريخ الشراء', 'Purchase date')])->validate();
        if (! AssetCategory::whereKey($d['asset_category_id'])->exists()) throw ApiException::field('asset_category_id', L::t('الفئة غير موجودة', 'Unknown category'), 'unknown');
        $d['serial'] = trim((string) ($d['serial'] ?? '')) ?: null;
        if ($d['serial'] && Asset::where('serial', $d['serial'])->when($a, fn ($q) => $q->where('id', '!=', $a->id))->exists()) {
            throw ApiException::field('serial', L::t('هذا الرقم التسلسلي مسجّل لأصل آخر.', 'This serial number belongs to another asset.'), 'duplicate_serial');
        }
        return DB::transaction(function () use ($a, $d, $by) {
            if ($a) { $a->update($d); self::move($a, 'edited', $a->employee_id, $by); return $a->refresh(); }
            $a = Asset::create($d + ['tag' => Counters::assetTag(), 'status' => 'available']);
            self::move($a, 'created', null, $by, null, $d['condition']);
            return $a;
        });
    }

    /** تسليم من المستودع ← بانتظار توقيع الموظف */
    public function issue(Asset $a, Employee $e, User $by, ?string $note = null): Asset
    {
        if (in_array($e->status, ['terminated', 'resigning'], true)) throw new ApiException(L::t('خدمة الموظف منتهية أو قيد الإنهاء.', "The employee's service is ending or ended."), 'employee_inactive');
        return DB::transaction(function () use ($a, $e, $by, $note) {
            $a = Asset::whereKey($a->id)->lockForUpdate()->first();
            if ($a->status !== 'available') throw new ApiException(L::t('هذا الأصل غير متاح في المستودع.', 'This asset is not available in stock.'), 'asset_unavailable');
            $a->update(['employee_id' => $e->id, 'status' => 'pending_ack', 'assigned_at' => now(), 'signed_at' => null, 'signature_file_id' => null, 'returned_at' => null]);
            self::move($a, 'issued', $e->id, $by, $note, $a->condition);
            $e->log("تسليم عهدة: {$a->name_ar} ({$a->tag}) — بانتظار توقيع الاستلام ({$by->displayName('ar')})", "Custody issued: {$a->name_en} ({$a->tag}) — awaiting signature ({$by->displayName('en')})");
            Notify::user($e->user()->value('id'), "سُلّمت لك عهدة: {$a->name_ar} — وقّع سند الاستلام", "Asset issued to you: {$a->name_en} — please sign the receipt", 'docs');
            OnboardingFlow::syncCustodyFor($e);
            return $a->refresh();
        });
    }

    /** استرجاع (أو إلغاء تسليم لم يُوقَّع) بحالة الأصل — التالف يذهب للصيانة */
    public function takeBack(Asset $a, string $condition, ?string $note, User $by): Asset
    {
        if (! in_array($condition, Asset::CONDITIONS, true)) throw ApiException::field('condition', L::t('اختر حالة الأصل عند الاستلام.', 'Choose the asset condition.'), 'condition');
        return DB::transaction(function () use ($a, $condition, $note, $by) {
            $a = Asset::with('employee')->whereKey($a->id)->lockForUpdate()->first();
            if (! in_array($a->status, ['assigned', 'pending_ack'], true) || ! $a->employee) throw new ApiException(L::t('الأصل غير مسلّم لأحد.', 'This asset is not issued to anyone.'), 'asset_not_issued');
            $e = $a->employee;
            $signed = $a->status === 'assigned';
            $to = $condition === 'damaged' ? 'maintenance' : 'available';
            $a->update(['employee_id' => null, 'status' => $to, 'condition' => $condition, 'returned_at' => now(), 'assigned_at' => null, 'signed_at' => null, 'signature_file_id' => $signed ? $a->signature_file_id : null]);
            self::move($a, $signed ? 'returned' : 'cancelled', $e->id, $by, $note, $condition);
            $cond = ['good' => ['سليمة', 'good'], 'fair' => ['مقبولة', 'fair'], 'damaged' => ['تالفة', 'damaged']][$condition];
            $e->log(($signed ? 'استرجاع عهدة' : 'إلغاء تسليم عهدة').": {$a->name_ar} ({$a->tag}) — {$cond[0]} ({$by->displayName('ar')})", ($signed ? 'Custody returned' : 'Custody issue cancelled').": {$a->name_en} ({$a->tag}) — {$cond[1]} ({$by->displayName('en')})", $condition === 'damaged' ? 'warn' : 'info');
            Notify::user($e->user()->value('id'), "استُرجعت العهدة: {$a->name_ar} ({$a->tag})", "Asset returned: {$a->name_en} ({$a->tag})", 'docs');
            OnboardingFlow::syncCustodyFor($e);
            return $a->refresh();
        });
    }

    /** صيانة ← مستودع، مستودع ← صيانة، أو استبعاد نهائي (لأصل غير مسلّم) */
    public function setStatus(Asset $a, string $status, ?string $note, User $by): Asset
    {
        $allowed = ['maintenance' => ['available'], 'available' => ['maintenance'], 'retired' => ['available', 'maintenance']];
        if (! isset($allowed[$status]) || ! in_array($a->status, $allowed[$status], true)) throw new ApiException(L::t('لا يمكن نقل الأصل لهذه الحالة الآن.', 'The asset cannot move to this status now.'), 'bad_status');
        if ($status === 'retired' && mb_strlen(trim((string) $note)) < 5) throw ApiException::field('note', L::t('اكتب سبب الاستبعاد.', 'Write the reason for retiring the asset.'), 'reason_required');
        $a->update(['status' => $status] + ($status === 'available' ? ['condition' => 'good'] : []));
        self::move($a, $status, null, $by, $note, $a->condition);
        return $a->refresh();
    }

    public static function row(Asset $a): array
    {
        return ['id' => $a->id, 'tag' => $a->tag, 'name' => ['ar' => $a->name_ar, 'en' => $a->name_en], 'name_ar' => $a->name_ar, 'name_en' => $a->name_en,
            'category' => ['id' => $a->asset_category_id, 'ar' => $a->category->name_ar, 'en' => $a->category->name_en, 'code' => $a->category->code],
            'serial' => $a->serial, 'value' => $a->value, 'condition' => $a->condition, 'purchased_at' => $a->purchased_at?->toDateString(), 'notes' => $a->notes, 'status' => $a->status,
            'holder' => $a->employee ? ['id' => $a->employee->id, 'code' => $a->employee->code, 'ar' => $a->employee->name, 'en' => $a->employee->name_en] : null,
            'assigned_at' => $a->assigned_at?->toIso8601String(), 'signed_at' => $a->signed_at?->toIso8601String(), 'returned_at' => $a->returned_at?->toIso8601String()];
    }

    public static function history(Asset $a): array
    {
        return $a->movements()->with(['employee', 'user.employee'])->limit(100)->get()->map(fn (AssetMovement $m) => ['action' => $m->action, 'at' => $m->at->toIso8601String(),
            'employee' => $m->employee ? ['id' => $m->employee->id, 'ar' => $m->employee->name, 'en' => $m->employee->name_en] : null, 'condition' => $m->condition, 'note' => $m->note,
            'by' => $m->user ? ['ar' => $m->user->displayName('ar'), 'en' => $m->user->displayName('en')] : null])->all();
    }
}
