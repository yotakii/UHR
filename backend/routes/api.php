<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Candidate\MyApplicationController;
use App\Http\Controllers\Api\Employee\MyDocumentsController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\Hr\ApprovalController;
use App\Http\Controllers\Api\Hr\CandidateController;
use App\Http\Controllers\Api\Hr\CvController;
use App\Http\Controllers\Api\Hr\DashboardController;
use App\Http\Controllers\Api\Hr\EmployeeController;
use App\Http\Controllers\Api\Hr\JobController;
use App\Http\Controllers\Api\Hr\JobTestController;
use App\Http\Controllers\Api\Hr\OnboardingController;
use App\Http\Controllers\Api\Hr\PermissionController;
use App\Http\Controllers\Api\Hr\RulesController;
use App\Http\Controllers\Api\Hr\SearchController;
use App\Http\Controllers\Api\NoticeController;
use App\Http\Controllers\Api\Platform\PlatformController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\PublicTestController;
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
    // الاختبار الأولي للمتقدم: الرابط السري (D-34)
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('/tests/{token}', [PublicTestController::class, 'show']);
        Route::post('/tests/{token}/start', [PublicTestController::class, 'start']);
        Route::post('/tests/{token}/submit', [PublicTestController::class, 'submit']);
    });
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
    Route::post('/applications/{id}/erase', [MyApplicationController::class, 'erase'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('/profile', [MyApplicationController::class, 'profile']);
});

// M14 — «وثائق للتوقيع»: لكل حساب مرتبط بموظف (ومنهم موظفو الموارد البشرية أنفسهم)
Route::middleware(['auth', 'fresh'])->prefix('me/documents')->group(function () {
    Route::get('/', [MyDocumentsController::class, 'index']);
    Route::post('/sign', [MyDocumentsController::class, 'sign'])->middleware('throttle:30,1');
});

