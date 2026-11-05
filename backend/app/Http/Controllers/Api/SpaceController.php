<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\SpacePost;
use App\Services\Space\Celebrations;
use App\Services\Space\Praise;
use App\Services\Space\Space;
use App\Support\Audit;
use App\Support\L;
use App\Support\Perm;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «الساحة» (12a) — للبوابتين: كل حساب في الشركة يملك عرض الساحة */
class SpaceController extends Controller
{
    public function __construct(private Space $space) {}

    private function meta(Request $r): array
    {
        $u = $r->user(); $cfg = Space::config();
        return ['settings' => ['enabled' => (bool) $cfg['enabled'], 'reactions' => $cfg['reactions'], 'polls' => (bool) $cfg['polls'], 'questions' => (bool) $cfg['questions'],
            'max_images' => (int) $cfg['max_images'], 'max_mb' => (int) $cfg['max_mb'], 'create_communities' => $cfg['create_communities']],
            'me' => $this->space->person($u->loadMissing('employee')),
            'can' => ['moderate' => Space::moderator($u), 'post' => Perm::can($u, 'space', 'create'), 'create_community' => $cfg['enabled'] && ($cfg['create_communities'] === 'everyone' || Space::moderator($u)), 'settings' => Perm::can($u, 'settings', 'edit')],
            'praise' => Praise::client($u)];
    }

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['community' => ['nullable', 'integer'], 'before' => ['nullable', 'integer'], 'filter' => ['nullable', 'in:all,questions,polls,mine,mentions,unanswered,praise,celebrations']]);
        $out = $this->space->feed($r->user(), $f) + ['communities' => $this->space->communities($r->user())->values()] + $this->meta($r);
        if (empty($f['community']) && empty($f['before'])) $out['praise_top'] = Praise::top();
        if (! empty($f['community'])) $out['community'] = collect($out['communities'])->firstWhere('id', (int) $f['community']);
        return response()->json($out);
    }

    public function post(Request $r, int $id): JsonResponse
    {
        return response()->json(['data' => $this->space->show($r->user(), SpacePost::findOrFail($id))] + $this->meta($r));
    }

    public function store(Request $r): JsonResponse
    {
        $p = $this->space->create($r->user(), $r->except('images'), $r->file('images', []) ?: []);
        return response()->json(['data' => $this->space->show($r->user(), $p)], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $p = $this->space->update($r->user(), SpacePost::findOrFail($id), (string) $r->input('body'));
        return response()->json(['data' => $this->space->show($r->user(), $p)]);
    }

    public function destroy(Request $r, int $id): JsonResponse { $this->space->delete($r->user(), SpacePost::findOrFail($id)); return response()->json(['ok' => true]); }
    public function pin(Request $r, int $id): JsonResponse { $this->space->pin($r->user(), SpacePost::findOrFail($id), $r->boolean('on')); return response()->json(['ok' => true]); }
    public function react(Request $r, string $target, int $id): JsonResponse { return response()->json(['reactions' => $this->space->react($r->user(), $target, $id, $r->input('type') ?: null)]); }
    public function vote(Request $r, int $id): JsonResponse
    {
        $this->space->vote($r->user(), SpacePost::findOrFail($id), array_map('strval', (array) $r->input('options', [])));
        return response()->json(['data' => $this->space->show($r->user(), SpacePost::findOrFail($id))]);
    }

    public function comment(Request $r, int $id): JsonResponse
    {
        $p = SpacePost::findOrFail($id);
        $this->space->comment($r->user(), $p, (string) $r->input('body'), $r->integer('parent_id') ?: null);
        return response()->json(['data' => $this->space->show($r->user(), $p->refresh())], 201);
    }

    public function updateComment(Request $r, int $id): JsonResponse
    {
        $c = $this->space->updateComment($r->user(), Space::findComment($id), (string) $r->input('body'));
        return response()->json(['data' => $this->space->show($r->user(), SpacePost::findOrFail($c->post_id))]);
    }

    public function destroyComment(Request $r, int $id): JsonResponse
    {
        $c = Space::findComment($id);
        $this->space->deleteComment($r->user(), $c);
        return response()->json(['data' => $this->space->show($r->user(), SpacePost::findOrFail($c->post_id))]);
    }

    public function best(Request $r, int $id): JsonResponse
    {
        $p = $this->space->best($r->user(), SpacePost::findOrFail($id), $r->integer('comment_id') ?: null);
        return response()->json(['data' => $this->space->show($r->user(), $p)]);
    }

    public function report(Request $r, string $target, int $id): JsonResponse
    {
        $this->space->report($r->user(), $target, $id, (string) $r->input('reason'), $r->input('note'));
        return response()->json(['message' => L::t('وصل بلاغك إلى فريق الإشراف. شكراً لك.', 'Your report reached the moderators. Thank you.')]);
    }

    /** «شكر زميل» (12b) */
    public function praise(Request $r, Praise $praise): JsonResponse
    {
        $p = $praise->give($r->user(), $r->all());
        return response()->json(['data' => $this->space->show($r->user(), $p), 'praise' => Praise::client($r->user())], 201);
    }

    /** زملاء للشكر: موظفون حاليون (ليس أنت) */
    public function colleagues(Request $r): JsonResponse
    {
        $q = trim((string) $r->query('q', '')); $me = $r->user()->employee_id;
        $rows = \App\Models\Employee::current()->with('department')->when($me, fn ($w) => $w->whereKeyNot($me))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%$q%")->orWhere('name_en', 'ilike', "%$q%")->orWhere('code', 'ilike', "%$q%")->orWhere('title_ar', 'ilike', "%$q%")))
            ->orderBy('name')->limit(10)->get();
        return response()->json(['data' => $rows->map(fn ($e) => ['id' => $e->id, 'name' => ['ar' => $e->name, 'en' => $e->name_en ?: $e->name], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en],
            'dept' => ['ar' => $e->department?->name_ar, 'en' => $e->department?->name_en], 'gender' => $e->gender])]);
    }

    public function people(Request $r): JsonResponse { return response()->json(['data' => $this->space->people((string) $r->query('q', ''))]); }

    // المجتمعات
    public function storeCommunity(Request $r): JsonResponse { $c = $this->space->saveCommunity($r->user(), $r->all()); return response()->json(['data' => $this->space->communityRow($c, $r->user())], 201); }
    public function updateCommunity(Request $r, int $id): JsonResponse { $c = $this->space->saveCommunity($r->user(), $r->all(), Community::findOrFail($id)); return response()->json(['data' => $this->space->communityRow($c, $r->user())]); }
    public function archiveCommunity(Request $r, int $id): JsonResponse { $this->space->archive($r->user(), Community::findOrFail($id), $r->boolean('restore')); return response()->json(['ok' => true]); }
    public function join(Request $r, int $id): JsonResponse { $this->space->join($r->user(), Community::findOrFail($id)); return response()->json(['ok' => true]); }
    public function leave(Request $r, int $id): JsonResponse { $this->space->leave($r->user(), Community::findOrFail($id)); return response()->json(['ok' => true]); }
    public function members(Request $r, int $id): JsonResponse { return response()->json(['data' => $this->space->members($r->user(), Community::findOrFail($id))]); }
    public function setMember(Request $r, int $id): JsonResponse
    {
        $d = $r->validate(['user_id' => ['required', 'integer'], 'role' => ['nullable', 'in:member,admin']]);
        $this->space->setMember($r->user(), Community::findOrFail($id), (int) $d['user_id'], $d['role'] ?? null);
        return response()->json(['data' => $this->space->members($r->user(), Community::findOrFail($id))]);
    }

    // الإشراف والإعدادات
    public function reports(Request $r): JsonResponse { return response()->json(['data' => $this->space->reports($r->user())]); }
    public function decide(Request $r, string $target, int $id): JsonResponse
    {
        $d = $r->validate(['action' => ['required', 'in:keep,remove'], 'note' => ['nullable', 'string', 'max:300']]);
        $this->space->decide($r->user(), $target, $id, $d['action'], $d['note'] ?? null);
        return response()->json(['data' => $this->space->reports($r->user())]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => Space::config(), 'defaults' => Settings::DEFAULTS['space'], 'reactions' => Space::REACTIONS,
            'praise' => Praise::config(), 'celebrations' => Celebrations::config(), 'praise_defaults' => Settings::DEFAULTS['praise'], 'celebrations_defaults' => Settings::DEFAULTS['celebrations']]);
    }

    /** إعدادات الشكر والاحتفالات (12b) */
    public function savePraiseSettings(Request $r): JsonResponse
    {
        $d = $r->validate(['praise.enabled' => ['required', 'boolean'], 'praise.points' => ['required', 'integer', 'between:0,100'], 'praise.monthly_limit' => ['required', 'integer', 'between:1,100'],
            'praise.min_message' => ['required', 'integer', 'between:0,200'], 'praise.max_recipients' => ['required', 'integer', 'between:1,20'],
            'praise.values' => ['required', 'array', 'min:1', 'max:12'], 'praise.values.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,29}$/'],
            'praise.values.*.ar' => ['required', 'string', 'min:2', 'max:40'], 'praise.values.*.en' => ['nullable', 'string', 'max:40'], 'praise.values.*.color' => ['required', 'in:'.implode(',', Space::COLORS)],
            'celebrations.enabled' => ['required', 'boolean'], 'celebrations.birthdays' => ['required', 'boolean'], 'celebrations.anniversaries' => ['required', 'boolean'],
            'celebrations.new_joiners' => ['required', 'boolean'], 'celebrations.promotions' => ['required', 'boolean']],
            ['praise.values.*.key.regex' => L::t('رمز القيمة بأحرف إنكليزية صغيرة (مثل teamwork).', 'Value code in lowercase English (e.g. teamwork).')],
            ['praise.values' => L::t('قيم الشركة', 'Company values'), 'praise.values.*.ar' => L::t('اسم القيمة', 'Value name')]);
        $keys = array_column($d['praise']['values'], 'key');
        if (count($keys) !== count(array_unique($keys))) throw \App\Exceptions\ApiException::field('praise.values', L::t('رمز القيمة مكرر.', 'Duplicate value code.'), 'duplicate');
        $before = ['praise' => Praise::config(), 'celebrations' => Celebrations::config()];
        $d['praise']['values'] = array_map(fn ($v) => ['key' => $v['key'], 'ar' => trim($v['ar']), 'en' => trim((string) ($v['en'] ?? '')) ?: trim($v['ar']), 'color' => $v['color']], $d['praise']['values']);
        Settings::put('praise', $d['praise']);
        Settings::put('celebrations', $d['celebrations']);
        Audit::record('settings.praise', null, $before, ['praise' => Praise::config(), 'celebrations' => Celebrations::config()]);
        return $this->settings();
    }
    public function saveSettings(Request $r): JsonResponse
    {
        $d = $r->validate(['enabled' => ['required', 'boolean'], 'create_communities' => ['required', 'in:everyone,moderators'], 'max_images' => ['required', 'integer', 'between:0,10'],
            'max_mb' => ['required', 'integer', 'between:1,25'], 'edit_minutes' => ['required', 'integer', 'between:0,1440'], 'reactions' => ['required', 'array', 'min:1'],
            'reactions.*' => ['in:'.implode(',', Space::REACTIONS)], 'polls' => ['required', 'boolean'], 'questions' => ['required', 'boolean'], 'auto_hide_reports' => ['required', 'integer', 'between:1,50']],
            [], ['reactions' => L::t('التفاعلات', 'Reactions')]);
        $before = Space::config();
        $d['reactions'] = array_values(array_intersect(Space::REACTIONS, $d['reactions']));
        Settings::put('space', $d);
        Audit::record('settings.space', null, $before, Space::config());
        return $this->settings();
    }
}
