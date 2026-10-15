<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Candidate\MyApplicationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Hr\JobController;
use App\Http\Controllers\Api\Platform\PlatformController;
use App\Http\Controllers\Api\PublicController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// كل المسارات هنا تحت البادئة /api
Route::get('/health', HealthController::class)->name('health');

// بوابة فرص العمل العامة (SRS §10.15) + صفحات الشركات والشواغر + التقديم F-28
Route::prefix('public')->group(function () {
    Route::get('/portal', [PublicController::class, 'portal']);
    Route::get('/jobs', [PublicController::class, 'jobs']);
    Route::get('/sitemap.xml', [PublicController::class, 'sitemap']);
    Route::get('/companies/{slug}', [PublicController::class, 'company']);
    Route::get('/companies/{slug}/logo', [PublicController::class, 'logo']);
    Route::get('/jobs/{slug}/{code}', [PublicController::class, 'job']);
    Route::post('/jobs/{slug}/{code}/check', [ApplicationController::class, 'checkDuplicate'])->middleware('throttle:30,1');
    Route::post('/jobs/{slug}/{code}/apply', [ApplicationController::class, 'store'])->middleware('throttle:applications');
});

// الدخول والجلسة
Route::prefix('auth')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot', [AuthController::class, 'forgot'])->middleware('throttle:password-email');
    Route::post('/reset', [AuthController::class, 'reset'])->middleware('throttle:login');
    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/password', [AuthController::class, 'changePassword']);
    });
});

// M05 — بوابة المتقدم: كل طلباته لدى كل الشركات
Route::middleware(['auth', 'fresh', 'portal:candidate'])->prefix('me')->group(function () {
    Route::get('/applications', [MyApplicationController::class, 'index']);
    Route::get('/applications/{id}', [MyApplicationController::class, 'show'])->whereNumber('id');
    Route::post('/applications/{id}/offer-response', [MyApplicationController::class, 'respondOffer'])->whereNumber('id');
    Route::get('/profile', [MyApplicationController::class, 'profile']);
});

// نظام الشركة — الوظائف الشاغرة ونشرها (HR ومسؤول التوظيف)
Route::middleware(['auth', 'fresh', 'portal:hr', 'role:'.implode(',', User::JOB_ROLES)])->prefix('hr')->group(function () {
    Route::get('/jobs', [JobController::class, 'index']);
    Route::get('/jobs/lookups', [JobController::class, 'lookups']);
    Route::get('/jobs/{id}', [JobController::class, 'show'])->whereNumber('id');
    Route::post('/jobs', [JobController::class, 'store']);
    Route::put('/jobs/{id}', [JobController::class, 'update'])->whereNumber('id');
    Route::post('/jobs/{id}/status', [JobController::class, 'status'])->whereNumber('id');
});

// مدير المنصة (Atmata)
Route::middleware(['auth', 'fresh', 'portal:platform'])->prefix('platform')->group(function () {
    Route::get('/overview', [PlatformController::class, 'overview']);
    Route::get('/companies', [PlatformController::class, 'companies']);
    Route::post('/companies', [PlatformController::class, 'storeCompany']);
    Route::post('/companies/{id}', [PlatformController::class, 'updateCompany'])->whereNumber('id');   // multipart مع الشعار
    Route::post('/companies/{id}/status', [PlatformController::class, 'companyStatus'])->whereNumber('id');
    Route::get('/reviews', [PlatformController::class, 'reviews']);
    Route::post('/reviews/{id}', [PlatformController::class, 'review'])->whereNumber('id');
    Route::get('/portal', [PlatformController::class, 'portal']);
    Route::put('/portal', [PlatformController::class, 'updatePortal']);
});
