<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الدفعة 23 — موديول التأهيل والتدريب والتطوير TD (SRS التدريب):
 * الكفاءات المطلوبة لكل وظيفة ومستويات الموظفين، المدربون والمراكز، مكتبة البرامج، الاحتياجات وحملات TNA، الخطة السنوية،
 * التنفيذ والترشيح والتعهدات والحضور والنتائج، الاستبيان وقياس الأثر 30-60-90، الشهادات برمز تحقق، التأهيل للموظف الجديد، خطة بناء القدرات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competencies', function (Blueprint $t) {
            $t->string('kind', 12)->default('behavioral');          // technical | behavioral | leadership
            $t->jsonb('levels')->nullable();                         // وصف المستويات 1–5
        });
        // FR-TD-003: الكفاءات المطلوبة لكل وظيفة (المسميات من دليل الترميز) ومستواها
        Schema::create('job_competencies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('job_id')->constrained('code_items')->cascadeOnDelete();
            $t->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('level');
            $t->timestamps();
            $t->unique(['job_id', 'competency_id']);
        });
        // مستوى كفاءة الموظف الحالي ومصدره (تقييم، تدريب + تحقق المدير، إدخال)
        Schema::create('employee_competencies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('competency_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('level');
            $t->string('source', 20)->default('manual');            // appraisal | training | manual
            $t->string('source_ref', 60)->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'competency_id']);
        });

        Schema::create('td_trainers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);                                  // internal | external
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('external_id')->nullable()->constrained('externals')->nullOnDelete();   // خبير من القائمة
            $t->foreignId('center_id')->nullable();
            $t->string('name'); $t->string('name_en')->nullable();
            $t->text('qualifications')->nullable(); $t->unsignedSmallInteger('years')->nullable();
            $t->jsonb('specialties')->nullable();
            $t->foreignId('cv_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->decimal('hourly_rate', 12, 2)->nullable(); $t->string('currency', 3)->nullable();   // سري
            $t->decimal('rating', 3, 2)->nullable(); $t->unsignedInteger('ratings')->default(0);
            $t->decimal('hours_given', 8, 2)->default(0);
            $t->string('status', 10)->default('approved');          // approved | watch | banned
            $t->string('status_note', 255)->nullable();
            $t->timestamps();
        });
        Schema::create('td_centers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('name'); $t->string('name_en')->nullable();
            $t->string('contact', 160)->nullable(); $t->string('phone', 40)->nullable(); $t->string('email', 160)->nullable(); $t->string('city', 80)->nullable();
            $t->text('accreditations')->nullable();
            $t->decimal('quality', 3, 2)->nullable(); $t->unsignedInteger('ratings')->default(0);
            $t->string('status', 10)->default('approved');          // approved | watch | banned
            $t->string('status_note', 255)->nullable();
            $t->timestamps();
        });

        // FR-TD-070: مكتبة البرامج
        Schema::create('td_programs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('title'); $t->string('title_en')->nullable();
            $t->string('path', 14);                                  // qualification | training | development
            $t->string('delivery', 20);                              // internal | internal_ext | external | ojt | stage | elearning | coaching | event
            $t->string('audience', 12)->default('staff');            // staff | departments | managers
            $t->string('method', 12)->default('general');            // general | workshop | lecture | group
            $t->text('objectives')->nullable(); $t->text('outcomes')->nullable();
            $t->jsonb('modules')->nullable();                        // [{title, hours}]
            $t->decimal('hours', 6, 1)->default(0);
            $t->jsonb('prerequisites')->nullable();                  // [program ids]
            $t->jsonb('competencies')->nullable();                   // [{id, level}]
            $t->boolean('mandatory')->default(false);
            $t->jsonb('materials')->nullable();                      // [file ids]
            $t->date('reviewed_on')->nullable();
            $t->unsignedTinyInteger('pass_mark')->nullable(); $t->unsignedTinyInteger('attendance_min')->nullable();
            $t->boolean('has_test')->default(false);
            $t->foreignId('kpi_id')->nullable()->constrained('kpis')->nullOnDelete();
            $t->jsonb('impact_items')->nullable();                   // بنود F-05 الخاصة بالبرنامج
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });

        Schema::create('td_campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('title'); $t->unsignedSmallInteger('year');
            $t->string('scope', 12)->default('company');            // company | branch | department
            $t->jsonb('scope_ids')->nullable();
            $t->date('opens_on'); $t->date('closes_on');
            $t->string('status', 8)->default('open');                // open | closed
            $t->jsonb('responses')->nullable();                      // {department_id: submitted_at}
            $t->timestamp('reminded_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // الاحتياج التدريبي (FR-TD-022/023)
        Schema::create('td_needs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref', 20);
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->jsonb('employees')->nullable();                      // مجموعة
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('competency_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('current_level')->nullable(); $t->unsignedTinyInteger('required_level')->nullable();
            $t->string('title');
            $t->string('path', 14)->default('training');
            $t->string('level', 10)->default('individual');         // company | job | individual
            $t->string('priority', 8)->default('normal');            // low | normal | high | urgent
            $t->decimal('score', 8, 2)->default(0);
            $t->jsonb('factors')->nullable();                        // {impact, gap, affected, urgency} 1..5
            $t->string('source', 12)->default('request');           // campaign | appraisal | request | gap | event | kpi | idp
            $t->string('source_ref', 60)->nullable();
            $t->string('cause', 12)->nullable();                     // knowledge | motivation | resources | procedures
            $t->jsonb('evidence')->nullable();                       // [survey, interview, observation, appraisal, kpi]
            $t->text('evidence_override')->nullable();
            $t->jsonb('objective')->nullable();                      // SMART {specific, measurable, achievable, relevant, timebound}
            $t->string('status', 12)->default('submitted');          // suggested | submitted | approved | rejected | not_training | planned | done
            $t->text('note')->nullable();
            $t->foreignId('campaign_id')->nullable()->constrained('td_campaigns')->nullOnDelete();
            $t->foreignId('program_id')->nullable()->constrained('td_programs')->nullOnDelete();
            $t->unsignedBigInteger('plan_item_id')->nullable();
            $t->decimal('estimated_cost', 14, 2)->nullable();
            $t->string('preferred_date', 40)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'ref']);
            $t->index(['company_id', 'status']);
        });

        // الخطة السنوية (FR-TD-040…048) — موضوع سير عمل
        Schema::create('td_plans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->string('title');
            $t->string('scope', 12)->default('company');
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('budget', 14, 2)->default(0); $t->string('currency', 3)->default('SYP');
            $t->jsonb('budget_split')->nullable();                   // {Q1..Q4} أو {path: amount}
            $t->string('status', 16)->default('draft');              // draft | pending_hr | pending_exec | approved | rejected
            $t->jsonb('baseline')->nullable();                       // النسخة الأساسية المقفلة عند الاعتماد
            $t->text('finance_note')->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'year', 'scope', 'location_id']);
        });
        Schema::create('td_plan_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained('td_plans')->cascadeOnDelete();
            $t->foreignId('program_id')->nullable()->constrained('td_programs')->nullOnDelete();
            $t->string('title');
            $t->string('path', 14); $t->string('delivery', 20); $t->string('audience', 12)->default('staff');
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('month')->nullable();            // 1..12
            $t->unsignedSmallInteger('trainees')->default(0);
            $t->decimal('estimated_cost', 14, 2)->default(0); $t->decimal('actual_cost', 14, 2)->default(0);
            $t->string('priority', 8)->default('normal');
            $t->string('objective', 255)->nullable();               // الهدف المؤسسي المرتبط
            $t->unsignedBigInteger('cbp_initiative')->nullable();
            $t->string('status', 12)->default('planned');            // planned | confirmed | in_progress | done | postponed | cancelled
            $t->jsonb('changes')->nullable();                        // طلبات التعديل بعد الاعتماد [{at, by, what, reason, cost_delta}]
            $t->timestamps();
        });

        // التنفيذ (Session/Run) — FR-TD-071
        Schema::create('td_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref', 20);
            $t->foreignId('program_id')->constrained('td_programs')->cascadeOnDelete();
            $t->foreignId('plan_item_id')->nullable()->constrained('td_plan_items')->nullOnDelete();
            $t->string('title');
            $t->date('starts_on'); $t->date('ends_on');
            $t->jsonb('meetings');                                   // [{date, from, to}]
            $t->foreignId('trainer_id')->nullable()->constrained('td_trainers')->nullOnDelete();
            $t->foreignId('center_id')->nullable()->constrained('td_centers')->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->string('place', 200)->nullable(); $t->string('link', 255)->nullable();
            $t->unsignedSmallInteger('capacity')->default(20);
            $t->jsonb('costs')->nullable();                          // [{type, amount, currency, note}]
            $t->string('currency', 3)->default('SYP');
            $t->jsonb('quotes')->nullable();                         // F-14 [{center, price, duration, prior, terms, score}]
            $t->unsignedTinyInteger('quote_choice')->nullable(); $t->text('quote_reason')->nullable();
            $t->jsonb('logistics')->nullable();                      // [{item, done}]
            $t->string('status', 12)->default('planned');            // planned | enrolling | confirmed | in_progress | completed | closed | cancelled
            $t->string('survey_token', 40)->nullable()->unique();
            $t->jsonb('kpi')->nullable();                            // {before, after, note}
            $t->jsonb('roi')->nullable();                            // {benefit, note}
            $t->text('cancel_reason')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'ref']);
        });
        Schema::create('td_enrollments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('session_id')->constrained('td_sessions')->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('need_id')->nullable()->constrained('td_needs')->nullOnDelete();
            $t->string('status', 18)->default('nominated');          // nominated | pending_commitment | confirmed | waitlist | withdrawn | completed
            $t->text('reason')->nullable();                          // سبب الترشيح (تكافؤ الفرص)
            $t->text('exception')->nullable();                       // استثناء موثق من فحص الأهلية
            $t->boolean('manager_ok')->nullable(); $t->timestamp('manager_at')->nullable();
            $t->jsonb('attendance')->nullable();                     // {index: present|absent|late|excused}
            $t->decimal('attendance_pct', 5, 1)->nullable();
            $t->decimal('pre_score', 5, 1)->nullable(); $t->decimal('post_score', 5, 1)->nullable();
            $t->string('result', 10)->nullable();                    // passed | failed | withdrawn
            $t->string('result_reason', 255)->nullable();
            $t->jsonb('failure')->nullable();                        // {decision retrain|extend|charge, note, by, at}
            $t->jsonb('apply_plan')->nullable();                     // F-12 {items[3]: {what, when}, support}
            $t->decimal('cost', 14, 2)->nullable();
            $t->timestamps();
            $t->unique(['session_id', 'employee_id']);
        });
        // تعهد الالتزام F-03 (BR-06/BR-13)
        Schema::create('td_commitments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enrollment_id')->nullable()->constrained('td_enrollments')->nullOnDelete();
            $t->string('title');
            $t->decimal('cost', 14, 2); $t->string('currency', 3);
            $t->unsignedSmallInteger('months');
            $t->date('starts_on')->nullable(); $t->date('ends_on')->nullable();
            $t->string('mode', 8)->default('prorata');              // full | prorata
            $t->text('body')->nullable();
            $t->string('status', 12)->default('generated');          // generated | active | fulfilled | breached | recovered | waived | cancelled
            $t->foreignId('signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamp('signed_at')->nullable(); $t->string('signed_ip', 45)->nullable();
            $t->decimal('recovery', 14, 2)->nullable();
            $t->string('decision_note', 255)->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('resignation_id')->nullable();
            $t->timestamps();
        });
        // F-07 استقصاء رأي المتدرب (قد يكون مجهولاً)
        Schema::create('td_surveys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('session_id')->constrained('td_sessions')->cascadeOnDelete();
            $t->foreignId('enrollment_id')->nullable()->constrained('td_enrollments')->nullOnDelete();
            $t->jsonb('ratings'); $t->jsonb('answers')->nullable();
            $t->decimal('avg', 3, 2);
            $t->timestamps();
        });
        // F-05 قياس الأثر عند 30/60/90 يوماً
        Schema::create('td_impacts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('enrollment_id')->constrained('td_enrollments')->cascadeOnDelete();
            $t->unsignedSmallInteger('point');                       // 30 | 60 | 90
            $t->string('kind', 10);                                  // talk | behavior | kpi
            $t->date('due_on');
            $t->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->jsonb('ratings')->nullable(); $t->text('notes')->nullable(); $t->text('barriers')->nullable();
            $t->decimal('avg', 5, 1)->nullable();
            $t->string('status', 8)->default('pending');             // pending | done | skipped
            $t->timestamp('reminded_at')->nullable(); $t->timestamp('escalated_at')->nullable(); $t->timestamp('done_at')->nullable();
            $t->timestamps();
            $t->unique(['enrollment_id', 'point']);
        });
        // F-13 تقييم المدرب والمركز
        Schema::create('td_ratings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('session_id')->unique()->constrained('td_sessions')->cascadeOnDelete();
            $t->jsonb('scores');                                     // {content, trainer, punctuality, facilities, results, value}
            $t->decimal('avg', 3, 2);
            $t->string('recommendation', 10);                        // approve | watch | ban
            $t->text('note')->nullable();
            $t->foreignId('rated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        // الشهادات (FR-TD-100…105): داخلية برمز تحقق، أو خارجية مرفوعة بسير تحقق
        Schema::create('td_certificates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('no', 30)->nullable(); $t->string('code', 16)->nullable()->unique();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('external_id')->nullable()->constrained('externals')->nullOnDelete();
            $t->string('type', 16);                                  // completion | competency | excellence | stage | professional | qualification
            $t->string('title'); $t->string('issuer', 160)->nullable();
            $t->foreignId('session_id')->nullable()->constrained('td_sessions')->nullOnDelete();
            $t->foreignId('competency_id')->nullable()->constrained()->nullOnDelete();
            $t->date('issued_on'); $t->date('valid_to')->nullable();
            $t->string('source', 8)->default('internal');           // internal | external
            $t->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->string('status', 10)->default('valid');              // pending | valid | rejected | revoked | expired
            $t->string('status_note', 255)->nullable();
            $t->jsonb('alerts')->nullable();                         // [90, 60, 30] أُرسلت
            $t->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        // F-01 برنامج تأهيل الموظف الجديد
        Schema::create('td_qualifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('template', 60)->nullable();
            $t->jsonb('stations');                                   // [{department_id, trainer_id(employee), topics, from, to, note, signed_at, signed_by}]
            $t->string('status', 10)->default('planned');            // planned | running | done
            $t->unsignedTinyInteger('progress')->default(0);
            $t->jsonb('evaluation')->nullable();                     // {skills: {}, recommendation confirm|extend|not_confirm, note, by, at}
            $t->foreignId('certificate_id')->nullable()->constrained('td_certificates')->nullOnDelete();
            $t->timestamps();
        });
        // خطة بناء القدرات CBP (FR-TD-050…056)
        Schema::create('td_cbps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('title'); $t->date('starts_on')->nullable(); $t->date('ends_on')->nullable();
            $t->string('owner', 160)->nullable();                    // الجهة المالكة أو الممول
            $t->text('description')->nullable();
            $t->jsonb('goals');                                      // [{id, title, kpi, baseline, target, achieved, capacities: [{competency_id, job_id, count, level, by}], initiatives: [{id, title, kind training|other, owner, cost, kpi, due, progress, plan_item_id}]}]
            $t->string('status', 8)->default('active');
            $t->timestamps();
        });

        Schema::table('engagements', function (Blueprint $t) {            // الستاج = تكليف متدرب في سجل غير الموظفين
            $t->string('stage_type', 6)->nullable();                     // paid | free | fee
            $t->unsignedSmallInteger('required_hours')->nullable();
            $t->jsonb('stations')->nullable();                           // [{department_id, from, to, goals, skills[]}]
            $t->jsonb('stage_eval')->nullable();                         // {skills: {}, behavior, rating, recommend_hire}
        });
        Schema::table('attendance_days', fn (Blueprint $t) => $t->foreignId('td_session_id')->nullable()->constrained('td_sessions')->nullOnDelete());

        foreach (\App\Models\Company::all() as $c) \App\Support\Tenant::run($c, fn () => \App\Support\Audit::mute(function () {
            \App\Support\Forms::seed();
            \App\Models\ApprovalChain::firstOrCreate(['type' => 'td_plan'], ['levels' => \App\Services\Training\Plans::DEFAULT_CHAIN]);
        }));
    }

    public function down(): void
    {
        Schema::table('attendance_days', fn (Blueprint $t) => $t->dropConstrainedForeignId('td_session_id'));
        Schema::table('engagements', fn (Blueprint $t) => $t->dropColumn(['stage_type', 'required_hours', 'stations', 'stage_eval']));
        foreach (['td_cbps', 'td_qualifications', 'td_certificates', 'td_ratings', 'td_impacts', 'td_surveys', 'td_commitments', 'td_enrollments', 'td_sessions', 'td_plan_items', 'td_plans', 'td_needs', 'td_campaigns', 'td_programs', 'td_centers', 'td_trainers', 'employee_competencies', 'job_competencies'] as $tbl) Schema::dropIfExists($tbl);
        Schema::table('competencies', fn (Blueprint $t) => $t->dropColumn(['kind', 'levels']));
        \Illuminate\Support\Facades\DB::table('approval_chains')->where('type', 'td_plan')->delete();
    }
};
