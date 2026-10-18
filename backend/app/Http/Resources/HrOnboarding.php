<?php

namespace App\Http\Resources;

use App\Models\Asset;
use App\Models\Employee;
use App\Services\Onboarding\F19;
use App\Services\Onboarding\OnboardingFlow;
use App\Services\Onboarding\WelcomeMemo;
use App\Support\Signature;
use Illuminate\Support\Carbon;

/** مخرجات M06: صف الموظف الجديد في القائمة، وصفحة مباشرته بتبويباتها الخمسة */
class HrOnboarding
{
    public const WITH = ['onboarding', 'department', 'location', 'manager'];

    public static function row(Employee $e): array
    {
        $ob = $e->onboarding;
        return [
            'id' => $e->id, 'code' => $e->code, 'name' => ['ar' => $e->name, 'en' => $e->name_en], 'gender' => $e->gender,
            'title' => ['ar' => $e->title_ar, 'en' => $e->title_en], 'dept' => ['ar' => $e->department->name_ar, 'en' => $e->department->name_en],
            'hire_date' => $e->hire_date?->toDateString(), 'status' => $e->status,
            'done' => $ob?->doneCount() ?? 0, 'total' => count(F19::ITEMS), 'probation_end' => OnboardingFlow::probationEnd($e, $ob),
        ];
    }

    public static function full(Employee $e, bool $withStock): array
    {
        $e->loadMissing([...self::WITH, 'onboarding.f22Signature', 'onboarding.f20Signature', 'onboarding.conductSignature', 'logs', 'assets.category']);
        $ob = $e->onboarding;
        $sig = fn (string $k) => ['signed_at' => $ob->{"{$k}_signed_at"}?->toIso8601String(), 'signature' => Signature::dataUrl($ob->{$k.'Signature'})];
        $end = OnboardingFlow::probationEnd($e, $ob);
        return array_merge(self::row($e), [
            'manager' => $e->manager ? ['ar' => $e->manager->name, 'en' => $e->manager->name_en] : null,
            'city' => ['ar' => $e->location->name_ar, 'en' => $e->location->name_en],
            'items' => array_map(fn ($it, $i) => ['ar' => $it[0], 'en' => $it[1], 'done' => (bool) ($ob->checklist[$i] ?? false), 'auto' => F19::AUTO[$i] ?? null], F19::ITEMS, array_keys(F19::ITEMS)),
            'probation_at' => F19::PROBATION_AT,
            'probation' => ['started' => $ob->probation_started_at?->toDateString(), 'end' => $end, 'due' => $end && Carbon::parse($end)->lte(now()), 'confirmed_at' => $ob->confirmed_at?->toIso8601String()],
            'memo' => ['sent_at' => $ob->memo_sent_at?->toIso8601String(), 'text' => WelcomeMemo::text($e)],
            'f22' => $sig('f22'), 'f20' => [...$sig('f20'), 'mother_name' => $ob->f20_mother_name], 'conduct' => $sig('conduct'),
            'assets' => $e->assets->sortBy('id')->values()->map(fn (Asset $a) => self::asset($a)),
            'stock' => $withStock ? Asset::with('category')->where('status', 'available')->orderBy('asset_category_id')->orderBy('tag')->get()->map(fn (Asset $a) => self::asset($a)) : [],
            'logs' => $e->logs->sortByDesc('at')->values()->map(fn ($l) => ['at' => $l->at->toIso8601String(), 'ar' => $l->text_ar, 'en' => $l->text_en, 'kind' => $l->kind, 'form' => $l->form]),
        ]);
    }

    public static function asset(Asset $a): array
    {
        return ['id' => $a->id, 'tag' => $a->tag, 'name' => ['ar' => $a->name_ar, 'en' => $a->name_en], 'serial' => $a->serial, 'value' => $a->value,
            'category' => ['ar' => $a->category->name_ar, 'en' => $a->category->name_en], 'status' => $a->status, 'signed_at' => $a->signed_at?->toIso8601String()];
    }
}
