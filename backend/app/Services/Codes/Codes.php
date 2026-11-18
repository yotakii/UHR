<?php

namespace App\Services\Codes;

use App\Exceptions\ApiException;
use App\Models\CodeItem;
use App\Models\CodeList;
use App\Models\Department;
use App\Models\Location;
use App\Support\L;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * قلب دليل الترميز: القراءة الموحدة (Lookup — FR-CS-016)، التحقق (النمط، التفرد شاملاً المعطل، الأب، عدم الدوران)،
 * قفل الرمز بعد أول استخدام، التعطيل بدل الحذف، عدّ الاستخدام، واقتراح الرمز التالي.
 */
class Codes
{
    /** أين يُستخدم كل رمز: list => [[جدول، عمود، يُنقل عند الدمج؟]] — الموديولات اللاحقة تضيف هنا */
    public const USAGE = [
        'grades' => [['employees', 'grade_id', true]],
        'jobs' => [['employees', 'job_id', true]],
        'worker_categories' => [['employees', 'worker_category_id', true]],
        'contract_types' => [['employees', 'contract_type_id', true], ['contracts', 'contract_type_id', false]],
        'cost_centers' => [['employees', 'cost_center_id', true], ['missions', 'cost_center_id', true]],
        'mission_types' => [['missions', 'type_id', false]],
        'mission_categories' => [['missions', 'category_id', true]],
        'bonus_types' => [['bonuses', 'type_id', false]],
        'pay_elements' => [['pay_lines', 'element_id', false]],
        'departments' => [['employees', 'department_id', true], ['job_openings', 'department_id', true], ['kpis', 'department_id', true], ['development_plans', 'department_id', true],
            ['succession_plans', 'department_id', true], ['announcements', 'department_id', true], ['performance_cycles', 'department_id', true], ['salary_grants', 'department_id', true]],
        'branches' => [['employees', 'location_id', true], ['job_openings', 'location_id', true], ['devices', 'location_id', true]],
    ];

    public static function config(): array { return Settings::merged('codes'); }

    public static function list(string $key): CodeList
    {
        return CodeList::where('key', $key)->orderByRaw('company_id is null')->first()
            ?? throw new ApiException(L::t('القائمة غير موجودة.', 'List not found.'), 'not_found', 404);
    }

    /** واجهة القراءة الموحدة: الرموز الفعالة اليوم (أو كلها) — مخزّنة مؤقتاً وتُبطل مع كل تغيير */
    public static function items(string $key, bool $activeOnly = true, ?int $parent = null, ?string $q = null): array
    {
        $l = self::list($key);
        $all = Cache::remember('codes:'.(Tenant::id() ?? 0).':'.self::version().":{$key}", 300, fn () => self::rows($l));
        return array_values(array_filter($all, fn ($r) => (! $activeOnly || $r['live'])
            && ($parent === null || $r['parent_id'] === $parent)
            && (! $q || str_contains(mb_strtolower($r['code'].' '.$r['ar'].' '.$r['en'].' '.($r['legacy_code'] ?? '')), mb_strtolower($q)))));
    }

    public static function version(): int { return (int) Cache::get('codes-ver:'.(Tenant::id() ?? 0), 0); }
    public static function bump(): void { Cache::forever('codes-ver:'.(Tenant::id() ?? 0), self::version() + 1); }

