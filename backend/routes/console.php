<?php

use App\Models\JobOpening;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// إغلاق الشواغر التي انتهى تاريخ إغلاقها (SRS §10.15) — يومياً بعد منتصف الليل
Artisan::command('jobs:close-expired', function () {
    $n = JobOpening::allCompanies()->where('status', 'open')->whereNotNull('closes_at')->whereDate('closes_at', '<', now()->toDateString())->update(['status' => 'closed']);
    $this->info("Closed {$n} expired job(s).");
})->purpose('Close vacancies past their closing date');

Schedule::command('jobs:close-expired')->dailyAt('00:10');

// الاختبار الأولي: «غير مكتمل» للروابط المنتهية والاختبارات التي تجاوزت وقتها (D-34) — كل 10 دقائق
Artisan::command('tests:expire', function () {
    $n = 0;
    \App\Models\CandidateTest::allCompanies()->whereIn('status', ['sent', 'started'])->get()->each(function ($ct) use (&$n) {
        if (! $ct->overdue()) return;
        \App\Support\Tenant::run($ct->company_id, fn () => app(\App\Services\Recruitment\PreliminaryTests::class)->expire($ct));
        $n++;
    });
    $this->info("Expired {$n} test(s).");
})->purpose('Mark overdue preliminary tests as incomplete');
Schedule::command('tests:expire')->everyTenMinutes();

// قاعدة السير: أرشفة السير بعد مدة الاحتفاظ لكل شركة (D-02) — يومياً
Artisan::command('cv:archive-expired', function () {
    $n = 0;
    foreach (\App\Models\Company::pluck('id') as $id) $n += \App\Support\Tenant::run($id, fn () => app(\App\Services\Cv\CvDatabase::class)->archiveExpired());
    $this->info("Archived {$n} CV(s).");
})->purpose('Archive CVs past the retention period');
Schedule::command('cv:archive-expired')->dailyAt('00:20');

// الملفات الإضافية للموظف: المنتهي ← «منتهي»، وتنبيه قبل الانتهاء بـ alert_days (D-25) — يومياً
Artisan::command('docs:sweep', function () {
    $t = ['expired' => 0, 'alerted' => 0];
    foreach (\App\Models\Company::pluck('id') as $id) foreach (\App\Support\Tenant::run($id, fn () => app(\App\Services\People\EmployeeDocs::class)->sweep()) as $k => $n) $t[$k] += $n;
    $this->info("Expired {$t['expired']}, alerted {$t['alerted']} document(s).");
})->purpose('Expire employee documents and alert before expiry');
Schedule::command('docs:sweep')->dailyAt('07:30');

// المسار الموحد: تذكير أصحاب الطلبات المتأخرة بعد reminder_hours ويتكرر (SRS §10.1) — كل ساعة
Artisan::command('requests:remind', function () {
    $n = 0;
    foreach (\App\Models\Company::pluck('id') as $id) $n += \App\Support\Tenant::run($id, fn () => app(\App\Services\Workflow\Workflow::class)->remind());
    $this->info("Sent {$n} reminder(s).");
})->purpose('Remind approvers of requests waiting longer than the reminder period');
Schedule::command('requests:remind')->hourly();

// المخالفات M17: لا جزاء بعد deadline_days من اكتشاف المخالفة ← «انقضت المهلة» (SRS §10.7) — يومياً
Artisan::command('discipline:expire', function () {
    $n = 0;
    foreach (\App\Models\Company::pluck('id') as $id) $n += \App\Support\Tenant::run($id, fn () => app(\App\Services\Discipline\Discipline::class)->expire());
    $this->info("Expired {$n} case(s).");
})->purpose('Expire disciplinary cases past their decision deadline');
Schedule::command('discipline:expire')->dailyAt('00:30');
