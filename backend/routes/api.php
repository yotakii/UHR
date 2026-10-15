<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Candidate\MyApplicationController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PublicController;
use Illuminate\Support\Facades\Route;

// كل المسارات هنا تحت البادئة /api
Route::get('/health', HealthController::class)->name('health');

// M01 — الواجهة العامة
Route::prefix('public')->group(function () {
    Route::get('/site', [PublicController::class, 'site']);
    Route::get('/jobs', [PublicController::class, 'jobs']);
    Route::get('/jobs/{code}', [PublicController::class, 'job']);
    // M02 — بوابة التوظيف F-28
    Route::post('/applications/check-duplicate', [ApplicationController::class, 'checkDuplicate'])->middleware('throttle:30,1');
    Route::post('/applications', [ApplicationController::class, 'store'])->middleware('throttle:applications');
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

// M05 — بوابة المتقدم
Route::middleware(['auth', 'fresh', 'portal:candidate'])->prefix('me/application')->group(function () {
    Route::get('/', [MyApplicationController::class, 'show']);
    Route::post('/offer-response', [MyApplicationController::class, 'respondOffer']);
});

// كل مسارات النظام القادمة: Route::middleware(['auth', 'fresh', 'portal:hr'])->group(...)