    /** صفوف القائمة بصيغة موحدة — المرتبطة بجداولها (الإدارات، الفروع) تُقرأ منها مباشرة */
    public static function rows(CodeList $l): array
    {
        $today = now()->toDateString();
        if ($l->source === 'departments') return Department::orderBy('sort')->orderBy('id')->get()->map(fn (Department $d) => [
            'id' => $d->id, 'code' => $d->code, 'ar' => $d->name_ar, 'en' => $d->name_en, 'parent_id' => null, 'status' => $d->active ? 'active' : 'inactive', 'live' => (bool) $d->active,
            'attrs' => null, 'valid_from' => null, 'valid_to' => null, 'legacy_code' => null, 'description' => null, 'sort' => (int) $d->sort, 'locked' => null, 'shared' => false, 'bound' => true])->all();
        if ($l->source === 'locations') return Location::orderByRaw('company_id is null')->orderBy('id')->get()->map(fn (Location $x) => [
            'id' => $x->id, 'code' => $x->code, 'ar' => $x->name_ar, 'en' => $x->name_en, 'parent_id' => null, 'status' => $x->active ? 'active' : 'inactive', 'live' => (bool) $x->active,
            'attrs' => ['timezone' => $x->timezone ?? null], 'valid_from' => null, 'valid_to' => null, 'legacy_code' => null, 'description' => null, 'sort' => (int) $x->id, 'locked' => null,
            'shared' => $x->company_id === null, 'bound' => true])->all();
        return CodeItem::where('code_list_id', $l->id)->orderBy('sort')->orderBy('code')->get()->map(fn (CodeItem $i) => [
            'id' => $i->id, 'code' => $i->code, 'ar' => $i->name_ar, 'en' => $i->name_en, 'parent_id' => $i->parent_id, 'status' => $i->status,
            'live' => $i->status === 'active' && (! $i->valid_from || $i->valid_from->toDateString() <= $today) && (! $i->valid_to || $i->valid_to->toDateString() >= $today),
            'attrs' => $i->attrs, 'valid_from' => $i->valid_from?->toDateString(), 'valid_to' => $i->valid_to?->toDateString(), 'legacy_code' => $i->legacy_code,
            'description' => $i->description, 'sort' => (int) $i->sort, 'locked' => $i->used_at !== null, 'shared' => $i->company_id === null, 'bound' => false, 'inherited' => (bool) $i->group_item_id,
            'replaced_by_id' => $i->replaced_by_id])->all();
    }

