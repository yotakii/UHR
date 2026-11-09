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
}
