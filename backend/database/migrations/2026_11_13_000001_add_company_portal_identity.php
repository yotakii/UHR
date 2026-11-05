<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الملاحظات 15f (86): بوابة لكل شركة — منصة واحدة ومساحة معزولة لكل شركة، ولكل شركة عنوان دخول وفرص عمل بهويتها:
 * {slug}.النطاق الأساسي للمنصة، أو نطاق خاص بها (domain)، أو /c/{slug}. brand_color لون هويتها في صفحة الدخول.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $t) {
            $t->string('domain', 190)->nullable()->unique()->after('website');
            $t->string('brand_color', 9)->nullable()->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['domain', 'brand_color']));
    }
};
