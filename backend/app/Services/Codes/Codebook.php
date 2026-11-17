<?php

namespace App\Services\Codes;

use App\Exceptions\ApiException;
use App\Models\CodebookVersion;
use App\Models\CodeChangeRequest;
use App\Models\CodeItem;
use App\Models\CodeList;
use App\Models\CodeMapping;
use App\Models\Department;
use App\Models\Location;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * دليل الترميز كوثيقة: بوابة التأسيس (POL-CS-01)، الاعتماد والإصدارات ومقارنتها (FR-CS-014)،
 * طلبات التغيير بعد الاعتماد (WF-CS-02) وإعادة الهيكلة بالدمج ونقل التبعية مع المحاكاة وجدول الربط (WF-CS-03).
 */
class Codebook
{
    public static function current(): ?CodebookVersion { return CodebookVersion::orderByDesc('major')->orderByDesc('minor')->first(); }
    public static function approved(): bool { return self::current() !== null; }

    /** حالة بوابة التأسيس: القوائم الإلزامية ونسبة الاكتمال والنواقص */
    public static function golive(): array
    {
        $lists = CodeList::where('system', false)->where('active', true)->orderBy('sort')->get();
        $checks = [];
        foreach ($lists->where('required', true) as $l) {
            $n = collect(Codes::rows($l))->where('live', true)->count();
            $checks[] = ['key' => $l->key, 'ar' => $l->name_ar, 'en' => $l->name_en, 'count' => $n, 'ok' => $n > 0];
        }
        // فحوص الاكتمال: لكل مسمى عائلة، لكل قسم رمز صالح
        $jobs = CodeList::where('key', 'jobs')->where('system', false)->first();
        $orphans = $jobs ? CodeItem::where('code_list_id', $jobs->id)->where('status', 'active')->whereNull('parent_id')->count() : 0;
        $warnings = [];
        if ($orphans) $warnings[] = ['key' => 'jobs_family', 'ar' => "{$orphans} مسمى وظيفي بلا عائلة وظيفية", 'en' => "{$orphans} job title(s) without a job family"];
        $ok = collect($checks)->where('ok', true)->count();
        $v = self::current();
        $cfg = Codes::config();
        return ['enabled' => (bool) $cfg['gate'], 'approved' => $v !== null, 'version' => $v?->version, 'approved_at' => $v?->approved_at?->toIso8601String(),
            'percent' => $checks ? (int) round($ok * 100 / count($checks)) : 100, 'checks' => $checks, 'warnings' => $warnings,
            'ready' => $ok === count($checks), 'blocked' => (bool) $cfg['gate'] && $v === null];
    }

    /** يمنع إنشاء موظف قبل اعتماد الدليل (حين تكون البوابة مفعلة) — AC-CS-01 */
    public static function requireLive(): void
    {
        if (! Codes::config()['gate'] || self::approved()) return;
        $g = self::golive();
        $missing = collect($g['checks'])->where('ok', false)->map(fn ($c) => L::t($c['ar'], $c['en']))->implode('، ');
        throw new ApiException(L::t('لا يمكن إنشاء ملف موظف قبل اعتماد دليل الترميز (اكتمال '.$g['percent'].'%'.($missing ? ' — ناقص: '.$missing : '').').',
            'An employee file cannot be created before the codebook is approved ('.$g['percent'].'% complete'.($missing ? ' — missing: '.$missing : '').').'), 'codebook_not_approved', 409, [], ['golive' => $g]);
    }

    /** هل يحق للمستخدم اعتماد الدليل (الإدارة العليا حسب الإعداد) */
    public static function canApproveBook(User $u): bool
    {
        return Perm::can($u, 'codes', 'decide') && array_intersect($u->roles->pluck('key')->all(), Codes::config()['approver_roles']) !== [];
    }

