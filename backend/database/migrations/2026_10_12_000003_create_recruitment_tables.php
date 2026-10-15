<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * التوظيف: المتقدمون، المقابلات F-25، العروض F-29، قرارات التعيين F-31، الإحالات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $t) {
            $t->id();
            $t->string('ref', 30)->unique();            // UNQ-2026-APP-48210 / UNQ-2026-INT-1234
            $t->foreignId('job_opening_id')->constrained();
            $t->string('stage', 12)->default('new');    // new, screening, interview, technical, offer, hired, rejected
            $t->date('stage_at');
            $t->string('name');
            $t->string('name_en')->nullable();
            $t->char('gender', 1);
            $t->string('marital', 12)->nullable();
            $t->string('nationality', 60)->nullable();
            $t->date('dob')->nullable();
            $t->string('pob', 80)->nullable();
            $t->string('national_id', 30)->nullable()->index();
            $t->string('blood', 4)->nullable();
            $t->string('email')->index();
            $t->string('mobile', 40);
            $t->string('mobile2', 40)->nullable();
            $t->string('address')->nullable();
            $t->string('linkedin')->nullable();
            $t->jsonb('education');                     // [{phase, institute, major, year, grade}]
            $t->jsonb('experience');                    // [{company, position, from, to, tasks, reason}]
            $t->jsonb('refs');                          // [{name, job, phone}]
            $t->string('english', 10)->default('good');
            $t->string('arabic', 10)->default('native');
            $t->boolean('shifts')->default(false);
            $t->boolean('travel')->default(false);
            $t->boolean('public_sector')->default(false);
            $t->boolean('conviction')->default(false);
            $t->decimal('salary', 12, 2)->default(0);
            $t->date('availability')->nullable();
            $t->string('source', 20)->default('website');   // website, linkedin, referral, expo, internal
            $t->unsignedSmallInteger('years_exp')->default(0);
            $t->unsignedTinyInteger('degree_level')->default(2);
            $t->foreignId('cv_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->foreignId('referrer_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('ref_code', 30)->nullable();
            $t->foreignId('internal_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->text('internal_reason')->nullable();
            $t->string('auto_flag', 10)->nullable();    // below, passed
            $t->string('reject_reason')->nullable();
            $t->unsignedSmallInteger('match_score')->default(0);
            $t->timestamps();
            $t->index(['stage', 'job_opening_id']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('candidate_id')->nullable()->after('employee_id')->unique()->constrained()->nullOnDelete();
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('candidate_id')->nullable()->after('manager_id')->constrained()->nullOnDelete();
        });

        Schema::create('interviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();
            $t->jsonb('ratings');                       // {readiness: 1..5, … 24 معياراً}
            $t->text('notes')->nullable();
            $t->string('recommendation', 12);           // recommended, reserve, rejected
            $t->string('interviewer');
            $t->foreignId('interviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('date');
            $t->unsignedTinyInteger('pct')->default(0);
            $t->timestamps();
        });

        Schema::create('offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('contract', 10);                 // fixed, open, project
            $t->char('currency', 3)->default('USD');
            $t->decimal('basic', 12, 2);
            $t->decimal('housing', 12, 2)->default(0);
            $t->decimal('transport', 12, 2)->default(0);
            $t->decimal('fuel', 12, 2)->default(0);
            $t->unsignedSmallInteger('leave_days')->default(14);
            $t->date('start_date');
            $t->timestamp('issued_at');
            $t->string('status', 12)->default('sent');  // sent, accepted, declined, negotiation
            $t->timestamp('responded_at')->nullable();
            $t->foreignId('signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->text('note')->nullable();
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('appointments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('candidate_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('decision_no', 30)->unique();    // QR-2026-417
            $t->timestamp('issued_at');
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('appointment_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('level');
            $t->string('role_ar');
            $t->string('role_en');
            $t->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();  // صاحب المستوى
            $t->string('approver_name');                // لقطة الاسم وقت القرار
            $t->string('status', 10)->default('pending');
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();  // من اعتمد فعلاً (نيابة؟)
            $t->unique(['appointment_id', 'level']);
        });

        Schema::create('referrals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('job_opening_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30)->unique();           // UHR1007-J1
            $t->unsignedInteger('clicks')->default(0);
            $t->foreignId('candidate_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 10)->default('shared');   // shared, applied, hired
            $t->unsignedInteger('awarded')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'job_opening_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('appointment_approvals');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('interviews');
        Schema::table('employees', fn (Blueprint $t) => $t->dropConstrainedForeignId('candidate_id'));
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('candidate_id'));
        Schema::dropIfExists('candidates');
    }
};
