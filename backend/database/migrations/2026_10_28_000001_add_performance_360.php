<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M13 (SRS M13، §10.12 D-28): دورة تقييم حقيقية — نماذج ذاتي/مدير/زملاء، المعايرة على 9-Box، اعتماد النتائج،
 * واستحقاق المكافأة حسب جدول الشركة (محتسبة ← معتمدة ← مصروفة؛ خارج السلّم ← مراجعة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_cycles', function (Blueprint $t) {
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();     // دورة لإدارة واحدة (اختياري)
            $t->timestamp('opened_at')->nullable();
            $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::table('performance_cycles')->where('status', 'open')->whereNotExists(fn ($q) => $q->from('performance_reviews')->whereColumn('performance_reviews.performance_cycle_id', 'performance_cycles.id'))
            ->update(['status' => 'draft']);   // دورة افتراضية بلا نماذج = مسودة

        Schema::table('performance_reviews', function (Blueprint $t) {
            $t->string('status', 12)->default('open');            // open, approved
            $t->boolean('calibrated')->default(false);
            $t->text('calibration_note')->nullable();
            $t->foreignId('calibrated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('calibrated_at')->nullable();
            $t->timestamp('approved_at')->nullable();
        });

        Schema::create('performance_forms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('performance_review_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();                  // المُقيَّم
            $t->string('role', 8);                                                          // self, manager, peer
            $t->foreignId('rater_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('rater_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->jsonb('ratings')->nullable();                                               // {competency_id: 1..scale}
            $t->unsignedTinyInteger('potential')->nullable();                               // نموذج المدير: 1–3
            $t->text('comment')->nullable();
            $t->string('status', 10)->default('pending');                                   // pending, submitted
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->unique(['performance_review_id', 'role', 'rater_employee_id']);
            $t->index(['rater_user_id', 'status']);
        });

        Schema::create('performance_awards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('performance_review_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->decimal('score', 3, 1);
            $t->jsonb('band')->nullable();                                                  // البند المطابق من الجدول
            $t->string('kind', 10)->nullable();                                             // percent, amount, points
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('currency', 3)->default('USD');
            $t->unsignedInteger('points')->default(0);
            $t->string('status', 12)->default('calculated');                                // review, calculated, approved, paid, rejected
            $t->string('month', 7)->nullable();                                             // كشف الشهر الذي تُصرف فيه
            $t->text('note')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('month_sheet_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique('performance_review_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_awards');
        Schema::dropIfExists('performance_forms');
        Schema::table('performance_reviews', function (Blueprint $t) {
            $t->dropConstrainedForeignId('calibrated_by');
            $t->dropColumn(['status', 'calibrated', 'calibration_note', 'calibrated_at', 'approved_at']);
        });
        Schema::table('performance_cycles', function (Blueprint $t) {
            foreach (['department_id', 'opened_by', 'approved_by', 'created_by'] as $c) $t->dropConstrainedForeignId($c);
            $t->dropColumn(['opened_at', 'approved_at']);
        });
    }
};
