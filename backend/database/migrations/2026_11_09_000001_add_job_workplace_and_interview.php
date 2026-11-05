<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الملاحظات 15b:
 * 07 مكان العمل (حضوري/عن بُعد/هجين) منفصل عن نوع الدوام — «عن بُعد» كان نوع دوام فيصبح دواماً كاملاً عن بُعد.
 * 30/58 أسئلة المقابلة والمهارات المطلوب قياسها لكل شاغر (تُستخدم في F-25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_openings', function (Blueprint $t) {
            $t->string('workplace', 10)->default('onsite')->after('employment_type');
            $t->jsonb('interview')->nullable()->after('requirements');   // {questions: [{ar,en}], skills: [{ar,en}]}
        });
        DB::table('job_openings')->where('employment_type', 'remote')->update(['employment_type' => 'full_time', 'workplace' => 'remote']);
        Schema::table('interviews', function (Blueprint $t) {
            $t->jsonb('answers')->nullable()->after('notes');            // {questions: [{q, note, score}], skills: [{s, score}]}
        });
    }

    public function down(): void
    {
        Schema::table('interviews', fn (Blueprint $t) => $t->dropColumn('answers'));
        Schema::table('job_openings', fn (Blueprint $t) => $t->dropColumn(['workplace', 'interview']));
    }
};
