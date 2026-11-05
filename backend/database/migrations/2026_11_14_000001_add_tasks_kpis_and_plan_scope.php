<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * دفعة الملاحظات 15g:
 * 61+62 إدارة المهام والأهداف ومؤشرات الأداء (KPIs)، ونقاط من الأداء (إنجاز المهام، تحقيق المؤشرات، نتيجة التقييم) لا من الإحالة فقط.
 * 69 خطط تطوير للإدارة أو للشركة كلها، لا الفردية فقط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpis', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('scope', 12)->default('employee');                 // employee | department | company
            $t->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('name_ar', 190);
            $t->string('name_en', 190)->nullable();
            $t->string('unit', 10)->default('number');                    // number | percent | currency | count | hours | days
            $t->decimal('target', 14, 2);
            $t->string('direction', 6)->default('higher');                // higher | lower (الأقل أفضل)
            $t->string('period', 8)->default('month');                    // month | quarter | year
            $t->unsignedSmallInteger('weight')->default(1);
            $t->text('note')->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'scope', 'active']);
        });
        Schema::create('kpi_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('kpi_id')->constrained()->cascadeOnDelete();
            $t->string('period', 8);                                      // 2026-11 | 2026-Q4 | 2026
            $t->decimal('actual', 14, 2);
            $t->decimal('achievement', 6, 1);                             // نسبة التحقيق %
            $t->text('note')->nullable();
            $t->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['kpi_id', 'period']);
        });
        Schema::create('work_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('title', 190);
            $t->text('description')->nullable();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();     // المكلَّف
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('kpi_id')->nullable()->constrained()->nullOnDelete();
            $t->string('priority', 8)->default('normal');                    // low | normal | high | urgent
            $t->string('status', 12)->default('todo');                       // todo | in_progress | review | done | cancelled
            $t->unsignedTinyInteger('progress')->default(0);
            $t->date('due_on')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('close_note')->nullable();
            $t->integer('points')->default(0);
            $t->timestamp('alerted_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'employee_id', 'status']);
        });
        Schema::create('work_task_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_task_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->text('body');
            $t->timestamps();
        });
        Schema::table('point_logs', function (Blueprint $t) {
            $t->string('source', 12)->nullable();          // referral | task | kpi | review | store | manual
            $t->unsignedBigInteger('source_id')->nullable();
            $t->index(['source', 'source_id']);
        });
        Schema::table('development_plans', function (Blueprint $t) {
            $t->string('scope', 12)->default('employee')->after('company_id');    // employee | department | company
            $t->foreignId('department_id')->nullable()->after('employee_id')->constrained()->nullOnDelete();
            $t->foreignId('employee_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('development_plans', function (Blueprint $t) { $t->dropConstrainedForeignId('department_id'); $t->dropColumn('scope'); });
        Schema::table('point_logs', function (Blueprint $t) { $t->dropIndex(['source', 'source_id']); $t->dropColumn(['source', 'source_id']); });
        Schema::dropIfExists('work_task_comments');
        Schema::dropIfExists('work_tasks');
        Schema::dropIfExists('kpi_entries');
        Schema::dropIfExists('kpis');
    }
};
