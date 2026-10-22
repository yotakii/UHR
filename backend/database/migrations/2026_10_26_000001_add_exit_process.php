<?php

use App\Models\ApprovalChain;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M11 حسب إجراء الشركة HR.01-03 (SRS §10.8): الاستقالة F-02 وإنهاء العقد F-03 بالمسار الموحد (المدير ← HR ← المدير العام)،
 * مقابلة HR والاستبيان، الإعفاء من الإخطار، الرجوع عن الاستقالة، تسليم المهام F-05، تسليم العهدة F-07 بجهاتها، براءة الذمة F-04،
 * شهادة الخدمة F-06، المخالصة F-33، نسخ الحساب للبديل (D-14)، مهام ما بعد الخروج، وإشعار الجهة الخارجية (D-17).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resignations', function (Blueprint $t) {
            $t->string('kind', 12)->default('resignation');         // resignation, termination
            $t->string('ref_no', 30)->nullable();
            $t->string('prev_status', 16)->nullable();                // حالة الموظف قبل الطلب (للرجوع)
            $t->jsonb('reasons')->nullable();                         // مفاتيح أسباب الاستقالة / الفصل
            $t->jsonb('survey')->nullable();                          // استبيان الاستقالة {question_key: answer}
            $t->date('notice_until')->nullable();                     // نهاية الإخطار المطلوب = التقديم + notice_days
            $t->boolean('notice_waived')->default(false);
            $t->text('waive_note')->nullable();
            $t->jsonb('hr_interview')->nullable();                    // {reasons, notes, recommendation, at, by}
            $t->jsonb('termination')->nullable();                     // F-03: {type, cause, reasons, other, notes}
            $t->boolean('rehire')->nullable();
            $t->text('rehire_reason')->nullable();
            $t->jsonb('handover')->nullable();                        // F-05
            $t->jsonb('withdrawal')->nullable();                      // {text, at, status, decided_by, note}
            $t->string('clearance_no', 30)->nullable();               // F-04 HR-n/YYYY
            $t->timestamp('clearance_issued_at')->nullable();
            $t->string('service_cert_no', 30)->nullable();            // F-06
            $t->timestamp('service_cert_at')->nullable();
            $t->jsonb('settlement')->nullable();                      // تفصيل F-33 عند الإغلاق
            $t->foreignId('successor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('account_moved_at')->nullable();
            $t->jsonb('followups')->nullable();                       // مهام ما بعد الخروج
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
        });
        // chain (الديمو) لم يعد إلزامياً — الخطوات في request_steps
        DB::statement('ALTER TABLE resignations ALTER COLUMN chain DROP NOT NULL');
        DB::statement('ALTER TABLE resignations ALTER COLUMN reason_key TYPE varchar(30)');
        DB::statement("ALTER TABLE resignations ALTER COLUMN reason_key DROP NOT NULL");
        DB::statement("ALTER TABLE resignations ALTER COLUMN text DROP NOT NULL");

        // إشعار الجهة الخارجية بانتهاء الخدمة (D-17): مُعدّ ← معتمد ← مُرسل
        Schema::create('exit_notices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('resignation_id')->constrained()->cascadeOnDelete();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('service_request_id')->nullable()->constrained()->nullOnDelete();
            $t->jsonb('addressee');
            $t->boolean('consent')->default(false);
            $t->string('status', 12)->default('prepared');   // prepared, sent, blocked
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });

        foreach (DB::table('companies')->pluck('id') as $company) Tenant::run($company, function () {
            $mgr = ['role_ar' => 'المدير المباشر', 'role_en' => 'Direct manager', 'role_key' => 'manager'];
            $hr = ['role_ar' => 'الموارد البشرية', 'role_en' => 'Human Resources', 'role_key' => 'hr_director'];
            $gm = ['role_ar' => 'المدير العام', 'role_en' => 'General manager', 'role_key' => 'ceo'];
            ApprovalChain::updateOrCreate(['type' => 'resignation'], ['levels' => [$mgr, $hr, $gm]]);
            ApprovalChain::firstOrCreate(['type' => 'termination'], ['levels' => [$mgr, $hr, $gm]]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exit_notices');
        Schema::table('resignations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('successor_user_id');
            $t->dropConstrainedForeignId('requested_by');
            $t->dropConstrainedForeignId('closed_by');
            $t->dropColumn(['kind', 'ref_no', 'prev_status', 'reasons', 'survey', 'notice_until', 'notice_waived', 'waive_note', 'hr_interview', 'termination', 'rehire', 'rehire_reason',
                'handover', 'withdrawal', 'clearance_no', 'clearance_issued_at', 'service_cert_no', 'service_cert_at', 'settlement', 'account_moved_at', 'followups', 'decided_at']);
        });
    }
};