    /** اعتماد الدليل: أول مرة 1.0، وإعادة الاعتماد الكامل ترفع الرقم الرئيسي */
    public static function approve(User $u, string $note = ''): CodebookVersion
    {
        if (! self::canApproveBook($u)) throw new ApiException(L::t('اعتماد الدليل من صلاحية الإدارة العليا المحددة في الإعدادات.', 'Approving the codebook belongs to the top-management roles set in the settings.'), 'forbidden', 403);
        $g = self::golive();
        if (! $g['ready']) throw new ApiException(L::t('أكمل القوائم الإلزامية أولاً.', 'Complete the required lists first.'), 'codebook_incomplete', 409, [], ['golive' => $g]);
        $v = self::snapshotVersion($u, $note ?: 'اعتماد دليل الترميز', $note ?: 'Codebook approved', true);
        Audit::record('codes.approved', null, null, ['version' => $v->version]);
        return $v;
    }

    /** يسجل إصداراً جديداً مع لقطة كاملة: major = اعتماد كامل، وإلا minor (بعد كل تغيير مطبّق) */
    public static function snapshotVersion(?User $u, string $ar, string $en, bool $major = false): CodebookVersion
    {
        $last = self::current();
        [$ma, $mi] = ! $last ? [1, 0] : ($major ? [$last->major + 1, 0] : [$last->major, $last->minor + 1]);
        return CodebookVersion::create(['version' => "{$ma}.{$mi}", 'major' => $ma, 'minor' => $mi, 'summary' => app()->getLocale() === 'en' ? $en : $ar,
            'snapshot' => self::snapshot(), 'approved_by' => $u?->id, 'approved_at' => now()]);
    }

    public static function snapshot(): array
    {
        $out = [];
        foreach (CodeList::where('system', false)->orderBy('sort')->get() as $l) {
            $rows = Codes::rows($l); $codes = collect($rows)->pluck('code', 'id');
            $out[$l->key] = ['ar' => $l->name_ar, 'en' => $l->name_en, 'items' => array_map(fn ($r) => ['code' => $r['code'], 'ar' => $r['ar'], 'en' => $r['en'], 'status' => $r['status'],
                'parent' => $r['parent_id'] ? (CodeItem::whereKey($r['parent_id'])->value('code')) : null], array_values(array_filter($rows, fn ($r) => ! $r['shared'])))];
            unset($codes);
        }
        return $out;
    }

    /** مقارنة إصدارين: المضاف والمحذوف من الفعّال وتغيّر الأسماء والحالة والأب */
    public static function compare(CodebookVersion $a, CodebookVersion $b): array
    {
        $out = [];
        foreach (array_unique([...array_keys($a->snapshot), ...array_keys($b->snapshot)]) as $key) {
            $x = collect($a->snapshot[$key]['items'] ?? [])->keyBy('code'); $y = collect($b->snapshot[$key]['items'] ?? [])->keyBy('code');
            $rows = [];
            foreach ($y as $code => $r) if (! $x->has($code)) $rows[] = ['code' => $code, 'change' => 'added', 'to' => $r];
            foreach ($x as $code => $r) {
                if (! $y->has($code)) { $rows[] = ['code' => $code, 'change' => 'removed', 'from' => $r]; continue; }
                $n = $y[$code]; $diff = array_filter(['ar', 'en', 'status', 'parent'], fn ($f) => ($r[$f] ?? null) !== ($n[$f] ?? null));
                if ($diff) $rows[] = ['code' => $code, 'change' => 'changed', 'fields' => array_values($diff), 'from' => $r, 'to' => $n];
            }
            if ($rows) $out[] = ['key' => $key, 'ar' => ($b->snapshot[$key] ?? $a->snapshot[$key])['ar'], 'en' => ($b->snapshot[$key] ?? $a->snapshot[$key])['en'], 'rows' => $rows];
        }
        return $out;
    }

    /** هل التعديل المباشر مسموح؟ قبل الاعتماد نعم؛ بعده يمر بطلب تغيير إن كان الإعداد مفعلاً (POL-CS-06) */
    public static function directAllowed(): bool { return ! self::approved() || ! Codes::config()['change_requests']; }

