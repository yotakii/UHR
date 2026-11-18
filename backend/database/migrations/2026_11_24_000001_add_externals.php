<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعة 22 — العاملون من غير الموظفين (SRS الأنظمة الأساسية، الجزء E): سجل منفصل عن الموظفين لا يدخل التعداد ولا الرواتب،
 * فئة العامل من دليل الترميز تقرر كل شيء؛ المتطوعون (اتفاقية، ساعات، مصادقة، شهادة) وقائمة الخبراء (تدقيق، تكليف بشروط مرجعية،
 * مخرجات ودفعات للمالية، تقييم).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('externals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref', 20);                                   // EXT-2026-0001
            $t->string('kind', 12);                                  // volunteer | expert | consultant | trial | intern
            $t->foreignId('worker_category_id')->nullable()->constrained('code_items')->nullOnDelete();
            $t->string('entity', 10)->default('person');             // person | firm (شركة استشارية)
            $t->string('name'); $t->string('name_en')->nullable();
            $t->string('gender', 1)->nullable(); $t->date('dob')->nullable();
            $t->string('national_id', 40)->nullable(); $t->string('nationality', 2)->nullable();
            $t->string('email')->nullable(); $t->string('phone', 40)->nullable();
            $t->string('country', 2)->nullable(); $t->string('city', 80)->nullable();
            $t->jsonb('emergency')->nullable();                      // {name, phone, relation}
            $t->jsonb('areas')->nullable();                          // مجالات التطوع المفضلة
            $t->jsonb('availability')->nullable();                   // {days[], hours, note}
            // الخبراء
            $t->jsonb('specialties')->nullable();                    // [code_item ids]
            $t->jsonb('sectors')->nullable(); $t->jsonb('keywords')->nullable(); $t->jsonb('languages')->nullable();
            $t->unsignedSmallInteger('years')->nullable();
            $t->string('level', 12)->nullable();                     // junior | mid | senior | expert
            $t->text('projects')->nullable();
            $t->string('available', 12)->default('now');             // now | from | no
            $t->date('available_from')->nullable();
            $t->jsonb('work_modes')->nullable();                     // onsite | remote | travel
            $t->decimal('rate', 12, 2)->nullable(); $t->string('rate_unit', 6)->nullable(); $t->string('rate_currency', 3)->nullable();   // سري
            $t->decimal('avg_rating', 3, 2)->nullable(); $t->unsignedInteger('ratings')->default(0);
            $t->timestamp('last_engaged_at')->nullable();
            $t->date('review_due')->nullable(); $t->timestamp('review_requested_at')->nullable();
            // مشترك
            $t->string('status', 14);                                // volunteer: applicant|accepted|waitlist|pending_sign|active|ended|suspended|excluded — expert: applicant|vetting|approved|assigned|inactive|excluded|rejected
            $t->string('status_note', 255)->nullable();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('cv_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->jsonb('documents')->nullable();                      // [{type, file_id, valid_to, note}]
            $t->text('notes')->nullable();                           // سرية
            $t->string('source', 10)->default('internal');           // internal | public
            $t->foreignId('opportunity_id')->nullable();
            $t->foreignId('converted_candidate_id')->nullable()->constrained('candidates')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'ref']);
            $t->index(['company_id', 'kind', 'status']);
        });

        Schema::create('volunteer_opportunities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('title'); $t->string('title_en')->nullable(); $t->text('description')->nullable();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->date('starts_on')->nullable(); $t->date('ends_on')->nullable();
            $t->unsignedInteger('slots')->default(0); $t->unsignedSmallInteger('weekly_hours')->nullable();
            $t->string('status', 8)->default('open');                // draft | open | closed
            $t->string('token', 40)->unique();                       // رابط التسجيل العام
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // التكليف / الاتفاقية لغير الموظف (متطوع، عقد تجريبي، استشاري) — موضوع سير عمل
        Schema::create('engagements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref', 20);                                   // ENG-2026-0001
            $t->foreignId('external_id')->constrained()->cascadeOnDelete();
            $t->string('title'); $t->text('tasks')->nullable();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->date('starts_on'); $t->date('ends_on');
            $t->unsignedSmallInteger('weekly_hours')->default(0);
            $t->jsonb('days')->nullable();
            $t->string('relation', 12)->default('alongside');        // replaces | alongside | independent (POL-VL-02)
            $t->foreignId('replaces_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('replaces_position')->nullable();
            $t->jsonb('relation_test')->nullable();                  // {q1..q6: bool}
            $t->unsignedTinyInteger('relation_score')->default(0);
            $t->text('justification')->nullable();
            $t->string('fee_model', 8)->default('none');             // none | lump | daily | monthly | expenses
            $t->decimal('amount', 14, 2)->nullable(); $t->string('currency', 3)->nullable();
            $t->decimal('expense_cap', 14, 2)->nullable();
            $t->string('tax_treatment', 255)->nullable();
            $t->text('body')->nullable();                            // نص الاتفاقية المجمّد عند الاعتماد
            $t->jsonb('pledges')->nullable();                        // {agreement, confidentiality, conduct, safety: at}
            $t->foreignId('signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamp('signed_at')->nullable();
            $t->jsonb('onboarding')->nullable();                     // [{item, done}]
            $t->string('status', 16)->default('draft');              // draft | pending_* | approved | active | ended | terminated | cancelled | rejected
            $t->jsonb('evaluation')->nullable();                     // {rating, note, at, by}
            $t->string('certificate_no', 30)->nullable(); $t->string('verify_code', 16)->nullable()->unique();
            $t->timestamp('end_warned_at')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'ref']);
        });

        Schema::create('volunteer_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('engagement_id')->constrained()->cascadeOnDelete();
            $t->date('date'); $t->decimal('hours', 5, 2);
            $t->string('activity', 500);
            $t->string('via', 10)->default('portal');                // portal | supervisor | nfc
            $t->string('status', 10)->default('pending');            // pending | approved | rejected
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable(); $t->string('note', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['engagement_id', 'date']);
        });

        // تكليف خبير (WF-RS-02): الشروط المرجعية ← قائمة مختصرة ← اختيار ← عقد ← مخرجات ← تقييم
        Schema::create('assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref', 20);                                   // ASG-2026-0001
            $t->string('title');
            $t->jsonb('tor');                                        // {objective, outputs, place, specialties[]}
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->date('starts_on')->nullable(); $t->date('ends_on')->nullable();
            $t->decimal('budget', 14, 2)->nullable(); $t->decimal('total_fee', 14, 2)->nullable(); $t->string('currency', 3)->default('USD');
            $t->string('tax_treatment', 255)->nullable();
            $t->jsonb('shortlist')->nullable();                      // [{external_id, note}]
            $t->foreignId('external_id')->nullable()->constrained()->nullOnDelete();      // الخبير المختار
            $t->text('selection_reason')->nullable();
            $t->jsonb('relation_test')->nullable(); $t->unsignedTinyInteger('relation_score')->default(0);
            $t->jsonb('pledges')->nullable();                        // {confidentiality, conflict: at}
            $t->foreignId('contract_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamp('contracted_at')->nullable();
            $t->string('status', 14)->default('request');            // request | shortlist | selected | contracted | in_progress | delivered | rated | closed | cancelled | terminated
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'ref']);
        });
        Schema::create('deliverables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $t->string('title'); $t->date('due_on')->nullable();
            $t->decimal('payment', 14, 2)->default(0);
            $t->string('status', 10)->default('pending');            // pending | submitted | approved | returned
            $t->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable(); $t->timestamp('reviewed_at')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamps();
        });
        Schema::create('expert_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignment_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('external_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('quality'); $t->unsignedTinyInteger('timeliness'); $t->unsignedTinyInteger('communication'); $t->unsignedTinyInteger('value');
            $t->decimal('score', 3, 2);
            $t->boolean('rehire')->default(true);
            $t->text('notes')->nullable();                           // سرية
            $t->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        // طلبات الدفع للمالية — خارج مسيّر الرواتب (مخرج معتمد أو تعويض مصاريف متطوع)
        Schema::create('payment_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref', 20);                                   // PAY-2026-0001
            $t->foreignId('external_id')->constrained()->cascadeOnDelete();
            $t->string('source_type', 12);                           // deliverable | expense
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('description');
            $t->decimal('amount', 14, 2); $t->string('currency', 3);
            $t->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->string('status', 10)->default('pending');            // pending | approved | paid | rejected
            $t->string('finance_ref', 60)->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->timestamp('paid_at')->nullable();
            $t->string('note', 255)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'ref']);
        });

        foreach ([['volunteer', 'متطوع', 'Volunteer'], ['expert', 'خبير خارجي', 'External expert']] as [$k, $ar, $en])
            DB::table('roles')->insertOrIgnore(['key' => $k, 'name_ar' => $ar, 'name_en' => $en, 'created_at' => now(), 'updated_at' => now()]);

        // قائمة التخصصات + سلسلة اعتماد التكليف ونموذج الاتفاقية والشهادة لكل شركة
        foreach (\App\Models\Company::all() as $c) \App\Support\Tenant::run($c, function () {
            \App\Support\Audit::mute(function () {
                \App\Services\Codes\CodeCatalog::seedCompany();
                \App\Support\Forms::seed();
                \App\Services\Externals\Externals::seedChains();
            });
        });
    }

    public function down(): void
    {
        foreach (['payment_requests', 'expert_ratings', 'deliverables', 'assignments', 'volunteer_logs', 'engagements', 'volunteer_opportunities', 'externals'] as $tbl) Schema::dropIfExists($tbl);
        DB::table('approval_chains')->whereIn('type', ['engagement', 'engagement_high'])->delete();
        DB::table('roles')->whereIn('key', ['volunteer', 'expert'])->delete();
    }
};
