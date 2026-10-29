<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// قرار المستخدم (29 تشرين الأول): لا ميزات ذكاء اصطناعي في النظام — الاختبار الأولي يكتبه HR ويصححه يدوياً.
// تُحذف أعمدة الأسئلة المولّدة والدرجات المقترحة، ومفتاح API المحفوظ للشركات.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_tests', fn (Blueprint $t) => $t->dropColumn(['generated', 'generated_at']));
        Schema::table('candidate_tests', fn (Blueprint $t) => $t->dropColumn('suggested'));
        DB::table('settings')->where('key', 'ai')->delete();
    }

    public function down(): void
    {
        Schema::table('job_tests', function (Blueprint $t) { $t->jsonb('generated')->nullable(); $t->timestamp('generated_at')->nullable(); });
        Schema::table('candidate_tests', fn (Blueprint $t) => $t->jsonb('suggested')->nullable());
    }
};
