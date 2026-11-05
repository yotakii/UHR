<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ملاحظة الاختبار 66: بريد للتواصل في التعميم (حقل مستقل يُتحقق من صيغته ويظهر زر مراسلة) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', fn (Blueprint $t) => $t->string('contact_email', 190)->nullable()->after('text_en'));
    }

    public function down(): void
    {
        Schema::table('announcements', fn (Blueprint $t) => $t->dropColumn('contact_email'));
    }
};