    public static function requireDirect(): void
    {
        if (! self::directAllowed()) throw new ApiException(L::t('الدليل معتمد: كل إضافة أو تعديل أو تعطيل يمر بطلب تغيير يعتمده مالك القائمة.', 'The codebook is approved: every addition, edit or deactivation goes through a change request approved by the list owner.'), 'change_request_required', 409);
    }

    /** الإدارات والفروع تُدار من صفحاتها؛ بعد الاعتماد كل إضافة أو تعديل فيها يُسجَّل إصداراً فرعياً في الدليل */
    public static function boundChanged(string $key, string $code, bool $added): void
    {
        Codes::bump();
        $v = self::current(); if (! $v) return;
        $l = Codes::list($key);
        if ((self::snapshot()[$key]['items'] ?? null) == ($v->snapshot[$key]['items'] ?? null)) return;   // لا تغيير في الرموز أو الأسماء أو الحالة
        self::snapshotVersion(auth()->user(), ($added ? 'إضافة ' : 'تعديل ')."{$code} — {$l->name_ar}", ($added ? 'Add ' : 'Edit ')."{$code} — {$l->name_en}");
    }

    // ———————————————— طلبات التغيير وإعادة الهيكلة ————————————————

    public const TYPES = ['add', 'edit', 'deactivate', 'reactivate', 'merge', 'move'];

    /** يقدم طلب تغيير بعد فحص الأثر (عدد السجلات، التعارض، الرمز التالي المقترح) */
    public static function request(User $u, string $key, array $v): CodeChangeRequest
    {
        $l = Codes::list($key);
        if ($l->system) throw new ApiException(L::t('القوائم القياسية مقفلة.', 'Standard lists are locked.'), 'system_list', 409);
        $type = $v['type'] ?? '';
        if (! in_array($type, self::TYPES, true)) throw ApiException::field('type', L::t('نوع التغيير غير معروف.', 'Unknown change type.'), 'invalid');
        if (mb_strlen(trim((string) ($v['reason'] ?? ''))) < 5) throw ApiException::field('reason', L::t('اكتب سبب التغيير (5 أحرف على الأقل).', 'Write the reason (at least 5 characters).'), 'reason');
        $on = $v['effective_on'] ?? now()->toDateString();
        if ($on < now()->toDateString()) throw ApiException::field('effective_on', L::t('تاريخ السريان اليوم أو بعده.', 'The effective date must be today or later.'), 'invalid');
        $itemId = isset($v['item_id']) ? (int) $v['item_id'] : null;
        $payload = (array) ($v['payload'] ?? []);
        if (in_array($type, ['add', 'edit'], true) && $l->source) throw new ApiException(L::t('الإضافة والتعديل لهذه القائمة من صفحتها الخاصة؛ الدمج متاح هنا.', 'Add and edit this list from its own page; merging is available here.'), 'bound_list', 409);
        if ($type !== 'add' && ! $itemId) throw ApiException::field('item_id', L::t('اختر الرمز.', 'Choose the code.'), 'invalid');
        $item = $itemId && ! $l->source ? CodeItem::where('code_list_id', $l->id)->findOrFail($itemId) : null;
        if ($itemId && $l->source) collect(Codes::rows($l))->firstWhere('id', $itemId) ?? abort(404);
        // فحص مبكر بنفس قواعد الحفظ كي لا يُعتمد طلب لن يُطبَّق
        if ($type === 'add') Codes::validate($l, $payload);
        if ($type === 'edit') Codes::validate($l, $payload, $item);
        if ($type === 'merge') self::checkMerge($l, $itemId, $payload);
        if ($type === 'move') { if (! $l->hierarchical && ! $l->parent_key) throw new ApiException(L::t('هذه القائمة ليست هرمية.', 'This list is not hierarchical.'), 'invalid', 422); Codes::validate($l, ['parent_id' => $payload['parent_id'] ?? null], $item); }
        if (CodeChangeRequest::where('list_key', $key)->where('item_id', $itemId)->whereIn('status', ['pending', 'approved'])->when(! $itemId, fn ($q) => $q->whereRaw("payload->>'code' = ?", [strtoupper((string) ($payload['code'] ?? ''))]))->exists())
            throw new ApiException(L::t('يوجد طلب مفتوح على هذا الرمز.', 'There is an open request on this code.'), 'request_open', 409);
        $r = CodeChangeRequest::create(['list_key' => $key, 'item_id' => $itemId, 'type' => $type, 'payload' => $payload, 'reason' => trim($v['reason']), 'effective_on' => $on,
            'impact' => self::impact($l, $type, $itemId, $payload), 'requested_by' => $u->id, 'status' => 'pending']);
        foreach (self::approvers($l, $type) as $uid) if ($uid !== $u->id) Notify::user($uid, "طلب تغيير في دليل الترميز: {$l->name_ar}", "Codebook change request: {$l->name_en}", 'codes');
        return $r;
    }

