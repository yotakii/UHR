<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعة 28 — إكمال الأنظمة الأساسية: قوائم المجموعة الموروثة (القابضة)، عطلة الفرع الأسبوعية، دعوة العمل ضمن المهمة،
 * الخبير مسافراً في مهمة، ربط اتفاقية التطوع بموظف/مهمة/مشروع، بطاقات غير الموظفين، رمز ذاكرة البطاقة، الإشارات في تعليقات المهمة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->foreignId('parent_id')->nullable()->constrained('companies')->nullOnDelete());
        Schema::table('code_lists', fn (Blueprint $t) => $t->boolean('inherit')->default(false));            // قائمة مجموعة تُورَّث للشركات التابعة
        Schema::table('code_items', fn (Blueprint $t) => $t->foreignId('group_item_id')->nullable());           // نسخة موروثة من رمز المجموعة (للقراءة فقط)
        Schema::table('locations', fn (Blueprint $t) => $t->jsonb('rest_days')->nullable());                    // أيام العطلة الأسبوعية للفرع (أرقام Carbon)
        Schema::table('missions', function (Blueprint $t) {
            $t->foreignId('external_id')->nullable()->constrained('externals')->nullOnDelete();                 // خبير مسافر: مستحقاته من عقده
            $t->string('ticket_class', 10)->nullable();
        });
        Schema::table('mission_comments', fn (Blueprint $t) => $t->jsonb('mentions')->nullable());
        Schema::create('mission_invites', function (Blueprint $t) {                                            // دعوة عمل ضمن المهمة بموافقة مستقلة (FR-TM-026)
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('mission_id')->constrained()->cascadeOnDelete();
            $t->string('guest_name', 160); $t->string('organization', 160)->nullable(); $t->string('nationality', 2)->nullable();
            $t->string('passport', 40)->nullable(); $t->text('purpose');
            $t->date('from_on'); $t->date('to_on');
            $t->string('status', 14)->default('pending_hr');
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->string('letter_no', 30)->nullable();
            $t->timestamps();
        });
        Schema::table('engagements', function (Blueprint $t) {
            $t->foreignId('relation_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('mission_id')->nullable()->constrained('missions')->nullOnDelete();
            $t->string('project', 160)->nullable();
        });
        Schema::table('credentials', function (Blueprint $t) {
            $t->foreignId('employee_id')->nullable()->change();
            $t->foreignId('external_id')->nullable()->constrained('externals')->cascadeOnDelete();
            $t->text('memory_token')->nullable();                                                                // رمز ذاكرة البطاقة (مشفّر)
        });
        Schema::table('contract_annexes', fn (Blueprint $t) => $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete());

        foreach (\App\Models\Company::all() as $c) \App\Support\Tenant::run($c, fn () => \App\Support\Audit::mute(function () {
            \App\Models\ApprovalChain::firstOrCreate(['type' => 'work_invite'], ['levels' => [\App\Services\Workflow\Workflow::level('hr_director')]]);
            \App\Models\ApprovalChain::firstOrCreate(['type' => 'mission_amend'], ['levels' => [\App\Services\Workflow\Workflow::level('manager'), \App\Services\Workflow\Workflow::level('finance')]]);
            \App\Support\Forms::seed();
        }));
    }

    public function down(): void
    {
        Schema::table('contract_annexes', fn (Blueprint $t) => $t->dropConstrainedForeignId('approved_by'));
        Schema::table('credentials', function (Blueprint $t) { $t->dropConstrainedForeignId('external_id'); $t->dropColumn('memory_token'); });
        Schema::table('engagements', function (Blueprint $t) { $t->dropConstrainedForeignId('relation_employee_id'); $t->dropConstrainedForeignId('mission_id'); $t->dropColumn('project'); });
        Schema::dropIfExists('mission_invites');
        Schema::table('mission_comments', fn (Blueprint $t) => $t->dropColumn('mentions'));
        Schema::table('missions', function (Blueprint $t) { $t->dropConstrainedForeignId('external_id'); $t->dropColumn('ticket_class'); });
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn('rest_days'));
        Schema::table('code_items', fn (Blueprint $t) => $t->dropColumn('group_item_id'));
        Schema::table('code_lists', fn (Blueprint $t) => $t->dropColumn('inherit'));
        Schema::table('companies', fn (Blueprint $t) => $t->dropConstrainedForeignId('parent_id'));
        \Illuminate\Support\Facades\DB::table('approval_chains')->whereIn('type', ['work_invite', 'mission_amend'])->delete();
    }
};
