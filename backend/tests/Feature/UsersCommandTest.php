<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** أمر الخادم uhr:users: يعرض الحسابات الفعّالة بلا كلمات مرور، ويعيد كلمات مرور التجربة (ليس في الإنتاج) */
class UsersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_accounts_and_resets_test_passwords(): void
    {
        $this->seed(TestDataSeeder::class);
        $hr = User::where('email', 'hr@uniqueness.sy')->first();
        $hr->forceFill(['password' => 'changed-999', 'locked_until' => now()->addHour()])->save();
        $this->artisan('uhr:users', ['--company' => 'uniqueness'])->expectsOutputToContain('hr@uniqueness.sy')->assertSuccessful();
        $this->artisan('uhr:users', ['--company' => 'uniqueness', '--reset-test' => true])->assertSuccessful();
        $hr->refresh();
        $this->assertTrue(Hash::check('123456', $hr->password));
        $this->assertNull($hr->locked_until);
        $this->app['env'] = 'production';
        $this->artisan('uhr:users', ['--reset-test' => true])->assertFailed();
    }

    public function test_prepares_company_test_accounts(): void
    {
        $this->seed(TestDataSeeder::class);
        $samer = User::where('email', 'samer.halabi@uniqueness.sy')->first();
        $samer->forceFill(['email' => 'shams075@gmail.com', 'password' => 'xyz'])->save();
        \App\Models\Employee::allCompanies()->whereKey($samer->employee_id)->update(['email' => 'shams075@gmail.com']);
        // أدوار بلا أصحاب
        $ceoRole = \App\Models\Role::where('key', 'recruiter')->first();
        \Illuminate\Support\Facades\DB::table('role_user')->where('role_id', $ceoRole->id)->whereIn('user_id', User::where('company_id', $samer->company_id)->pluck('id'))->delete();
        $this->artisan('uhr:test-accounts', ['company' => 'uniqueness', '--dry-run' => true])->expectsOutputToContain('shams075@uniqueness.sy')->assertSuccessful();
        $this->assertSame('shams075@gmail.com', $samer->fresh()->email);
        $this->artisan('uhr:test-accounts', ['company' => 'uniqueness'])->assertSuccessful();
        $samer->refresh();
        $this->assertSame('shams075@uniqueness.sy', $samer->email);
        $this->assertTrue(Hash::check('123456', $samer->password));
        $this->assertSame('shams075@uniqueness.sy', \App\Models\Employee::allCompanies()->find($samer->employee_id)->email);
        $this->assertTrue(User::where('email', 'recruiter@uniqueness.sy')->first()->hasRole('recruiter'));
        $this->postJson('/api/auth/login', ['email' => 'recruiter@uniqueness.sy', 'password' => '123456'])->assertOk();
        $this->assertSame('leen.ali@mail.sy', User::where('email', 'leen.ali@mail.sy')->value('email'));   // المتقدم لا يُمس
    }
}