    public static function impact(CodeList $l, string $type, ?int $itemId, array $payload): array
    {
        $usage = $itemId ? Codes::usage($l, $itemId) : [];
        $out = ['usage' => $usage, 'records' => array_sum($usage), 'next' => $type === 'add' ? Codes::suggest($l, (string) ($payload['code'] ?? '')) : null];
        if ($type === 'merge') $out['simulation'] = self::simulate($l, [$itemId], (int) $payload['target_id']);
        return $out;
    }

    private static function checkMerge(CodeList $l, ?int $source, array $p): void
    {
        $target = (int) ($p['target_id'] ?? 0);
        if (! $target || $target === $source) throw ApiException::field('target_id', L::t('اختر الرمز الذي يُدمج فيه.', 'Choose the code to merge into.'), 'invalid');
        $row = collect(Codes::rows($l))->firstWhere('id', $target);
        if (! $row || $row['status'] !== 'active') throw ApiException::field('target_id', L::t('الرمز الهدف غير موجود أو غير فعّال.', 'The target code is missing or inactive.'), 'invalid');
        if ($row['shared']) throw ApiException::field('target_id', L::t('لا يمكن الدمج في مدينة مشتركة — اختر فرعاً للشركة.', 'Cannot merge into a shared city — pick a company branch.'), 'invalid');
    }

    /** محاكاة الدمج: كم سجلاً سينتقل من كل جدول — دون أي تغيير (WF-CS-03 خطوة 2) */
    public static function simulate(CodeList $l, array $sources, int $target): array
    {
        $rows = collect(Codes::rows($l))->keyBy('id');
        $tables = [];
        foreach ($sources as $s) foreach (Codes::usage($l, $s) as $t => $n) if ($t !== 'attrs') $tables[$t] = ($tables[$t] ?? 0) + $n;
        return ['sources' => array_map(fn ($s) => ['id' => $s, 'code' => $rows[$s]['code'] ?? '?', 'ar' => $rows[$s]['ar'] ?? '', 'en' => $rows[$s]['en'] ?? ''], $sources),
            'target' => ['id' => $target, 'code' => $rows[$target]['code'] ?? '?', 'ar' => $rows[$target]['ar'] ?? '', 'en' => $rows[$target]['en'] ?? ''],
            'tables' => $tables, 'records' => array_sum($tables)];
    }

    /** من يعتمد: مالك القائمة (أو مدير النظام إن سمح الإعداد)؛ الدمج/النقل لدور إعادة الهيكلة */
    public static function canDecide(User $u, CodeChangeRequest $r): bool
    {
        $cfg = Codes::config();
        if ($r->requested_by === $u->id && ! $cfg['self_approve']) return false;
        if (! Perm::can($u, 'codes', 'decide')) return false;
        $roles = $u->roles->pluck('key')->all();
        if ($cfg['admin_approves'] && in_array('admin', $roles, true)) return true;
        $l = Codes::list($r->list_key);
        $need = in_array($r->type, ['merge', 'move'], true) ? $cfg['restructure_role'] : ($l->owner_role ?: 'admin');
        return in_array($need, $roles, true);
    }

