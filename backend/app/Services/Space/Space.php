<?php

namespace App\Services\Space;

use App\Exceptions\ApiException;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SpaceComment;
use App\Models\SpacePost;
use App\Models\SpaceReaction;
use App\Models\SpaceReport;
use App\Models\SpaceVote;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use App\Support\Notify;
use App\Support\Perm;
use App\Support\Settings;
use App\Support\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «الساحة» (12a): مجتمعات (الشركة كلها، الإدارات تلقائياً، اهتمامات ينشئها الموظفون)، منشورات (نص وصور، استطلاع، سؤال بأفضل إجابة، تثبيت)،
 * تفاعلات، تعليقات بردود، إشارات @ بإشعار، بلاغات وإشراف. الإشراف لمن يملك «اعتماد» في وحدة الساحة، ولمشرفي كل مجتمع.
 * صيغة الإشارة في النص: @[الاسم](رقم المستخدم).
 */
class Space
{
    public const REACTIONS = ['like', 'love', 'celebrate', 'insightful', 'support', 'funny'];
    public const REASONS = ['inappropriate', 'offensive', 'spam', 'private', 'other'];
    public const COLORS = ['brand', 'brass', 'info', 'danger', 'ink-3'];
    public const PAGE = 15;
    private const MENTION = '/@\[([^\]\n]{1,80})\]\((\d{1,10})\)/u';

    public static function config(): array { return Settings::merged('space'); }

    public static function moderator(User $u): bool { return Perm::can($u, 'space', 'decide'); }

    private static function deptOf(User $u): ?int
    {
        static $memo = [];
        return $memo[$u->id] ??= ($u->employee_id ? Employee::whereKey($u->employee_id)->value('department_id') : null) ?: 0;
    }

    /** مجتمع الشركة ومجتمعات الإدارات تُنشأ تلقائياً */
    public function ensureDefaults(): void
    {
        if (! Community::where('kind', 'company')->exists()) {
            $co = Tenant::company();
            Community::create(['kind' => 'company', 'name_ar' => $co?->short_ar ?: ($co?->name_ar ?? 'الشركة'), 'name_en' => $co?->short_en ?: $co?->name_en, 'about' => 'مساحة الشركة كلها: أخبار وتواصل بين الجميع.', 'color' => 'brand']);
        }
        $have = Community::where('kind', 'department')->pluck('department_id')->all();
        foreach (Department::where('active', true)->whereNotIn('id', $have)->get() as $d) {
            Community::create(['kind' => 'department', 'department_id' => $d->id, 'name_ar' => $d->name_ar, 'name_en' => $d->name_en, 'about' => 'مجتمع إدارة '.$d->name_ar.'.', 'color' => 'info']);
        }
    }

    private function member(User $u, Community $c): ?CommunityMember
    {
        return CommunityMember::where('community_id', $c->id)->where('user_id', $u->id)->first();
    }

    public function isMember(User $u, Community $c): bool
    {
        return match ($c->kind) {
            'company' => true,
            'department' => self::deptOf($u) === $c->department_id || (bool) $this->member($u, $c),
            default => (bool) $this->member($u, $c),
        };
    }

    public function canSee(User $u, Community $c): bool
    {
        if ($c->archived_at && ! self::moderator($u)) return false;
        if (self::moderator($u) || $c->kind === 'company') return true;
        if ($c->kind === 'interest' && $c->visibility === 'public') return true;
        return $this->isMember($u, $c);
    }

    public function canPost(User $u, Community $c): bool
    {
        return ! $c->archived_at && Perm::can($u, 'space', 'create') && ($this->isMember($u, $c) || self::moderator($u));
    }

    public function isAdmin(User $u, Community $c): bool
    {
        return self::moderator($u) || $this->member($u, $c)?->role === 'admin';
    }

    private function enabled(): void
    {
        if (! self::config()['enabled']) throw new ApiException(L::t('الساحة غير مفعّلة في شركتك.', 'The Space is turned off for your company.'), 'space_off', 409);
    }

    // ── المجتمعات ──

    /** كل المجتمعات التي يراها المستخدم، مع العضوية والعدادات */
    public function communities(User $u): Collection
    {
        $this->ensureDefaults();
        $mine = CommunityMember::where('user_id', $u->id)->get()->keyBy('community_id');
        $list = Community::query()->with('department')->when(! self::moderator($u), fn ($q) => $q->whereNull('archived_at'))->orderByRaw("case kind when 'company' then 0 when 'department' then 1 else 2 end")->orderBy('name_ar')->get()
            ->filter(fn ($c) => $this->canSee($u, $c))->values();
        $explicit = CommunityMember::whereIn('community_id', $list->pluck('id'))->selectRaw('community_id, count(*) n')->groupBy('community_id')->pluck('n', 'community_id');
        $staff = User::where('company_id', Tenant::id())->where('active', true)->count();
        $deptUsers = User::where('users.company_id', Tenant::id())->where('active', true)->join('employees', 'employees.id', '=', 'users.employee_id')->selectRaw('employees.department_id d, count(*) n')->groupBy('employees.department_id')->pluck('n', 'd');
        $last = SpacePost::whereIn('community_id', $list->pluck('id'))->whereNull('hidden_at')->selectRaw('community_id, max(created_at) at, count(*) n')->groupBy('community_id')->get()->keyBy('community_id');
        return $list->map(fn (Community $c) => $this->communityRow($c, $u, $mine->get($c->id), match ($c->kind) { 'company' => $staff, 'department' => (int) ($deptUsers[$c->department_id] ?? 0), default => (int) ($explicit[$c->id] ?? 0) }, $last->get($c->id), false));
    }

