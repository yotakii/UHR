<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** أوزان مطابقة خاصة بالشاغر (SRS §10.9: «أوزان المتطلبات قابلة للتعديل لكل شاغر») — null = أوزان الشركة */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_openings', fn (Blueprint $t) => $t->jsonb('match_weights')->nullable()->after('requirements'));
    }

    public function down(): void
    {
        Schema::table('job_openings', fn (Blueprint $t) => $t->dropColumn('match_weights'));
    }
};
