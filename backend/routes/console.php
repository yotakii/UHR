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

// الإعلانات (D-06): نشر المجدول وإشعار المستهدفين، وأرشفة المنتهي — كل 10 دقائق
Artisan::command('announcements:sweep', function () {
    $t = ['published' => 0, 'expired' => 0];
    foreach (\App\Models\Company::pluck('id') as $id) foreach (\App\Support\Tenant::run($id, fn () => app(\App\Services\Engagement\Announcements::class)->sweep()) as $k => $n) $t[$k] += $n;
    $this->info("Published {$t['published']}, expired {$t['expired']} announcement(s).");
})->purpose('Publish scheduled announcements and expire old ones');
Schedule::command('announcements:sweep')->everyTenMinutes();

// التعاقب وخطط التطوير (D-23، D-24): أنشطة متأخرة، مراجعات مستحقة، ومناصب بلا مرشح جاهز — يومياً
Artisan::command('development:sweep', function () {
    $t = ['overdue' => 0, 'review' => 0, 'gaps' => 0];
    foreach (\App\Models\Company::pluck('id') as $id) \App\Support\Tenant::run($id, function () use (&$t) {
        foreach (app(\App\Services\Development\DevelopmentPlans::class)->sweep() as $k => $n) $t[$k] += $n;
        $s = app(\App\Services\Development\Succession::class)->sweep();
        $t['gaps'] += $s['gaps']; $t['review'] += $s['review'];
    });
    $this->info("Overdue {$t['overdue']}, reviews {$t['review']}, gaps {$t['gaps']}.");
})->purpose('Alert overdue development activities, due reviews and succession gaps');
Schedule::command('development:sweep')->dailyAt('08:00');

// النسخ الاحتياطي (D-27): نسخة الخادم الكاملة يومياً في وقت إعداد المنصة — يُفحص كل 15 دقيقة
Artisan::command('backup:run {--auto : only when due} {--company= : slug — نسخة بيانات شركة}', function () {
    $svc = app(\App\Services\Admin\Backups::class);
    if ($slug = $this->option('company')) {
        $b = $svc->company(\App\Models\Company::where('slug', $slug)->firstOrFail());
    } else {
        if ($this->option('auto') && ! $svc->due()) return;
        $b = $svc->full(null, $this->option('auto') ? 'auto' : 'manual');
    }
    $b->status === 'ok' ? $this->info("Backup {$b->path} (".number_format($b->size / 1024).' KB)') : $this->error("Backup failed: {$b->error}");
})->purpose('Create a full server backup (or a company data copy)');
Schedule::command('backup:run --auto')->everyFifteenMinutes()->withoutOverlapping();

// سجل التدقيق: أرشيف شهري دائم للأشهر التي خرجت من نافذة العرض (لا حذف) — يومياً
Artisan::command('audit:archive', function () {
    $this->info('Archived '.app(\App\Services\Admin\AuditArchive::class)->run().' month file(s).');
})->purpose('Write monthly audit archive files');
Schedule::command('audit:archive')->dailyAt('03:30');