    public function communityRow(Community $c, User $u, ?CommunityMember $m = null, ?int $members = null, $last = null, bool $lookup = true): array
    {
        if ($lookup && ! $m) $m = $this->member($u, $c);
        if ($lookup && $members === null && $c->kind === 'interest') $members = CommunityMember::where('community_id', $c->id)->count();
        $member = $c->kind === 'interest' ? (bool) $m : $this->isMember($u, $c);
        return ['id' => $c->id, 'kind' => $c->kind, 'name' => ['ar' => $c->name_ar, 'en' => $c->name_en ?: $c->name_ar], 'about' => $c->about, 'visibility' => $c->visibility, 'color' => $c->color,
            'archived' => (bool) $c->archived_at, 'member' => $member, 'role' => $m?->role, 'members' => $members, 'posts' => (int) ($last?->n ?? 0), 'last_at' => $last?->at ? \Illuminate\Support\Carbon::parse($last->at)->toIso8601String() : null,
            'can' => ['post' => $this->canPost($u, $c), 'admin' => $this->isAdmin($u, $c), 'join' => $c->kind === 'interest' && ! $m && $c->visibility === 'public' && ! $c->archived_at, 'leave' => $c->kind === 'interest' && (bool) $m]];
    }

    public function saveCommunity(User $u, array $in, ?Community $c = null): Community
    {
        $this->enabled();
        if ($c && ! $this->isAdmin($u, $c)) throw new ApiException(L::t('لمشرفي المجتمع فقط.', 'Community admins only.'), 'forbidden', 403);
        if (! $c && self::config()['create_communities'] !== 'everyone' && ! self::moderator($u)) throw new ApiException(L::t('إنشاء المجتمعات للمشرفين فقط في شركتك.', 'Only moderators can create communities in your company.'), 'forbidden', 403);
        $d = validator($in, ['name_ar' => ['required', 'string', 'min:3', 'max:120'], 'name_en' => ['nullable', 'string', 'max:120'], 'about' => ['nullable', 'string', 'max:600'],
            'visibility' => ['required', 'in:public,private'], 'color' => ['required', 'in:'.implode(',', self::COLORS)]], [], ['name_ar' => L::t('اسم المجتمع', 'Community name')])->validate();
        if ($c && $c->kind !== 'interest') { unset($d['visibility']); }
        if (Community::where('name_ar', trim($d['name_ar']))->when($c, fn ($q) => $q->where('id', '!=', $c->id))->exists()) throw ApiException::field('name_ar', L::t('يوجد مجتمع بهذا الاسم.', 'A community with this name exists.'), 'duplicate');
        $row = ['name_ar' => trim($d['name_ar']), 'name_en' => trim((string) ($d['name_en'] ?? '')) ?: null, 'about' => trim((string) ($d['about'] ?? '')) ?: null, 'color' => $d['color']] + (isset($d['visibility']) ? ['visibility' => $d['visibility']] : []);
        if ($c) { $c->update($row); return $c; }
        return DB::transaction(function () use ($row, $u) {
            $c = Community::create($row + ['kind' => 'interest', 'created_by' => $u->id]);
            CommunityMember::create(['community_id' => $c->id, 'user_id' => $u->id, 'role' => 'admin']);
            return $c;
        });
    }

    public function archive(User $u, Community $c, bool $restore): Community
    {
        if (! $this->isAdmin($u, $c) || $c->kind === 'company') throw new ApiException(L::t('لا يمكن أرشفة هذا المجتمع.', 'This community cannot be archived.'), 'forbidden', 403);
        $c->update(['archived_at' => $restore ? null : now()]);
        return $c;
    }

    public function join(User $u, Community $c): void
    {
        $this->enabled();
        if ($c->kind !== 'interest' || $c->archived_at) throw new ApiException(L::t('العضوية في هذا المجتمع تلقائية.', 'Membership of this community is automatic.'), 'auto_member', 409);
        if ($c->visibility === 'private' && ! self::moderator($u)) throw new ApiException(L::t('مجتمع خاص — يضيفك مشرفه.', 'Private community — an admin adds you.'), 'private', 403);
        CommunityMember::firstOrCreate(['community_id' => $c->id, 'user_id' => $u->id], ['role' => 'member']);
    }

