<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعة 19 — تبويبة العقود (SRS الأنظمة الأساسية، الجزء D): سجل العقود، حالات التجديد وتقييم فترة التجربة،
 * طلب الاستثناء (المسار الموحد contract_exception)، وملاحق العقود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('ref_no', 30);
            $t->foreignId('contract_type_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->date('starts_on'); $t->date('ends_on')->nullable();             // null = غير محدد المدة
            $t->unsignedSmallInteger('probation_months')->default(0);
            $t->date('probation_ends_on')->nullable();
            // draft | pending_signatures | signed (بانتظار تاريخ البدء) | active | renewed | ended | expired_no_action | terminated | cancelled
            $t->string('status', 24)->default('draft');
            $t->string('source', 12)->default('manual');                       // manual | imported | renewal | probation
            $t->foreignId('previous_id')->nullable()->constrained('contracts')->nullOnDelete();
            $t->unsignedBigInteger('case_id')->nullable();
            $t->text('body')->nullable();                                      // نص العقد من القالب (مجمّد عند الإنشاء)
            $t->jsonb('signatures')->nullable();                               // [{role, ar, en, user_id, at, file_id}]
            $t->jsonb('copies')->nullable();                                   // {employee, file, insurance: {status, at, by}}
            $t->foreignId('signed_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->boolean('exception')->default(false);
            $t->text('exception_reason')->nullable();
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('signed_at')->nullable(); $t->timestamp('activated_at')->nullable(); $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'ref_no']);
            $t->index(['company_id', 'status', 'ends_on']);
        });

        Schema::create('contract_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);                                            // renewal | probation
            $t->string('ref_no', 30);
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $t->date('due_on');                                                // نهاية العقد أو نهاية فترة التجربة
            // manager | signatures | hr_review | decision | exception | preparing | closed
            $t->string('status', 16)->default('manager');
            $t->string('outcome', 16)->nullable();                             // renewed | converted | not_renewed | confirmed | terminated | extended
            $t->foreignId('evaluator_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->date('manager_due')->nullable(); $t->date('hr_due')->nullable();
            $t->jsonb('summary')->nullable();                                  // ملخص الموظف عند الفتح
            $t->jsonb('evaluation')->nullable();                               // {items, justify, total, grade, rating, recommendation, notes, by, at}
            $t->jsonb('signatures')->nullable();                               // توقيعات تقييم التجربة بالتسلسل
            $t->jsonb('review')->nullable();                                   // قائمة تحقق HR {checks, note, by, at}
            $t->jsonb('suggestion')->nullable();                               // {decision, type_code, months, rule}
            $t->jsonb('decision')->nullable();                                 // {decision, contract_type_id, months, reason, exception, by, at}
            $t->foreignId('new_contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $t->foreignId('resignation_id')->nullable()->constrained('resignations')->nullOnDelete();
            $t->timestamp('opened_at')->nullable(); $t->timestamp('closed_at')->nullable();
            $t->timestamp('reminded_at')->nullable(); $t->timestamp('escalated_at')->nullable(); $t->timestamp('lapsed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'ref_no']);
            $t->index(['company_id', 'kind', 'status']);
        });
        Schema::table('contracts', fn (Blueprint $t) => $t->foreign('case_id')->references('id')->on('contract_cases')->nullOnDelete());

        // طلب استثناء من السياسة (POL-CR-06/07) — موضوع المسار الموحد
        Schema::create('contract_exceptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_case_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->jsonb('decision');
            $t->text('reason');
            $t->string('status', 20)->default('pending');
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        // ملحق عقد: تعديل المنصب أو الدرجة دون عقد جديد (FR-CR-018)
        Schema::create('contract_annexes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('contract_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('ref_no', 30);
            $t->string('kind', 12);                                            // position | grade | other
            $t->date('effective_on');
            $t->jsonb('changes')->nullable();                                  // {job_id, title_ar, title_en, grade_id}
            $t->text('text');
            $t->string('status', 12)->default('pending');                      // pending | applied
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('applied_at')->nullable();
            $t->timestamps();
        });

        \App\Support\Audit::mute(function () {
            foreach (\App\Models\Company::all() as $c) \App\Support\Tenant::run($c, function () {
                \App\Models\ApprovalChain::firstOrCreate(['type' => 'contract_exception'], ['levels' => [['role_ar' => 'الرئيس التنفيذي', 'role_en' => 'CEO', 'role_key' => 'ceo']]]);
                \App\Support\Forms::seed();
                \App\Services\Contracts\Contracts::backfill();
            });
        });
    }

    public function down(): void
    {
        Schema::table('contracts', fn (Blueprint $t) => $t->dropForeign(['case_id']));
        foreach (['contract_annexes', 'contract_exceptions', 'contract_cases', 'contracts'] as $t) Schema::dropIfExists($t);
    }
};