// نظام الشركة (M15/M16 + الوحدات): كل مسار محمي بصلاحية الوحدة والإجراء من مصفوفة الشركة (SRS §10.2)
Route::middleware(['auth', 'fresh', 'portal:hr'])->prefix('hr')->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/search', SearchController::class);

    Route::middleware('perm:jobs,view')->group(function () {
        Route::get('/jobs', [JobController::class, 'index']);
        Route::get('/jobs/lookups', [JobController::class, 'lookups']);
        Route::get('/jobs/{id}', [JobController::class, 'show'])->whereNumber('id');
        Route::get('/jobs/{id}/test', [JobTestController::class, 'show'])->whereNumber('id');
    });
    // الاختبار الأولي للشاغر: الإعداد، اقتراح الأسئلة بالذكاء الاصطناعي، التعديل والاعتماد
    Route::middleware('perm:jobs,edit')->group(function () {
        Route::put('/jobs/{id}/test', [JobTestController::class, 'update'])->whereNumber('id');
        Route::post('/jobs/{id}/test/generate', [JobTestController::class, 'generate'])->whereNumber('id')->middleware('throttle:10,1');
    });
    Route::get('/ai-settings', [JobTestController::class, 'ai'])->middleware('perm:jobs,view');
    Route::put('/ai-settings', [JobTestController::class, 'saveAi'])->middleware('perm:settings,edit');
    Route::post('/jobs', [JobController::class, 'store'])->middleware('perm:jobs,create');
    Route::put('/jobs/{id}', [JobController::class, 'update'])->whereNumber('id')->middleware('perm:jobs,edit');
    Route::post('/jobs/{id}/status', [JobController::class, 'status'])->whereNumber('id')->middleware('perm:jobs,edit');

    // M03/M04 — لوحة المتقدمين وملف المتقدم
    Route::middleware('perm:candidates,view')->group(function () {
        Route::get('/candidates', [CandidateController::class, 'index']);
        Route::get('/candidates/{id}', [CandidateController::class, 'show'])->whereNumber('id');
        Route::get('/recruitment-rules', [RulesController::class, 'show']);
        // قاعدة السير الذاتية (D-02)
        Route::get('/cv', [CvController::class, 'index']);
    });
    Route::get('/cv/export', [CvController::class, 'export'])->middleware('perm:candidates,export');
    Route::middleware('perm:candidates,edit')->group(function () {
        Route::post('/candidates/{id}/move', [CandidateController::class, 'move'])->whereNumber('id');
        Route::post('/candidates/{id}/notes', [CandidateController::class, 'note'])->whereNumber('id');
        Route::put('/candidates/{id}/interview', [CandidateController::class, 'interview'])->whereNumber('id');
        Route::put('/candidates/{id}/offer', [CandidateController::class, 'offer'])->whereNumber('id');
        Route::post('/candidates/{id}/test', [CandidateController::class, 'sendTest'])->whereNumber('id');
        Route::post('/candidates/{id}/test/review', [CandidateController::class, 'reviewTest'])->whereNumber('id');
        Route::post('/cv/{id}/archive', [CvController::class, 'archive'])->whereNumber('id');
    });
    Route::put('/recruitment-rules', [RulesController::class, 'update'])->middleware('perm:settings,edit');
    Route::post('/recruitment-rules/preview', [RulesController::class, 'preview'])->middleware('perm:settings,edit');
    // «اعتماداتي» — قرار التعيين F-31: لصاحب المستوى فقط (التحقق في AppointmentFlow)
    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::post('/approvals/{id}', [ApprovalController::class, 'decide'])->whereNumber('id');

    // M06 — التعيين والمباشرة (نطاق «الموظفين» للمستخدم: خاصته/فريقه/الكل)
    Route::middleware('perm:employees,view')->group(function () {
        Route::get('/onboarding', [OnboardingController::class, 'index']);
        Route::get('/onboarding/{id}', [OnboardingController::class, 'show'])->whereNumber('id');
    });
    Route::middleware('perm:employees,edit')->prefix('onboarding/{id}')->whereNumber('id')->group(function () {
        Route::post('/check', [OnboardingController::class, 'check']);
        Route::post('/memo', [OnboardingController::class, 'memo']);
        Route::post('/assets', [OnboardingController::class, 'issueAsset']);
        Route::delete('/assets/{assetId}', [OnboardingController::class, 'returnAsset'])->whereNumber('assetId');
        Route::post('/confirm', [OnboardingController::class, 'confirm']);
    });
    // معالج إضافة موظف + استيراد الموظفين الحاليين من Excel
    Route::middleware('perm:employees,create')->prefix('employees')->group(function () {
        Route::get('/lookups', [EmployeeController::class, 'lookups']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::get('/import/template', [EmployeeController::class, 'template']);
        Route::post('/import/preview', [EmployeeController::class, 'preview'])->middleware('throttle:30,1');
        Route::post('/import', [EmployeeController::class, 'import'])->middleware('throttle:10,1');
    });

    // M16 — لوحة الصلاحيات
    Route::get('/permissions', [PermissionController::class, 'show'])->middleware('perm:users,view');
    Route::put('/permissions', [PermissionController::class, 'update'])->middleware('perm:users,edit');

    // M16 — سجل النماذج وقالب الطباعة
    Route::get('/forms', [FormController::class, 'index'])->middleware('perm:settings,view');
    Route::put('/forms/{id}', [FormController::class, 'update'])->whereNumber('id')->middleware('perm:settings,edit');
    Route::put('/print-template', [FormController::class, 'updateTemplate'])->middleware('perm:settings,edit');
});

// الجرس — لكل مستخدم مسجّل (HR: إشعارات الموارد البشرية + إشعاراته، الموظف والمتقدم: إشعاراته)
Route::middleware(['auth', 'fresh'])->group(function () {
    Route::get('/notices', [NoticeController::class, 'index']);
    Route::post('/notices/read', [NoticeController::class, 'read']);
    // الطباعة: قالب الشركة + تسجيل طباعة النماذج الحساسة
    Route::get('/print/{code}', [FormController::class, 'printMeta'])->where('code', '[A-Za-z0-9.\-]+');
    Route::post('/print/{code}', [FormController::class, 'logPrint'])->where('code', '[A-Za-z0-9.\-]+');
    // مستودع الملفات: التنزيل عبر الخادم فقط، بصلاحية وتسجيل (SRS §10.3)
    Route::get('/files/{id}', [FileController::class, 'show'])->whereNumber('id');
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
