<?php

namespace Tests;

use App\Models\Company;
use App\Services\Platform\CompanyDefaults;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Tenant::forget();
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

    /** الشركة الحالية = شركة بيانات الاختبار (الفرادة) */
    protected function useCompany(string $slug = 'alfarada'): Company
    {
        $c = Company::where('slug', $slug)->firstOrFail();
        Tenant::set($c);
        return $c;
    }
}