    public function leave(User $u, Community $c): void
    {
        $m = $this->member($u, $c);
        if (! $m) return;
        if ($m->role === 'admin' && CommunityMember::where('community_id', $c->id)->where('role', 'admin')->count() === 1 && CommunityMember::where('community_id', $c->id)->count() > 1)
            throw new ApiException(L::t('أنت المشرف الوحيد — عيّن مشرفاً آخر قبل المغادرة.', 'You are the only admin — appoint another admin before leaving.'), 'last_admin', 409);
        $m->delete();
    }

    public function members(User $u, Community $c): array
    {
        if (! $this->canSee($u, $c)) abort(404);
        $rows = CommunityMember::with('user.employee')->where('community_id', $c->id)->get();
        return $rows->map(fn ($m) => ['user' => $this->person($m->user), 'role' => $m->role])->sortBy(fn ($r) => ($r['role'] === 'admin' ? '0' : '1').$r['user']['name']['ar'])->values()->all();
    }

    /** المشرف يضيف عضواً أو يغيّر دوره أو يزيله */
    public function setMember(User $u, Community $c, int $userId, ?string $role): void
    {
        if (! $this->isAdmin($u, $c) || $c->kind !== 'interest') throw new ApiException(L::t('لمشرفي المجتمع فقط.', 'Community admins only.'), 'forbidden', 403);
        $target = User::where('company_id', Tenant::id())->where('active', true)->findOrFail($userId);
        if ($role === null) {
            if ($target->id === $u->id) { $this->leave($u, $c); return; }
            CommunityMember::where('community_id', $c->id)->where('user_id', $target->id)->delete();
            return;
        }
        if (! in_array($role, ['member', 'admin'], true)) abort(422);
        $new = ! $this->member($target, $c);
        CommunityMember::updateOrCreate(['community_id' => $c->id, 'user_id' => $target->id], ['role' => $role]);
        if ($new) Notify::user($target->id, "أُضفت إلى مجتمع «{$c->name_ar}» في الساحة", "You were added to the «".($c->name_en ?: $c->name_ar)."» community", 'space');
    }

    // ── المنشورات ──

    /** @param array{community?: int, before?: int, filter?: string, post?: int} $f */
    public function feed(User $u, array $f): array
    {
        $this->ensureDefaults();
        $mod = self::moderator($u);
        $q = SpacePost::query()->with(['community', 'user.employee']);
        $community = null;
        if (! empty($f['community'])) {
            $community = Community::findOrFail((int) $f['community']);
            if (! $this->canSee($u, $community)) abort(404);
            $q->where('community_id', $community->id);
        } else {
            $ids = Community::whereNull('archived_at')->get()->filter(fn ($c) => $this->isMember($u, $c))->pluck('id');
            $q->where(fn ($w) => $w->whereIn('community_id', $ids)->orWhereJsonContains('mentions', $u->id));
        }
        if (! $mod) $q->where(fn ($w) => $w->whereNull('hidden_at')->orWhere('user_id', $u->id));
        match ($f['filter'] ?? 'all') {
            'questions' => $q->where('kind', 'question'), 'polls' => $q->where('kind', 'poll'), 'mine' => $q->where('user_id', $u->id),
            'mentions' => $q->whereJsonContains('mentions', $u->id), 'unanswered' => $q->where('kind', 'question')->whereNull('best_comment_id'), default => null,
        };
        $pinned = collect();
        if ($community && empty($f['before'])) {
            $pinned = (clone $q)->whereNotNull('pinned_at')->orderByDesc('pinned_at')->get();
            $q->whereNull('pinned_at');
        }
        if (! empty($f['before'])) $q->where('id', '<', (int) $f['before']);
        $rows = $q->orderByDesc('id')->limit(self::PAGE + 1)->get();
        $more = $rows->count() > self::PAGE;
        $posts = $pinned->concat($rows->take(self::PAGE));
        return ['data' => $this->rows($posts, $u), 'more' => $more, 'next' => $more ? $rows->take(self::PAGE)->last()?->id : null];
    }

    public function show(User $u, SpacePost $p): array
    {
        $p->loadMissing(['community', 'user.employee']);
        if (! $this->canSee($u, $p->community) || ($p->hidden_at && ! self::moderator($u) && $p->user_id !== $u->id)) abort(404);
        return $this->rows(collect([$p]), $u)[0] + ['comments' => $this->comments($u, $p)];
    }