    /** عدد السجلات المرتبطة بالرمز في كل جدول (تقرير الأثر) */
    public static function usage(CodeList $l, int $id): array
    {
        $out = [];
        foreach (self::USAGE[$l->key] ?? [] as [$table, $col]) {
            $q = DB::table($table)->where($col, $id);
            if (Tenant::id() && \Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id')) $q->where('company_id', Tenant::id());
            if ($n = $q->count()) $out[$table] = $n;
        }
        if (! $l->source) {
            $children = CodeItem::where('parent_id', $id)->count(); if ($children) $out['code_items'] = $children;
            $ref = CodeItem::where('code_list_id', '!=', $l->id)->whereRaw("attrs::text like ?", ['%"'.(CodeItem::find($id)?->code ?? '#').'"%'])->count(); if ($ref) $out['attrs'] = $ref;
        }
        return $out;
    }

    /** عدد الاستخدام لكل رموز القائمة دفعة واحدة */
    public static function usageCounts(CodeList $l): array
    {
        $counts = [];
        foreach (self::USAGE[$l->key] ?? [] as [$table, $col]) {
            $q = DB::table($table)->whereNotNull($col)->selectRaw("{$col} as id, count(*) as n")->groupBy($col);
            if (Tenant::id() && \Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id')) $q->where('company_id', Tenant::id());
            foreach ($q->get() as $r) $counts[$r->id] = ($counts[$r->id] ?? 0) + $r->n;
        }
        if (! $l->source) foreach (CodeItem::whereNotNull('parent_id')->whereIn('parent_id', CodeItem::where('code_list_id', $l->id)->pluck('id'))->selectRaw('parent_id, count(*) as n')->groupBy('parent_id')->get() as $r)
            $counts[$r->parent_id] = ($counts[$r->parent_id] ?? 0) + $r->n;
        return $counts;
    }

    /** رمز فعّال اليوم من قائمة، بالمعرّف أو بالرمز أو بالرمز السابق (للاستيراد) */
    public static function resolve(string $key, mixed $value): ?CodeItem
    {
        if ($value === null || $value === '') return null;
        $l = CodeList::where('key', $key)->orderByRaw('company_id is null')->first(); if (! $l) return null;
        $q = CodeItem::where('code_list_id', $l->id)->live();
        return is_int($value) || ctype_digit((string) $value) && ! preg_match('/^0/', (string) $value) && (clone $q)->whereKey((int) $value)->exists()
            ? (clone $q)->find((int) $value)
            : (clone $q)->where(fn ($w) => $w->where('code', strtoupper(trim((string) $value)))->orWhere('legacy_code', trim((string) $value)))->first();
    }

    /** يقفل الرموز عند أول استخدام (يُستدعى بعد حفظ سجل يحمل رموزاً) */
    public static function markUsed(?int ...$ids): void
    {
        $ids = array_values(array_filter($ids));
        if ($ids && CodeItem::whereIn('id', $ids)->whereNull('used_at')->update(['used_at' => now()])) self::bump();
    }

    public static function isLocked(CodeList $l, int $id): bool
    {
        if ($l->source) return (bool) self::usage($l, $id);
        $i = CodeItem::where('code_list_id', $l->id)->findOrFail($id);
        return $i->used_at !== null || (bool) self::usage($l, $id);
    }

    /** يتحقق من رمز جديد/معدّل ويعيد القيم الجاهزة للحفظ */
    public static function validate(CodeList $l, array $v, ?CodeItem $item = null): array
    {
        if ($l->system) throw new ApiException(L::t('القوائم القياسية مقفلة ومشتركة ولا تُعدَّل.', 'Standard lists are locked and shared.'), 'system_list', 409);
        if ($l->source) throw new ApiException(L::t('هذه القائمة تُدار من صفحتها الخاصة.', 'This list is managed from its own page.'), 'bound_list', 409);
        if ($item?->group_item_id) throw new ApiException(L::t('هذا الرمز موروث من قوائم المجموعة ويُعدَّل في الشركة الأم.', 'This code is inherited from the group lists and is edited in the parent company.'), 'inherited', 409);
        $err = [];
        $code = strtoupper(trim((string) ($v['code'] ?? $item?->code)));
        if ($item && $code !== $item->code && self::isLocked($l, $item->id))
            throw ApiException::field('code', L::t('الرمز مستخدم في سجلات ولا يمكن تعديله — يمكن تعديل الاسم فقط.', 'This code is in use and cannot change — only its name can.'), 'code_locked');
        if ($code === '') $err['code'] = L::t('الرمز مطلوب.', 'Code is required.');
        elseif ($l->pattern && ! @preg_match('/'.$l->pattern.'/', $code)) $err['code'] = str_replace(':ex', (string) $l->example, L::t('الرمز لا يطابق نمط القائمة (مثال: :ex).', 'The code does not match the list pattern (e.g. :ex).'));
        elseif (! preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $code)) $err['code'] = L::t('أحرف لاتينية كبيرة وأرقام و - فقط، دون مسافات.', 'Capital Latin letters, digits and - only, no spaces.');
        elseif (CodeItem::where('code_list_id', $l->id)->where('code', $code)->when($item, fn ($q) => $q->where('id', '!=', $item->id))->exists())
            $err['code'] = str_replace(':next', self::suggest($l, $code), L::t('الرمز مستخدم سابقاً في هذه القائمة (ولو كان معطلاً) — جرّب :next.', 'This code was already used in this list (even if inactive) — try :next.'));
        $ar = trim((string) ($v['ar'] ?? $item?->name_ar)); $en = trim((string) ($v['en'] ?? $item?->name_en));
        if ($ar === '') $err['ar'] = L::t('الاسم بالعربية مطلوب.', 'Arabic name is required.');
        if ($en === '') $err['en'] = L::t('الاسم بالإنكليزية مطلوب.', 'English name is required.');
        $parent = array_key_exists('parent_id', $v) ? ($v['parent_id'] ?: null) : $item?->parent_id;
        if ($parent) {
            $parentList = $l->parent_key ? self::list($l->parent_key)->id : ($l->hierarchical ? $l->id : null);
            if (! $parentList || ! CodeItem::where('code_list_id', $parentList)->whereKey($parent)->exists()) $err['parent_id'] = L::t('الرمز الأب غير موجود في قائمته.', 'The parent code does not exist in its list.');
            elseif ($item && $l->hierarchical && ! $l->parent_key) {   // منع الدوران في الهرمية
                $p = $parent; $seen = [];
                while ($p && ! isset($seen[$p])) { if ($p === $item->id) { $err['parent_id'] = L::t('لا يمكن جعل الرمز تابعاً لأحد فروعه.', 'A code cannot sit under one of its own children.'); break; } $seen[$p] = 1; $p = CodeItem::whereKey($p)->value('parent_id'); }
            }
        }
        $from = $v['valid_from'] ?? $item?->valid_from?->toDateString(); $to = $v['valid_to'] ?? $item?->valid_to?->toDateString();
        if ($from && $to && $to < $from) $err['valid_to'] = L::t('تاريخ النهاية قبل البداية.', 'The end date is before the start.');
        $attrs = self::cleanAttrs($l, $v['attrs'] ?? $item?->attrs ?? [], $err);
        if ($err) throw new ApiException(L::t('راجع الحقول المظللة.', 'Check the highlighted fields.'), 'invalid', 422, array_map(fn ($m) => [$m], $err));
        return ['code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'parent_id' => $parent, 'description' => array_key_exists('description', $v) ? ($v['description'] ?: null) : $item?->description,
            'attrs' => $attrs ?: null, 'valid_from' => $from ?: null, 'valid_to' => $to ?: null, 'legacy_code' => array_key_exists('legacy_code', $v) ? (trim((string) $v['legacy_code']) ?: null) : $item?->legacy_code,
            'sort' => (int) ($v['sort'] ?? $item?->sort ?? (CodeItem::where('code_list_id', $l->id)->max('sort') + 1))];
    }

    private static function cleanAttrs(CodeList $l, array $attrs, array &$err): array
    {
        $out = [];
        foreach ($l->attrs_schema ?? [] as $f) {
            $val = $attrs[$f['key']] ?? null;
            if ($val === null || $val === '') continue;
            $out[$f['key']] = match ($f['type']) {
                'bool' => (bool) $val,
                'number' => is_numeric($val) ? $val + 0 : ($err['attrs.'.$f['key']] = L::t('رقم فقط.', 'Numbers only.')),
                'select' => in_array($val, $f['options'] ?? [], true) ? $val : ($err['attrs.'.$f['key']] = L::t('قيمة غير معروفة.', 'Unknown value.')),
                'list' => collect(self::items($f['list'], false))->contains('code', $val) ? $val : ($err['attrs.'.$f['key']] = L::t('رمز غير موجود في القائمة.', 'Code not in the list.')),
                default => mb_substr(trim((string) $val), 0, 200),
            };
        }
        return $out;
    }

    /** الرمز التالي في التسلسل (مع تجاوز المستخدم سابقاً ولو معطلاً) — للتصحيح وتقرير الأثر */
    public static function suggest(CodeList $l, string $seed = ''): string
    {
        $codes = $l->source ? collect(self::rows($l))->pluck('code')->map(fn ($c) => strtoupper($c))->all() : CodeItem::where('code_list_id', $l->id)->pluck('code')->all();
        $base = $seed !== '' ? strtoupper($seed) : (string) $l->example;
        if ($l->key === 'jobs') {   // JB-{tag}-{nnn}
            $tag = preg_match('/^JB-([A-Z0-9]+)/', $base, $m) ? $m[1] : (preg_match('/^[A-Z0-9]{2,6}$/', $base) ? $base : 'GEN');
            for ($n = 1; ; $n++) { $c = sprintf('JB-%s-%03d', $tag, $n); if (! in_array($c, $codes, true)) return $c; }
        }
        if (preg_match('/^(.*?)(\d+)$/', $base, $m)) {
            [$prefix, $digits] = [$m[1], strlen($m[2])];
            $max = 0; foreach ($codes as $c) if (str_starts_with($c, $prefix) && preg_match('/^\d+$/', $rest = substr($c, strlen($prefix)))) $max = max($max, (int) $rest);
            for ($n = max($max + 1, 1); ; $n++) { $c = $prefix.str_pad((string) $n, $digits, '0', STR_PAD_LEFT); if (! in_array($c, $codes, true)) return $c; }
        }
        return $base;
    }

    /** يحفظ رمزاً مباشرة (في مرحلة التأسيس، أو بعد اعتماد طلب تغيير) */
    public static function store(CodeList $l, array $v, ?CodeItem $item = null, string $status = 'active'): CodeItem
    {
        $data = self::validate($l, $v, $item);
        if ($item) $item->update($data); else $item = CodeItem::create($data + ['code_list_id' => $l->id, 'status' => $status]);
        self::bump();
        if ($l->inherit && ($c = Tenant::company()) && \App\Models\Company::where('parent_id', $c->id)->exists()) CodeCatalog::syncGroup($c);
        return $item;
    }

    public static function setStatus(CodeList $l, int $id, bool $active, ?string $until = null): void
    {
        if ($l->system) throw new ApiException(L::t('القوائم القياسية مقفلة.', 'Standard lists are locked.'), 'system_list', 409);
        if ($l->source === 'departments') {
            $d = Department::findOrFail($id);
            if (! $active && \App\Models\Employee::current()->where('department_id', $id)->exists())
                throw new ApiException(L::t('لا يمكن تعطيل إدارة فيها موظفون حاليون — انقلهم أو ادمجها أولاً.', 'A department with current employees cannot be deactivated — move or merge them first.'), 'dept_in_use', 409);
            $d->update(['active' => $active]);
        } elseif ($l->source === 'locations') {
            $x = Location::whereNotNull('company_id')->findOrFail($id);
            if (! $active && \App\Models\Employee::current()->where('location_id', $id)->exists())
                throw new ApiException(L::t('لا يمكن تعطيل فرع فيه موظفون حاليون.', 'A branch with current employees cannot be deactivated.'), 'branch_in_use', 409);
            $x->update(['active' => $active]);
        } else {
            $i = CodeItem::where('code_list_id', $l->id)->findOrFail($id);
            $i->update($active ? ['status' => 'active', 'valid_to' => null] : ['status' => 'inactive', 'valid_to' => $until ?: now()->toDateString()]);
        }
        self::bump();
    }

    /** نمط رمز القائمة (للصفحات المرتبطة: الإدارات والفروع) */
    public static function pattern(string $key, string $fallback): string
    {
        $p = CodeList::where('key', $key)->value('pattern');
        return $p && @preg_match('/'.$p.'/', '') !== false ? $p : $fallback;
    }

    /** تقرير الرموز غير المستخدمة منذ N شهراً، والمكررة بالاسم (FR-CS-020) */
    public static function reports(): array
    {
        $months = (int) self::config()['unused_months']; $since = now()->subMonths($months);
        $norm = fn ($s) => preg_replace('/\s+/u', ' ', strtr(mb_strtolower(trim($s)), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']));
        $unused = []; $dups = [];
        foreach (CodeList::where('system', false)->orderBy('sort')->get() as $l) {
            $counts = self::usageCounts($l); $rows = self::rows($l);
            foreach ($rows as $r) if ($r['status'] === 'active' && empty($counts[$r['id']]) && ! $r['shared']) {
                $created = $l->source ? null : CodeItem::whereKey($r['id'])->value('created_at');
                if (! $l->source && $created && \Carbon\Carbon::parse($created)->gt($since)) continue;
                $unused[] = ['list' => $l->key, 'list_ar' => $l->name_ar, 'list_en' => $l->name_en, 'id' => $r['id'], 'code' => $r['code'], 'ar' => $r['ar'], 'en' => $r['en']];
            }
            foreach (collect($rows)->groupBy(fn ($r) => $norm($r['ar'])) as $group) if ($group->count() > 1)
                $dups[] = ['list' => $l->key, 'list_ar' => $l->name_ar, 'list_en' => $l->name_en, 'name' => $group->first()['ar'], 'items' => $group->map(fn ($r) => ['id' => $r['id'], 'code' => $r['code'], 'status' => $r['status']])->values()];
        }
        return ['months' => $months, 'unused' => $unused, 'duplicates' => $dups];
    }
}