    /** المستخدمون الذين يمكنهم الاعتماد (للإشعار) */
    public static function approvers(CodeList $l, string $type): array
    {
        $cfg = Codes::config();
        $need = array_filter([in_array($type, ['merge', 'move'], true) ? $cfg['restructure_role'] : ($l->owner_role ?: 'admin'), $cfg['admin_approves'] ? 'admin' : null]);
        return User::where('company_id', Tenant::id())->where('active', true)->whereHas('roles', fn ($q) => $q->whereIn('key', $need))->pluck('id')->all();
    }

    public static function decide(User $u, CodeChangeRequest $r, bool $approve, ?string $note): CodeChangeRequest
    {
        if ($r->status !== 'pending') throw new ApiException(L::t('الطلب ليس بانتظار القرار.', 'The request is not pending.'), 'not_pending', 409);
        if (! self::canDecide($u, $r)) throw new ApiException(L::t('اعتماد هذا الطلب من صلاحية مالك القائمة.', 'This request is for the list owner to decide.'), 'forbidden', 403);
        if (! $approve && mb_strlen(trim((string) $note)) < 5) throw ApiException::field('note', L::t('اكتب سبب الرفض.', 'Write the reason for rejecting.'), 'reason');
        $r->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $u->id, 'decided_at' => now(), 'note' => $note ?: null]);
        Notify::user($r->requested_by, $approve ? 'اعتُمد طلب تغيير الترميز' : 'رُفض طلب تغيير الترميز', $approve ? 'Your codebook change was approved' : 'Your codebook change was rejected', 'codes');
        if ($approve && $r->effective_on->toDateString() <= now()->toDateString()) self::apply($r);
        return $r->fresh();
    }

    public static function cancel(User $u, CodeChangeRequest $r): void
    {
        if ($r->requested_by !== $u->id || ! in_array($r->status, ['pending', 'approved'], true) || $r->applied_at)
            throw new ApiException(L::t('لا يمكن إلغاء هذا الطلب.', 'This request cannot be cancelled.'), 'cancel_locked', 409);
        $r->update(['status' => 'cancelled']);
    }

    /** يطبق طلباً معتمداً (اليوم أو حين يحل تاريخ سريانه — الأمر codes:apply) ويرفع رقم الإصدار */
    public static function apply(CodeChangeRequest $r): void
    {
        $l = Codes::list($r->list_key); $p = $r->payload;
        try {
            DB::transaction(function () use ($r, $l, $p) {
                $item = $r->item_id && ! $l->source ? CodeItem::where('code_list_id', $l->id)->findOrFail($r->item_id) : null;
                match ($r->type) {
                    'add' => Codes::store($l, $p),
                    'edit' => Codes::store($l, $p, $item),
                    'deactivate' => Codes::setStatus($l, $r->item_id, false, $r->effective_on->toDateString()),
                    'reactivate' => Codes::setStatus($l, $r->item_id, true),
                    'move' => tap($item)->update(['parent_id' => $p['parent_id'] ?: null]),
                    'merge' => self::merge($l, $r),
                };
                $v = self::snapshotVersion(User::find($r->decided_by), self::label($r, 'ar', $l), self::label($r, 'en', $l));
                $r->update(['status' => 'applied', 'applied_at' => now(), 'version' => $v->version]);
            });
        } catch (ApiException $e) {
            $r->update(['status' => 'failed', 'note' => trim(($r->note ? $r->note.' — ' : '').$e->getMessage())]);
        }
        Codes::bump();
    }

    /** دمج: نقل كل السجلات الفعالة من الرمز المصدر إلى الهدف، تعطيل المصدر «مستبدَل»، وحفظ جدول الربط */
    private static function merge(CodeList $l, CodeChangeRequest $r): void
    {
        $source = $r->item_id; $target = (int) $r->payload['target_id'];
        self::checkMerge($l, $source, $r->payload);
        $rows = collect(Codes::rows($l))->keyBy('id');
        $moved = [];
        foreach (Codes::USAGE[$l->key] ?? [] as [$table, $col, $move]) {
            if (! $move) continue;
            $q = DB::table($table)->where($col, $source);
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'company_id')) $q->where('company_id', Tenant::id());
            if ($n = $q->update([$col => $target])) $moved[$table] = $n;
        }
        if ($l->source === 'departments') Department::whereKey($source)->update(['active' => false]);
        elseif ($l->source === 'locations') Location::whereKey($source)->whereNotNull('company_id')->update(['active' => false]);
        else {
            CodeItem::where('parent_id', $source)->update(['parent_id' => $target]);
            CodeItem::whereKey($source)->update(['status' => 'replaced', 'replaced_by_id' => $target, 'valid_to' => $r->effective_on]);
            CodeItem::whereKey($target)->whereNull('used_at')->update(['used_at' => now()]);
        }
        CodeMapping::create(['list_key' => $l->key, 'old_id' => $source, 'old_code' => $rows[$source]['code'] ?? '?', 'new_id' => $target, 'new_code' => $rows[$target]['code'] ?? '?',
            'operation' => 'merge', 'effective_on' => $r->effective_on, 'moved' => $moved, 'change_request_id' => $r->id]);
        Audit::record('codes.merged', null, null, ['list' => $l->key, 'from' => $rows[$source]['code'] ?? null, 'to' => $rows[$target]['code'] ?? null, 'moved' => $moved]);
    }

    public static function label(CodeChangeRequest $r, string $lang, ?CodeList $l = null): string
    {
        $l ??= Codes::list($r->list_key);
        $name = $lang === 'en' ? $l->name_en : $l->name_ar;
        $code = $r->payload['code'] ?? ($r->item_id ? (collect(Codes::rows($l))->firstWhere('id', $r->item_id)['code'] ?? '') : '');
        $t = $lang === 'en' ? ['add' => 'Add', 'edit' => 'Edit', 'deactivate' => 'Deactivate', 'reactivate' => 'Reactivate', 'merge' => 'Merge', 'move' => 'Move']
            : ['add' => 'إضافة', 'edit' => 'تعديل', 'deactivate' => 'تعطيل', 'reactivate' => 'إعادة تفعيل', 'merge' => 'دمج', 'move' => 'نقل تبعية'];
        return "{$t[$r->type]} {$code} — {$name}";
    }

    /** يطبق الطلبات المعتمدة التي حل تاريخ سريانها (يومياً) */
    public static function applyDue(): int
    {
        $n = 0;
        foreach (CodeChangeRequest::where('status', 'approved')->whereNull('applied_at')->whereDate('effective_on', '<=', now()->toDateString())->orderBy('id')->get() as $r) { self::apply($r); $n++; }
        return $n;
    }

    public static function row(CodeChangeRequest $r, User $u): array
    {
        $l = CodeList::where('key', $r->list_key)->first();
        return ['id' => $r->id, 'list' => $r->list_key, 'list_ar' => $l?->name_ar, 'list_en' => $l?->name_en, 'type' => $r->type, 'item_id' => $r->item_id, 'payload' => $r->payload,
            'reason' => $r->reason, 'effective_on' => $r->effective_on->toDateString(), 'impact' => $r->impact, 'status' => $r->status, 'note' => $r->note, 'version' => $r->version,
            'label_ar' => self::label($r, 'ar', $l), 'label_en' => self::label($r, 'en', $l),
            'requested_by' => $r->requester ? ['ar' => $r->requester->name, 'en' => $r->requester->name_en ?: $r->requester->name] : null,
            'decided_by' => $r->decider ? ['ar' => $r->decider->name, 'en' => $r->decider->name_en ?: $r->decider->name] : null,
            'created_at' => $r->created_at->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String(), 'applied_at' => $r->applied_at?->toIso8601String(),
            'can_decide' => $r->status === 'pending' && self::canDecide($u, $r), 'can_cancel' => $r->requested_by === $u->id && in_array($r->status, ['pending', 'approved'], true) && ! $r->applied_at];
    }
}