    /** صفوف المنشورات مع التفاعلات والاستطلاعات وأفضل إجابة دفعة واحدة */
    public function rows(Collection $posts, User $u): array
    {
        if ($posts->isEmpty()) return [];
        $ids = $posts->pluck('id');
        $react = SpaceReaction::where('target', 'post')->whereIn('target_id', $ids)->selectRaw('target_id, type, count(*) n')->groupBy('target_id', 'type')->get()->groupBy('target_id');
        $mineR = SpaceReaction::where('target', 'post')->whereIn('target_id', $ids)->where('user_id', $u->id)->pluck('type', 'target_id');
        $votes = SpaceVote::whereIn('post_id', $ids)->selectRaw('post_id, option, count(*) n')->groupBy('post_id', 'option')->get()->groupBy('post_id');
        $voters = SpaceVote::whereIn('post_id', $ids)->selectRaw('post_id, count(distinct user_id) n')->groupBy('post_id')->pluck('n', 'post_id');
        $mineV = SpaceVote::whereIn('post_id', $ids)->where('user_id', $u->id)->get()->groupBy('post_id');
        $best = SpaceComment::with('user.employee')->whereIn('id', $posts->pluck('best_comment_id')->filter())->get()->keyBy('id');
        $mod = self::moderator($u); $cfg = self::config();
        $admins = CommunityMember::where('user_id', $u->id)->where('role', 'admin')->pluck('community_id')->flip();
        return $posts->map(function (SpacePost $p) use ($u, $react, $mineR, $votes, $voters, $mineV, $best, $mod, $cfg, $admins) {
            $cAdmin = $mod || isset($admins[$p->community_id]);
            $mine = $p->user_id === $u->id;
            $poll = null;
            if ($p->poll) {
                $counts = ($votes[$p->id] ?? collect())->pluck('n', 'option');
                $my = ($mineV[$p->id] ?? collect())->pluck('option')->all();
                $closed = ! empty($p->poll['closes_at']) && now()->gt($p->poll['closes_at']);
                $poll = ['multi' => (bool) ($p->poll['multi'] ?? false), 'closes_at' => $p->poll['closes_at'] ?? null, 'closed' => $closed, 'voters' => (int) ($voters[$p->id] ?? 0),
                    'options' => array_map(fn ($o) => ['id' => $o['id'], 'text' => $o['text'], 'votes' => (int) ($counts[$o['id']] ?? 0), 'mine' => in_array($o['id'], $my, true)], $p->poll['options'] ?? [])];
            }
            return [
                'id' => $p->id, 'kind' => $p->kind, 'body' => $p->body, 'at' => $p->created_at?->toIso8601String(), 'edited' => (bool) $p->edited_at,
                'community' => ['id' => $p->community->id, 'kind' => $p->community->kind, 'name' => ['ar' => $p->community->name_ar, 'en' => $p->community->name_en ?: $p->community->name_ar], 'color' => $p->community->color],
                'author' => $this->person($p->user), 'images' => array_map(fn ($id) => ['id' => (int) $id], $p->images ?? []), 'poll' => $poll,
                'reactions' => ['counts' => ($react[$p->id] ?? collect())->pluck('n', 'type')->map(fn ($n) => (int) $n), 'mine' => $mineR[$p->id] ?? null, 'total' => (int) $p->reactions_count],
                'comments_count' => (int) $p->comments_count, 'best' => $p->best_comment_id && isset($best[$p->best_comment_id]) ? $this->commentRow($best[$p->best_comment_id], $u, collect(), collect()) : null,
                'pinned' => (bool) $p->pinned_at, 'hidden' => (bool) $p->hidden_at, 'hidden_reason' => $p->hidden_at ? $p->hidden_reason : null,
                'can' => ['edit' => $mine && ! $p->poll && $p->created_at?->gt(now()->subMinutes((int) $cfg['edit_minutes'])), 'delete' => $mine || $cAdmin, 'pin' => $cAdmin, 'best' => $p->kind === 'question' && ($mine || $cAdmin),
                    'report' => ! $mine, 'moderate' => $mod, 'comment' => ! $p->hidden_at && $this->canSee($u, $p->community)],
            ];
        })->values()->all();
    }

    public function person(?User $usr): array
    {
        $e = $usr?->employee;
        return ['id' => $usr?->id, 'name' => ['ar' => $usr?->displayName('ar') ?? '—', 'en' => $usr?->displayName('en') ?? '—'], 'title' => $e ? ['ar' => $e->title_ar, 'en' => $e->title_en] : null,
            'gender' => $e?->gender, 'employee_id' => $e?->id];
    }

    /** المستخدمون المشار إليهم في النص (من نفس الشركة فقط) */
    public static function mentions(string $text): array
    {
        preg_match_all(self::MENTION, $text, $m);
        $ids = array_values(array_unique(array_map('intval', $m[2] ?? [])));
        return $ids ? User::where('company_id', Tenant::id())->where('active', true)->whereIn('id', $ids)->pluck('id')->all() : [];
    }

    private function notifyMentions(array $ids, array $already, User $by, string $ar, string $en, string $link): void
    {
        foreach (array_diff($ids, $already, [$by->id]) as $id) Notify::user($id, $by->displayName('ar').' '.$ar, $by->displayName('en').' '.$en, $link);
    }

