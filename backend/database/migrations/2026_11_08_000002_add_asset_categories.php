<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** ملاحظة الاختبار 52: فئتا «أدوات مكتبية» و«أدوات عمل مخصصة» لكل شركة قائمة (الجديدة تأخذها من CompanyDefaults) */
return new class extends Migration
{
    private const CATS = ['office' => ['أدوات مكتبية', 'Office supplies'], 'tools' => ['أدوات عمل مخصصة', 'Job-specific tools']];

    public function up(): void
    {
        foreach (DB::table('companies')->pluck('id') as $cid) {
            foreach (self::CATS as $code => [$ar, $en]) {
                if (DB::table('asset_categories')->where('company_id', $cid)->where('code', $code)->exists()) continue;
                DB::table('asset_categories')->insert(['company_id' => $cid, 'code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        DB::table('asset_categories')->whereIn('code', array_keys(self::CATS))->whereNotExists(fn ($q) => $q->from('assets')->whereColumn('assets.asset_category_id', 'asset_categories.id'))->delete();
    }
};
