<?php

use App\Models\ApprovalChain;
use App\Support\Tenant;
use App\Support\ViolationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M17 المخالفات والجزاءات (SRS §10.7): دليل المخالفات، حالات المخالفة (فتح ← إبلاغ ← تحقيق ← قرار)، موافقة المدير العام
 * على الجزاءات الأشد بالمسار الموحد، إقرار الموظف، والشكاوى HR.01-01-F-02.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('violation_types', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('group', 10);                       // time, work, conduct
            $t->unsignedSmallInteger('number');
            $t->text('title_ar');
            $t->text('title_en');
            $t->string('article', 40)->nullable();         // المادة 95/ب/3
            $t->jsonb('penalties');                        // [{kind, days?}] أول/ثاني/ثالث/رابع مرة
            $t->string('auto', 12)->nullable();            // اقتراح من الحضور
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['company_id', 'group', 'number']);
        });

        Schema::create('discipline_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref_no', 30);
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('violation_type_id')->constrained();
            $t->date('occurred_on');
            $t->date('discovered_on');
            $t->date('deadline');                          // discovered_on + deadline_days
            $t->string('source', 12)->default('manual');   // manual, attendance
            $t->text('description');
            $t->string('status', 14)->default('open');     // open, investigated, pending_gm, decided, dismissed, expired
            $t->unsignedTinyInteger('occurrence')->default(1);
            $t->jsonb('suggested')->nullable();            // جزاء الدرجة
            $t->jsonb('penalty')->nullable();              // الجزاء النهائي
            $t->text('reduce_reason')->nullable();
            $t->decimal('deduct_days', 5, 2)->default(0);  // بعد سقف الشهر
            $t->boolean('capped')->default(false);
            $t->char('month', 7)->nullable();               // شهر الحسم
            $t->text('defense')->nullable();               // أقوال الموظف من بوابته
            $t->timestamp('defended_at')->nullable();
            $t->jsonb('investigation')->nullable();        // statement, witnesses, notes, union_notified, at, by
            $t->text('close_reason')->nullable();
            $t->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->foreignId('ack_signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'ref_no']);
            $t->index(['employee_id', 'violation_type_id', 'status']);
        });

        // موافقة المدير العام على الجزاء الأشد (بند 4 فما فوق) — طلب في المسار الموحد
        Schema::create('penalty_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('discipline_case_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->jsonb('penalty');
            $t->text('reason')->nullable();
            $t->string('status', 16)->default('pending_exec');
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });

        Schema::create('complaints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('ref_no', 30);
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->string('subject', 190);
            $t->text('body');
            $t->string('against', 190)->nullable();
            $t->string('status', 12)->default('received');   // received, reviewing, closed
            $t->text('response')->nullable();
            $t->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('responded_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'ref_no']);
        });

        foreach (DB::table('companies')->pluck('id') as $company) Tenant::run($company, function () {
            ViolationCatalog::seed();
            ApprovalChain::firstOrCreate(['type' => 'penalty'], ['levels' => [['role_ar' => 'المدير العام', 'role_en' => 'General manager', 'role_key' => 'ceo']]]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('penalty_approvals');
        Schema::dropIfExists('discipline_cases');
        Schema::dropIfExists('violation_types');
    }
};
