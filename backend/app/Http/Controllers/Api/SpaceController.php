<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\SpacePost;
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
            'can' => ['moderate' => Space::moderator($u), 'post' => Perm::can($u, 'space', 'create'), 'create_community' => $cfg['enabled'] && ($cfg['create_communities'] === 'everyone' || Space::moderator($u)), 'settings' => Perm::can($u, 'settings', 'edit')]];
    }

    public function index(Request $r): JsonResponse
    {
        $f = $r->validate(['community' => ['nullable', 'integer'], 'before' => ['nullable', 'integer'], 'filter' => ['nullable', 'in:all,questions,polls,mine,mentions,unanswered']]);
        $out = $this->space->feed($r->user(), $f) + ['communities' => $this->space->communities($r->user())->values()] + $this->meta($r);
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

    public function settings(): JsonResponse { return response()->json(['data' => Space::config(), 'defaults' => Settings::DEFAULTS['space'], 'reactions' => Space::REACTIONS]); }
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
