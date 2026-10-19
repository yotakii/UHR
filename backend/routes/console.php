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
