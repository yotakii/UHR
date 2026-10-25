<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التعاقب (D-23): المناصب الحرجة والكفاءات المطلوبة ← ترشيح المدير مع الجاهزية (الآن / خلال سنة / خلال 3 سنوات) ← فجوات HR وربطها بخطة تطوير؛
 * منصب بلا مرشح جاهز الآن = تنبيه فجوة. الحالات: draft ← active ← reviewed.
 * خطة التطوير الفردية (D-24): الفجوات، الأهداف، الأنشطة بمسؤول وموعد، المتابعة وإعادة التقييم الدورية. الحالات: draft ← active ← done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('development_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('title', 190);
            $t->text('gaps')->nullable();
            $t->jsonb('goals');                                   // [{text, done}]
            $t->string('status', 10)->default('draft');           // draft, active, done
            $t->string('source', 12)->default('manual');          // manual, succession, review
            $t->date('next_review')->nullable();
            $t->jsonb('reviews')->nullable();                     // [{at, by, note, rating}]
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('review_alerted_at')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status']);
        });

        Schema::create('development_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('development_plan_id')->constrained()->cascadeOnDelete();
            $t->string('title', 190);
            $t->string('kind', 12)->default('course');            // course, mentoring, project, reading, rotation, other
            $t->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->date('due_on');
            $t->string('status', 12)->default('planned');         // planned, in_progress, done, cancelled
            $t->unsignedTinyInteger('progress')->default(0);
            $t->text('note')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->timestamp('alerted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('succession_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('title_ar', 190);
            $t->string('title_en', 190);
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('incumbent_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('risk', 8)->default('medium');             // low, medium, high — أثر شغور المنصب
            $t->text('reason')->nullable();
            $t->jsonb('competencies');                            // [competency_id]
            $t->string('status', 10)->default('draft');           // draft, active, reviewed
            $t->date('next_review')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('review_note')->nullable();
            $t->timestamp('gap_alerted_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('succession_candidates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('succession_plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('readiness', 4);                           // now, 1y, 3y
            $t->text('note')->nullable();
            $t->text('gaps')->nullable();
            $t->foreignId('development_plan_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('nominated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['succession_plan_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['succession_candidates', 'succession_plans', 'development_activities', 'development_plans'] as $t) Schema::dropIfExists($t);
    }
};