    public function create(User $u, array $in, array $images): SpacePost
    {
        $this->enabled();
        $cfg = self::config();
        $d = validator($in, ['community_id' => ['required', 'integer'], 'kind' => ['required', 'in:post,poll,question'], 'body' => ['required', 'string', 'min:1', 'max:5000'],
            'options' => ['nullable', 'array', 'max:6'], 'options.*' => ['nullable', 'string', 'max:120'], 'multi' => ['nullable', 'boolean'], 'closes_at' => ['nullable', 'date', 'after:now']],
            ['closes_at.after' => L::t('موعد الإغلاق في المستقبل.', 'The closing time must be in the future.')], ['body' => L::t('النص', 'Text'), 'closes_at' => L::t('موعد الإغلاق', 'Closing time')])->validate();
        $c = Community::findOrFail((int) $d['community_id']);
        if (! $this->canPost($u, $c)) throw new ApiException(L::t('انضم إلى المجتمع لتنشر فيه.', 'Join the community to post in it.'), 'not_member', 403);
        if ($d['kind'] === 'poll' && ! $cfg['polls']) throw new ApiException(L::t('الاستطلاعات غير مفعّلة.', 'Polls are turned off.'), 'poll_off', 409);
        if ($d['kind'] === 'question' && ! $cfg['questions']) throw new ApiException(L::t('الأسئلة غير مفعّلة.', 'Questions are turned off.'), 'question_off', 409);
        $poll = null;
        if ($d['kind'] === 'poll') {
            $opts = array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), $d['options'] ?? []))));
            if (count($opts) < 2) throw ApiException::field('options', L::t('خياران على الأقل في الاستطلاع.', 'A poll needs at least two options.'), 'options');
            $poll = ['options' => array_map(fn ($t, $i) => ['id' => 'o'.($i + 1), 'text' => $t], $opts, array_keys($opts)), 'multi' => (bool) ($d['multi'] ?? false), 'closes_at' => $d['closes_at'] ?? null];
        }
        $files = $this->storeImages($images, $u);
        $mentions = self::mentions($d['body']);
        $p = SpacePost::create(['community_id' => $c->id, 'user_id' => $u->id, 'kind' => $d['kind'], 'body' => trim($d['body']), 'images' => $files ?: null, 'poll' => $poll, 'mentions' => $mentions ?: null]);
        $this->notifyMentions($mentions, [], $u, 'أشار إليك في منشور في الساحة', 'mentioned you in a Space post', 'space:'.$p->id);
        return $p;
    }

    /** @param UploadedFile[] $images */
    private function storeImages(array $images, User $u): array
    {
        $cfg = self::config();
        if (count($images) > (int) $cfg['max_images']) throw ApiException::field('images', L::t("حتى {$cfg['max_images']} صور.", "Up to {$cfg['max_images']} images."), 'too_many');
        $ids = [];
        foreach ($images as $f) {
            $ext = strtolower($f->getClientOriginalExtension());
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) || $f->getSize() > (int) $cfg['max_mb'] * 1048576)
                throw ApiException::field('images', L::t("صور JPG أو PNG أو WEBP أو GIF حتى {$cfg['max_mb']} MB.", "JPG, PNG, WEBP or GIF images up to {$cfg['max_mb']} MB."), 'bad_file');
            $path = $f->storeAs('space/'.now()->format('Y/m'), Str::uuid().'.'.$ext, 'local');
            $ids[] = StoredFile::create(['disk' => 'local', 'path' => $path, 'name' => $f->getClientOriginalName(), 'size' => $f->getSize(), 'mime' => $f->getMimeType(), 'uploaded_by' => $u->id])->id;
        }
        return $ids;
    }

    public function update(User $u, SpacePost $p, string $body): SpacePost
    {
        $row = collect($this->rows(collect([$p->loadMissing(['community', 'user.employee'])]), $u))->first();
        if (! $row['can']['edit']) throw new ApiException(L::t('انتهت مهلة تعديل المنشور.', 'The editing window for this post has ended.'), 'edit_closed', 409);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 5000) throw ApiException::field('body', L::t('النص مطلوب.', 'Text is required.'), 'required');
        $old = $p->mentions ?? [];
        $mentions = self::mentions($body);
        $p->update(['body' => $body, 'mentions' => $mentions ?: null, 'edited_at' => now()]);
        $this->notifyMentions($mentions, $old, $u, 'أشار إليك في منشور في الساحة', 'mentioned you in a Space post', 'space:'.$p->id);
        return $p;
    }

    public function delete(User $u, SpacePost $p): void
    {
        $p->loadMissing('community');
        if ($p->user_id !== $u->id && ! $this->isAdmin($u, $p->community)) throw new ApiException(L::t('لصاحب المنشور أو مشرف المجتمع.', 'For the author or a community admin.'), 'forbidden', 403);
        DB::transaction(function () use ($p) {
            SpaceReaction::where('target', 'post')->where('target_id', $p->id)->delete();
            SpaceReaction::where('target', 'comment')->whereIn('target_id', SpaceComment::where('post_id', $p->id)->pluck('id'))->delete();
            SpaceReport::where('target', 'post')->where('target_id', $p->id)->update(['status' => 'removed', 'decided_at' => now()]);
            $p->delete();
        });
    }

    public function pin(User $u, SpacePost $p, bool $on): SpacePost
    {
        $p->loadMissing('community');
        if (! $this->isAdmin($u, $p->community)) throw new ApiException(L::t('التثبيت لمشرفي المجتمع.', 'Pinning is for community admins.'), 'forbidden', 403);
        $p->update(['pinned_at' => $on ? now() : null, 'pinned_by' => $on ? $u->id : null]);
        return $p;
    }

    public function react(User $u, string $target, int $id, ?string $type): array
    {
        $this->enabled();
        if ($type !== null && ! in_array($type, self::config()['reactions'], true)) throw ApiException::field('type', L::t('تفاعل غير متاح.', 'Reaction not available.'), 'invalid');
        $model = $target === 'post' ? SpacePost::findOrFail($id) : self::findComment($id);
        $post = $target === 'post' ? $model : SpacePost::find($model->post_id);
        if (! $post || ! $this->canSee($u, $post->community()->first())) abort(404);
        DB::transaction(function () use ($u, $target, $id, $type, $model) {
            $had = SpaceReaction::where('target', $target)->where('target_id', $id)->where('user_id', $u->id)->first();
            if ($type === null) { if ($had) { $had->delete(); $model->decrement('reactions_count'); } return; }
            if ($had) $had->update(['type' => $type]);
            else { SpaceReaction::create(['target' => $target, 'target_id' => $id, 'user_id' => $u->id, 'type' => $type]); $model->increment('reactions_count'); }
        });
        $counts = SpaceReaction::where('target', $target)->where('target_id', $id)->selectRaw('type, count(*) n')->groupBy('type')->pluck('n', 'type')->map(fn ($n) => (int) $n);
        return ['counts' => $counts, 'mine' => $type, 'total' => (int) $counts->sum()];
    }

    public function vote(User $u, SpacePost $p, array $options): void
    {
        $this->enabled();
        $p->loadMissing('community');
        if (! $p->poll || ! $this->canSee($u, $p->community) || $p->hidden_at) abort(404);
        if (! empty($p->poll['closes_at']) && now()->gt($p->poll['closes_at'])) throw new ApiException(L::t('أُغلق الاستطلاع.', 'The poll is closed.'), 'poll_closed', 409);
        $valid = array_column($p->poll['options'], 'id');
        $options = array_values(array_unique(array_intersect($options, $valid)));
        if (! ($p->poll['multi'] ?? false)) $options = array_slice($options, 0, 1);
        DB::transaction(function () use ($u, $p, $options) {
            SpaceVote::where('post_id', $p->id)->where('user_id', $u->id)->delete();
            foreach ($options as $o) SpaceVote::create(['post_id' => $p->id, 'user_id' => $u->id, 'option' => $o]);
        });
    }

    // ── التعليقات ──

    /** تعليق ضمن منشورات الشركة الحالية فقط (التعليقات بلا company_id) */
    public static function findComment(int $id): SpaceComment
    {
        $c = SpaceComment::findOrFail($id);
        if (! SpacePost::whereKey($c->post_id)->exists()) abort(404);
        return $c;
    }

    public function comments(User $u, SpacePost $p): array
    {
        $mod = self::moderator($u);
        $rows = SpaceComment::with('user.employee')->where('post_id', $p->id)->when(! $mod, fn ($q) => $q->where(fn ($w) => $w->whereNull('hidden_at')->orWhere('user_id', $u->id)))->orderBy('id')->get();
        $react = SpaceReaction::where('target', 'comment')->whereIn('target_id', $rows->pluck('id'))->selectRaw('target_id, count(*) n')->groupBy('target_id')->pluck('n', 'target_id');
        $mine = SpaceReaction::where('target', 'comment')->whereIn('target_id', $rows->pluck('id'))->where('user_id', $u->id)->pluck('type', 'target_id');
        $cAdmin = $this->isAdmin($u, $p->community);
        return $rows->map(fn ($c) => $this->commentRow($c, $u, $react, $mine) + ['best' => $p->best_comment_id === $c->id,
            'can' => ['edit' => $c->user_id === $u->id && $c->created_at?->gt(now()->subMinutes((int) self::config()['edit_minutes'])), 'delete' => $c->user_id === $u->id || $cAdmin, 'report' => $c->user_id !== $u->id]])->values()->all();
    }

    private function commentRow(SpaceComment $c, User $u, Collection $react, Collection $mine): array
    {
        return ['id' => $c->id, 'parent_id' => $c->parent_id, 'body' => $c->body, 'author' => $this->person($c->user), 'at' => $c->created_at?->toIso8601String(), 'edited' => (bool) $c->edited_at,
            'reactions' => (int) ($react[$c->id] ?? $c->reactions_count), 'liked' => isset($mine[$c->id]), 'hidden' => (bool) $c->hidden_at];
    }

    public function comment(User $u, SpacePost $p, string $body, ?int $parentId): SpaceComment
    {
        $this->enabled();
        $p->loadMissing('community');
        if (! $this->canSee($u, $p->community) || $p->hidden_at || ! Perm::can($u, 'space', 'create')) abort(404);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 3000) throw ApiException::field('body', L::t('اكتب تعليقاً (حتى 3000 حرف).', 'Write a comment (up to 3000 characters).'), 'required');
        $parent = $parentId ? SpaceComment::where('post_id', $p->id)->findOrFail($parentId) : null;
        if ($parent?->parent_id) $parent = SpaceComment::find($parent->parent_id);    // ردود بمستوى واحد
        $mentions = self::mentions($body);
        $c = DB::transaction(function () use ($u, $p, $body, $parent, $mentions) {
            $c = SpaceComment::create(['post_id' => $p->id, 'user_id' => $u->id, 'parent_id' => $parent?->id, 'body' => $body, 'mentions' => $mentions ?: null]);
            $p->increment('comments_count');
            return $c;
        });
        $link = 'space:'.$p->id; $told = [$u->id];
        if ($p->user_id !== $u->id) { Notify::user($p->user_id, $u->displayName('ar').($p->kind === 'question' ? ' أجاب عن سؤالك في الساحة' : ' علّق على منشورك في الساحة'), $u->displayName('en').($p->kind === 'question' ? ' answered your question' : ' commented on your post'), $link); $told[] = $p->user_id; }
        if ($parent && ! in_array($parent->user_id, $told, true)) { Notify::user($parent->user_id, $u->displayName('ar').' ردّ على تعليقك في الساحة', $u->displayName('en').' replied to your comment', $link); $told[] = $parent->user_id; }
        $this->notifyMentions($mentions, $told, $u, 'أشار إليك في تعليق في الساحة', 'mentioned you in a comment', $link);
        return $c;
    }

    public function updateComment(User $u, SpaceComment $c, string $body): SpaceComment
    {
        if ($c->user_id !== $u->id || $c->created_at?->lt(now()->subMinutes((int) self::config()['edit_minutes']))) throw new ApiException(L::t('انتهت مهلة التعديل.', 'The editing window has ended.'), 'edit_closed', 409);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 3000) throw ApiException::field('body', L::t('اكتب تعليقاً.', 'Write a comment.'), 'required');
        $old = $c->mentions ?? []; $mentions = self::mentions($body);
        $c->update(['body' => $body, 'mentions' => $mentions ?: null, 'edited_at' => now()]);
        $this->notifyMentions($mentions, $old, $u, 'أشار إليك في تعليق في الساحة', 'mentioned you in a comment', 'space:'.$c->post_id);
        return $c;
    }

    public function deleteComment(User $u, SpaceComment $c): void
    {
        $p = SpacePost::with('community')->findOrFail($c->post_id);
        if ($c->user_id !== $u->id && ! $this->isAdmin($u, $p->community)) throw new ApiException(L::t('لصاحب التعليق أو مشرف المجتمع.', 'For the author or a community admin.'), 'forbidden', 403);
        DB::transaction(function () use ($c, $p) {
            $ids = SpaceComment::where('parent_id', $c->id)->pluck('id')->push($c->id);
            SpaceReaction::where('target', 'comment')->whereIn('target_id', $ids)->delete();
            if ($p->best_comment_id && $ids->contains($p->best_comment_id)) $p->best_comment_id = null;
            $p->comments_count = max(0, $p->comments_count - $ids->count());
            $p->save();
            SpaceComment::whereIn('id', $ids)->delete();
        });
    }

    public function best(User $u, SpacePost $p, ?int $commentId): SpacePost
    {
        $p->loadMissing('community');
        if ($p->kind !== 'question' || ($p->user_id !== $u->id && ! $this->isAdmin($u, $p->community))) throw new ApiException(L::t('لصاحب السؤال أو مشرف المجتمع.', 'For the asker or a community admin.'), 'forbidden', 403);
        $c = $commentId ? SpaceComment::where('post_id', $p->id)->findOrFail($commentId) : null;
        $p->update(['best_comment_id' => $c?->id]);
        if ($c && $c->user_id !== $u->id) Notify::user($c->user_id, 'اختيرت إجابتك أفضل إجابة في الساحة', 'Your answer was marked as the best answer', 'space:'.$p->id);
        return $p;
    }

    // ── البلاغات والإشراف ──

    public function report(User $u, string $target, int $id, string $reason, ?string $note): void
    {
        if (! in_array($reason, self::REASONS, true)) throw ApiException::field('reason', L::t('اختر سبب البلاغ.', 'Choose a reason.'), 'invalid');
        $model = $target === 'post' ? SpacePost::findOrFail($id) : self::findComment($id);
        if ($model->user_id === $u->id) throw new ApiException(L::t('لا يمكنك الإبلاغ عن محتواك.', 'You cannot report your own content.'), 'own', 409);
        $exists = SpaceReport::where('target', $target)->where('target_id', $id)->where('user_id', $u->id)->exists();
        if ($exists) throw new ApiException(L::t('أبلغت عن هذا المحتوى من قبل.', 'You already reported this.'), 'reported', 409);
        SpaceReport::create(['target' => $target, 'target_id' => $id, 'user_id' => $u->id, 'reason' => $reason, 'note' => $note ? mb_substr(trim($note), 0, 500) : null]);
        $open = SpaceReport::where('target', $target)->where('target_id', $id)->where('status', 'open')->count();
        if ($open === 1) Notify::hr('بلاغ جديد عن محتوى في الساحة بانتظار المراجعة', 'New Space content report awaiting review', 'space-moderation');
        if ($open >= (int) self::config()['auto_hide_reports'] && ! $model->hidden_at) $model->update(['hidden_at' => now()] + ($target === 'post' ? ['hidden_reason' => 'reports'] : []));
    }

    public function reports(User $u): array
    {
        if (! self::moderator($u)) abort(403);
        $open = SpaceReport::with('user.employee')->where('status', 'open')->orderBy('id')->get()->groupBy(fn ($r) => $r->target.':'.$r->target_id);
        $out = [];
        foreach ($open as $key => $rs) {
            [$target, $id] = explode(':', $key);
            $m = $target === 'post' ? SpacePost::with(['user.employee', 'community'])->find($id) : SpaceComment::with(['user.employee', 'post.community'])->find($id);
            if ($m instanceof SpaceComment && ! $m->post) $m = null;
            if (! $m) { SpaceReport::whereIn('id', $rs->pluck('id'))->update(['status' => 'removed', 'decided_at' => now()]); continue; }
            $out[] = ['target' => $target, 'id' => (int) $id, 'post_id' => $target === 'post' ? $m->id : $m->post_id, 'body' => mb_substr($m->body, 0, 400), 'author' => $this->person($m->user),
                'community' => ($target === 'post' ? $m->community : $m->post?->community)?->name_ar, 'hidden' => (bool) $m->hidden_at, 'at' => $m->created_at?->toIso8601String(),
                'reports' => $rs->map(fn ($r) => ['reason' => $r->reason, 'note' => $r->note, 'by' => $r->user?->displayName('ar'), 'by_en' => $r->user?->displayName('en'), 'at' => $r->created_at?->toIso8601String()])->values()];
        }
        return $out;
    }

    /** keep = يبقى ظاهراً (ويُلغى الإخفاء التلقائي) · remove = يُخفى ويُبلَّغ صاحبه */
    public function decide(User $u, string $target, int $id, string $action, ?string $note): void
    {
        if (! self::moderator($u)) abort(403);
        $m = $target === 'post' ? SpacePost::findOrFail($id) : self::findComment($id);
        if ($action === 'remove') {
            $m->update(['hidden_at' => now(), 'hidden_by' => $u->id] + ($target === 'post' ? ['hidden_reason' => $note ? mb_substr($note, 0, 300) : 'moderation'] : []));
            Notify::user($m->user_id, 'أُخفي محتوى نشرته في الساحة بعد مراجعة الإشراف'.($note ? ": {$note}" : ''), 'Content you posted in the Space was hidden after review'.($note ? ": {$note}" : ''), 'space:'.($target === 'post' ? $m->id : $m->post_id));
        } elseif ($action === 'keep') {
            $m->update(['hidden_at' => null, 'hidden_by' => null] + ($target === 'post' ? ['hidden_reason' => null] : []));
        } else abort(422);
        SpaceReport::where('target', $target)->where('target_id', $id)->where('status', 'open')->update(['status' => $action === 'remove' ? 'removed' : 'kept', 'decided_by' => $u->id, 'decided_at' => now()]);
        Audit::record('space.'.($action === 'remove' ? 'hidden' : 'kept'), $m, null, ['target' => $target, 'note' => $note]);
    }

    /** اقتراحات الإشارة @ */
    public function people(string $q): array
    {
        $q = trim($q);
        return User::with('employee')->where('company_id', Tenant::id())->where('active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', "%$q%")->orWhere('email', 'ilike', "%$q%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%$q%")->orWhere('name_en', 'ilike', "%$q%"))))
            ->orderBy('id')->limit(8)->get()->map(fn ($x) => $this->person($x))->all();
    }
}
