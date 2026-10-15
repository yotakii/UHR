<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AttendanceDay;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\User;
use App\Support\Counters;
use Database\Seeders\TestDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * بيانات الاختبار تطابق ديمو المعرض حرفياً. القيم المتوقعة هنا مأخوذة بتشغيل data/seed.ts الأصلي
 * بنفس الساعة المجمّدة (2026-10-12 10:30 بتوقيت دمشق).
 */
class TestDataSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:30:00', 'Asia/Damascus'));
        $this->seed(TestDataSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_counts_match_the_demo(): void
    {
        $this->assertSame(8, Department::count());
        $this->assertSame(32, Employee::count());
        $this->assertSame(6, JobOpening::where('status', 'open')->count());
        $this->assertSame(10, Candidate::count());
        $this->assertSame(74, Asset::count());
        $this->assertSame(351, AttendanceDay::count());
        $this->assertSame(15, AttendanceDay::where('status', 'absent')->count());
        $this->assertSame(10, AttendanceDay::where('status', 'leave')->count());
        $this->assertSame(31, AttendanceDay::whereDate('date', '2026-10-12')->count());
        $this->assertSame(0, AuditLog::count(), 'seeding is not audited');
    }

    public function test_random_values_match_demo_seed_exactly(): void
    {
        $expect = [
            'UHR-1001' => ['+963 937 172 599', '2017-08-12', '1998-02-12', '09488338822', '04:10:A7:F4:6C', 2, 0, 'hani.samman@alfarada.sy'],
            'UHR-1007' => ['+963 932 289 493', '2023-09-12', '1978-08-12', '04232655206', '04:16:A7:E6:6C', 1, 0, 'samer.halabi@alfarada.sy'],
            'UHR-1032' => ['+963 948 709 753', '2023-10-12', '1994-08-12', '09229242673', '04:2F:A7:73:6C', 0, 0, 'ziad.kassem@alfarada.sy'],
        ];
        foreach ($expect as $code => [$phone, $hire, $dob, $nid, $nfc, $leave, $sick, $email]) {
            $e = Employee::where('code', $code)->firstOrFail();
            $this->assertSame([$phone, $hire, $dob, $nid, $nfc, (float) $leave, $sick, $email],
                [$e->phone, $e->hire_date->toDateString(), $e->dob->toDateString(), $e->national_id, $e->nfc_uid, $e->leave_used, $e->sick_used, $e->email], $code);
        }

        $c1 = Candidate::where('ref', 'UNQ-2026-APP-48210')->firstOrFail();
        $this->assertSame(['2026-10-03', '2001-07-12', '05273074303', 'married', '', true, false, 307742],
            [$c1->created_at->toDateString(), $c1->dob->toDateString(), $c1->national_id, $c1->marital, (string) $c1->linkedin, $c1->shifts, $c1->travel, $c1->cv->size]);

        $leen = Candidate::where('ref', 'UNQ-2026-APP-48506')->firstOrFail();
        $this->assertSame(['offer', 'leen.ali@mail.sy', 'linkedin.com/in/c9', 226262], [$leen->stage, $leen->email, $leen->linkedin, $leen->cv->size]);
        $r = $leen->interview->ratings;   // jsonb لا يحفظ ترتيب المفاتيح — نقارن بالمفتاح
        $this->assertSame([4, 3, 5, 3, 5, 4], [$r['readiness'], $r['english'], $r['computer'], $r['flex'], $r['body'], $r['team']]);
        $this->assertCount(24, $r);
        $this->assertSame(['sent', 650.0], [$leen->offer->status, (float) $leen->offer->basic]);

        $jana = Candidate::where('ref', 'UNQ-2026-APP-48469')->firstOrFail();
        $this->assertSame(5, $jana->interview->ratings['readiness']);
        $this->assertSame(4, $jana->interview->ratings['team']);

        $this->assertSame('SN52374919', Asset::where('tag', 'AST-0001')->value('serial'));
        $this->assertSame('SN40561702', Asset::where('tag', 'AST-0074')->value('serial'));

        $e10 = Employee::where('code', 'UHR-1010')->first();
        $d = AttendanceDay::where('employee_id', $e10->id)->orderBy('date')->first();
        $this->assertSame(['2026-09-28', '07:45', '16:16'], [$d->date->toDateString(), $d->in_at->format('H:i'), $d->out_at->format('H:i')]);
    }

    public function test_relations_accounts_and_counters(): void
    {
        $samer = Employee::where('code', 'UHR-1007')->first();
        $this->assertSame('UHR-1008', $samer->manager->code);                 // مدير تقنية المعلومات
        $this->assertSame('UHR-1002', Employee::where('code', 'UHR-1008')->first()->manager->code);
        $this->assertNull(Employee::where('code', 'UHR-1001')->first()->manager_id);
        $this->assertSame('resigning', Employee::where('code', 'UHR-1028')->value('status'));
        $this->assertSame(0, AttendanceDay::where('employee_id', $samer->id)->whereDate('date', '2026-10-12')->count());
        $this->assertSame(1, $samer->logs()->count());

        $this->assertSame('hr', User::where('email', 'admin@alfarada.sy')->first()->portal());
        $hr = User::where('email', 'hr@alfarada.sy')->first();
        $this->assertTrue($hr->hasRole('hr_director'));
        $this->assertSame('UHR-1003', $hr->employee->code);
        $this->assertSame('employee', User::where('email', 'samer.halabi@alfarada.sy')->first()->portal());
        $this->assertSame('candidate', User::where('email', 'leen.ali@mail.sy')->first()->portal());

        $this->assertSame('UHR-1033', Counters::employeeCode());
        $this->assertSame('AST-0075', Counters::assetTag());
        $this->assertSame('UNQ-2026-APP-48544', Counters::candidateRef());
    }

    public function test_reseeding_is_safe(): void
    {
        $this->seed(TestDataSeeder::class);
        $this->assertSame(32, Employee::count());
        $this->assertSame(10, Candidate::count());
        $this->assertSame(74, Asset::count());
        $this->assertSame(351, AttendanceDay::count());
    }
}
