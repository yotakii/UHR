<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Candidate\MyApplicationController;
use App\Http\Controllers\Api\Employee\ApprovalInboxController;
use App\Http\Controllers\Api\Employee\MyDocumentsController;
use App\Http\Controllers\Api\Employee\MyRequestsController;
use App\Http\Controllers\Api\Employee\MyServicesController;
use App\Http\Controllers\Api\Hr\PayrollController;
use App\Http\Controllers\Api\Hr\CustodyController;
use App\Http\Controllers\Api\Hr\DisciplineController;
use App\Http\Controllers\Api\Hr\OffboardingController;
use App\Http\Controllers\Api\Employee\MyExitController;
use App\Http\Controllers\Api\Employee\MyRewardsController;
use App\Http\Controllers\Api\Employee\MyReviewsController;
use App\Http\Controllers\Api\Employee\MyPortalController;
use App\Http\Controllers\Api\DevelopmentController;
use App\Http\Controllers\Api\Hr\ReferralsController;
use App\Http\Controllers\Api\Hr\AnnouncementController;
use App\Http\Controllers\Api\Hr\AnalyticsController;
use App\Http\Controllers\Api\Hr\PerformanceController;
use App\Http\Controllers\Api\Employee\MyDisciplineController;
use App\Http\Controllers\Api\Hr\LeaveAdminController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\Hr\ApprovalController;
use App\Http\Controllers\Api\Hr\CandidateController;
use App\Http\Controllers\Api\Hr\CvController;
use App\Http\Controllers\Api\Device\TerminalController;
use App\Http\Controllers\Api\Hr\AttendanceController;
use App\Http\Controllers\Api\Hr\DashboardController;
use App\Http\Controllers\Api\Hr\DeviceController;
use App\Http\Controllers\Api\Hr\PolicyController;
use App\Http\Controllers\Api\Hr\DirectoryController;
use App\Http\Controllers\Api\Hr\EmployeeDocController;
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
use App\Http\Controllers\Api\Security\VaultController;
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
// المسار الموحد للطلبات (SRS §10.1): طلباتي وحضوري لكل حساب له ملف موظف، وصندوق الاعتماد لكل معتمِد (المدير، HR، المستويات العليا)
Route::middleware(['auth', 'fresh'])->prefix('me')->group(function () {
    Route::get('/leave', [MyRequestsController::class, 'index']);
    Route::post('/leave/check', [MyRequestsController::class, 'check'])->middleware('throttle:60,1');
    Route::post('/leave', [MyRequestsController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/leave/{id}/cancel', [MyRequestsController::class, 'cancel'])->whereNumber('id');
    Route::post('/leave/{id}/document', [MyRequestsController::class, 'attach'])->whereNumber('id')->middleware('throttle:20,1');
    Route::get('/attendance', [MyRequestsController::class, 'attendance']);
    Route::post('/justifications', [MyRequestsController::class, 'justify'])->middleware('throttle:20,1');
    Route::get('/approvals', [ApprovalInboxController::class, 'index']);
    Route::post('/approvals/{stepId}', [ApprovalInboxController::class, 'decide'])->whereNumber('stepId');
    Route::put('/delegate', [ApprovalInboxController::class, 'delegate']);
    Route::post('/overtime', [ApprovalInboxController::class, 'assignOvertime'])->middleware('throttle:30,1');
    // خدماتي (D-16) وكشفي الشهري (D-01)
    Route::get('/services', [MyServicesController::class, 'index']);
    Route::post('/services', [MyServicesController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/services/{id}/cancel', [MyServicesController::class, 'cancel'])->whereNumber('id');
    Route::get('/services/{id}/certificate', [MyServicesController::class, 'certificate'])->whereNumber('id');
    Route::get('/payslips', [MyServicesController::class, 'payslips']);
    // M17 — إنذاراتي (الأقوال، الإقرار) وشكاواي F-02، والمدير يفتح حالة لفريقه
    Route::get('/discipline', [MyDisciplineController::class, 'index']);
    Route::post('/discipline', [MyDisciplineController::class, 'open'])->middleware('throttle:20,1');
    Route::post('/discipline/{id}/defense', [MyDisciplineController::class, 'defend'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/discipline/{id}/acknowledge', [MyDisciplineController::class, 'acknowledge'])->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/complaints', [MyDisciplineController::class, 'complain'])->middleware('throttle:10,1');
    // M11 — طلب الاستقالة F-02، الرجوع عنها، تسليم المهام F-05 (المسلِّم والمستلم)
    Route::get('/exit', [MyExitController::class, 'index']);
    Route::post('/exit', [MyExitController::class, 'store'])->middleware('throttle:10,1');
    Route::post('/exit/{id}/withdraw', [MyExitController::class, 'withdraw'])->whereNumber('id')->middleware('throttle:10,1');
    Route::put('/exit/{id}/handover', [MyExitController::class, 'handover'])->whereNumber('id');
    Route::post('/exit/{id}/handover/sign', [MyExitController::class, 'sign'])->whereNumber('id')->middleware('throttle:20,1');
    // M12 — المكافآت والإحالات، والاستقطاب الداخلي F-27، والإعلانات
    Route::get('/rewards', [MyRewardsController::class, 'index']);
    Route::post('/rewards/share', [MyRewardsController::class, 'share'])->middleware('throttle:30,1');
    Route::post('/rewards/redeem', [MyRewardsController::class, 'redeem'])->middleware('throttle:10,1');
    Route::get('/internal', [MyRewardsController::class, 'internal']);
    Route::post('/internal', [MyRewardsController::class, 'applyInternal'])->middleware('throttle:10,1');
    Route::post('/internal/{id}/cancel', [MyRewardsController::class, 'cancelInternal'])->whereNumber('id');
    Route::post('/internal/{id}/offer-response', [MyRewardsController::class, 'offerResponse'])->whereNumber('id')->middleware('throttle:10,1');
    Route::get('/announcements', [AnnouncementController::class, 'mine']);
    // M14 — بوابة الموظف: الرئيسية، التسجيل من الجوال، فريقي، ملفي الشخصي
    Route::get('/home', [MyPortalController::class, 'home']);
    Route::post('/checkin', [MyPortalController::class, 'checkIn'])->middleware('throttle:10,1');
    Route::get('/team', [MyPortalController::class, 'team']);
    Route::get('/my-file', [MyPortalController::class, 'profile']);
    Route::put('/my-file/phone', [MyPortalController::class, 'phone'])->middleware('throttle:10,1');
    // M13 — نماذج تقييم 360 المطلوبة مني ونتائجي
    Route::get('/reviews', [MyReviewsController::class, 'index']);
    Route::post('/reviews/{id}', [MyReviewsController::class, 'fill'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/exit/{id}/clearance/{section}/sign', [MyExitController::class, 'signSection'])->whereNumber('id')->where('section', '[a-z0-9_]+');
    Route::post('/exit/{id}/clearance/{section}/{item}', [MyExitController::class, 'markItem'])->whereNumber('id')->where(['section' => '[a-z0-9_]+', 'item' => '[a-z0-9_]+']);
});

// التعاقب وخطط التطوير (D-23، D-24): من البوابتين؛ النطاق والدور يُفحصان في الخدمات
Route::middleware(['auth', 'fresh', 'perm:succession,view'])->prefix('development')->group(function () {
    Route::get('/', [DevelopmentController::class, 'index']);
    Route::post('/plans', [DevelopmentController::class, 'storePlan'])->middleware('perm:succession,create');
    Route::put('/plans/{id}', [DevelopmentController::class, 'updatePlan'])->whereNumber('id')->middleware('perm:succession,edit');
    Route::post('/plans/{id}/activate', [DevelopmentController::class, 'activate'])->whereNumber('id')->middleware('perm:succession,edit');
    Route::post('/plans/{id}/review', [DevelopmentController::class, 'review'])->whereNumber('id')->middleware('perm:succession,edit');
    Route::post('/activities/{id}', [DevelopmentController::class, 'progress'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('/succession', [DevelopmentController::class, 'storeSuccession'])->middleware('perm:succession,create');
    Route::put('/succession/{id}', [DevelopmentController::class, 'updateSuccession'])->whereNumber('id')->middleware('perm:succession,edit');
    Route::post('/succession/{id}/activate', [DevelopmentController::class, 'activateSuccession'])->whereNumber('id')->middleware('perm:succession,edit');
    Route::post('/succession/{id}/review', [DevelopmentController::class, 'reviewSuccession'])->whereNumber('id')->middleware('perm:succession,edit');
    Route::post('/succession/{id}/candidates', [DevelopmentController::class, 'nominate'])->whereNumber('id')->middleware('perm:succession,create');
    Route::put('/candidates/{id}', [DevelopmentController::class, 'assess'])->whereNumber('id')->middleware('perm:succession,edit');
    Route::delete('/candidates/{id}', [DevelopmentController::class, 'removeCandidate'])->whereNumber('id')->middleware('perm:succession,create');
    Route::put('/settings', [DevelopmentController::class, 'saveSettings'])->middleware('perm:settings,edit');
});

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
    // M07 — دليل الموظفين والملف الوظيفي والملفات الإضافية والهيكل (بنطاق own/team/all)
    Route::middleware('perm:employees,view')->group(function () {
        Route::get('/employees', [DirectoryController::class, 'index']);
        Route::get('/employees/lookups', [EmployeeController::class, 'lookups']);
        Route::get('/employees/{id}', [DirectoryController::class, 'show'])->whereNumber('id');
        Route::get('/employees/{id}/documents/{docId}/log', [EmployeeDocController::class, 'history'])->whereNumber(['id', 'docId']);
        Route::get('/employee-docs/settings', [EmployeeDocController::class, 'settings']);
    });
    Route::get('/employees/export', [DirectoryController::class, 'export'])->middleware('perm:employees,export');
    Route::middleware('perm:employees,edit')->prefix('employees/{id}')->whereNumber('id')->group(function () {
        Route::put('/', [DirectoryController::class, 'update']);
        Route::put('/card', [DirectoryController::class, 'card']);
        Route::get('/card/last-read', [DirectoryController::class, 'lastCard']);
        Route::post('/documents', [EmployeeDocController::class, 'store'])->middleware('throttle:30,1');
        Route::post('/documents/{docId}/archive', [EmployeeDocController::class, 'archive'])->whereNumber('docId');
    });
    Route::put('/employee-docs/settings', [EmployeeDocController::class, 'saveSettings'])->middleware('perm:settings,edit');
    Route::get('/org', [DirectoryController::class, 'org'])->middleware('perm:org,view');

    // M08 — الحضور والدوام (بنطاق own/team/all لوحدة attendance) + الأجهزة المسجّلة
    Route::middleware('perm:attendance,view')->group(function () {
        Route::get('/attendance', [AttendanceController::class, 'daily']);
        Route::get('/attendance/period', [AttendanceController::class, 'period']);
    });
    Route::get('/attendance/export', [AttendanceController::class, 'export'])->middleware('perm:attendance,export');
    Route::middleware('perm:attendance,edit')->group(function () {
        Route::put('/attendance/{id}', [AttendanceController::class, 'correct'])->whereNumber('id');
        Route::get('/devices', [DeviceController::class, 'index']);
        Route::post('/devices', [DeviceController::class, 'store']);
        Route::put('/devices/{id}', [DeviceController::class, 'update'])->whereNumber('id');
        Route::post('/devices/{id}/rotate', [DeviceController::class, 'rotate'])->whereNumber('id');
        Route::post('/devices/{id}/package', [DeviceController::class, 'package'])->whereNumber('id');
    });

    // M09 — الإجازات والتبريرات والتكليف بالإضافي (القرار من صندوق الاعتماد /me/approvals)
    Route::get('/leaves', [LeaveAdminController::class, 'index'])->middleware('perm:leaves,view');
    Route::get('/services/{id}/certificate', [PayrollController::class, 'certificate'])->whereNumber('id')->middleware('perm:leaves,view');

    // M10 — العهد: السجل، التسليم والاسترجاع، الصيانة، الاستيراد من Excel (D-13)
    Route::prefix('custody')->group(function () {
        Route::get('/', [CustodyController::class, 'index'])->middleware('perm:assets,view');
        Route::get('/export', [CustodyController::class, 'export'])->middleware('perm:assets,export');
        Route::get('/import/template', [CustodyController::class, 'template'])->middleware('perm:assets,create');
        Route::post('/import/preview', [CustodyController::class, 'preview'])->middleware(['perm:assets,create', 'throttle:30,1']);
        Route::post('/import', [CustodyController::class, 'import'])->middleware(['perm:assets,create', 'throttle:10,1']);
        Route::post('/', [CustodyController::class, 'store'])->middleware('perm:assets,create');
        Route::get('/{id}', [CustodyController::class, 'history'])->whereNumber('id')->middleware('perm:assets,view');
        Route::middleware('perm:assets,edit')->group(function () {
            Route::put('/{id}', [CustodyController::class, 'update'])->whereNumber('id');
            Route::post('/{id}/issue', [CustodyController::class, 'issue'])->whereNumber('id');
            Route::post('/{id}/return', [CustodyController::class, 'takeBack'])->whereNumber('id');
            Route::post('/{id}/status', [CustodyController::class, 'status'])->whereNumber('id');
        });
    });

    // M12 — الإحالات والمكافآت، والإعلانات (D-06)
    Route::get('/referrals', [ReferralsController::class, 'index'])->middleware('perm:referrals,view');
    Route::middleware('perm:referrals,edit')->group(function () {
        Route::post('/referrals/redemptions/{id}', [ReferralsController::class, 'decide'])->whereNumber('id');
        Route::post('/referrals/rewards', [ReferralsController::class, 'saveReward']);
        Route::put('/referrals/rewards/{id}', [ReferralsController::class, 'updateReward'])->whereNumber('id');
    });
    Route::prefix('announcements')->group(function () {
        Route::get('/', [AnnouncementController::class, 'index'])->middleware('perm:announcements,view');
        Route::post('/', [AnnouncementController::class, 'store'])->middleware(['perm:announcements,create', 'throttle:30,1']);
        Route::post('/{id}', [AnnouncementController::class, 'update'])->whereNumber('id')->middleware('perm:announcements,create');
        Route::post('/{id}/decide', [AnnouncementController::class, 'decide'])->whereNumber('id')->middleware('perm:announcements,decide');
        Route::post('/{id}/archive', [AnnouncementController::class, 'archive'])->whereNumber('id')->middleware('perm:announcements,archive');
        Route::put('/settings', [AnnouncementController::class, 'saveSettings'])->middleware('perm:settings,edit');
    });

    // M13 — الأداء وتقييم 360، المعايرة، الاعتماد ومكافآت التقييم (D-28)، والتحليلات
    Route::prefix('performance')->group(function () {
        Route::get('/', [PerformanceController::class, 'index'])->middleware('perm:performance,view');
        Route::get('/reviews/{id}', [PerformanceController::class, 'review'])->whereNumber('id')->middleware('perm:performance,view');
        Route::get('/settings', [PerformanceController::class, 'settings'])->middleware('perm:performance,view');
        Route::put('/settings', [PerformanceController::class, 'saveSettings'])->middleware('perm:settings,edit');
        Route::post('/settings/preview', [PerformanceController::class, 'preview'])->middleware('perm:settings,edit');
        Route::post('/cycles', [PerformanceController::class, 'store'])->middleware('perm:performance,create');
        Route::middleware('perm:performance,edit')->group(function () {
            Route::put('/cycles/{id}', [PerformanceController::class, 'update'])->whereNumber('id');
            Route::post('/cycles/{id}/open', [PerformanceController::class, 'open'])->whereNumber('id');
            Route::post('/cycles/{id}/remind', [PerformanceController::class, 'remind'])->whereNumber('id')->middleware('throttle:6,1');
            Route::post('/reviews/{id}/place', [PerformanceController::class, 'place'])->whereNumber('id');
            Route::post('/reviews/{id}/peers', [PerformanceController::class, 'addPeer'])->whereNumber('id');
            Route::delete('/reviews/{id}/peers/{form}', [PerformanceController::class, 'removePeer'])->whereNumber(['id', 'form']);
        });
        Route::middleware('perm:performance,decide')->group(function () {
            Route::post('/cycles/{id}/approve', [PerformanceController::class, 'approve'])->whereNumber('id');
            Route::post('/awards/{id}', [PerformanceController::class, 'decideAward'])->whereNumber('id');
        });
        Route::get('/cycles/{id}/export', [PerformanceController::class, 'export'])->whereNumber('id')->middleware('perm:performance,export');
    });
    Route::get('/analytics', [AnalyticsController::class, 'index'])->middleware('perm:employees,export');
    Route::get('/analytics/export', [AnalyticsController::class, 'export'])->middleware('perm:employees,export');

    // M11 — الاستقالات وإنهاء الخدمة (HR.01-03): الإجراءات حسب الدور تُفحص في ExitFlow (المدير، الجهات، مدير النظام)
    Route::prefix('offboarding')->group(function () {
        Route::middleware('perm:employees,view')->group(function () {
            Route::get('/', [OffboardingController::class, 'index']);
            Route::get('/report', [OffboardingController::class, 'report']);
            Route::get('/settings', [OffboardingController::class, 'settings']);
            Route::get('/{id}', [OffboardingController::class, 'show'])->whereNumber('id');
            Route::post('/', [OffboardingController::class, 'terminate']);
            Route::post('/{id}/waive', [OffboardingController::class, 'waive'])->whereNumber('id');
            Route::post('/{id}/handover/sign', [OffboardingController::class, 'signHandover'])->whereNumber('id');
            Route::post('/{id}/clearance/{section}/sign', [OffboardingController::class, 'signSection'])->whereNumber('id')->where('section', '[a-z0-9_]+');
            Route::post('/{id}/clearance/{section}/{item}', [OffboardingController::class, 'markItem'])->whereNumber('id')->where(['section' => '[a-z0-9_]+', 'item' => '[a-z0-9_]+']);
            Route::post('/{id}/successor', [OffboardingController::class, 'successor'])->whereNumber('id');
        });
        Route::middleware('perm:employees,edit')->prefix('{id}')->whereNumber('id')->group(function () {
            Route::post('/interview', [OffboardingController::class, 'interview']);
            Route::post('/withdrawal', [OffboardingController::class, 'withdrawal']);
            Route::put('/handover', [OffboardingController::class, 'handover']);
            Route::post('/exit', [OffboardingController::class, 'exitInterview']);
            Route::post('/close', [OffboardingController::class, 'close']);
            Route::post('/certificate', [OffboardingController::class, 'certificate']);
            Route::post('/followups/{key}', [OffboardingController::class, 'followup'])->whereIn('key', ['labor', 'insurance']);
            Route::post('/notices/{noticeId}/send', [OffboardingController::class, 'sendNotice'])->whereNumber('noticeId');
        });
        Route::put('/settings', [OffboardingController::class, 'saveSettings'])->middleware('perm:settings,edit');
    });

    // M17 — المخالفات والجزاءات (بنطاق own/team/all)، دليل المخالفات، والشكاوى السرية (نطاق «الكل»)
    Route::prefix('discipline')->group(function () {
        Route::middleware('perm:discipline,view')->group(function () {
            Route::get('/', [DisciplineController::class, 'index']);
            Route::get('/suggestions', [DisciplineController::class, 'suggestions']);
            Route::get('/catalog', [DisciplineController::class, 'catalog']);
            Route::get('/complaints', [DisciplineController::class, 'complaints']);
            Route::get('/{id}', [DisciplineController::class, 'show'])->whereNumber('id');
        });
        Route::get('/{id}/warning', [DisciplineController::class, 'warning'])->whereNumber('id')->middleware('perm:discipline,print');
        Route::post('/', [DisciplineController::class, 'store'])->middleware('perm:discipline,create');
        Route::post('/{id}/investigate', [DisciplineController::class, 'investigate'])->whereNumber('id')->middleware('perm:discipline,edit');
        Route::post('/complaints/{id}', [DisciplineController::class, 'complaint'])->whereNumber('id')->middleware('perm:discipline,edit');
        Route::middleware('perm:discipline,decide')->group(function () {
            Route::post('/{id}/decide', [DisciplineController::class, 'decide'])->whereNumber('id');
            Route::post('/{id}/dismiss', [DisciplineController::class, 'dismiss'])->whereNumber('id');
        });
        Route::middleware('perm:settings,edit')->group(function () {
            Route::post('/catalog', [DisciplineController::class, 'saveType']);
            Route::put('/catalog/{id}', [DisciplineController::class, 'updateType'])->whereNumber('id');
            Route::post('/catalog/reset', [DisciplineController::class, 'resetCatalog']);
        });
    });

    // الكشف الشهري للدوام والمستحقات (D-01): مسودة ← محتسب ← معتمد ← مغلق
    Route::middleware('perm:payroll,view')->group(function () {
        Route::get('/payroll', [PayrollController::class, 'show']);
        Route::post('/payroll', [PayrollController::class, 'open'])->middleware('perm:payroll,edit');
        Route::post('/payroll/{id}/{action}', [PayrollController::class, 'act'])->whereNumber('id')->whereIn('action', ['calculate', 'approve', 'reopen', 'close']);
        Route::get('/payroll/{id}/export', [PayrollController::class, 'export'])->whereNumber('id')->middleware('perm:payroll,export');
    });

    // M16 — سياسات الدوام بتاريخ نفاذ والعطل الرسمية
    Route::middleware('perm:policies,view')->group(function () {
        Route::get('/policies', [PolicyController::class, 'index']);
        Route::get('/holidays', [PolicyController::class, 'holidays']);
    });
    Route::middleware('perm:policies,edit')->group(function () {
        Route::post('/policies', [PolicyController::class, 'store']);
        Route::post('/policies/preview', [PolicyController::class, 'preview'])->middleware('throttle:30,1');
        Route::delete('/policies/{id}', [PolicyController::class, 'destroy'])->whereNumber('id');
        Route::post('/holidays', [PolicyController::class, 'addHoliday']);
        Route::post('/holidays/fixed', [PolicyController::class, 'fixedHolidays']);
        Route::delete('/holidays/{id}', [PolicyController::class, 'deleteHoliday'])->whereNumber('id');
    });

    Route::middleware('perm:employees,create')->prefix('employees')->group(function () {
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

// جهاز الحضور وجسر القارئ: مفتاح الجهاز في X-Device-Key (بلا جلسة)
Route::middleware(['device', 'throttle:120,1'])->prefix('device')->group(function () {
    Route::get('/me', [TerminalController::class, 'me']);
    Route::post('/scan', [TerminalController::class, 'scan']);
});

// حماية الرواتب بخطوتين (SRS §4.4): لكل حساب بجلسة؛ المحاولات والإيقاف في SalaryVault
Route::middleware(['auth', 'fresh'])->prefix('me/mfa')->group(function () {
    Route::get('/', [VaultController::class, 'show']);
    Route::post('/lock', [VaultController::class, 'lock']);
    Route::post('/totp/enrol', [VaultController::class, 'enrol'])->middleware('throttle:10,1,mfa-totp-enrol');
    Route::post('/totp/confirm', [VaultController::class, 'confirm'])->middleware('throttle:20,1,mfa-totp-confirm');
    Route::post('/totp/verify', [VaultController::class, 'verifyApp'])->middleware('throttle:20,1,mfa-totp-verify');
    Route::post('/backup/verify', [VaultController::class, 'verifyBackup'])->middleware('throttle:20,1,mfa-backup-verify');
    Route::post('/otp/send', [VaultController::class, 'sendOtp'])->middleware('throttle:6,10,mfa-otp-send');
    Route::post('/otp/verify', [VaultController::class, 'verifyOtp'])->middleware('throttle:20,1,mfa-otp-verify');
    Route::post('/passkey/options', [VaultController::class, 'passkeyOptions'])->middleware('throttle:20,1,mfa-passkey-options');
    Route::post('/passkey/register', [VaultController::class, 'passkeyRegister'])->middleware('throttle:10,1,mfa-passkey-register');
    Route::post('/passkey/verify', [VaultController::class, 'passkeyVerify'])->middleware('throttle:20,1,mfa-passkey-verify');
    Route::delete('/passkey/{id}', [VaultController::class, 'removePasskey'])->whereNumber('id');
    Route::delete('/', [VaultController::class, 'reset'])->middleware('throttle:5,1,mfa-reset');
});
Route::middleware(['auth', 'fresh', 'portal:hr'])->prefix('hr/vault-settings')->group(function () {
    Route::get('/', [VaultController::class, 'settings'])->middleware('perm:settings,view');
    Route::put('/', [VaultController::class, 'saveSettings'])->middleware('perm:settings,edit');
});
