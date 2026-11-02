<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** تثبيت نظيف ← رابط تفعيل مدير الشركة ← اختيار كلمة المرور ← خروج ← دخول بها؛ وأمر فك القفل وإعادة التعيين */
class InstallFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_install_temp_password_change_and_sign_in_again(): void
    {
        Artisan::call('uhr:install', ['--email' => 'platform@atmata.sy', '--company' => 'alfarada', '--company-name-ar' => 'الفرادة', '--company-name-en' => 'Uniqueness', '--company-admin' => 'admin@alfarada.sy']);
        preg_match('#Company admin activation link \(also emailed\): \S+/activate/(\S+)#', Artisan::output(), $m);
        $token = $m[1];
        $this->postJson('/api/auth/login', ['email' => 'admin@alfarada.sy', 'password' => 'anything1'])->assertStatus(422)->assertJsonPath('code', 'not_activated');
        $this->postJson("/api/auth/activate/{$token}", ['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->postJson("/api/auth/activate/{$token}", ['password' => 'Fresh-Start-2026', 'password_confirmation' => 'Fresh-Start-2026'])->assertOk();
        $this->postJson("/api/auth/activate/{$token}", ['password' => 'Fresh-Start-2026', 'password_confirmation' => 'Fresh-Start-2026'])->assertStatus(410);   // لمرة واحدة
        $this->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => 'Admin@Alfarada.sy ', 'password' => 'Fresh-Start-2026'])->assertOk()->assertJsonPath('user.must_change_password', false);

        // قفل بعد 5 محاولات خاطئة ← أمر الخادم يفكّه ويعيّن كلمة مرور جديدة
        $this->postJson('/api/auth/logout');
        $this->app['auth']->forgetGuards();
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/auth/login', ['email' => 'admin@alfarada.sy', 'password' => '123456']);
        $this->postJson('/api/auth/login', ['email' => 'admin@alfarada.sy', 'password' => 'Fresh-Start-2026'])->assertStatus(423);
        $this->artisan('uhr:reset-password', ['email' => 'admin@alfarada.sy', '--password' => 'Back-In-2026'])->assertSuccessful();
        $this->postJson('/api/auth/login', ['email' => 'admin@alfarada.sy', 'password' => 'Back-In-2026'])->assertOk();
        $this->assertNull(User::where('email', 'admin@alfarada.sy')->value('locked_until'));
    }
}
