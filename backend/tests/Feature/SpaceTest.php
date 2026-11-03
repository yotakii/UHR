<?php

namespace Tests\Feature;

use App\Models\Community;
use App\Models\Employee;
use App\Models\Notice;
use App\Models\SpacePost;
use App\Models\User;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 12a «الساحة»: المجتمعات، المنشورات، الاستطلاع، السؤال، التفاعلات، التعليقات، الإشارات، الإشراف والإعدادات */
class SpaceTest extends TestCase
{
    use RefreshDatabase;

    private User $samer; private User $other; private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->samer = User::with('employee')->where('email', 'samer.halabi@alfarada.sy')->firstOrFail();
        $this->hr = User::where('email', 'hr@alfarada.sy')->firstOrFail();
        $dept = $this->samer->employee->department_id;
        $this->other = User::where('company_id', $this->samer->company_id)->where('active', true)->whereHas('employee', fn ($e) => $e->where('department_id', '!=', $dept)->where('status', 'active'))
            ->whereDoesntHave('roles', fn ($r) => $r->whereIn('key', ['hr_director', 'hr_officer', 'admin']))->orderBy('id')->firstOrFail();
        $this->other->forceFill(['password' => '123456'])->save();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function as(User $u): void
    {
        $this->postJson('/api/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => '123456'])->assertOk();
    }

    public function test_feed_posts_mentions_comments_reactions_and_department_privacy(): void
    {
        $this->as($this->hr);
        $d = $this->getJson('/api/space')->assertOk()->json();
        $company = collect($d['communities'])->firstWhere('kind', 'company');
        $this->assertNotNull($company);
        $this->assertGreaterThanOrEqual(8, collect($d['communities'])->where('kind', 'department')->count());
        $this->assertTrue($d['can']['moderate']);

        $body = 'أهلاً بالجميع، شكراً @[سامر]('.$this->samer->id.') على العرض.';
        $post = $this->postJson('/api/space/posts', ['community_id' => $company['id'], 'kind' => 'post', 'body' => $body])->assertCreated()->json('data');
        $this->assertSame([$this->samer->id], SpacePost::find($post['id'])->mentions);
        $this->assertSame(1, Notice::where('user_id', $this->samer->id)->where('link', 'space:'.$post['id'])->count());

        $this->as($this->samer);
        $feed = $this->getJson('/api/space')->json('data');
        $this->assertSame($post['id'], $feed[0]['id']);
        $this->assertSame(['like' => 1], $this->postJson("/api/space/post/{$post['id']}/react", ['type' => 'like'])->assertOk()->json('reactions.counts'));
        $this->postJson("/api/space/post/{$post['id']}/react", ['type' => 'nope'])->assertStatus(422);
        $c = $this->postJson("/api/space/posts/{$post['id']}/comments", ['body' => 'شكراً لك!'])->assertCreated()->json('data.comments.0.id');
        $this->assertSame(1, Notice::where('user_id', $this->hr->id)->where('link', 'space:'.$post['id'])->count());
        $this->as($this->other);
        $this->postJson("/api/space/posts/{$post['id']}/comments", ['body' => 'وأنا أيضاً', 'parent_id' => $c])->assertCreated()->assertJsonPath('data.comments.1.parent_id', $c);
        $this->assertSame(1, Notice::where('user_id', $this->samer->id)->where('text_ar', 'like', '%ردّ على تعليقك%')->count());
        $this->getJson("/api/space/posts/{$post['id']}")->assertOk()->assertJsonPath('data.comments_count', 2);

        // مجتمع إدارة سامر: لا يراه موظف من إدارة أخرى
        $dept = Community::where('kind', 'department')->where('department_id', $this->samer->employee->department_id)->first();
        $this->as($this->samer);
        $dp = $this->postJson('/api/space/posts', ['community_id' => $dept->id, 'kind' => 'post', 'body' => 'خبر داخلي للإدارة'])->assertCreated()->json('data.id');
        $this->as($this->other);
        $this->getJson("/api/space?community={$dept->id}")->assertNotFound();
        $this->getJson("/api/space/posts/{$dp}")->assertNotFound();
        $this->postJson('/api/space/posts', ['community_id' => $dept->id, 'kind' => 'post', 'body' => 'x'])->assertForbidden();
        $this->assertNotContains($dp, array_column($this->getJson('/api/space')->json('data'), 'id'));
    }

    public function test_interest_communities_polls_questions_and_edit_window(): void
    {
        $this->as($this->samer);
        $c = $this->postJson('/api/space/communities', ['name_ar' => 'نادي القراءة', 'visibility' => 'public', 'color' => 'brass'])->assertCreated()->json('data');
        $this->assertSame(['admin', true], [$c['role'], $c['member']]);
        $priv = $this->postJson('/api/space/communities', ['name_ar' => 'فريق المشروع السري', 'visibility' => 'private', 'color' => 'info'])->assertCreated()->json('data.id');

        $this->as($this->other);
        $this->postJson('/api/space/posts', ['community_id' => $c['id'], 'kind' => 'post', 'body' => 'مرحباً'])->assertForbidden()->assertJsonPath('code', 'not_member');
        $this->postJson("/api/space/communities/{$c['id']}/join")->assertOk();
        $this->postJson("/api/space/communities/{$priv}/join")->assertForbidden();
        $this->getJson("/api/space?community={$priv}")->assertNotFound();

        // استطلاع
        $this->postJson('/api/space/posts', ['community_id' => $c['id'], 'kind' => 'poll', 'body' => 'ما الكتاب القادم؟', 'options' => ['أ', '']])->assertStatus(422)->assertJsonValidationErrors('options');
        $poll = $this->postJson('/api/space/posts', ['community_id' => $c['id'], 'kind' => 'poll', 'body' => 'ما الكتاب القادم؟', 'options' => ['رواية', 'سيرة', 'علوم'], 'closes_at' => '2026-10-13 10:00:00'])
            ->assertCreated()->json('data');
        $this->postJson("/api/space/posts/{$poll['id']}/vote", ['options' => ['o2', 'o3']])->assertOk()->assertJsonPath('data.poll.voters', 1)
            ->assertJsonPath('data.poll.options.1.votes', 1)->assertJsonPath('data.poll.options.2.votes', 0);   // اختيار واحد فقط
        $this->as($this->samer);
        $this->postJson("/api/space/posts/{$poll['id']}/vote", ['options' => ['o2']])->assertJsonPath('data.poll.options.1.votes', 2);

        // سؤال بأفضل إجابة
        $q = $this->postJson('/api/space/posts', ['community_id' => $c['id'], 'kind' => 'question', 'body' => 'من يرشّح كتاباً في الإدارة؟'])->assertCreated()->json('data.id');
        $this->as($this->other);
        $ans = $this->postJson("/api/space/posts/{$q}/comments", ['body' => 'كتاب العادات السبع'])->json('data.comments.0.id');
        $this->postJson("/api/space/posts/{$q}/best", ['comment_id' => $ans])->assertForbidden();
        $this->as($this->samer);
        $this->postJson("/api/space/posts/{$q}/best", ['comment_id' => $ans])->assertOk()->assertJsonPath('data.best.id', $ans);
        $this->assertSame(1, Notice::where('user_id', $this->other->id)->where('text_ar', 'like', '%أفضل إجابة%')->count());
        $this->assertCount(1, $this->getJson("/api/space?community={$c['id']}&filter=questions")->json('data'));

        // تثبيت (مشرف المجتمع) ومهلة التعديل
        $this->postJson("/api/space/posts/{$q}/pin", ['on' => true])->assertOk();
        $this->assertSame($q, $this->getJson("/api/space?community={$c['id']}")->json('data.0.id'));
        $this->putJson("/api/space/posts/{$q}", ['body' => 'من يرشّح كتاباً؟'])->assertOk()->assertJsonPath('data.edited', true);
        $this->travel(61)->minutes();
        $this->putJson("/api/space/posts/{$q}", ['body' => 'تعديل متأخر'])->assertStatus(409)->assertJsonPath('code', 'edit_closed');
        $this->travel(1)->days();
        $this->postJson("/api/space/posts/{$poll['id']}/vote", ['options' => ['o1']])->assertStatus(409)->assertJsonPath('code', 'poll_closed');
    }

    public function test_images_reports_moderation_and_settings(): void
    {
        $this->as($this->samer);
        $company = Community::where('kind', 'company')->first() ?? null;
        $this->getJson('/api/space');
        $company = Community::where('kind', 'company')->firstOrFail();
        $imgs = array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg", 40, 40), range(1, 5));
        $this->post('/api/space/posts', ['community_id' => $company->id, 'kind' => 'post', 'body' => 'صور', 'images' => $imgs], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('images');
        $p = $this->post('/api/space/posts', ['community_id' => $company->id, 'kind' => 'post', 'body' => 'صور من الفعالية', 'images' => array_slice($imgs, 0, 2)], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertCount(2, $p['images']);
        $this->as($this->other);
        $this->get('/api/files/'.$p['images'][0]['id'])->assertOk();

        // بلاغات: تمنع تكرار البلاغ، والإخفاء التلقائي عند العتبة، ثم قرار الإشراف
        $this->postJson("/api/space/post/{$p['id']}/report", ['reason' => 'spam'])->assertOk();
        $this->postJson("/api/space/post/{$p['id']}/report", ['reason' => 'spam'])->assertStatus(409);
        $this->getJson('/api/space/moderation')->assertForbidden();
        $this->as($this->hr);
        $this->putJson('/api/space/settings', ['enabled' => true, 'create_communities' => 'moderators', 'max_images' => 4, 'max_mb' => 8, 'edit_minutes' => 60, 'reactions' => ['like', 'celebrate'],
            'polls' => true, 'questions' => true, 'auto_hide_reports' => 1])->assertOk()->assertJsonPath('data.reactions', ['like', 'celebrate']);
        $this->postJson("/api/space/post/{$p['id']}/report", ['reason' => 'inappropriate', 'note' => 'صورة غير مناسبة'])->assertOk();
        $this->assertNotNull(SpacePost::find($p['id'])->hidden_at);                // وصل العتبة (1)
        $rows = $this->getJson('/api/space/moderation')->assertOk()->json('data');
        $this->assertCount(2, $rows[0]['reports']);
        $this->postJson("/api/space/moderation/post/{$p['id']}", ['action' => 'keep'])->assertOk()->assertJsonCount(0, 'data');
        $this->assertNull(SpacePost::find($p['id'])->hidden_at);
        $this->postJson("/api/space/moderation/post/{$p['id']}", ['action' => 'remove', 'note' => 'مخالفة لميثاق السلوك'])->assertOk();
        $this->assertNotNull(SpacePost::find($p['id'])->hidden_at);
        $this->assertSame(1, Notice::where('user_id', $this->samer->id)->where('text_ar', 'like', '%أُخفي محتوى%')->count());
        $this->as($this->other);
        $this->getJson("/api/space/posts/{$p['id']}")->assertNotFound();
        $this->postJson('/api/space/communities', ['name_ar' => 'نادي الشطرنج', 'visibility' => 'public', 'color' => 'brand'])->assertForbidden();   // للمشرفين فقط الآن
        $this->postJson("/api/space/post/{$p['id']}/react", ['type' => 'love'])->assertStatus(422);
        $this->as($this->hr);
        $this->putJson('/api/space/settings', ['enabled' => false, 'create_communities' => 'everyone', 'max_images' => 4, 'max_mb' => 8, 'edit_minutes' => 60, 'reactions' => ['like'],
            'polls' => true, 'questions' => true, 'auto_hide_reports' => 3])->assertOk();
        $this->postJson('/api/space/posts', ['community_id' => $company->id, 'kind' => 'post', 'body' => 'x'])->assertStatus(409)->assertJsonPath('code', 'space_off');
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.space']);
    }
}
