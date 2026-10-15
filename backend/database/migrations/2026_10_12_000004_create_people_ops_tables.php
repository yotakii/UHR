<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * شؤون الموظفين: المباشرة، الحضور، الطلبات، الخدمات، العهد، الاستقالات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboardings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $t->jsonb('checklist');                     // bool[28] — F-19
            $t->timestamp('memo_sent_at')->nullable();  // F-32
            $t->foreignId('f22_signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamp('f22_signed_at')->nullable();
            $t->foreignId('f20_signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamp('f20_signed_at')->nullable();
            $t->string('f20_mother_name')->nullable();
            $t->timestamps();
        });

        Schema::create('attendance_days', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->timestamp('in_at')->nullable();
            $t->timestamp('out_at')->nullable();
            $t->string('status', 10)->default('present');   // present, late, absent, leave, weekend
            $t->boolean('justified')->default(false);
            $t->timestamps();
            $t->unique(['employee_id', 'date']);
            $t->index('date');
        });

        Schema::create('scans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->timestamp('ts');
            $t->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $t->string('device_ar');                    // بوابة المقر — دمشق / تطبيق الجوال — ضمن نطاق المقر
            $t->string('device_en');
            $t->string('type', 3);                      // in, out
            $t->string('raw_uid', 40)->nullable();
            $t->index(['ts']);
        });

        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('type', 10);                     // annual, sick, unpaid, hourly, mission, justify
            $t->date('from');
            $t->date('to');
            $t->unsignedTinyInteger('hours')->nullable();
            $t->unsignedSmallInteger('minutes')->nullable();
            $t->date('ref_date')->nullable();
            $t->text('reason');
            $t->string('status', 16)->default('pending_manager');   // pending_manager, pending_hr, approved, rejected
            $t->jsonb('chain');                         // [{role:{ar,en}, by, by_user_id, at, decision}]
            $t->timestamps();
            $t->index(['status', 'employee_id']);
        });

        Schema::create('service_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);                     // salary_cert, experience_cert, advance, data_update
            $t->text('note');
            $t->decimal('amount', 12, 2)->nullable();
            $t->string('status', 10)->default('pending');
            $t->string('decided_by')->nullable();
            $t->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('done_at')->nullable();
            $t->timestamps();
        });

        Schema::create('asset_categories', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();          // computers, phones, cards, vehicles, peripherals
            $t->string('name_ar');
            $t->string('name_en');
            $t->timestamps();
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->string('tag', 20)->unique();           // AST-0001
            $t->string('name_ar');
            $t->string('name_en');
            $t->foreignId('asset_category_id')->constrained();
            $t->string('serial', 60)->nullable();
            $t->decimal('value', 12, 2)->default(0);
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 12)->default('available');   // available, pending_ack, assigned, maintenance
            $t->timestamp('assigned_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->foreignId('signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'employee_id']);
        });

        Schema::create('resignations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('last_day');
            $t->string('reason_key', 10);               // offer, travel, study, other
            $t->text('text');
            $t->string('status', 16)->default('pending_manager');   // pending_manager, pending_hr, clearance, closed, rejected
            $t->text('manager_note')->nullable();
            $t->jsonb('exit')->nullable();              // {rating, would_return, feedback}
            $t->jsonb('clearance');                     // {custody, it, finance, hr}
            $t->jsonb('chain');                         // [{role:{ar,en}, by, by_user_id, at}]
            $t->decimal('settlement_total', 12, 2)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['resignations', 'assets', 'asset_categories', 'service_requests', 'leave_requests', 'scans', 'attendance_days', 'onboardings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
