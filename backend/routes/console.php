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
