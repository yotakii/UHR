<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المسار الموحد للطلبات (SRS §10.1): خطوات اعتماد لكل طلب (المدير ← الموارد البشرية ← المدير التنفيذي/الرئيس التنفيذي)،
 * مع النائب عند غياب صاحب المستوى، والتذكير بعد المهلة. + حقول إجازات HR.01-01-F-03 (البديل، المستند، الأيام)،
 * والتكليف بالعمل الإضافي HR.01-01-F-04، ونائب كل مدير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->morphs('subject');
            $t->unsignedTinyInteger('level');
            $t->string('role_key', 30);                         // manager, hr_director, executive, ceo …
            $t->string('role_ar');
            $t->string('role_en');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();         // صاحب المستوى المسمّى (أو النائب بعد التحويل)
            $t->foreignId('delegate_for')->nullable()->constrained('users')->nullOnDelete(); // حُوّل من هذا المستخدم لأنه في إجازة
            $t->string('status', 10)->default('waiting');      // waiting, pending, approved, rejected, skipped, cancelled
            $t->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('note')->nullable();
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        Schema::table('leave_requests', function (Blueprint $t) {
            $t->string('type', 20)->change();
            $t->foreignId('substitute_id')->nullable()->after('reason')->constrained('employees')->nullOnDelete();
            $t->decimal('days', 6, 2)->nullable()->after('substitute_id');
            $t->foreignId('doc_file_id')->nullable()->after('days')->constrained('files')->nullOnDelete();
            $t->jsonb('warnings')->nullable()->after('doc_file_id');   // over_balance, conflict:[names], late_notice…
            $t->foreignId('requested_by')->nullable()->after('warnings')->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->jsonb('chain')->nullable()->change();
        });

        Schema::create('overtime_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->unsignedSmallInteger('minutes');
            $t->text('reason');
            $t->string('status', 16)->default('pending_hr');   // pending_*, approved, rejected, cancelled
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'employee_id', 'date']);
        });

        Schema::table('employees', function (Blueprint $t) {
            $t->foreignId('delegate_id')->nullable()->after('manager_id')->constrained('employees')->nullOnDelete();   // نائب المدير
        });
    }

    public function down(): void
    {
        Schema::table('employees', fn (Blueprint $t) => $t->dropConstrainedForeignId('delegate_id'));
        Schema::dropIfExists('overtime_requests');
        Schema::table('leave_requests', function (Blueprint $t) {
            $t->dropConstrainedForeignId('substitute_id');
            $t->dropConstrainedForeignId('doc_file_id');
            $t->dropConstrainedForeignId('requested_by');
            $t->dropColumn(['days', 'warnings', 'decided_at']);
        });
        Schema::dropIfExists('request_steps');
    }
};
