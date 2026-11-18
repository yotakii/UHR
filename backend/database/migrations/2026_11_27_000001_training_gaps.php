<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعة 27 — إكمال موديول التدريب حسب SRS: القاعات وتعارضها، تأجيل التنفيذ وتقييمه، اعتماد اختيار العرض،
 * طلبات تعديل الخطة المعتمدة، السجل التدريبي السابق للنظام، التدوير الداخلي، فرص الستاج وإيراده، ورابط التقويم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('td_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120); $t->string('name_en', 120)->nullable();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('capacity')->default(20);
            $t->jsonb('equipment')->nullable();                      // ["projector", …]
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::table('td_sessions', function (Blueprint $t) {
            $t->foreignId('room_id')->nullable()->constrained('td_rooms')->nullOnDelete();
            $t->foreignId('quotes_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('quotes_approved_at')->nullable();
            $t->string('quote_status', 10)->nullable();              // pending | approved | rejected (اختيار العرض الخارجي)
            $t->text('postpone_reason')->nullable();
            $t->timestamp('evaluated_at')->nullable();
            $t->jsonb('ai_summary')->nullable();                     // خلاصة آراء المتدربين {text, at}
        });
        Schema::table('td_enrollments', function (Blueprint $t) {
            $t->text('exclude_reason')->nullable();
        });
        Schema::table('td_plan_items', function (Blueprint $t) {
            $t->jsonb('pending_change')->nullable();                 // طلب تعديل بعد الاعتماد بانتظار القرار
        });
        Schema::create('td_approvals', function (Blueprint $t) {   // موضوع سير عمل: تعديل خطة معتمدة (BR-18) أو اختيار عرض خارجي (BR-05)
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 12);                                  // plan_change | quote
            $t->foreignId('plan_id')->nullable()->constrained('td_plans')->cascadeOnDelete();
            $t->foreignId('item_id')->nullable()->constrained('td_plan_items')->cascadeOnDelete();
            $t->foreignId('session_id')->nullable()->constrained('td_sessions')->cascadeOnDelete();
            $t->jsonb('data'); $t->text('reason'); $t->decimal('cost_delta', 14, 2)->default(0);
            $t->string('status', 14)->default('pending_hr');
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('td_history', function (Blueprint $t) {        // تدريب سابق لاستخدام النظام (FR-TD-104)
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('title'); $t->string('provider', 160)->nullable(); $t->string('place', 160)->nullable();
            $t->string('kind', 10)->default('external');             // internal | external
            $t->date('from_on')->nullable(); $t->date('to_on')->nullable();
            $t->decimal('hours', 6, 1)->default(0); $t->decimal('cost', 14, 2)->nullable(); $t->string('currency', 3)->nullable();
            $t->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->text('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('td_qualifications', function (Blueprint $t) {
            $t->dropUnique(['employee_id']);
            $t->string('kind', 14)->default('qualification');        // qualification | rotation (ستاج داخلي لموظف)
            $t->foreignId('development_activity_id')->nullable()->constrained('development_activities')->nullOnDelete();
            $t->index(['employee_id', 'kind']);
        });
        Schema::table('volunteer_opportunities', function (Blueprint $t) {
            $t->string('kind', 10)->default('volunteer');            // volunteer | intern
            $t->string('stage_type', 6)->nullable();                 // paid | free | fee
            $t->decimal('fee', 12, 2)->nullable(); $t->string('fee_currency', 3)->nullable();
        });
        Schema::table('engagements', function (Blueprint $t) {
            $t->decimal('stage_fee', 12, 2)->nullable(); $t->string('stage_fee_currency', 3)->nullable();
            $t->timestamp('stage_fee_paid_at')->nullable(); $t->string('stage_fee_ref', 60)->nullable();
        });
        Schema::table('users', fn (Blueprint $t) => $t->string('calendar_token', 48)->nullable()->unique());

        foreach (\App\Models\Company::all() as $c) \App\Support\Tenant::run($c, fn () => \App\Support\Audit::mute(function () {
            \App\Support\Forms::seed();
            \App\Models\ApprovalChain::firstOrCreate(['type' => 'td_plan_change'], ['levels' => [\App\Services\Workflow\Workflow::level('ceo')]]);
            \App\Models\ApprovalChain::firstOrCreate(['type' => 'quote_choice'], ['levels' => [\App\Services\Workflow\Workflow::level('ceo')]]);
        }));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('calendar_token'));
        Schema::table('engagements', fn (Blueprint $t) => $t->dropColumn(['stage_fee', 'stage_fee_currency', 'stage_fee_paid_at', 'stage_fee_ref']));
        Schema::table('volunteer_opportunities', fn (Blueprint $t) => $t->dropColumn(['kind', 'stage_type', 'fee', 'fee_currency']));
        Schema::table('td_qualifications', function (Blueprint $t) { $t->dropIndex(['employee_id', 'kind']); $t->dropConstrainedForeignId('development_activity_id'); $t->dropColumn('kind'); $t->unique('employee_id'); });
        Schema::dropIfExists('td_history');
        Schema::dropIfExists('td_approvals');
        Schema::table('td_plan_items', fn (Blueprint $t) => $t->dropColumn('pending_change'));
        Schema::table('td_enrollments', fn (Blueprint $t) => $t->dropColumn('exclude_reason'));
        Schema::table('td_sessions', function (Blueprint $t) { $t->dropConstrainedForeignId('room_id'); $t->dropConstrainedForeignId('quotes_approved_by'); $t->dropColumn(['quotes_approved_at', 'quote_status', 'postpone_reason', 'evaluated_at', 'ai_summary']); });
        Schema::dropIfExists('td_rooms');
        \Illuminate\Support\Facades\DB::table('approval_chains')->whereIn('type', ['td_plan_change', 'quote_choice'])->delete();
    }
};
