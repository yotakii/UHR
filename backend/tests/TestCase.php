<?php

namespace Tests;

use App\Models\Company;
use App\Services\Platform\CompanyDefaults;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Tenant::forget();
        // Eloquent يخزّن أعمدة كل جدول (لفحص $guarded) في متغير ساكن طوال العملية؛ اختبار وحدة قبل
        // RefreshDatabase قد يقرأ مخططاً قديماً من uhr_test ← نفرّغه بعد كل اختبار
        (fn () => static::$guardableColumns = [])->bindTo(null, EloquentModel::class)();
        parent::tearDown();
    }

    /** شركة بقيمها الافتراضية، وتُضبط كشركة حالية للاختبار */
    protected function makeCompany(string $slug = 'acme', array $attrs = []): Company
    {
        $c = Company::create($attrs + ['slug' => $slug, 'name_ar' => 'شركة '.$slug, 'name_en' => ucfirst($slug).' Co.', 'status' => 'active', 'portal_enabled' => true, 'portal_review' => true]);
        CompanyDefaults::seed($c);
        Tenant::set($c);
        return $c;
    }

    /** يفتح نافذة الرواتب (التحقق بخطوتين — SRS §4.4) لجلسة المستخدم الحالي */
    protected function unlockSalaries(): void
    {
        $this->withSession(['salary_vault' => ['uid' => auth()->id(), 'until' => now()->timestamp + 3600]]);
    }

    /** الشركة الحالية = شركة بيانات الاختبار (الفرادة) */
    protected function useCompany(string $slug = 'alfarada'): Company
    {
        $c = Company::where('slug', $slug)->firstOrFail();
        Tenant::set($c);
        return $c;
    }
}
