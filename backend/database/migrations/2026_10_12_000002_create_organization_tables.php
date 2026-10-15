<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الهيكل: المواقع (المدن)، الإدارات، الموظفون، الوظائف، أجهزة الحضور.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();          // dam, alp, hom …
            $t->string('name_ar');
            $t->string('name_en');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('departments', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();          // exec, hr, fin, it, sales, ops, cs, admin
            $t->string('name_ar');
            $t->string('name_en');
            $t->unsignedInteger('headcount')->default(0);   // الملاك العددي المعتمد
            $t->string('color', 20)->default('brand');      // لون الإدارة في الهيكل
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();          // UHR-1001
            $t->string('name');
            $t->string('name_en');
            $t->char('gender', 1);                      // M / F
            $t->string('title_ar');
            $t->string('title_en');
            $t->foreignId('department_id')->constrained();
            $t->foreignId('location_id')->constrained();
            $t->string('email')->unique();
            $t->string('phone', 40)->nullable();
            $t->date('hire_date');
            $t->date('dob')->nullable();
            $t->string('national_id', 30)->nullable()->unique();
            $t->decimal('salary', 12, 2)->default(0);
            $t->char('currency', 3)->default('USD');
            $t->string('status', 20)->default('onboarding');   // onboarding, probation, active, leave, resigning, terminated
            $t->string('nfc_uid', 40)->nullable()->unique();
            $t->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->decimal('leave_used', 6, 1)->default(0);
            $t->unsignedSmallInteger('sick_used')->default(0);
            $t->unsignedInteger('points')->default(0);
            $t->unsignedInteger('lifetime_points')->default(0);
            $t->date('end_date')->nullable();
            $t->timestamps();
            $t->index(['department_id', 'status']);
        });

        Schema::table('departments', function (Blueprint $t) {
            $t->foreignId('head_id')->nullable()->after('name_en')->constrained('employees')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('employee_id')->nullable()->after('name')->unique()->constrained()->nullOnDelete();
        });

        Schema::create('job_openings', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();          // REQ-2026-031
            $t->string('title_ar');
            $t->string('title_en');
            $t->foreignId('department_id')->constrained();
            $t->foreignId('location_id')->constrained();
            $t->string('employment_type', 20)->default('full_time');   // full_time, part_time, shifts, contract
            $t->unsignedSmallInteger('openings')->default(1);
            $t->unsignedSmallInteger('min_years')->default(0);
            $t->unsignedTinyInteger('degree')->default(2);             // 0 ثانوية … 4 دكتوراه
            $t->decimal('salary_min', 12, 2);
            $t->decimal('salary_max', 12, 2);
            $t->char('currency', 3)->default('USD');
            $t->text('summary_ar');
            $t->text('summary_en');
            $t->jsonb('requirements');                  // [{ar, en}]
            $t->string('status', 10)->default('draft'); // draft, open, closed
            $t->date('posted_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('devices', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar');
            $t->string('name_en');
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->string('key_hash');                     // مفتاح الجهاز (مشفّر)
            $t->string('kind', 20)->default('reader');  // reader (USB/PC-SC)، sunmi، mobile
            $t->timestamp('last_seen_at')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
        Schema::dropIfExists('job_openings');
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('employee_id'));
        Schema::table('departments', fn (Blueprint $t) => $t->dropConstrainedForeignId('head_id'));
        Schema::dropIfExists('employees');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('locations');
    }
};
