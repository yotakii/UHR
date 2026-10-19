<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المهمة 06c (SRS §10.9): قاعدة السير (حالة السيرة: نشطة ← مؤرشفة ← محذوفة بطلب المتقدم)،
 * والاختبار الأولي: أسئلة الشاغر (مسودة ← معتمد) واختبار كل متقدم (مُرسل ← جارٍ ← مكتمل ← مُراجَع، و«غير مكتمل» عند انتهاء المدة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $t) {
            $t->string('cv_status', 10)->default('active')->after('keep_cv');   // active, archived, erased
            $t->timestamp('cv_archived_at')->nullable()->after('cv_status');
            $t->timestamp('erased_at')->nullable()->after('cv_archived_at');
            $t->index(['keep_cv', 'cv_status']);
        });

        Schema::table('job_openings', function (Blueprint $t) {
            $t->boolean('test_enabled')->default(false);
            $t->string('test_audience', 8)->default('passed');     // all, passed (المطابقون فوق حد الفرز)
            $t->unsignedSmallInteger('test_minutes')->default(30);
            $t->unsignedTinyInteger('test_attempts')->default(1);
            $t->unsignedTinyInteger('test_valid_days')->default(3);
        });

        Schema::create('job_tests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('job_opening_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('status', 10)->default('draft');            // draft, approved
            $t->jsonb('questions');                                 // [{id, type: mcq|short|scenario, text, options[], answer, points, rubric}]
            $t->jsonb('generated')->nullable();                     // الأسئلة كما اقترحها الذكاء الاصطناعي (للسجل)
            $t->timestamp('generated_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('candidate_tests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('candidate_id')->constrained()->cascadeOnDelete();
            $t->foreignId('job_test_id')->nullable()->constrained()->nullOnDelete();
            $t->string('token', 64)->unique();
            $t->string('status', 10)->default('sent');             // sent, started, completed, reviewed, expired
            $t->unsignedTinyInteger('attempt')->default(1);
            $t->jsonb('questions');                                 // نسخة الأسئلة وقت الإرسال
            $t->jsonb('answers')->nullable();                       // {questionId: index|text}
            $t->jsonb('suggested')->nullable();                     // {questionId: {score, note}} تقييم مقترح للأسئلة المفتوحة
            $t->jsonb('scores')->nullable();                        // {questionId: score} الدرجة المعتمدة
            $t->unsignedSmallInteger('minutes');
            $t->unsignedTinyInteger('auto_pct')->nullable();        // نسبة الأسئلة الموضوعية
            $t->unsignedTinyInteger('pct')->nullable();             // النتيجة النهائية بعد المراجعة
            $t->timestamp('sent_at');
            $t->timestamp('expires_at');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('deadline_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
            $t->index(['candidate_id', 'attempt']);
            $t->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_tests');
        Schema::dropIfExists('job_tests');
        Schema::table('job_openings', fn (Blueprint $t) => $t->dropColumn(['test_enabled', 'test_audience', 'test_minutes', 'test_attempts', 'test_valid_days']));
        Schema::table('candidates', function (Blueprint $t) {
            $t->dropIndex(['keep_cv', 'cv_status']);
            $t->dropColumn(['cv_status', 'cv_archived_at', 'erased_at']);
        });
    }
};
